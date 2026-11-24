<?php

/*
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  @author Ink
 *  @link https://www.github.com/ink-db
 *
 *
 */

declare(strict_types=1);

namespace inkphp\driver;

use inkphp\contracts\AsyncDriverInterface;
use inkphp\exception\ConnectionException;
use inkphp\exception\ProtocolException;
use inkphp\result\AppendResult;
use inkphp\result\QueryResult;
use inkphp\utils\IwpUtils;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use Throwable;
use function bin2hex;
use function file_get_contents;
use function hash;
use function hex2bin;
use function is_file;
use function json_encode;
use function ord;
use function pack;
use function React\Promise\reject;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function sodium_crypto_sign_seed_keypair;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * IWP client driven by ReactPHP's event loop; it creates no worker threads.
 */
final class AsyncInkTcpDriver implements AsyncDriverInterface
{
	private ?ConnectionInterface $connection = null;
	private ?string $secretKey = null;
	private string $publicKey = '';
	private string $readBuffer = '';
	private int $nextStreamId = 1;
	private bool $authenticated = false;
	/** @var Deferred<bool>|null */
	private ?Deferred $connectDeferred = null;

	/** @var array<int, array{kind: string, deferred: Deferred<QueryResult|AppendResult>, columns: list<string>, rows: list<array<string, mixed>>, count: int, time: int, append?: array{recordId: int, hash: string, timestamp: int}}> */
	private array $pending = [];

	public function __construct(
		private readonly LoopInterface $loop,
		private ?ConnectorInterface $connector = null,
	) {
	}

	/** @return PromiseInterface<bool> */
	public function connect(string $host, int $port, string $privateKeyPath, float $connectTimeout = 5.0) : PromiseInterface
	{
		if ($this->connection !== null || $this->connectDeferred !== null) {
			return reject(new ConnectionException('AsyncInkTcpDriver is already connecting or connected.'));
		}
		if ($port < 1 || $port > 65535) {
			return reject(new ConnectionException('Ink TCP port must be between 1 and 65535.'));
		}
		if ($privateKeyPath === '' || !is_file($privateKeyPath)) {
			return reject(new ConnectionException('The private key path must point to an Ink private key file.'));
		}

		try {
			$this->loadKey($privateKeyPath);
		} catch (Throwable $error) {
			return reject($error);
		}

		/** @var Deferred<bool> $deferred */
		$deferred = new Deferred();
		$this->connectDeferred = $deferred;
		$loop = $this->loop;
		$connector = $this->connector ??= new Connector(['timeout' => $connectTimeout], $loop);
		$connector->connect(sprintf('tcp://%s:%d', $host, $port))->then(
			function (ConnectionInterface $connection) : void {
				$this->connection = $connection;
				$connection->on('data', $this->onData(...));
				$connection->on('error', fn (Throwable $error) => $this->failAll(new ConnectionException('Ink TCP connection failed: ' . $error->getMessage(), 0, $error)));
				$connection->on('close', $this->onClose(...));
				$this->writeFrame(0x01, 0, $this->publicKey . pack('vV', 1, 0));
			},
			function (Throwable $error) use ($deferred) : void {
				$this->connectDeferred = null;
				$deferred->reject(new ConnectionException('Could not connect to Ink: ' . $error->getMessage(), 0, $error));
			},
		);
		return $deferred->promise();
	}

	public function getConnector() : ?ConnectorInterface
	{
		return $this->connector;
	}

	public function getConnection() : ?ConnectionInterface
	{
		return $this->connection;
	}

	/** @return PromiseInterface<QueryResult|AppendResult> */
	public function query(string $iql) : PromiseInterface
	{
		/** @var Deferred<QueryResult|AppendResult> $deferred */
		$deferred = new Deferred();
		$streamId = $this->allocateStreamId();
		$this->pending[$streamId] = ['kind' => 'query', 'deferred' => $deferred, 'columns' => [], 'rows' => [], 'count' => 0, 'time' => 0];
		try {
			$this->writeFrame(0x10, $streamId, pack('V', strlen($iql)) . $iql);
		} catch (Throwable $error) {
			unset($this->pending[$streamId]);
			$deferred->reject($error);
		}
		return $deferred->promise();
	}

	/**
	 * @param array<string, mixed> $data
	 * @return PromiseInterface<QueryResult|AppendResult>
	 */
	public function append(string $table, array $data) : PromiseInterface
	{
		/** @var Deferred<QueryResult|AppendResult> $deferred */
		$deferred = new Deferred();
		$streamId = $this->allocateStreamId();
		try {
			$json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			strlen($table) <= 65535 || throw new ConnectionException('Ink table name exceeds the IWP maximum length.');
			$this->pending[$streamId] = ['kind' => 'append-challenge', 'deferred' => $deferred, 'columns' => [], 'rows' => [], 'count' => 0, 'time' => 0];
			$this->writeFrame(0x15, $streamId, pack('v', strlen($table)) . $table . pack('V', strlen($json)) . $json);
		} catch (Throwable $error) {
			unset($this->pending[$streamId]);
			$deferred->reject($error);
		}
		return $deferred->promise();
	}

	public function close() : void
	{
		$this->connection?->close();
		$this->connection = null;
		$this->authenticated = false;
		$this->readBuffer = '';
		$this->secretKey = null;
		$this->publicKey = '';
		$this->connectDeferred = null;
		$this->failAll(new ConnectionException('Ink connection was closed.'));
	}

	private function onData(string $data) : void
	{
		$this->readBuffer .= $data;
		try {
			while (strlen($this->readBuffer) >= 18) {
				$header = substr($this->readBuffer, 0, 18);
				$length = IwpUtils::uint32($header, 10);
				$length <= 67_108_864 || throw new ProtocolException('Ink frame exceeds the 64 MiB protocol limit.');
				$frameLength = 18 + $length + 4;
				if (strlen($this->readBuffer) < $frameLength) {
					return;
				}
				$frame = substr($this->readBuffer, 0, $frameLength);
				$this->readBuffer = substr($this->readBuffer, $frameLength);
				$this->handleFrame($frame);
			}
		} catch (Throwable $error) {
			$this->failAll($error);
			$this->connection?->close();
		}
	}

	private function handleFrame(string $frame) : void
	{
		$header = substr($frame, 0, 18);
		str_starts_with($header, 'INK') && ord($header[3]) === 1 || throw new ProtocolException('Ink returned a frame with invalid magic or protocol version.');
		IwpUtils::uint32($header, 14) === IwpUtils::crc32($header, 0, 14) || throw new ProtocolException('Ink frame header checksum failed.');
		$type = ord($header[4]);
		$streamId = IwpUtils::uint32($header, 6);
		$length = IwpUtils::uint32($header, 10);
		$payload = substr($frame, 18, $length);
		IwpUtils::uint32($frame, 18 + $length) === IwpUtils::crc32($payload) || throw new ProtocolException('Ink frame payload checksum failed.');

		if (!$this->authenticated) {
			$this->handleAuthenticationFrame($type, $payload);
			return;
		}
		if (!isset($this->pending[$streamId])) {
			return;
		}
		if ($type === 0x05) {
			$this->rejectStream($streamId, IwpUtils::remoteError($payload));
			return;
		}

		$request = &$this->pending[$streamId];
		if ($request['kind'] === 'append-challenge' && $type === 0x16) {
			strlen($payload) >= 68 || throw new ProtocolException('Ink returned an invalid append challenge.');
			$lease = substr($payload, 0, 16);
			$recordId = IwpUtils::uint64($payload, 16);
			$timestamp = IwpUtils::uint64($payload, 24);
			$hash = substr($payload, 32, 32);
			$request['kind'] = 'append-commit';
			$request['append'] = ['recordId' => $recordId, 'hash' => bin2hex($hash), 'timestamp' => $timestamp];
			$this->writeFrame(0x17, $streamId, $lease . sodium_crypto_sign_detached($hash, $this->secretKey()));
		} elseif ($request['kind'] === 'query' && $type === 0x11) {
			$request['columns'] = IwpUtils::decodeColumns($payload);
		} elseif ($type === 0x12) {
			if ($request['kind'] === 'query') {
				$request['rows'][] = IwpUtils::decodeRow($payload);
			}
		} elseif ($type === 0x13) {
			if ($request['kind'] === 'query') {
				strlen($payload) >= 16 || throw new ProtocolException('Ink returned a truncated query summary.');
				$request['count'] = IwpUtils::uint64($payload, 0);
				$request['time'] = IwpUtils::uint64($payload, 8);
				$result = new QueryResult($request['columns'], $request['rows'], $request['count'], $request['time']);
			} elseif ($request['kind'] === 'append-commit' && isset($request['append'])) {
				$result = new AppendResult($request['append']['recordId'], $request['append']['hash'], $request['append']['timestamp']);
			} else {
				throw new ProtocolException(sprintf('Unexpected Ink response frame 0x%02x.', $type));
			}
			$deferred = $request['deferred'];
			unset($this->pending[$streamId]);
			$deferred->resolve($result);
		} else {
			throw new ProtocolException(sprintf('Unexpected Ink response frame 0x%02x.', $type));
		}
		unset($request);
	}

	private function handleAuthenticationFrame(int $type, string $payload) : void
	{
		if ($type === 0x05) {
			$error = IwpUtils::remoteError($payload);
			$this->connectDeferred?->reject($error);
			$this->connectDeferred = null;
			$this->connection?->close();
			return;
		}
		if ($type === 0x02) {
			strlen($payload) >= 68 || throw new ProtocolException('Ink sent an invalid authentication challenge.');
			$databaseId = substr($payload, 0, 16);
			$nonce = substr($payload, 16, 32);
			$sessionId = substr($payload, 48, 16);
			$digest = IwpUtils::blake3('INK-AUTH-v1' . $databaseId . $nonce . $sessionId . str_repeat("\0", 32));
			$this->writeFrame(0x03, 0, $sessionId . sodium_crypto_sign_detached($digest, $this->secretKey()));
			return;
		}
		$type === 0x04 && strlen($payload) >= 20 || throw new ProtocolException('Ink rejected or malformed the authentication response.');
		$this->authenticated = true;
		$deferred = $this->connectDeferred;
		$this->connectDeferred = null;
		$deferred?->resolve(true);
	}

	private function writeFrame(int $type, int $streamId, string $payload) : void
	{
		$this->connection !== null && $this->connection->isWritable() || throw new ConnectionException('Not connected to Ink. Resolve connect() before sending queries.');
		$this->connection->write(IwpUtils::encodeFrame($type, $streamId, $payload));
	}

	private function loadKey(string $path) : void
	{
		try {
			$content = ErrorToExceptionHandler::trapAndRemoveFalse(static fn () : string|false => file_get_contents($path));
		} catch (Throwable $error) {
			throw new ConnectionException("Unable to read Ink key file '{$path}'.", 0, $error);
		}
		if (strlen($content) !== 32) {
			$decoded = hex2bin(trim($content));
			$decoded !== false && strlen($decoded) === 32 || throw new ConnectionException('Ink key must contain a 32-byte raw seed or a 64-character hexadecimal seed.');
			$content = $decoded;
		}
		$pair = sodium_crypto_sign_seed_keypair($content);
		$this->secretKey = sodium_crypto_sign_secretkey($pair);
		$this->publicKey = sodium_crypto_sign_publickey($pair);
	}

	private function allocateStreamId() : int
	{
		$this->authenticated || throw new ConnectionException('Resolve DataConnector::connect() before executing queries.');
		return $this->nextStreamId++;
	}

	private function onClose() : void
	{
		$this->authenticated = false;
		$this->connection = null;
		$this->failAll(new ConnectionException('Ink closed the TCP connection unexpectedly.'));
	}

	private function failAll(Throwable $error) : void
	{
		if ($this->connectDeferred !== null) {
			$this->connectDeferred->reject($error);
			$this->connectDeferred = null;
		}
		foreach ($this->pending as $request) {
			$request['deferred']->reject($error);
		}
		$this->pending = [];
	}

	private function rejectStream(int $streamId, Throwable $error) : void
	{
		$this->pending[$streamId]['deferred']->reject($error);
		unset($this->pending[$streamId]);
	}

	/** @return non-empty-string */
	private function secretKey() : string
	{
		$this->secretKey !== null && $this->secretKey !== '' || throw new ConnectionException('No Ink signing key has been loaded.');
		return $this->secretKey;
	}
}

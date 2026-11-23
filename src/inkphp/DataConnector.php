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

namespace inkphp;

use inkphp\contracts\AsyncDriverInterface;
use inkphp\contracts\LoggerInterface;
use inkphp\contracts\QueryParserInterface;
use inkphp\driver\AsyncInkTcpDriver;
use inkphp\exception\ConnectionException;
use inkphp\exception\QueryException;
use inkphp\logger\NoopLogger;
use inkphp\query\IqlOperation;
use inkphp\query\IqlParser;
use inkphp\query\QueryFileBlock;
use inkphp\result\AppendResult;
use inkphp\result\QueryResult;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Throwable;
use function array_filter;
use function array_key_exists;
use function array_key_last;
use function array_merge;
use function array_pop;
use function array_values;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_split;
use function React\Promise\reject;
use function str_starts_with;
use function trim;

/**
 * Asynchronous query API; all network I/O uses an event loop, never threads.
 */
final class DataConnector
{
	private readonly AsyncDriverInterface $driver;
	private readonly QueryParserInterface $parser;
	private readonly LoggerInterface $logger;

	/** @var array<string, string> */
	private array $queries = [];

	private bool $connected = false;

	public function __construct(
		?AsyncDriverInterface $driver = null,
		?QueryParserInterface $parser = null,
		?LoggerInterface $logger = null,
		?LoopInterface $loop = null,
	) {
		$this->driver = $driver ?? new AsyncInkTcpDriver($loop);
		$this->parser = $parser ?? new IqlParser();
		$this->logger = $logger ?? new NoopLogger();
	}

	/** @return PromiseInterface<void> */
	public function connect(string $host, int $port, string $privateKeyPath, float $connectTimeout = 5.0) : PromiseInterface
	{
		if ($this->connected) {
			return reject(new ConnectionException('DataConnector is already connected; close it before connecting again.'));
		}
		return $this->driver->connect($host, $port, $privateKeyPath, $connectTimeout)->then(function () : void {
			$this->connected = true;
		});
	}

	public function close() : void {
		$this->driver->close();
		$this->connected = false;
	}

   /**
    * Load named `-- #{ name` query blocks from one or more IQL files.
    */
	public function loadQueries(string ...$files) : void
	{
		foreach ($files as $file) {
			try {
				/** @var string $source */
				$source = ErrorToExceptionHandler::trapAndRemoveFalse(static fn () : string|false => file_get_contents($file));
			} catch (Throwable $error) {
				throw new QueryException("Unable to read query file '$file'.", 0, $error);
			}
			$this->parseQueryFile($source, $file);
		}
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return PromiseInterface<QueryResult|AppendResult>
	 */
	public function executeGeneric(string $name, array $arguments = [], ?callable $onSuccess = null, ?callable $onError = null) : PromiseInterface
	{
		return $this->execute($name, $arguments, null, $onSuccess, $onError);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return PromiseInterface<QueryResult>
	 */
	public function executeSelect(string $name, array $arguments = [], ?callable $onSuccess = null, ?callable $onError = null) : PromiseInterface
	{
		return $this->execute($name, $arguments, IqlOperation::READ, $onSuccess, $onError)->then(
			static function (QueryResult|AppendResult $result) : QueryResult {
				$result instanceof QueryResult || throw new QueryException('Expected a query result.');
				return $result;
			},
		);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return PromiseInterface<AppendResult>
	 */
	public function executeInsert(string $name, array $arguments = [], ?callable $onSuccess = null, ?callable $onError = null) : PromiseInterface
	{
		return $this->execute($name, $arguments, IqlOperation::APPEND, $onSuccess, $onError)->then(
			static function (QueryResult|AppendResult $result) : AppendResult {
				$result instanceof AppendResult || throw new QueryException('Expected an append result.');
				return $result;
			},
		);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return PromiseInterface<QueryResult>
	 */
	public function executeCorrect(string $name, array $arguments = [], ?callable $onSuccess = null, ?callable $onError = null) : PromiseInterface
	{
		return $this->execute($name, $arguments, IqlOperation::CORRECT, $onSuccess, $onError)->then(
			static function (QueryResult|AppendResult $result) : QueryResult {
				$result instanceof QueryResult || throw new QueryException('Expected a query result.');
				return $result;
			},
		);
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return PromiseInterface<QueryResult|AppendResult>
	 */
	private function execute(string $name, array $arguments, ?IqlOperation $expectedType, ?callable $onSuccess, ?callable $onError) : PromiseInterface
	{
		if (!$this->connected) {
			return reject(new ConnectionException('Resolve DataConnector::connect() before executing queries.'));
		}
		if (!array_key_exists($name, $this->queries)) {
			return reject(new QueryException("Query '$name' has not been loaded."));
		}

		try {
			$query = $this->parser->parse($this->queries[$name], $arguments);
			($expectedType === null || $query->type === $expectedType) || throw new QueryException("Expected $expectedType->value query; received {$query->type->value}.");
			$promise = $query->type === IqlOperation::APPEND
				? $this->driver->append($query->table ?? throw new QueryException('APPEND query has no table.'), $query->data ?? throw new QueryException('APPEND query has no data.'))
				: $this->driver->query($query->iql);
		} catch (Throwable $error) {
			return $this->rejectQuery($error, $onError);
		}

		return $promise->then(
			function (QueryResult|AppendResult $result) use ($name, $query, $onSuccess) : QueryResult|AppendResult {
				try {
					$this->logger->debug("Ink {$query->type->value} query '$name' completed.");
					if ($onSuccess !== null) {
						if ($result instanceof AppendResult) {
							$onSuccess($result->recordId, 1);
						} elseif ($query->type === IqlOperation::READ || $query->type === IqlOperation::CORRECT) {
							$onSuccess($result->rows);
						} else {
							$onSuccess();
						}
					}
				} catch (Throwable $error) {
					$this->logError($error->getMessage());
					throw $error;
				}
				return $result;
			},
			function (Throwable $error) use ($onError) : never {
				$this->logError($error->getMessage());
				if ($onError !== null) {
					$onError($error);
				}
				throw $error;
			},
		);
	}

	/** @return PromiseInterface<QueryResult|AppendResult> */
	private function rejectQuery(Throwable $error, ?callable $onError) : PromiseInterface
	{
		$this->logError($error->getMessage());
		if ($onError !== null) {
			try {
				$onError($error);
			} catch (Throwable $callbackError) {
				$error = $callbackError;
			}
		}
		return reject($error);
	}

	private function parseQueryFile(string $source, string $file) : void
	{
		/** @var list<string> $groups */
		$groups = [];
		/** @var list<QueryFileBlock> $blocks */
		$blocks = [];
		foreach (preg_split('/\R/', $source) ?: [] as $lineNumber => $line) {
			if (preg_match('/^\s*--\s*#\s*\{\s*(\S+)\s*$/', $line, $match) === 1) {
				if ($blocks !== []) {
					$parent = $blocks[array_key_last($blocks)];
					if (!$parent->hasChild) {
						$parent->hasChild = true;
						$groups[] = $parent->name;
					}
				}
				$blocks[] = new QueryFileBlock($match[1]);
				continue;
			}
			if (preg_match('/^\s*--\s*#\s*}\s*$/', $line) === 1) {
				$blocks !== [] || throw new QueryException("Unmatched query block close in '$file' on line " . ($lineNumber + 1) . '.');
				$block = array_pop($blocks);
				$body = trim(implode("\n", $block->body));
				if (!$block->hasChild && $body !== '') {
					$name = implode('.', array_merge($groups, [$block->name]));
					$queryLines = array_values(array_filter(
						preg_split('/\R/', $body) ?: [],
						static fn (string $queryLine) : bool => preg_match('/^\s*--\s*#/', $queryLine) !== 1 && trim($queryLine) !== '',
					));
					$query = trim(implode("\n", $queryLines));
					$query !== '' || throw new QueryException("Query '$name' in '$file' is empty.");
					!isset($this->queries[$name]) || throw new QueryException("Duplicate query '$name' in '$file'.");
					$this->queries[$name] = $query;
				} elseif ($block->hasChild) {
					$body === '' || throw new QueryException("Group '$block->name' in '$file' cannot contain query text and child blocks at once.");
					array_pop($groups);
				}
				continue;
			}
			if ($blocks !== []) {
				$blocks[array_key_last($blocks)]->body[] = $line;
			} else {
				(trim($line) === '' || str_starts_with(trim($line), '--')) || throw new QueryException("Text outside a query block in '$file' on line " . ($lineNumber + 1) . '.');
			}
		}
		$blocks === [] || throw new QueryException("Unclosed query block in '$file'.");
		$this->queries !== [] || throw new QueryException("No named queries were found in '$file'.");
	}

	private function logError(string $message) : void
	{
		try {
			$this->logger->error($message);
		} catch (Throwable) {
			// Logging failures must not replace the original query error.
		}
	}
}

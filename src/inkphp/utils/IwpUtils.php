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

namespace inkphp\utils;

use inkphp\exception\ConnectionException;
use inkphp\exception\ProtocolException;
use JsonException;
use stdClass;
use function blake3_hash;
use function chr;
use function function_exists;
use function get_object_vars;
use function hash;
use function is_int;
use function is_string;
use function json_decode;
use function pack;
use function strlen;
use function strrev;
use function substr;
use function unpack;
use const JSON_THROW_ON_ERROR;

final class IwpUtils
{

	private function __construct(){
		// NOOP
	}

	public static function encodeFrame(int $type, int $streamId, string $payload) : string
	{
		$header = 'INK' . chr(1) . chr($type) . "\0" . pack('V2', $streamId, strlen($payload));
		$header .= pack('V', self::crc32($header));
		return $header . $payload . pack('V', self::crc32($payload)); // what the flip
	}

	public static function blake3(string $input) : string
	{
		function_exists('blake3_hash') || throw new ConnectionException('The ext-blake3 extension is not loaded.');
		return blake3_hash($input);
	}

	public static function crc32(string $bytes, int $offset = 0, ?int $length = null) : int
	{
		$crc = hash('crc32b', substr($bytes, $offset, $length), true);
		return self::uint32(strrev($crc), 0);
	}

	public static function uint16(string $bytes, int $offset) : int
	{
		$value = unpack('vvalue', substr($bytes, $offset, 2));
		($value !== false && is_int($value['value'] ?? null)) || throw new ProtocolException('Invalid 16-bit integer in an Ink frame.');
		return $value['value'];
	}

	public static function uint32(string $bytes, int $offset) : int
	{
		$value = unpack('Vvalue', substr($bytes, $offset, 4));
		($value !== false && is_int($value['value'] ?? null)) || throw new ProtocolException('Invalid 32-bit integer in an Ink frame.');
		return $value['value'];
	}

	public static function uint64(string $bytes, int $offset) : int
	{
		$value = unpack('Pvalue', substr($bytes, $offset, 8));
		($value !== false && is_int($value['value'] ?? null)) || throw new ProtocolException('Ink returned a 64-bit integer outside this PHP platform range.');
		return $value['value'];
	}

	public static function remoteError(string $payload) : ConnectionException
	{
		if (strlen($payload) < 4) {
			return new ConnectionException('Ink returned an incomplete error response.');
		}
		$code = self::uint16($payload, 0);
		$length = self::uint16($payload, 2);
		return new ConnectionException('Ink error ' . $code . ': ' . substr($payload, 4, $length));
	}

	/** @return list<string> */
	public static function decodeColumns(string $payload) : array
	{
		strlen($payload) >= 2 || throw new ProtocolException('Ink returned an incomplete column header.');
		$count = self::uint16($payload, 0);
		$offset = 2;
		$columns = [];
		for ($i = 0; $i < $count; $i++) {
			$length = self::uint16($payload, $offset);
			$offset += 2;
			strlen($payload) >= $offset + $length || throw new ProtocolException('Ink returned a truncated column name.');
			$columns[] = substr($payload, $offset, $length);
			$offset += $length;
		}
		return $columns;
	}

	/** @return array<string, mixed> */
	public static function decodeRow(string $payload) : array
	{
		strlen($payload) >= 12 || throw new ProtocolException('Ink returned a truncated row.');
		$length = self::uint32($payload, 8);
		strlen($payload) >= 12 + $length || throw new ProtocolException('Ink returned a truncated row JSON payload.');
		try {
			$row = json_decode(substr($payload, 12, $length), false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new ProtocolException('Ink returned invalid JSON in a row.', 0, $error);
		}
		$row instanceof stdClass || throw new ProtocolException('Ink returned a row that is not a JSON object.');
		$result = [];
		foreach (get_object_vars($row) as $key => $value) {
			is_string($key) || throw new ProtocolException('Ink returned a row with a non-string key.');
			$result[$key] = $value;
		}
		return $result;
	}
}

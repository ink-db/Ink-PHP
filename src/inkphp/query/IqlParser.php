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

namespace inkphp\query;

use inkphp\contracts\QueryParserInterface;
use inkphp\exception\QueryException;
use JsonException;
use stdClass;
use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_map;
use function get_object_vars;
use function implode;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_quote;
use function stripcslashes;
use function strlen;
use function strpos;
use function strtok;
use function strtoupper;
use function substr;
use function trim;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class IqlParser implements QueryParserInterface
{
	/** @param array<string, mixed> $arguments */
	public function parse(string $iql, array $arguments) : ParsedQuery
	{
		$iql = trim($iql);
		$iql !== '' || throw new QueryException('IQL query cannot be empty.');

		[$bound, $used] = self::bindArguments($iql, $arguments);
		$unused = array_diff(array_keys($arguments), $used);
		$unused === [] || throw new QueryException('Unexpected argument(s): ' . implode(', ', $unused) . '.');

		self::assertSingleStatement($bound);

		$keyword = strtoupper((string) strtok($bound, " \t\r\n;"));
		$type = IqlOperation::tryFrom($keyword);
		$type !== null || throw new QueryException("Unsupported IQL operation '$keyword'. Ink is immutable and does not support UPDATE or DELETE.");

		if ($type === IqlOperation::APPEND) {
			$append = preg_quote(IqlOperation::APPEND->value, '/');
			preg_match('/^' . $append . '\s+TO\s+(?:"((?:[^"\\\\]|\\\\.)*)"|([A-Za-z_][A-Za-z0-9_]*))\s*/is', $bound, $tableMatch) === 1 || throw new QueryException('Invalid ' . IqlOperation::APPEND->value . ' syntax; expected ' . IqlOperation::APPEND->value . ' TO "table" { JSON object }.');
			$quotedTable = $tableMatch[1] ?? '';
			$plainTable = $tableMatch[2] ?? '';
			$table = stripcslashes($quotedTable !== '' ? $quotedTable : $plainTable);
			$json = self::readObject(substr($bound, strlen($tableMatch[0])));
			$data = self::decodeObject($json, IqlOperation::APPEND);
			return new ParsedQuery($bound, $type, $table, $data);
		}

		$data = null;
		if ($type === IqlOperation::CORRECT) {
			$correct = preg_quote(IqlOperation::CORRECT->value, '/');
			preg_match('/^' . $correct . '\s+record\s+#\d+\s+WITH\s*/i', $bound, $matches) === 1 || throw new QueryException('Invalid ' . IqlOperation::CORRECT->value . ' syntax; expected ' . IqlOperation::CORRECT->value . ' record #id WITH { JSON object }, reason: "...".');
			$json = self::readObject(substr($bound, strlen($matches[0])));
			$data = self::decodeObject($json, IqlOperation::CORRECT);
		}

		return new ParsedQuery($bound, $type, null, $type === IqlOperation::CORRECT ? $data : null);
	}

	/** @return array<string, mixed> */
	private static function decodeObject(string $json, IqlOperation $operation) : array
	{
		try {
			$value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new QueryException("Invalid $operation->value JSON object: {$exception->getMessage()}", 0, $exception);
		}
		$value instanceof stdClass || throw new QueryException($operation->value . ' payload must be a JSON object.');
		$data = [];
		foreach (get_object_vars($value) as $key => $member) {
			is_string($key) || throw new QueryException($operation->value . ' payload keys must be strings.');
			$data[$key] = self::normalizeJsonValue($member);
		}
		return $data;
	}

	private static function normalizeJsonValue(mixed $value) : mixed
	{
		if ($value instanceof stdClass) {
			$object = [];
			foreach (get_object_vars($value) as $key => $member) {
				$object[(string) $key] = self::normalizeJsonValue($member);
			}
			return $object;
		}
		if (is_array($value)) {
			return array_map(self::normalizeJsonValue(...), $value);
		}
		return $value;
	}

	private static function encodeValue(mixed $value, string $name) : string
	{
		(is_string($value) || is_int($value) || is_bool($value) || $value === null || (is_float($value) && is_finite($value))) || throw new QueryException("Argument :$name must be a finite number, string, boolean, or null.");
		try {
			return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		} catch (JsonException $exception) {
			throw new QueryException("Unable to encode argument :$name: {$exception->getMessage()}", 0, $exception);
		}
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @return array{string, list<string>}
	 */
	private static function bindArguments(string $iql, array $arguments) : array
	{
		$bound = '';
		$used = [];
		$inString = false;
		$escaped = false;
		$length = strlen($iql);
		for ($i = 0; $i < $length; $i++) {
			$char = $iql[$i];
			if ($inString) {
				$bound .= $char;
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === '"') {
				$inString = true;
				$bound .= $char;
				continue;
			}
			if ($char === ':' && ($i === 0 || $iql[$i - 1] !== ':') && preg_match('/^:([A-Za-z_][A-Za-z0-9_]*)/', substr($iql, $i), $match) === 1) {
				$name = $match[1];
				array_key_exists($name, $arguments) || throw new QueryException("Missing argument :$name.");
				$used[] = $name;
				$bound .= self::encodeValue($arguments[$name], $name);
				$i += strlen($match[0]) - 1;
				continue;
			}
			$bound .= $char;
		}
		return [$bound, $used];
	}

	private static function readObject(string $source) : string
	{
		$start = strpos($source, '{');
		$start !== false || throw new QueryException('Expected a JSON object in IQL statement.');
		$depth = 0;
		$inString = false;
		$escaped = false;
		for ($i = $start, $length = strlen($source); $i < $length; $i++) {
			$char = $source[$i];
			if ($inString) {
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === '"') {
				$inString = true;
			} elseif ($char === '{') {
				$depth++;
			} elseif ($char === '}' && --$depth === 0) {
				return substr($source, $start, $i - $start + 1);
			}
		}
		throw new QueryException('Unterminated JSON object in IQL statement.');
	}

	private static function assertSingleStatement(string $iql) : void
	{
		$inString = false;
		$escaped = false;
		$objectDepth = 0;
		$statementEnded = false;
		for ($i = 0, $length = strlen($iql); $i < $length; $i++) {
			$char = $iql[$i];
			if ($inString) {
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === '"') {
				$inString = true;
			} elseif ($char === '{') {
				$objectDepth++;
			} elseif ($char === '}') {
				$objectDepth--;
			} elseif ($char === ';' && $objectDepth === 0) {
				$statementEnded = true;
			} elseif ($statementEnded) {
				trim($char) === '' || throw new QueryException('Only one IQL statement is allowed per registered query.');
			}
		}
	}
}

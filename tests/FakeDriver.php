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

namespace inkphp\tests;

use inkphp\contracts\AsyncDriverInterface;
use inkphp\result\AppendResult;
use inkphp\result\QueryResult;
use React\Promise\PromiseInterface;
use function dirname;
use function str_repeat;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class FakeDriver implements AsyncDriverInterface
{
	public bool $connected = false;
	public ?string $lastQuery = null;
	public ?string $lastTable = null;
	/** @var array<string, mixed>|null */
	public ?array $lastData = null;
	public bool $fail = false;

	/** @return PromiseInterface<bool> */
	public function connect(string $host, int $port, string $privateKeyPath, float $connectTimeout = 5.0) : PromiseInterface
	{
		$this->connected = true;
		return \React\Promise\resolve(true);
	}

	/** @return PromiseInterface<QueryResult|AppendResult> */
	public function query(string $iql) : PromiseInterface
	{
		!$this->fail || throw new \RuntimeException('fake failure');
		$this->lastQuery = $iql;
		return \React\Promise\resolve(new QueryResult(['id'], [['id' => 42]], 1, 15));
	}

	/**
	 * @param array<string, mixed> $data
	 * @return PromiseInterface<QueryResult|AppendResult>
	 */
	public function append(string $table, array $data) : PromiseInterface
	{
		!$this->fail || throw new \RuntimeException('fake failure');
		$this->lastTable = $table;
		$this->lastData = $data;
		return \React\Promise\resolve(new AppendResult(43, str_repeat('a', 64), 1234));
	}

	public function close() : void
	{
		$this->connected = false;
	}
}

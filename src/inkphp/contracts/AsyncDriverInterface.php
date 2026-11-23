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

namespace inkphp\contracts;

use inkphp\result\AppendResult;
use inkphp\result\QueryResult;
use React\Promise\PromiseInterface;

interface AsyncDriverInterface
{
	/** @return PromiseInterface<bool> */
	public function connect(string $host, int $port, string $privateKeyPath, float $connectTimeout = 5.0) : PromiseInterface;

	/** @return PromiseInterface<QueryResult|AppendResult> */
	public function query(string $iql) : PromiseInterface;

	/**
	 * @param array<string, mixed> $data
	 * @return PromiseInterface<QueryResult|AppendResult>
	 */
	public function append(string $table, array $data) : PromiseInterface;

	public function close() : void;
}

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

namespace inkphp\result;

final class QueryResult
{
	/**
	 * @param list<string>               $columns
	 * @param list<array<string, mixed>> $rows
	 */
	public function __construct(
		public readonly array $columns,
		public readonly array $rows,
		public readonly int $rowsReturned,
		public readonly int $executionTimeUs,
	) {
	}
}

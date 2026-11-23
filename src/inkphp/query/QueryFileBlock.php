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

/**
 * @internal Parser state for one named block in an IQL file.
 */
final class QueryFileBlock
{
	/** @var list<string> */
	public array $body = [];

	public bool $hasChild = false;

	public function __construct(
		public readonly string $name
	){

	}
}

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

namespace inkphp\logger;

use inkphp\contracts\LoggerInterface;

final class NoopLogger implements LoggerInterface
{
	public function debug(string $message) : void
	{
		// NOOP
	}

	public function error(string $message) : void
	{
		// NOOP
	}
}

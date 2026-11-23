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

enum IqlOperation : string
{
	case READ = 'READ';
	case APPEND = 'APPEND';
	case CORRECT = 'CORRECT';
	case HISTORY = 'HISTORY';
	case VERIFY = 'VERIFY';
	case COUNT = 'COUNT';
	case SUM = 'SUM';
	case AVERAGE = 'AVG';
	case MINIMUM = 'MIN';
	case MAXIMUM = 'MAX';
	case TABLES = 'TABLES';
	case STATUS = 'STATUS';
}

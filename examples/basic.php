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

require dirname(__DIR__) . '/vendor/autoload.php';

use inkphp\DataConnector;
use React\EventLoop\Loop;

$database = new DataConnector();
$database->loadQueries(__DIR__ . '/queries.iql');

$database->connect('127.0.0.1', 7777, __DIR__ . '/alice.key')->then(
	static fn () => $database->executeInsert('sales.append', ['customer' => 'Acme', 'amount' => 1500],
		static fn (int $recordId, int $affectedRows) => printf("Added record #%d\n", $recordId),
	),
)->then(
	static fn () => $database->executeSelect('sales.list', ['minimum' => 1000],
		static function (array $rows) : void {
			printf("Ink returned %d rows.\n", count($rows));
			print_r($rows);
		},
	),
)->then(
	static function () use ($database) : void {
		$database->close();
		Loop::stop();
	},
	static function (Throwable $error) use ($database) : void {
		fprintf(STDERR, "Ink operation failed: %s\n", $error->getMessage());
		$database->close();
		Loop::stop();
	},
);

Loop::run();

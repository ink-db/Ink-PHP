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

use inkphp\DataConnector;
use inkphp\exception\ConnectionException;
use inkphp\result\AppendResult;
use inkphp\result\QueryResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use Throwable;
use function dirname;
use function getenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

#[Group('integration')]
final class InkIntegrationTest extends TestCase
{
	public function testConnectAndQueryInkOverTcp() : void
	{
		$keyPath = getenv('INK_PHP_PRIVATE_KEY');
		if ($keyPath === false || $keyPath === '') {
			self::markTestSkipped('Set INK_PHP_PRIVATE_KEY and run a local Ink TCP server to run integration tests.');
		}
		$host = getenv('INK_PHP_HOST') ?: '127.0.0.1';
		$port = (int) (getenv('INK_PHP_PORT') ?: 7777);
		$connector = new DataConnector();
		$connector->loadQueries(__DIR__ . '/queries.iql');
		$result = null;
		$error = null;

		$connector->connect($host, $port, $keyPath)->then(
			static function () use ($connector, &$result, &$error) {
				return $connector->executeGeneric('maintenance.tables')->then(
					static function (QueryResult|AppendResult $value) use (&$result, $connector) : void {
						$result = $value;
						$connector->close();
						Loop::stop();
					},
					static function (Throwable $reason) use (&$error, $connector) : void {
						$error = $reason;
						$connector->close();
						Loop::stop();
					},
				);
			},
			static function (Throwable $reason) use (&$error) : void {
				$error = $reason;
				Loop::stop();
			},
		);
		Loop::run();

		if ($error !== null) {
			// do not drop errors e.g. an 'Access Denied' error is still valid for this test
			self::assertInstanceOf(ConnectionException::class, $error);
			self::assertStringContainsString('Access denied', $error->getMessage());
		} else {
			// now we assert the instance of the result
			self::assertInstanceOf(QueryResult::class, $result);
		}

		$connector->close();
	}
}

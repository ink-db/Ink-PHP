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
use inkphp\exception\InkException;
use inkphp\result\AppendResult;
use inkphp\result\QueryResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use function dirname;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class DataConnectorTest extends TestCase
{
	private FakeDriver $driver;
	private DataConnector $connector;

	protected function setUp() : void
	{
		$this->driver = new FakeDriver();
		$this->connector = new DataConnector($this->driver);
		$this->connector->loadQueries(__DIR__ . '/queries.iql');
		$this->connector->connect('127.0.0.1', 7777, 'fake.key');
	}

	public function testSelectReturnsItsResultAsPromiseAndInvokesCallback() : void
	{
		$callbackRows = null;
		$result = null;
		$this->connector->executeSelect('users.active', ['active' => true], static function (array $rows) use (&$callbackRows) : void {
			$callbackRows = $rows;
		})->then(static function (QueryResult $value) use (&$result) : void {
			$result = $value;
		});
		self::assertInstanceOf(QueryResult::class, $result);
		self::assertSame([['id' => 42]], $callbackRows);
		self::assertSame('READ FROM "users" WHERE active = true', $this->driver->lastQuery);
	}

	public function testInsertUsesInkAppendFlow() : void
	{
		$result = null;
		$this->connector->executeInsert('users.append', ['name' => 'Ada'])->then(static function (AppendResult $value) use (&$result) : void {
			$result = $value;
		});
		self::assertInstanceOf(AppendResult::class, $result);
		self::assertSame('users', $this->driver->lastTable);
		self::assertSame(['name' => 'Ada'], $this->driver->lastData);
	}

	public function testCorrectUsesInkIql() : void
	{
		$this->connector->executeCorrect('users.correct', ['name' => 'Ada']);
		self::assertSame('CORRECT record #1 WITH {"name": "Ada"}, reason: "typo"', $this->driver->lastQuery);
	}

	public function testGenericDispatchesAppend() : void
	{
		$result = null;
		$this->connector->executeGeneric('users.append', ['name' => 'Ada'])->then(static function (AppendResult|QueryResult $value) use (&$result) : void {
			if ($value instanceof AppendResult) {
				$result = $value;
			}
		});
		self::assertInstanceOf(AppendResult::class, $result);
	}

	public function testMissingArgumentRejectsPromise() : void
	{
		$error = null;
		$this->connector->executeGeneric('users.active')->then(null, static function (Throwable $value) use (&$error) : void {
			$error = $value;
		});
		self::assertInstanceOf(InkException::class, $error);
		self::assertStringContainsString('Missing argument :active', $error->getMessage());
	}

	public function testDriverFailureRejectsPromise() : void
	{
		$this->driver->fail = true;
		$error = null;
		$this->connector->executeSelect('users.active', ['active' => false])->then(null, static function (Throwable $value) use (&$error) : void {
			$error = $value;
		});
		self::assertInstanceOf(RuntimeException::class, $error);
	}

	public function testCloseClosesDriver() : void
	{
		$this->connector->close();
		self::assertFalse($this->driver->connected);
	}

	public function testConnectingTwiceRejectsWithConnectionException() : void
	{
		$error = null;
		$this->connector->connect('127.0.0.1', 7777, 'fake.key')->then(null, static function (Throwable $value) use (&$error) : void {
			$error = $value;
		});
		self::assertInstanceOf(ConnectionException::class, $error);
	}
}

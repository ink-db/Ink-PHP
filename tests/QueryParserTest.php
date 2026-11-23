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

use inkphp\exception\QueryException;
use inkphp\query\IqlOperation;
use inkphp\query\IqlParser;
use PHPUnit\Framework\TestCase;
use function dirname;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class QueryParserTest extends TestCase
{

	private IqlParser $parser;

	protected function setUp() : void {
		$this->parser = new IqlParser();
	}

	public function testBindsTypedValuesInIql() : void
	{
		$query = $this->parser->parse('READ FROM "sales" WHERE amount >= :minimum AND active = :active', [
			'minimum' => 10,
			'active' => true,
		]);
		self::assertSame(IqlOperation::READ, $query->type);
		self::assertStringContainsString('amount >= 10', $query->iql);
		self::assertStringContainsString('active = true', $query->iql);
	}

	public function testParsesAppendPayloadWithNestedJson() : void
	{
		$query = $this->parser->parse('APPEND TO "sales" {"customer": :customer, "meta": {"active": true}}', ['customer' => 'Ada']);
		self::assertSame(IqlOperation::APPEND, $query->type);
		self::assertSame('sales', $query->table);
		self::assertSame(['customer' => 'Ada', 'meta' => ['active' => true]], $query->data);
	}

	public function testDoesNotBindPlaceholdersInsideStringLiterals() : void
	{
		$query = $this->parser->parse('READ FROM "events" WHERE message = "user:admin"', []);
		self::assertSame('READ FROM "events" WHERE message = "user:admin"', $query->iql);
	}

	public function testRejectsEmptyQueryMissingOrInvalidArguments() : void
	{
		$parser = $this->parser;
		try {
			$parser->parse('', []);
			self::fail('Expected empty-query exception.');
		} catch (QueryException $exception) {
			self::assertStringContainsString('cannot be empty', $exception->getMessage());
		}
		try {
			$parser->parse('READ FROM "sales" WHERE id = :id', []);
			self::fail('Expected missing argument exception.');
		} catch (QueryException $exception) {
			self::assertStringContainsString('Missing argument :id', $exception->getMessage());
		}
		$this->expectException(QueryException::class);
		$parser->parse('READ FROM "sales" WHERE id = :id', ['id' => ['invalid']]);
	}

	public function testRejectsMultipleStatementsAndUnsupportedMutation() : void
	{
		$parser = $this->parser;
		try {
			$parser->parse('READ FROM "sales"; STATUS', []);
			self::fail('Expected multi-statement exception.');
		} catch (QueryException $exception) {
			self::assertStringContainsString('Only one IQL statement', $exception->getMessage());
		}
		$this->expectException(QueryException::class);
		$parser->parse('UPDATE sales SET amount = 1', []);
	}
}

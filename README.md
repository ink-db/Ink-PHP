# Ink PHP Connector

Asynchronous PHP connector for Ink over TCP using IWP/1.0. Network I/O runs through ReactPHP's event loop, without worker threads. Queries live in `.iql` files, are executed by name, and return results through callbacks or ReactPHP promises.

## Requirements

- PHP 8.2 or later
- Composer
- PHP extensions `json`, `sodium`, and `blake3`

The BLAKE3 extension is available at [ink-db/ext-blake3](https://github.com/ink-db/ext-blake3). This connector uses the `blake3` algorithm through PHP's `hash()` API.

## Run Ink

Ink is the database server, not a PHP library linked through Composer. From the Ink repository, build and start the daemon:

```sh
cargo build --release
ink serve --dir ./your_directory_data --bind 127.0.0.1:7777
```

Create a client key and grant it permissions in Ink:

```sh
ink keygen --out ./alice.key
```

The connector accepts unencrypted keys as either 32 raw bytes or 64 hexadecimal characters.

## Query files

Declare named IQL queries in `.iql` files:

```iql
-- #{ sales
-- #    { append
APPEND TO "sales" {"customer": :customer, "amount": :amount}
-- #    }
-- #    { list
READ FROM "sales" WHERE amount >= :minimum
-- #    }
-- #}
```

The queries are registered as `sales.append` and `sales.list`. The `:name` placeholders accept strings, integers, finite floats, booleans, and `null`; values are encoded as JSON literals and validated before sending.

## Asynchronous usage

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use inkphp\DataConnector;

$database = new DataConnector();
$database->loadQueries(__DIR__ . '/queries.iql');

$database->connect('127.0.0.1', 7777, __DIR__ . '/alice.key')->then(
    static fn () => $database->executeInsert('sales.append', ['customer' => 'Acme', 'amount' => 1500],
        static fn (int $recordId, int $affectedRows) => printf("Added record #%d\n", $recordId),
    ),
)->then(
    static fn () => $database->executeSelect('sales.list', ['minimum' => 1000],
        static function (array $rows): void {
            print_r($rows);
        },
    ),
)->then(
    static function () use ($database): void {
        $database->close();
        Loop::stop();
    },
    static function (\Throwable $error) use ($database): void {
        fprintf(STDERR, "Ink operation failed: %s\n", $error->getMessage());
        $database->close();
        Loop::stop();
    },
);

Loop::run();
```

`executeGeneric()` invokes its success callback without arguments for ordinary commands. `executeSelect()` and `executeCorrect()` pass the returned rows. `executeInsert()` passes the record ID and the number of inserted records. All query methods return ReactPHP promises; the optional error callback receives the exception while the promise is also rejected.

Pass `host`, `port`, and `privateKeyPath` directly to `connect()`. The event loop can be injected when constructing the connector:

```php
$database = new DataConnector(loop: $loop);
```

## Protocol limitations

- Ink processes `APPEND` with an Ed25519 client signature through IWP. The current TCP interface does not provide a signing message for `CORRECT`.
- The current daemon accepts direct TCP and does not implement TLS.

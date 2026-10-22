<?php

declare(strict_types=1);

namespace Inilim\SqliteShardWriter;

use Inilim\IPDO\IPDO;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShardWriter\KeyNotFoundKvException;
use Inilim\Tool\Obj;

/**
 * @internal
 */
final class Kv
{
    function __construct(
        public readonly IPDOSQLite $ipdo,
    ) {
        if (!$ipdo->existsTable('kv')) {
            throw Obj::sprintfInvalidArgumentException('Table "kv" not exists');
        }
    }

    /**
     * @throws KeyNotFoundKvException
     */
    function get(string $key): mixed
    {
        $value = $this->findRaw($key);
        if (null === $value) {
            throw new KeyNotFoundKvException($key);
        }
        return \json_decode($value, true)['value'];
    }

    function find(string $key): mixed
    {
        $value = $this->findRaw($key);
        return null === $value ? null : \json_decode($value, true)['value'];
    }

    function findRaw(string $key): ?string
    {
        $value = $this->ipdo->exec(
            'SELECT v FROM kv WHERE k = {k}',
            ['k' => $key],
            IPDO::FETCH_ONCE_NUM
        );
        /** @var string|null $value */
        $value = $value[0] ?? null;
        if (null === $value) {
            return null;
        }
        return $value;
    }

    function existsKey(string $key): bool
    {
        return $this->ipdo->exists('SELECT * FROM kv WHERE k = {k}', [
            'k' => $key,
        ]);
    }

    function exists(string $key, mixed $value): bool
    {
        return $this->ipdo->exists('SELECT * FROM kv WHERE k = {k} AND v = {v}', [
            'k' => $key,
            'v' => \json_encode(['value' => $value]),
        ]);
    }

    /**
     * @param string[] $keys
     * @return array<string,mixed>
     */
    function getItems(array $keys): array
    {
        $results = $this->ipdo->exec(
            'SELECT * FROM kv WHERE k in ({keys})',
            ['keys' => $keys],
            IPDO::FETCH_ALL
        );
        $results = \array_column($results, 'v', 'k');
        /** @var array<string,string> $results */

        foreach ($keys as $key) {
            $result = $results[$key] ?? null;
            if (null !== $result) {
                $result = \json_decode($result, true)['value'];
            }

            $results[$key] = $result;
        }

        return $results;
    }

    function set(string $key, mixed $value): self
    {
        $this->ipdo->execBusyRetry(
            'INSERT INTO kv (k,v) VALUES ({k}, {v})
                ON CONFLICT(k) DO UPDATE SET v = {v}',
            [
                'k' => $key,
                'v' => \json_encode(['value' => $value]),
            ]
        );
        return $this;
    }
}

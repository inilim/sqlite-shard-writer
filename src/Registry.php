<?php

declare(strict_types=1);

namespace Inilim\SqliteShardWriter;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDO;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShardWriter\CreateConfig;
use Inilim\SqliteShardWriter\HelperService;
use Inilim\SqliteShardWriter\Kv;
use Inilim\SqliteShardWriter\RegistryNameExistsException;
use Inilim\SqliteShardWriter\ShardNotFoundException;
use Inilim\SqliteShardWriter\ShardNotFreeException;
use Inilim\Tool\Assert;
use Inilim\Tool\File;
use Inilim\Tool\FS;
use Inilim\Tool\ID;
use Inilim\Tool\Obj;
use Inilim\Tool\Path;
use Inilim\Tool\VD;

class Registry
{
    protected Kv $kv;
    protected string $baseDir;
    protected(set) int $minSecHeartbeat = 10;

    protected function __construct(
        protected IPDOSQLite $ipdo,
        ?Kv $kv = null,
    ) {
        $this->baseDir = \dirname($ipdo->getMainFile(), 1);
        $this->kv = null === $kv
            ? \DI(Kv::class, [$ipdo])
            : $kv;
    }

    function setMinSecHeartbeat(int $seconds): self
    {
        Assert::positiveInteger($seconds);
        $this->minSecHeartbeat = $seconds;
        return $this;
    }

    function setRentTimeout(int $rentTimeout): self
    {
        Assert::positiveInteger($rentTimeout);

        $this->kv->setIfChangeValue('rent_timeout', $rentTimeout);
        return $this;
    }

    function setMinShards(int $minShards): self
    {
        Assert::positiveInteger($minShards);

        $this->kv->setIfChangeValue('min_shards', $minShards);
        return $this;
    }

    function getName(): string
    {
        return $this->kv->get('name');
    }

    /**
     * @return string[]
     */
    function createNewShards(int $count = 1): array
    {
        Assert::positiveInteger($count);
        $shardIds = [];
        $files = [];
        $time = \time();
        $sql_shard = 'INSERT INTO shards (id, created_at, updated_at, schema_version, active) VALUES ({id}, {time}, {time}, 1, 0)';

        try {
            $sqls = $this->kv->get('schema_sql');
            /** @var string[] $sqls */
            foreach (Obj::rangeInt(1, $count) as $_) {
                $shardId = ID::uuidv4();
                $files[$shardId] = $ptf = $this->getShardPtf($shardId);
                File::put($ptf, '');
                $ipdoShard = \DI(IPDOSQLite::class, [$ptf]);
                try {
                    foreach ($sqls as $sql) {
                        $ipdoShard->exec($sql);
                    }
                } finally {
                    $ipdoShard->close();
                }

                $this->ipdo->exec($sql_shard, ['id' => $shardId, 'time' => $time]);
                $shardIds[] = $shardId;
                // INFO успешные созданные шарды оставляем
                unset($files[$shardId]);
            }
        } catch (IPDOException $e) {
            if ([] !== $files) {
                FS::unlink_m2($files);
            }
            throw $e;
        }
        return $shardIds;
    }

    function createNewShard(): string
    {
        return $this->createNewShards(1)[0];
    }

    /**
     * @return string[]
     */
    function getAllShardIds(): array
    {
        $ids = $this->ipdo->exec('SELECT id FROM shards', [], IPDO::FETCH_ALL_NUM);
        return \array_column($ids, 0);
    }

    function getCountShards(): int
    {
        $count = $this->ipdo->exec('SELECT COUNT(*) FROM shards', [], IPDO::FETCH_ONCE_NUM);
        return (int)$count[0];
    }

    /**
     * @return string[]
     */
    function getFreeShardIds(): array
    {
        $threshold = \time() - $this->kv->getInt('rent_timeout');

        $ids = $this->ipdo->exec(
            'SELECT id FROM shards WHERE active = 0 OR updated_at <= {threshold} ORDER BY updated_at ASC',
            ['threshold' => $threshold],
            IPDO::FETCH_ALL_NUM
        );
        return \array_column($ids, 0);
    }

    function existsShard(string $shardId): bool
    {
        return $this->ipdo->exists('SELECT * FROM shards WHERE id = {id}', ['id' => $shardId]);
    }

    /**
     * @throws ShardNotFoundException
     */
    function isFree(string $shardId): bool
    {
        $this->checkShard($shardId);

        $threshold = \time() - $this->kv->getInt('rent_timeout');
        return $this->ipdo->exists(
            'SELECT id FROM shards WHERE id = {id} AND (active = 0 OR updated_at <= {threshold})',
            [
                'id' => $shardId,
                'threshold' => $threshold,
            ],
        );
    }

    /**
     * @throws ShardNotFreeException
     */
    function rentById(string $shardId): self
    {
        if (!$this->isFree($shardId)) {
            throw new ShardNotFreeException($shardId);
        }

        $time = \time();
        $threshold = $time - $this->kv->getInt('rent_timeout');
        $record = $this->ipdo->execBusyRetry(
            'UPDATE shards SET active = 1, updated_at = {time} WHERE id IN (
                SELECT id FROM shards WHERE id = {id} AND (active = 0 OR updated_at <= {threshold})
            ) RETURNING id',
            [
                'id' => $shardId,
                'time' => $time,
                'threshold' => $threshold,
            ],
            IPDO::FETCH_ONCE_NUM
        );

        if ([] === $record) {
            throw new ShardNotFreeException($shardId);
        }

        return $this;
    }

    function tryRentById(string $shardId): bool
    {
        try {
            $this->rentById($shardId);
            return true;
        } catch (ShardNotFreeException) {
            return false;
        }
    }

    /**
     * @throws ShardNotFreeException
     */
    function rentFree(): string
    {
        $ids = $this->getFreeShardIds();
        if ([] !== $ids) {
            // \shuffle($ids);
            foreach ($ids as $id) {
                if ($this->tryRentById($id)) {
                    return $id;
                }
            }
        }

        throw new ShardNotFreeException('There are no free shards');
    }

    function tryRentFree(): ?string
    {
        try {
            return $this->rentFree();
        } catch (ShardNotFreeException) {
            return null;
        }
    }

    function getIPDOById(string $shardId): IPDOSQLite
    {
        $this->checkShard($shardId);
        $ptf = $this->getShardPtf($shardId);
        return \DI(IPDOSQLite::class, [$ptf]);
    }

    function release(string $shardId): self
    {
        $this->checkShard($shardId);
        $this->ipdo->execBusyRetry(
            'UPDATE shards SET active = 0 WHERE id IN (
                SELECT id FROM shards WHERE id = {id} AND active = 1
            )',
            ['id' => $shardId],
        );

        return $this;
    }

    /**
     * Сколько времени осталось до просрочки аренды
     * 
     * | -1 - не активный шард | 0 - время вышло |
     * @return int<-1,max>
     */
    function leftTimeUntilRelease(string $shardId): int
    {
        $this->checkShard($shardId);
        $record = $this->ipdo->exec(
            'SELECT updated_at FROM shards WHERE id = {id} AND active = 1',
            ['id' => $shardId],
            IPDO::FETCH_ONCE_NUM
        );

        $updated_at = $record[0] ?? null;
        if (null === $updated_at) {
            return -1;
        }
        $updated_at = (int)$updated_at;
        $threshold = \time() - $this->kv->getInt('rent_timeout');
        $time = $updated_at - $threshold;
        return $time < 0 ? 0 : $time;
    }

    /**
     * Принудительно продлеваем аренду
     */
    function heartbeat(string $shardId): self
    {
        $this->checkShard($shardId);
        $this->_heartbeat($shardId, $this->ipdo);
        return $this;
    }

    /**
     * более экономичная стратегия продления аренды
     */
    function heartbeat_m2(string $shardId): self
    {
        $left_time_sec = $this->leftTimeUntilRelease($shardId);

        if (-1 === $left_time_sec || 0 === $left_time_sec) {
            return $this;
        }

        if ($left_time_sec <= $this->minSecHeartbeat) {
            $this->_heartbeat($shardId, $this->ipdo);
        }

        return $this;
    }

    /**
     * @return \Generator<string,IPDOSQLite>
     */
    function getAllShardAsIPDO(): \Generator
    {
        foreach ($this->getAllShardAsPtf() as $shardId => $ptf) {
            yield $shardId => \DI(IPDOSQLite::class, [$ptf]);
        }
    }

    /**
     * @return \Generator<string,string>
     */
    function getAllShardAsPtf(): \Generator
    {
        foreach ($this->getAllShardIds() as $shardId) {
            yield $shardId => $this->getShardPtf($shardId);
        }
    }

    // STATIC

    static function discover(string $baseDir, string $name): ?Registry
    {
        $ptf = \DI(HelperService::class)->findRegistryFile($baseDir, $name);
        if (null === $ptf) {
            return null;
        }
        $ipdo = \DI(IPDOSQLite::class, [$ptf]);
        if (!$ipdo->existsTable('kv') || !$ipdo->existsTable('shards')) {
            $ipdo->close();
            // INFO Удаляем сломанный регистр
            FS::unlink($ptf);
            return null;
        }
        $kv = \DI(Kv::class, [$ipdo]);
        $reg = new self(ipdo: $ipdo, kv: $kv);

        // INFO Проверяем наличие минимальных шардов
        $curCount = $reg->getCountShards();
        $minShards = $kv->getInt('min_shards');

        if ($curCount >= $minShards) {
            return $reg;
        }

        $reqCount = $minShards - $curCount;
        $reg->createNewShards($reqCount);

        return $reg;
    }

    static function discoverFromCreateConfig(CreateConfig $config): ?Registry
    {
        $reg = self::discover($config->baseDir, $config->name);
        if (null === $reg) {
            return null;
        }

        $reg
            ->setRentTimeout($config->rentTimeout)
            ->setMinShards($config->minShards)
            // 
        ;
        return $reg;
    }

    /**
     * @throws RegistryNameExistsException
     */
    static function create(CreateConfig $config): Registry
    {
        $obj = self::discoverFromCreateConfig($config);
        if (null !== $obj) {
            throw new RegistryNameExistsException($obj->getName());
        }
        return self::_create($config);
    }

    static protected function _create(CreateConfig $config): Registry
    {
        $files = [];
        try {

            // INFO callback для очищения переменных, они не очищаются если выбросится исключение, тем самым файлы не удалятся
            [$ipdoReg, $kv] = (static function (CreateConfig $config, array &$files): array {
                $ptf = $config->ptfRegistry;
                File::put($ptf, '');
                $files[] = $ptf;
                $ipdoReg = \DI(IPDOSQLite::class, [$ptf]);
                foreach (
                    [
                        'CREATE TABLE kv (k TEXT PRIMARY KEY NOT NULL, v TEXT NOT NULL)',
                        'CREATE TABLE shards (id TEXT PRIMARY KEY NOT NULL, schema_version INTEGER NOT NULL, active INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)',
                    ] as $sql
                ) {
                    $ipdoReg->exec($sql);
                }

                $kv = \DI(Kv::class, [$ipdoReg]);
                foreach (
                    [
                        'name'         => $config->name,
                        'schema_sql'   => $config->schemaSql,
                        'created_at'   => \time(),
                        'rent_timeout' => $config->rentTimeout,
                        'min_shards'   => $config->minShards,
                    ] as $key => $value
                ) {
                    $kv->set($key, $value);
                }

                return [$ipdoReg, $kv];
            })($config, $files);
            $files = [];

            $registry = new self(ipdo: $ipdoReg, kv: $kv);
            $registry->createNewShards($config->minShards);

            return $registry;
        } catch (\Throwable $e) {
            if ([] !== $files) {
                FS::unlink_m2($files);
            }
            throw $e;
        }
    }

    static function createOrDiscover(CreateConfig $config): Registry
    {
        $reg = self::discoverFromCreateConfig($config);
        return null === $reg ? self::_create($config) : $reg;
    }

    // PROTECTED

    protected function _heartbeat(string $shardId, IPDOSQLite $ipdo): void
    {
        $ipdo->execBusyRetry(
            'UPDATE shards SET updated_at = {time} WHERE id IN (
                SELECT id FROM shards WHERE id = {id} AND active = 1
            )  AND active = 1',
            ['id' => $shardId, 'time' => \time()],
        );
    }

    /**
     * @throws ShardNotFoundException
     */
    protected function checkShard(string $shardId): void
    {
        if ('' === $shardId || !$this->existsShard($shardId)) {
            throw new ShardNotFoundException($shardId);
        }
    }

    protected function getShardPtf(string $shardId): string
    {
        return Path::normalize(\sprintf('%s/%s.sqlite', $this->baseDir, $shardId));
    }
}

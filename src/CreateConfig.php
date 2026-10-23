<?php

declare(strict_types=1);

namespace Inilim\SqliteShardWriter;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDOSQLite;
use Inilim\Tool\Assert;
use Inilim\Tool\Obj;
use Inilim\Tool\Path;

class CreateConfig
{
    public readonly string $baseDir;
    /**
     * @var string[]
     */
    public readonly array $schemaSql;
    protected(set) int $minShards;
    protected(set) int $rentTimeout;

    public readonly string $tableName;
    public readonly string $ptfRegistry;

    /**
     * @param string|string[] $schemaSql
     */
    function __construct(
        string $baseDir,
        string|array $schemaSql,
        ?string $tableName = null,
        int $minShards = 5,
        int $rentTimeout = 120,
    ) {
        $this->setRentTimeout($rentTimeout);
        $this->setMinShards($minShards);
        $_baseDir = Path::realPath($baseDir);
        if (null === $_baseDir) {
            throw Obj::sprintfInvalidArgumentException('Not found dir "%s"', [$baseDir]);
        }
        Assert::directory($baseDir);
        $this->baseDir = Path::normalize($_baseDir);
        $this->schemaSql = \is_string($schemaSql) ? [$schemaSql] : $schemaSql;
        $ipdoMem = \DI(IPDOSQLite::class, [':memory:']);
        foreach ($this->schemaSql as $sql) {
            try {
                $ipdoMem->exec($sql);
            } catch (IPDOException $e) {
                $ipdoMem->close();
                throw Obj::sprintfInvalidArgumentException(
                    '"%s" [%s...]',
                    [
                        $e->getMessage(),
                        \substr((string)\json_encode($sql), 1, 15)
                    ]
                );
            }
        }

        if (null === $tableName) {
            $names = \array_column($ipdoMem->master('table'), 'tbl_name');
            /** @var string[] $names */
            // INFO Если schemaSql создаёт несколько таблиц, будет взята первая таблица что выдаст метод master().
            foreach ($names as $name) {
                if (false === \in_array($name, ['sqlite_sequence'], true)) {
                    $tableName = $name;
                    break;
                }
            }
            unset($names);
        }

        if (null === $tableName || !$ipdoMem->existsTable($tableName)) {
            $ipdoMem->close();
            throw Obj::sprintfInvalidArgumentException('SQL not created table');
        }

        $ipdoMem->close();
        unset($ipdoMem);

        $this->tableName = $tableName;
        $this->ptfRegistry = Path::normalize(\sprintf('%s/registry-%s.sqlite', $this->baseDir, \sha1($tableName, false)));
    }

    function setMinShards(int $minShards): self
    {
        Assert::positiveInteger($minShards);
        $this->minShards = $minShards;
        return $this;
    }

    function setRentTimeout(int $rentTimeout): self
    {
        Assert::positiveInteger($rentTimeout);
        $this->rentTimeout = $rentTimeout;
        return $this;
    }

    // static function fromIPDO(IPDOSQLite $ipdo): self
    // {
    //     $ptf = $ipdo->getMainFile();
    //     if ('' === $ptf) {
    //         throw Obj::sprintfInvalidArgumentException('[IPDOSQLite $ipdo] connection must be to an SQLite file.');
    //     }
    //     $baseDir = \dirname($ptf);

    //     // Получаем все нужные ключи из kv
    //     $kv = \DI(Kv::class, [$ipdo]);
    //     $data = $kv->getItems(['schema_sql', 'min_shards', 'rent_timeout', 'table_name']);

    //     return new self(
    //         baseDir: $baseDir,
    //         schemaSql: $data['schema_sql'],
    //         tableName: $data['table_name'],
    //         minShards: $data['min_shards'],
    //         rentTimeout: $data['rent_timeout'],
    //     );
    // }
}

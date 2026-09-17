<?php

declare(strict_types=1);

namespace Inilim\SqliteShardWriter;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDOSQLite;
use Inilim\Tool\Assert;
use Inilim\Tool\LarStr;
use Inilim\Tool\Obj;
use Inilim\Tool\Path;
use Inilim\Tool\VD;

class SystemConfig
{
    public readonly string $baseDir;
    public readonly string $schemaSql;
    public readonly int $minShards;
    protected(set) int $rentTimeout;

    public readonly string $tableName;
    public readonly string $ptfRegistry;
    /**
     * @var (array{cid:int,name:string,type:string,notnull:int,dflt_value:int|string|float|null,pk:int})[]
     */
    public array $cols;

    function __construct(
        string $baseDir,
        string $schemaSql,
        int $minShards = 5,
        int $rentTimeout = 120,
    ) {
        Assert::positiveInteger($minShards);
        $this->setRentTimeout($rentTimeout);
        $_baseDir = Path::realPath($baseDir);
        if (null === $_baseDir) {
            throw Obj::sprintfInvalidArgumentException('Not found dir "%s"', [$baseDir]);
        }
        Assert::directory($baseDir);
        $this->minShards = $minShards;
        $this->baseDir = Path::normalize($_baseDir);
        $this->schemaSql = LarStr::trim($schemaSql);
        $ipdo = \DI(IPDOSQLite::class, [':memory:']);
        try {
            $ipdo->exec($this->schemaSql);
        } catch (IPDOException $e) {
            throw Obj::sprintfInvalidArgumentException(
                'Syntax SQL invalid "%s" [%s...]',
                [
                    $e->getMessage(),
                    \substr((string)\json_encode($this->schemaSql), 1, 15)
                ]
            );
        }

        $table = \array_column($ipdo->master('table'), 'tbl_name');
        // TODO Если schemaSql создаёт несколько таблиц, будет взята первая произвольная. Нужно либо запрещать больше одной таблицы, либо явно передавать имя таблицы в конфиг.
        foreach ($table as $tbl) {
            if (false === \in_array($tbl, ['sqlite_sequence'], true)) {
                $table = $tbl;
                break;
            }
        }

        if (!\is_string($table)) {
            throw Obj::sprintfInvalidArgumentException('SQL not created table');
        }

        $this->cols = $ipdo->tableInfo($table);
        $ipdo->close();
        unset($ipdo);

        $this->tableName = $table;
        $this->ptfRegistry = Path::normalize(\sprintf('%s/registry-%s.sqlite', $this->baseDir, \md5($table)));
    }

    function setRentTimeout(int $rentTimeout): self
    {
        Assert::positiveInteger($rentTimeout);
        $this->rentTimeout = $rentTimeout;
        return $this;
    }

    static function fromIPDO(IPDOSQLite $ipdo): self
    {
        $ptf = $ipdo->getMainFile();
        if ('' === $ptf) {
            throw Obj::sprintfInvalidArgumentException('IPDOSQLite $ipdo');
        }
        $baseDir = \dirname($ptf);

        // Получаем все нужные ключи из kv
        $kv = \DI(Kv::class, [$ipdo]);
        $data = $kv->getItems(['schema_sql', 'min_shards', 'rent_timeout']);

        return new self(
            baseDir: $baseDir,
            schemaSql: $data['schema_sql'],
            minShards: $data['min_shards'],
            rentTimeout: $data['rent_timeout'],
        );
    }
}

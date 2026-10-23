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

    public readonly string $name;
    public readonly string $ptfRegistry;

    /**
     * @param string|string[] $schemaSql
     */
    function __construct(
        string $baseDir,
        string|array $schemaSql,
        string $name,
        int $minShards = 5,
        int $rentTimeout = 30,
    ) {
        Assert::stringNotEmpty($name);
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

        $ipdoMem->close();
        unset($ipdoMem);

        $this->name = $name;
        $this->ptfRegistry = Path::normalize(\sprintf('%s/registry-%s.sqlite', $this->baseDir, \sha1($name, false)));
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
}

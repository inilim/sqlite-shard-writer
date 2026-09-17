<?php

declare(strict_types=1);

namespace Inilim\SqliteShardWriter;

use Inilim\IPDO\IPDOSQLite;
use Inilim\Tool\Assert;
use Inilim\Tool\Path;
use Symfony\Component\Finder\Finder;

final class HelperService
{
    /**
     * @return \Generator<int,array{table_name:string,ptf:string,ipdo:IPDOSQLite,kv:Kv}>
     */
    function registryIterator(string $dir): \Generator
    {
        Assert::directory($dir);
        // registry-098f6bcd4621d373cade4e832627b4f6.sqlite
        foreach (\DI(Finder::class)->in($dir)->files()->name('registry-*.sqlite') as $spl) {
            $ptf = Path::normalize((string)$spl->getRealPath());
            $ipdo = \DI(IPDOSQLite::class, [$ptf]);
            if ([] === $ipdo->tableInfo('kv')) {
                continue;
            }
            $kv = \DI(Kv::class, [$ipdo]);
            yield [
                'table_name' => $kv->get('table_name'),
                'ptf' => $ptf,
                'ipdo' => $ipdo,
                'kv' => $kv,
            ];
        }
    }
}

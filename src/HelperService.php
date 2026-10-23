<?php

declare(strict_types=1);

namespace Inilim\SqliteShardWriter;

use Inilim\IPDO\IPDOSQLite;
use Inilim\Tool\Assert;
use Inilim\Tool\FS;
use Inilim\Tool\Path;
use Inilim\Tool\VD;
use Symfony\Component\Finder\Finder;

final class HelperService
{
    /**
     * @return \Generator<int,array{ptf:string,ipdo:IPDOSQLite}>
     */
    function registryIterator(string $baseDir): \Generator
    {
        Assert::directory($baseDir);

        foreach (\DI(Finder::class)->in($baseDir)->files()->name('registry-*.sqlite') as $spl) {
            $ptf = Path::normalize((string)$spl->getRealPath());
            $ipdo = \DI(IPDOSQLite::class, [$ptf]);
            yield [
                'ptf' => $ptf,
                'ipdo' => $ipdo,
            ];
        }
    }

    function findRegistryFile(string $baseDir, string $name): ?string
    {
        Assert::directory($baseDir);
        Assert::stringNotEmpty($name);

        $ptf = \sprintf('%s/registry-%s.sqlite', $baseDir, \sha1($name, false));
        $ptf = Path::normalize($ptf);
        // VD::de($ptf);
        return FS::isFile($ptf) ? $ptf : null;
    }

    // TODO добавить метод который будет проверять наличие шардов файлов и записями в регитри
}

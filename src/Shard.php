<?php

declare(strict_types=1);

namespace Inilim\SqliteShard;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\Registry;

class Shard
{
    protected(set) ?string $ownerToken = null;

    function __construct(
        public readonly string $id,
        public readonly Registry $reg,
    ) {}

    // rent
    // tryRent
    // existsShard

    function isFree(): bool
    {
        return $this->reg->isFree($this);
    }

    function getIPDO(): IPDOSQLite
    {
        return $this->reg->getIPDOByShard($this);
    }

    function leftTimeUntilRelease(): int
    {
        return $this->reg->leftTimeUntilRelease($this);
    }

    function release(): self
    {
        $this->reg->release($this);
        return $this;
    }

    /**
     * @throws \Inilim\SqliteShard\ShardRentLostException
     */
    function heartbeat(): self
    {
        $this->reg->heartbeat($this);
        return $this;
    }

    /**
     * @throws \Inilim\SqliteShard\ShardRentLostException
     */
    function heartbeat_m2(): self
    {
        $this->reg->heartbeat_m2($this);
        return $this;
    }

    function rentValid(): bool
    {
        return $this->reg->rentValid($this);
    }
}

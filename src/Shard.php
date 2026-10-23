<?php

declare(strict_types=1);

namespace Inilim\SqliteShard;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\Registry;
use Inilim\Tool\ID;

readonly class Shard
{
    public string $ownerToken;

    function __construct(
        public string $id,
        public Registry $reg,
    ) {
        $this->ownerToken = ID::uuidv7();
    }

    // rent
    // tryRent
    // isFree
    // existsShard

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
        return $this->reg->isMyRentValid($this);
    }
}

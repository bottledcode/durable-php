<?php

namespace Bottledcode\DurablePhp\Activity\Command;

use Bottledcode\DurablePhp\Infrastructure\PartitionId;
use Ramsey\Uuid\UuidInterface;

readonly class SendActivityResult
{
    public function __construct(
        public string $result,
        public PartitionId $senderPartitionId,
        public UuidInterface $senderId,
        public bool $isError = false,
    ) {
    }
}

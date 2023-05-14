<?php

namespace Bottledcode\DurablePhp\Activity\Command;

use Bottledcode\DurablePhp\Infrastructure\PartitionId;
use Crell\Serde\Attributes\DateField;
use Crell\Serde\Attributes\Field;
use Ramsey\Uuid\UuidInterface;

readonly class ScheduleActivity
{
    public function __construct(
        #[Field]
        public PartitionId $partitionId,

        #[Field]
        public UuidInterface $id,

        #[Field]
        public string $name,

        #[Field]
        public array $input,

        #[DateField]
        public \DateTimeInterface|null $scheduledTime = null,
    ) {
    }
}

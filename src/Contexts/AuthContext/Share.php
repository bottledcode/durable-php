<?php

namespace Bottledcode\DurablePhp\Contexts\AuthContext;

use Bottledcode\DurablePhp\Contexts\AuthContext\Share\Owner;
use Bottledcode\DurablePhp\Contexts\AuthContext\Share\Role;
use Bottledcode\DurablePhp\Contexts\AuthContext\Share\User;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Crell\Serde\Attributes\SequenceField;
use Crell\Serde\Attributes\StaticTypeMap;
use Withinboredom\Record;

#[StaticTypeMap(key: 'shareType', map: [
    'owner' => Owner::class,
    'role' => Role::class,
    'user' => User::class,
])]
abstract readonly class Share extends Record
{
    public string $subject;

    /**
     * @var array<Operation>
     */
    #[SequenceField(arrayType: Operation::class)]
    public array $allowed;
}

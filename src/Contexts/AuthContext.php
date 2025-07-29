<?php

namespace Bottledcode\DurablePhp\Contexts;

use Bottledcode\DurablePhp\Contexts\AuthContext\Share;
use Bottledcode\DurablePhp\Contexts\AuthContext\Share\Owner;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\Serializer;
use Crell\Serde\Attributes\SequenceField;
use Withinboredom\Record;

readonly class AuthContext extends Record
{
    public StateId $contextId;

    /**
     * @var array<Owner>
     */
    #[SequenceField(arrayType: Owner::class)]
    public array $owners;

    /**
     * @var array<Share>
     */
    #[SequenceField(arrayType: Share::class)]
    public array $shares;

    public static function fromCurrentContext(): ?AuthContext
    {
        if (isset($_SERVER['HTTP_DPHP_AUTH_CONTEXT'])) {
            $json = json_decode($_SERVER['HTTP_DPHP_AUTH_CONTEXT'], true, flags: JSON_THROW_ON_ERROR);

            return Serializer::deserialize($json, self::class);
        }

        return null;
    }
}

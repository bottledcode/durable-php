<?php

namespace Bottledcode\DurablePhp\Serialization;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class DataMember
{
	public function __construct(public string|null $name = null)
	{
	}
}

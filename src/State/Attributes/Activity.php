<?php

namespace Bottledcode\DurablePhp\State\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_FUNCTION)]
class Activity extends Tag {}

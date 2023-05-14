<?php

namespace Bottledcode\DurablePhp\Activity;

enum ReturnType: string
{
    case Void = 'Void';
    case Wait = 'Wait';
    case Generator = 'Generator';
}

<?php

namespace Bottledcode\DurablePhp\Events;

abstract class Event {
    public bool $isReplaying = false;
}

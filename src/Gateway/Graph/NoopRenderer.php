<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

class NoopRenderer extends ScalarRenderer
{
    public function renderType(TypeManager $typeManager): string
    {
        return '';
    }
}

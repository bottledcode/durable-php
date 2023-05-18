<?php

namespace Bottledcode\DurablePhp\Transmutation;

use Bottledcode\DurablePhp\Logger;

trait Router
{
    public function transmutate(object $object, object $to): array
    {
        $objectClass = basename(str_replace('\\', '/', get_class($object)));
        $toClass = get_class($to);

        Logger::log('Calling %s::apply%s', $toClass, $objectClass);

        if (method_exists($toClass, 'apply' . $objectClass)) {
            return $to->{'apply' . $objectClass}($object);
        }

        return [];
    }
}

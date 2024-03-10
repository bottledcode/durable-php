<?php

use Psr\Container\ContainerInterface;

return (static function (): ContainerInterface {
    $builder = new \DI\ContainerBuilder();
    $builder->addDefinitions([
        // definitions go here
    ]);

    return $builder->build();
});

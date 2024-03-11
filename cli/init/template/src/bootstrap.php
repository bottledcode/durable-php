<?php

use Psr\Container\ContainerInterface;
use {{.Name}}\Entities\CountInterface;

return (static function (): ContainerInterface {
    $builder = new \DI\ContainerBuilder();
    $builder->addDefinitions([
        CountInterface::class => \DI\autowire(\{{.Name}}\Entities\CountState::class)
    ]);

    return $builder->build();
})();

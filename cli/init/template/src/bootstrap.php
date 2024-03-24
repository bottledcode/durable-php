<?php

use Psr\Container\ContainerInterface;
use {{.Name}}\Entities\CountInterface;

return (static function (): ContainerInterface {
    return [
        CountInterface::class => \DI\autowire(\{{.Name}}\Entities\CountState::class)
    ];
})();

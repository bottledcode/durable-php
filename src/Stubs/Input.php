<?php

namespace parallel\Events {

    use parallel\Events\Input\Error\Existence;
    use parallel\Events\Input\Error\IllegalValue;

    final class Input
    {
        /**
         * @param string $target
         * @param mixed $value
         * @return void
         * @throws Existence
         * @throws IllegalValue
         */
        public function add(string $target, mixed $value): void {}

        public function clear(): void {}

        /**
         * @param string $target
         * @return void
         * @throws Existence
         */
        public function remove(string $target): void {}
    }
}

namespace parallel\Events\Input\Error {

    use Exception;

    class Existence extends Exception {}

    class IllegalValue extends Exception {}
}

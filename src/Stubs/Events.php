<?php

namespace parallel {

    use Countable;
    use Iterator;
    use parallel\Events\Error;
    use parallel\Events\Event;
    use parallel\Events\Input;

    final class Events implements Countable, Iterator
    {
        public function count(): int
        {
            // TODO: Implement count() method.
        }

        public function setInput(Input $input): void {}

        /**
         * @param Channel $channel
         * @return void
         * @throws Error\Existence
         */
        public function addChannel(Channel $channel): void {}

        /**
         * @param string $name
         * @param Future $future
         * @return void
         * @throws Error\Existence
         */
        public function addFuture(string $name, Future $future): void {}

        /**
         * @param string $target
         * @return void
         * @throws Error\Existence
         */
        public function remove(string $target): void {}

        /**
         * @param bool $blocking
         * @return void
         * @throws Error
         */
        public function setBlocking(bool $blocking): void {}

        /**
         * @param int $timeout
         * @return void
         * @throws Error
         */
        public function setTimeout(int $timeout): void {}

        /**
         * @return Event|null
         * @throws Error\Timeout
         */
        public function poll(): Event|null {}

        public function current(): mixed
        {
            // TODO: Implement current() method.
        }

        public function next(): void
        {
            // TODO: Implement next() method.
        }

        public function key(): mixed
        {
            // TODO: Implement key() method.
        }

        public function valid(): bool
        {
            // TODO: Implement valid() method.
        }

        public function rewind(): void
        {
            // TODO: Implement rewind() method.
        }
    }
}

namespace parallel\Events {

    use Exception;
    use parallel\Channel;
    use parallel\Future;

    final class Event
    {
        public int $type;
        public string $source;
        public Future|Channel $object;

        public mixed $value;
    }

    class Error extends Exception {}
}

namespace parallel\Events\Error {

    use Exception;

    class Existence extends Exception {}

    class Timeout extends Exception {}
}

namespace parallel\Events\Event {

    final class Type
    {
        public const Read = 1;
        public const Write = 2;
        public const Close = 3;
        public const Cancel = 4;
        public const Kill = 5;
        public const Error = 6;
    }
}

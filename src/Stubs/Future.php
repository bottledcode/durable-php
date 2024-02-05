<?php

namespace parallel {

    use parallel\Future\Error;
    use parallel\Future\Error\Cancelled;
    use parallel\Future\Error\Foreign;
    use parallel\Future\Error\Killed;
    use Throwable;

    return;

    final class Future
    {
        /**
         * Shall return (and if necessary wait for) return from task
         *
         * @return mixed
         * @throws Throwable
         * @throws Foreign
         * @throws Killed
         * @throws Cancelled
         * @throws Error
         */
        public function value(): mixed {}

        /**
         * Shall indicate if the task was cancelled
         * @return bool
         */
        public function cancelled(): bool {}

        /**
         * Shall indicate if the task is completed
         * @return bool
         */
        public function done(): bool {}

        /**
         * Shall try to cancel the task
         *
         * @return bool
         * @throws Killed
         * @throws Cancelled
         */
        public function cancel(): bool {}
    }
}

namespace parallel\Future {

    class Error extends \Error {}
}

namespace parallel\Future\Error {

    use parallel\Future\Error;

    class Killed extends Error {}

    class Cancelled extends Error {}

    class Foreign extends Error {}
}

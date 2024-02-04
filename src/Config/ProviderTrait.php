<?php
/*
 * Copyright ©2024 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the “Software”), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Bottledcode\DurablePhp\Config;

use Bottledcode\DurablePhp\Abstractions\ProjectorInterface;
use Bottledcode\DurablePhp\Abstractions\Semaphore;

trait ProviderTrait
{
    private function configureProviders(array $projectors): void
    {
        /** @var ProjectorInterface|null $previous */
        $previous = null;

        foreach ($projectors as $p) {
            if (!class_exists($p)) {
                throw new \RuntimeException("Unable to locate class $p for projecting");
            }

            $projectorClass = new $p();
            $found = false;
            if ($projectorClass instanceof ProjectorInterface) {
                $projectorClass->connect();
                $previous?->chain($projectorClass);
                $previous = $projectorClass;
                $this->projector ??= $projectorClass;
                $found = true;
            }

            if ($projectorClass instanceof Semaphore) {
                $projectorClass->connect();
                $this->semaphore ??= $projectorClass;
                $found = true;
            }

            if (!$found) {
                throw new \RuntimeException("$p does not implement Semaphore or Projector interface");
            }
        }
    }
}

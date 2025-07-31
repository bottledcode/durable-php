<?php

/*
 * Copyright ©2023 Robert Landers
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

namespace Bottledcode\DurablePhp;

use Amp\DeferredFuture;
use Bottledcode\DurablePhp\State\Serializer;
use LogicException;

/**
 * @template T
 */
class DurableFuture
{
    /**
     * @param  DeferredFuture<T>  $future
     * @param  class-string<T>|null  $resultType
     */
    public function __construct(public readonly DeferredFuture $future, public readonly ?string $resultType = null) {}

    /**
     * @return T
     */
    public function getResult(): mixed
    {
        if ($this->future->isComplete()) {
            if ($this->resultType === null) {
                return $this->future->getFuture()->await();
            }

            return Serializer::deserialize($this->future->getFuture()->await(), $this->resultType);
        }

        throw new LogicException('Future is not complete');
    }

    public function hasResult(): bool
    {
        return $this->future->isComplete();
    }
}

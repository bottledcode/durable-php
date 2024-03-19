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

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\Events\Shares\NeedsTarget;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Crell\Serde\Attributes\SequenceField;
use Ramsey\Uuid\Uuid;

#[NeedsTarget(Operation::SharePlus)]
class ShareWithRole extends Event
{
    private function __construct(public string $role, #[SequenceField(Operation::class)] public array $allowedOperations)
    {
        parent::__construct(Uuid::uuid7());
    }

    public static function For(string $role, Operation ...$allowedOperations): self
    {
        return new self($role, $allowedOperations);
    }

    public function __toString()
    {
        return sprintf("Share(role: %s, %s)", $this->role, implode(', ', $this->allowedOperations));
    }
}

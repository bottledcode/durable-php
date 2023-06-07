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

/**
 * This is a serializable monotonic clock implementation for use in determining if we've
 * already handled an event. Simply put, it's a clock that only goes forward.
 *
 * There are some key assumptions made here.
 * 1. If the partition is restarted, the realworld clock has moved forward.
 * 2. Only one worker is running per partition.
 */
class MonotonicClock
{
    private static MonotonicClock $instance;
    private int $secondsOffset;
    private int $μOffset;
    public function __construct()
    {
        $offset = hrtime();
        $time = explode(' ', microtime(), 2);
        $this->secondsOffset = $time[1] - $offset[0];
        $this->μOffset = (int)($time[0] * 1000000) - (int)($offset[1] / 1000);
    }

    public static function current(): self
    {
        self::$instance ??= new self();
        return self::$instance;
    }

    public function __serialize(): array
    {
        return [
            'now' => $this->now()->format('u U'),
        ];
    }

    public function now(): \DateTimeImmutable
    {
        [$s, $μs] = hrtime();
        if (1000000 <= $μs = (int)($μs / 1000) + $this->μOffset) {
            ++$s;
            $μs -= 1000000;
        } elseif (0 > $μs) {
            --$s;
            $μs += 1000000;
        }

        if (6 !== \strlen($now = (string)$μs)) {
            $now = str_pad($now, 6, '0', \STR_PAD_LEFT);
        }

        $now = '@' . ($s + $this->secondsOffset) . '.' . $now;
        return new \DateTimeImmutable($now);
    }

    public function __unserialize(array $data): void
    {
        self::$instance ??= $this;
        $offset = hrtime();
        $time = explode(' ', $data['now'], 2);
        $this->secondsOffset = $time[1] - $offset[0];
        $this->μOffset = (int)($time[0]) - (int)($offset[1] / 1000);
    }
}

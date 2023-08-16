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

use Bottledcode\DurablePhp\Proxy\ClientProxy;
use Bottledcode\DurablePhp\Proxy\Pure;

if (!interface_exists(orchProxy::class)) {
    interface orchProxy
    {
        public function callExample(): string;

        public function signalExample(int $a): void;

        #[Pure]
        public function pureExample(int|float $number): string;
    }
}

it('generates a proxy correctly', function () {
    $generator = new ClientProxy();
    $proxy = $generator->generate(orchProxy::class);
    expect($proxy)->toBe(
        <<<'EOT'


class __ClientProxy_orchProxy implements orchProxy {
  public function __construct(private mixed $source) {}
  public function callExample(): string {
    throw new Bottledcode\DurablePhp\Proxy\ImpureException();
}
public function signalExample(int $a): void {
    throw new Bottledcode\DurablePhp\Proxy\ImpureException();
}
public function pureExample(int|float $number): string {
    return $this->source->pureExample(...func_get_args());
}
}
EOT
    );
});

it('is actually callable', function () {
    $generator = new ClientProxy();
    $proxy = $generator->generate(orchProxy::class);
    eval($proxy);
    $instance = new class {
        public function pureExample(int|float $number): string
        {
            return "Hello $number";
        }
    };
    $proxy = new __ClientProxy_orchProxy($instance);
    expect($proxy->pureExample(1))->toBe('Hello 1')
        ->and(fn() => $proxy->signalExample(1))->toThrow(\Bottledcode\DurablePhp\Proxy\ImpureException::class)
        ->and(fn() => $proxy->callExample())->toThrow(\Bottledcode\DurablePhp\Proxy\ImpureException::class);
});

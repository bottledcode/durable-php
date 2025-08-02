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

use Amp\DeferredFuture;
use Bottledcode\DurablePhp\DurableFuture;
use Bottledcode\DurablePhp\Proxy\OrchestratorProxy;
use Bottledcode\DurablePhp\Proxy\Pure;

use function Bottledcode\DurablePhp\EntityId;

if (!interface_exists(orchProxy::class)) {
    interface orchProxy
    {
        public string $prop {
            get;
            set;
        }

        public function callExample(): string;

        public function signalExample(int $a): void;

        #[Pure]
        public function pureExample(float|int $number): string;
    }
}

it('generates a proxy correctly', function (): void {
    $generator = new OrchestratorProxy();
    $proxy = $generator->generate(orchProxy::class);
    expect($proxy)->toMatchSnapshot();
});

it('actually works', function (): void {
    $generator = new OrchestratorProxy();
    eval($generator->generate(orchProxy::class));
    $context = Mockery::mock(Bottledcode\DurablePhp\OrchestrationContextInterface::class);
    $context->shouldReceive('waitOne')->andReturn('waited');
    $context->shouldReceive('callEntity')->andReturn(
        new DurableFuture(new DeferredFuture()),
    );
    $context->shouldReceive('signalEntity')->andReturn('signal');
    $proxy = new __OrchestratorProxy_orchProxy($context, EntityId('test', 'test'));

    expect($proxy->callExample())
        ->toBe('waited')->and($proxy->pureExample(1))->toBe('waited')->and($proxy->signalExample(1))->toBe(null);
});

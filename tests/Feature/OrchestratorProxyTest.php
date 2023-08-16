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

use Bottledcode\DurablePhp\Proxy\OrchestratorProxy;
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
    $generator = new OrchestratorProxy();
    $proxy = $generator->generate(orchProxy::class);
    expect($proxy)->toBe(
        <<<'EOT'


class __OrchestratorProxy_orchProxy implements orchProxy {
  public function __construct(private Bottledcode\DurablePhp\OrchestrationContextInterface $context, private Bottledcode\DurablePhp\State\EntityId $id) {}
  public function callExample(): string {
    return $this->context->waitOne($this->context->callEntity($this->id, __METHOD__, func_get_args()));
}
public function signalExample(int $a): void {
    $this->context->signalEntity($this->id, __METHOD__, func_get_args());
}
public function pureExample(int|float $number): string {
    return $this->context->waitOne($this->context->callEntity($this->id, __METHOD__, func_get_args()));
}
}
EOT
    );
});

it('actually works', function () {
    $generator = new OrchestratorProxy();
    eval($generator->generate(orchProxy::class));
    $context = Mockery::mock(Bottledcode\DurablePhp\OrchestrationContextInterface::class);
    $context->shouldReceive('waitOne')->andReturn('waited');
    $context->shouldReceive('callEntity')->andReturn(
        new \Bottledcode\DurablePhp\DurableFuture(new \Amp\DeferredFuture())
    );
    $context->shouldReceive('signalEntity')->andReturn('signal');
    $proxy = new __OrchestratorProxy_orchProxy($context, new \Bottledcode\DurablePhp\State\EntityId('test', 'test'));

    expect($proxy->callExample())->toBe('waited')
        ->and($proxy->pureExample(1))->toBe('waited')
        ->and($proxy->signalExample(1))->toBe(null);
});

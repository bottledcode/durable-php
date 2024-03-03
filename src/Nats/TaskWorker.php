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

namespace Bottledcode\DurablePhp\Nats;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Basis\Nats\AmpClient;
use Bottledcode\DurablePhp\DurableLogger;
use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\TargetType;
use Bottledcode\DurablePhp\State\ApplyStateInterface;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Transmutation\Router;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class TaskWorker implements Task
{
    use Router;

    private LoggerInterface $logger;

    private ContainerInterface $container;

    private AmpClient $client;

    public function __construct(private EventDescription $eventDescription, private string $bootstrap) {}

    #[\Override]
    public function run(Channel $channel, Cancellation $cancellation): array
    {
        gc_enable();
        gc_collect_cycles();
        $this->logger = new DurableLogger();
        $this->logger->info('Running worker', ['event' => $this->eventDescription]);

        $this->container = include $this->bootstrap;

        $this->client = new AmpClient(new EnvConfiguration());
        $this->client->background(true, 50);

        if ($this->eventDescription->targetType === TargetType::Entity) {
            $state = $this->getState($this->eventDescription->destination);
        }
    }

    public function getState(StateId $target): ApplyStateInterface&StateInterface
    {
        $bucket = $this->client->getApi()->getBucket($target->id);
        $state = $bucket->get('state');
        $state ??= new ($target->getStateType())($target, $this->logger);
        $state->setContainer($this->container);

        return $state;
    }
}

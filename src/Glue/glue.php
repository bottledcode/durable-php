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

namespace Bottledcode\DurablePhp\Glue;

use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\State\Ids\StateId;

require_once __DIR__ . '/autoload.php';

function process(): void
{
    $bootstrap = $_SERVER['HTTP_DPHP_BOOTSTRAP'] ?: null;
    $function = $_SERVER['HTTP_DPHP_FUNCTION'];
    $payload = stream_get_contents($payload_handle = fopen($_SERVER['HTTP_DPHP_PAYLOAD'], 'rb'));
    $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

    register_shutdown_function(static fn() => fclose($payload_handle));

    $input = file_get_contents('php://input');
    $input = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

    $function = __NAMESPACE__ . '\\' . $function;

    $function($bootstrap, $payload_handle, $payload, ...$input);
}

function outputEvent(EventDescription $event): void
{
    echo "EVENT~!~" . $event->toStream();
}

function entitySignal(string $bootstrap, $payload_handle, array $payload, ...$input): void
{
    $id = new StateId($_SERVER['STATE_ID']);

    var_dump($payload);

    $event = WithEntity::forInstance($id, RaiseEvent::forOperation($payload['signal'], $payload['input']));
    $description = new EventDescription($event);
    outputEvent($description);
}

process();
exit();

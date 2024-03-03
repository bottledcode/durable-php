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

namespace Bottledcode\DurablePhp;

// we are in the context of this project (testing/developing)
use Basis\Nats\AmpClient;
use Basis\Nats\Configuration;
use Bottledcode\DurablePhp\Events\EventDescription;

if (file_exists(__DIR__ . "/../vendor/autoload.php")) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// we are in the context of a project
if (file_exists(__DIR__ . "/../../../vendor/autoload.php")) {
    require_once __DIR__ . "/../../../vendor/autoload.php";
}

// configure a nats client
$nats = new AmpClient(new Configuration([
    'host' => explode(':', getenv('NATS_SERVER'))[0],
    'port' => explode(':', getenv('NATS_SERVER'))[1],
]));
$nats->connect();
$nats->background(false);

// determine what context we are loading
$route = array_values(array_filter(explode('/', $_SERVER['REQUEST_URI'])));

switch($route[0] ?? null) {
    case 'event':
        $subject = $route[1] ?? null;
        if($subject === null) {
            echo "ERROR: subject must be set\n";
            http_response_code(404);
            die();
        }
        [$stream, $type, $destination] = explode('.', $subject);
        if($stream !== getenv('NATS_STREAM')) {
            echo "ERROR: NATS_STREAM must match event stream\n";
            http_response_code(404);
            die();
        }
        try {
            $event = EventDescription::fromJson(file_get_contents("php://input"));
        } catch(\JsonException $e) {
            echo "ERROR: json decoding error: {$e->getMessage()}\n";
            http_response_code(400);
            die();
        }

        switch($type) {
            case 'activities':
                echo "ERROR: activities not implemented yet\n";
                http_response_code(500);
                die();
            case 'orchestrations':
                echo "ERROR: orchestrations not implemented yet\n";
                http_response_code(500);
                die();
            case 'entities':
                echo "ERROR: entities not implemented yet\n";
                http_response_code(500);
                die();
            default:
                echo "ERROR: unknown route type \"{$type}\"\n";
                http_response_code(404);
                die();
        }
    case 'activity':
    case 'entity':
    case 'orchestration':
        break;
}

http_response_code(404);

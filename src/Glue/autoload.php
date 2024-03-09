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

// fast check for development
use Bottledcode\DurablePhp\DurableLogger;
use Monolog\Level;

if(file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    goto verify_protocol;
}

// fast check for standard installs
if(file_exists(__DIR__ . '/../../../../autoload.php')) {
    require_once __DIR__ . '/../../../../autoload.php';
    goto verify_protocol;
}

echo "ERROR: FAILED TO LOCATE AUTOLOADER\n";
return;

verify_protocol:

$logger = new DurableLogger(level: match (getenv('LOG_LEVEL') ?: 'INFO') {
    'DEBUG' => Level::Debug,
    'INFO' => Level::Info,
    'ERROR' => Level::Error,
});

if(($_SERVER['SERVER_PROTOCOL'] ?? null) !== 'DPHP/1.0') {
    http_response_code(400);
    $logger->critical("Invalid request protocol", [$_SERVER['SERVER_PROTOCOL'] ?? null]);
    die();
}

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

namespace Bottledcode\DurablePhp\Gateway\Graph;

use DI\Definition\Helper\AutowireDefinitionHelper;
use DI\Definition\Helper\CreateDefinitionHelper;

class SchemaGenerator
{
    private string $bootstrap;
    private string $root;

    public function __construct()
    {
        $this->bootstrap = $_SERVER['HTTP_DPHP_BOOTSTRAP'] ?: null;
    }

    public function generateSchema(): string
    {
        $projectRoot = $this->findComposerJson(__DIR__ . '/../../../..');
        $this->root = $projectRoot;

        return $this->findPhpFiles($projectRoot);
    }

    public function findComposerJson(string $startDirectory): ?string
    {
        $dir = realpath($startDirectory);

        while ($dir !== '/' && $dir !== null) {
            $path = $dir . '/composer.json';

            if (file_exists($path)) {
                return dirname($path);
            }

            $dir = dirname($dir);
        }

        return null;
    }

    public function findPhpFiles($dir): string
    {
        $schema = '';

        $items = glob($dir . '/*');

        foreach ($items as $item) {
            if (is_dir($item) && ! str_ends_with($item, 'vendor')) {
                $schema .= $this->findPhpFiles($item);
            } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
                $schema .= $this->processPhpFile($item);
            }
        }

        return $schema;
    }

    public function processPhpFile(string $file): string
    {
        $content = file_get_contents($file);

        if (str_contains($content, 'OrchestrationContext')) {
            return $this->defineOrchestration($file, $content);
        }

        if (str_contains($content, 'EntityState')) {
            return $this->defineEntity($file, $content);
        }

        return '';
    }

    public function defineOrchestration(string $filename, string $contents): string
    {
        $name = ucfirst(mb_strtolower(basename($filename, '.php')));

        $mutation = <<<GRAPHQL

StartNew{$name}Orchestration(id String, input: [Input!]!): Orchestration

GRAPHQL;

        $waitForExternalEventCalls = [];
        $tokens = token_get_all($contents);
        $waitForExternalEventFound = false;
        $nextStringIsArgument = false;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === \T_STRING && $token[1] === 'waitForExternalEvent') {
                    $waitForExternalEventFound = true;
                } elseif ($waitForExternalEventFound && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $waitForExternalEventCalls[] = trim($token[1], '\'"');
                    $waitForExternalEventFound = false;
                }
            }
        }
        foreach (array_unique($waitForExternalEventCalls) as $event) {
            $event = str_replace(' ', '', ucwords($event));
            $mutation .= "Send{$event}To{$name}Orchestration(input: [Input!]!): Void\n";
        }

        return $mutation;
    }

    public function defineEntity(string $filename, string $contents): string
    {
        $parsedName = basename($filename, '.php');
        $shortName = $parsedName;

        $parsed = MetaParser::parseFile($contents);
        $definitions = include $this->bootstrap;

        $parsedName = $parsed->namespace . '\\' . $parsedName;

        $rootName = $this->findRootName($parsedName, $parsed->implements);

        $filename = $this->getPathForClass($rootName);
        $contents = file_get_contents($filename);

        $parsed = MetaParser::parseFile($contents);

        var_dump($parsed);

        return '';
    }

    private function findRootName(string $parsedName, array $matches): string
    {
        $definitions = include $this->bootstrap;

        foreach ($definitions as $name => $class) {
            if ($class instanceof AutowireDefinitionHelper) {
                $class = $class->getDefinition('none')->getClassName();
            } elseif ($class instanceof CreateDefinitionHelper) {
                $class = $class->getDefinition('none')->getClassName();
            }
            if ($class === $parsedName && in_array($name, $matches)) {
                // we have an alias
                return $this->findRootName($name);
            }
        }

        return $parsedName;
    }
}

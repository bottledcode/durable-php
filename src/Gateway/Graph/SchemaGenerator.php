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

class SchemaGenerator
{
    public function __construct() {}

    public function generateSchema(string|null $rootDirectory = null): string
    {
        $projectRoot = $rootDirectory ?? $this->findComposerJson(__DIR__ . '/../../../..');

        $typeManager = new TypeManager();

        $this->findPhpFiles($projectRoot, $typeManager);

        return $typeManager->renderTypes();
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

    public function findPhpFiles($dir, TypeManager $typeManager): void
    {
        $items = glob($dir . '/*');

        foreach ($items as $item) {
            if (is_dir($item) && !str_ends_with($item, 'vendor')) {
                $this->findPhpFiles($item, $typeManager);
            } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
                $this->processPhpFile($item, $typeManager);
            }
        }
    }

    public function processPhpFile(string $file, TypeManager $typeManager): void
    {
        $type = new SchemaExtractor($file);
        $type->parse();
        $typeManager->addType($type->getPhpType(), $type);
    }
}

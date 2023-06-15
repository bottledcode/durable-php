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

namespace Bottledcode\DurablePhp\Abstractions\WAL;

use Bottledcode\DurablePhp\State\Serializer;

class Index
{
    public function __construct(
        public readonly string $id, public array $recordLocations = [], public mixed $state = null,
        public bool $deleted = false, public bool $deserialized = false
    ) {
    }

    public function build(Record $record, int $location): void
    {
        if($this->deleted) {
            // it is possible we're getting data for a deleted key
            $this->deleted = false;
        }
        $this->recordLocations[$location] = $record;
        if($record->data === '__DELETE__') {
            $this->deleted = true;
            $this->state = null;
            $this->recordLocations = [];
            $this->deserialized = false;
            return;
        }
        if ($record->type === RecordType::Last) {
            $data = '';
            foreach ($this->recordLocations as $r) {
                $data .= $r->data;
            }
            $this->state = $data;
        }
    }

    /**
     * @template T
     * @param class-string<T> $type
     * @return <T>
     */
    public function deserialize(string $type): mixed
    {
        if($this->deleted) {
            return null;
        }

        if ($this->deserialized) {
            return $this->state;
        }

        $this->state = Serializer::deserialize(json_decode($this->state, true, flags: JSON_THROW_ON_ERROR), $type);
        $this->deserialized = true;
        return $this->state;
    }
}

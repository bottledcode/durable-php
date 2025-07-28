<?php

namespace bottledcode\DurablePhp\State;

use Crell\Serde\Attributes\Field;
use Crell\Serde\Deserializer;
use Crell\Serde\PropertyHandler\DictionaryExporter;
use Crell\Serde\Serializer;
use Override;
use ReflectionClass;
use Withinboredom\Record;

class Exporter extends DictionaryExporter
{
    #[Override]
    public function exportValue(Serializer $serializer, Field $field, mixed $value, mixed $runningValue): mixed
    {
        assert($value instanceof Record);

        $ref = new ReflectionClass($value);
        $id = $ref->getMethod('getIdentity')->invoke($value);

        return parent::exportValue($serializer, $field, $id, $runningValue);
    }

    public function canExport(Field $field, mixed $value, string $format): bool
    {
        return $value instanceof Record;
    }

    public function importValue(Deserializer $deserializer, Field $field, mixed $source): mixed
    {
        $reflectedRecord = new ReflectionClass($field->phpType);
        $record = $reflectedRecord->getMethod('fromArgs')->invoke(null, ...($source['root']));

        return $record;
    }

    public function canImport(Field $field, string $format): bool
    {
        return is_a($field->phpType, Record::class, true);
    }
}

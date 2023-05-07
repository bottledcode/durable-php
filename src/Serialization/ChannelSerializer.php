<?php

namespace Bottledcode\DurablePhp\Serialization;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class ChannelSerializer
{
	public static function serialize($data)
	{
		if (is_null($data)) {
			return null;
		}

		if (is_scalar($data)) {
			return $data;
		}

		if (is_array($data)) {
			$serialized = [];
			foreach ($data as $key => $value) {
				$serialized[$key] = self::serialize($value);
			}
			return $serialized;
		}

		if (is_object($data) && self::isDataContract($data)) {
			$reflector = new \ReflectionClass($data);
			$properties = $reflector->getProperties();
			$serialized = [
				'$dataContract' => $data::class,
				'properties' => []
			];
			foreach ($properties as $property) {
				if (($datamember = $property->getAttributes(
						DataMember::class,
						\ReflectionAttribute::IS_INSTANCEOF
					)) === []) {
					continue;
				}
				$name = $datamember[0]->newInstance()->name ?? $property->getName();
				$value = self::serialize($property->getValue($data));
				$serialized['properties'][$name] = $value;
			}

			return $serialized;
		}

		if ($data instanceof \DateTimeInterface) {
			return ['$date' => $data->format(\DateTimeInterface::ATOM)];
		}

		if ($data instanceof UuidInterface) {
			return ['$uuid' => $data->toString()];
		}

		return igbinary_serialize($data);
	}

	private static function isDataContract($data): bool
	{
		$reflector = new \ReflectionClass($data);
		return $reflector->getAttributes(DataContract::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
	}

	public static function unserialize($data)
	{
		if (is_null($data)) {
			return null;
		}

		if (is_scalar($data)) {
			return $data;
		}

		if (is_array($data)) {
			if (isset($data['$date'])) {
				return new \DateTimeImmutable($data['$date']);
			}
			if (isset($data['$uuid'])) {
				return Uuid::fromString($data['$uuid']);
			}
			if (isset($data['$dataContract'])) {
				$reflector = new \ReflectionClass($data['$dataContract']);
				$obj = $reflector->newInstanceWithoutConstructor();
				$properties = $reflector->getProperties();
				foreach ($properties as $property) {
					if (($datamember = $property->getAttributes(
							DataMember::class,
							\ReflectionAttribute::IS_INSTANCEOF
						)) === []) {
						continue;
					}
					$name = $datamember[0]->newInstance()->name ?? $property->getName();
					$value = self::unserialize($data['properties'][$name] ?? null);
					$property->setValue($obj, $value);
				}
				return $obj;
			}

			$unserialized = [];
			foreach ($data as $key => $value) {
				$unserialized[$key] = self::unserialize($value);
			}
			return $unserialized;
		}

		return igbinary_unserialize($data);
	}
}

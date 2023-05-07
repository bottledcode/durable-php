<?php

namespace Bottledcode\DurablePhp;

final readonly class EntityId implements \Stringable
{
	public string $name;

	private string $schedulerId;

	public function __construct(string $name, public string $key)
	{
		$this->name = strtolower($name);
	}

	public function __toString(): string
	{
		return $this->getSchedulerId();
	}

	public function getSchedulerId(): string
	{
		return $this->schedulerId ??= ($this->schedulerId = sprintf('@%s@%s', $this->name, $this->key));
	}

	public static function fromSchedulerId(string $schedulerId): self
	{
		$idx = strpos($schedulerId, '@', 1);
		$name = substr($schedulerId, 1, $idx - 1);
		$key = substr($schedulerId, $idx + 1, -1);
		return new EntityId($name, $key);
	}
}

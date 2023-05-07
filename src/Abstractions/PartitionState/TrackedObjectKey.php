<?php

namespace Bottledcode\DurablePhp\Abstractions\PartitionState;

class TrackedObjectKey
{
	private function __construct(
		public readonly TrackedObjectType $objectType,
		public readonly string|null $instanceId = null
	) {
	}

	public static function dedupe(): self
	{
		static $dedupe = new self(TrackedObjectType::Dedupe);
		return $dedupe;
	}

	public static function outbox(): self
	{
		static $outbox = new self(TrackedObjectType::Outbox);
		return $outbox;
	}

	public static function reassembly(): self
	{
		static $reassembly = new self(TrackedObjectType::Reassembly);
		return $reassembly;
	}

	public static function sessions(): self
	{
		static $sessions = new self(TrackedObjectType::Sessions);
		return $sessions;
	}

	public static function timers(): self
	{
		static $timers = new self(TrackedObjectType::Timers);
		return $timers;
	}

	public static function prefetch(): self
	{
		static $prefetch = new self(TrackedObjectType::Prefetch);
		return $prefetch;
	}

	public static function queries(): self
	{
		static $queries = new self(TrackedObjectType::Queries);
		return $queries;
	}

	public static function stats(): self
	{
		static $stats = new self(TrackedObjectType::Stats);
		return $stats;
	}

	public static function history(string $id): self
	{
		return new self(TrackedObjectType::History, $id);
	}

	public static function instance(string $id): self
	{
		return new self(TrackedObjectType::Instance, $id);
	}

	public static function factory(self $key): TrackedObject
	{
		return match ($key) {
			self::activities() => new Activities(),
		};
	}

	public static function activities(): self
	{
		static $activities = new self(TrackedObjectType::Activities);
		return $activities;
	}
}

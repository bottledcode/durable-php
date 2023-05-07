<?php

namespace Bottledcode\DurablePhp\Events;

enum EventCategory {
	/**
	 * Event sent from a client to a partition.
	 */
	case ClientRequest;

	/**
	 * Event sent from a partition to a client.
	 */
	case ClientResponse;

	/**
	 * Event sent from a partition to itself
	 */
	case PartitionInternal;

	/**
	 * Event sent from a partition to another partition.
	 */
	case PartitionToPartition;

	/**
	 * Event sent from a partition to the load monitor
	 */
	case ToLoadMonitor;

	/**
	 * Event sent from the load monitor to a partition
	 */
	case LoadMonitorToPartition;
}

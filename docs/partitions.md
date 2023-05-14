# Partitions

Partitions are logical divisions of work. Each partition is assigned to a single consumer. The consumer is responsible
for executing activities and orchestrations that are assigned to the partition.

## Partition Lifecycle

Partitions are managed by a partition actor. The partition is responsible for managing the lifecycle of the partition.

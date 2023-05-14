# Activities

Activities are the building blocks of Durable PHP. An activity has an address, a callback, and a set of parameters that
define the runtime behavior of the activity.

When an activity is scheduled, it is attempted to be executed by the same partition. If the partition is falling behind
on processing, the activity will be migrated to another [partition](./partitions.md).

## Scheduling work

Activities are scheduled by sending a schedule command to the Redis stream. Once complete the activity will send a
result command to the identity stream.

## Monitoring work queues

A dedicated monitoring actor watches the current state of the work queues. If the queue depth is growing, pending
activities will be migrated to another partition. If configured, a scale controller will be notified to scale up the
number of partitions.

## Activity lifecycle

1. An activity is scheduled by sending a schedule command to the Redis stream.
2. The activity is picked up by a worker process.
3. The worker checks to see if there are any available processors. If not, the activity is migrated to another partition.
4. If there are available processors, the activity is executed.
5. The activity sends a result command to the identity stream.
6. The activity is marked as complete.
7. The activity is removed from the work queue.
8. The activity stream is scheduled to be removed via a 'void' activity.

## Activity types

- Return type: the activity returns a value.
- Void type: the activity does not return a value and cannot be waited on.
- Generator type: the activity returns a generator/iterator that yields values.

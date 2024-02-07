# Entities

Unlike orchestrations, entities manage a piece of state explicitly instead of through flow control (such as
orchestrations). Entities provide a means for scaling by distributing the workload across many entities.

## Concepts

Entities are very similar to actors or like services that communicate via the sending of messages. Like services, they
perform work when prompted to do so (operations).

## Identity

Entities are accessed by a unique identity. The identity is simply a pair of strings: a name, and id. Traditionally,
the name is the class/interface name, and the id is a unique identifier.

## Accessing entities

Entities may only be signaled, called, or snapshot.

- Signal: invokes an entity but the return is lost.
- Call: invokes an entity and the result is returned to the orchestration.
- Snapshot: get the current snapshot of the entity. Note that this is not updated, you'll need to take a new snapshot to
  get updated information.

There are specific rules for which type of operation may be performed in various contexts:

- From clients: signal and snapshot.
- From orchestrations: signal and call.
- From entities: signal

## Coordination

When coordinating between multiple entities, you should use an intermediary orchestration.

## Critical sections

No operations from other clients are allowed on an entity while it's in a locked state. This behavior ensures that only
one orchestration instance can lock an entity at a time. If a caller tries to invoke an operation on an entity while
it's locked by an orchestration, that operation is placed in a pending operation queue. No pending operations are
processed until after the holding orchestration releases its lock.

Locks are internally persisted as part of an entity's durable state.

Unlike transactions, critical sections don't automatically roll back changes when errors occur. Instead, any error
handling, such as roll-back or retry, must be explicitly coded, for example by catching errors or exceptions. This
design choice is intentional.

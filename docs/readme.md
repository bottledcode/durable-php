# Overview

Durable PHP is a self-hosted FaaS engine, heavily influenced by Durable Functions in C#.
Durable PHP helps you write stateful workflows by writing [orchestrations](orchestrations.md)
and [entities](entities.md)
(aka actors).

## Infrastructure Requirements

| Requirement                              | Provides                                            |
| ---------------------------------------- | --------------------------------------------------- |
| PHP 8.3                                  | Language                                            |
| [Beanstalkd](./Components/beanstalkd.md) | Stores and orders events in-transit                 |
| [Rethinkdb](./Components/rethinkdb.md)   | Provides distributed locks and state storage        |
| Kubernetes¹²                             | Provides distributed locks                          |
| Redis¹²                                  | Provides state storage and stores events in-transit |

(¹) optional, replaces other requirement
(²) not implemented yet

## Patterns

The primary use-case for Durable PHP is simplifying complex, stateful coordination requirements in any application.
You can view the following sections to view some common patterns:

- [Chaining](chaining.md)
- [Fan-in/Fan-out](fan-in-out.md)
- [Monitoring](monitoring.md)
- [Human interaction](human-interaction.md)
- [Aggregation](aggregation.md)
- [Idempotent side-effects](activities.md)

## Technology

Durable PHP is built on composable event-sourcing, where every operation/event/signal is composed of multiple events.
This allows for flexible operations, prioritizing, and scaling. Originally, a partitioned approach was taken (similar to
durable functions) to remove the need for distributed locking; however, through testing, it was discovered that a
distributed lock was more scalable, where a partitioned approach was far more complex to scale.

## Code constraints

To provide long-running execution guarantees in orchestrations, orchestrations have a set [of rules](orchestration-rules.md)
that must be adhered to. We recommend PHPStan with the durable-php code rules applied (todo).

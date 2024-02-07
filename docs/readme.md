# Overview

Durable PHP is a self-hosted FaaS engine, heavily influenced by Durable Functions in C#.
Durable PHP helps you write stateful workflows by writing [orchestrations](orchestrations.md) and [entities](entities.md)
(aka actors).

## Infrastructure Requirements

| Requirement  | Provides                                            |
| ------------ | --------------------------------------------------- |
| PHP 8.3      | Language                                            |
| Beanstalkd   | Stores and orders events in-transit                 |
| Rethinkdb    | Provides distributed locks and state storage        |
| Kubernetes¹² | Provides distributed locks                          |
| Redis¹²      | Provides state storage and stores events in-transit |

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

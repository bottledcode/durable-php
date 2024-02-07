# Overview

Durable PHP is a self-hosted FaaS engine, heavily influenced by Durable Functions in C#.
Durable PHP helps you write stateful workflows by writing [orchestrations](orchestrations.md) and [entities](entities.md)
(aka actors).

## Infrastructure Requirements

| Requirement | Provides                                     |
| ----------- | -------------------------------------------- |
| PHP 8.3     | Language                                     |
| Beanstalkd  | Stores and orders events in-transit          |
| Rethinkdb   | Provides distributed locks and state storage |

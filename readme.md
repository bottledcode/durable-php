# Durable PHP

This is a framework/library to allow building global, distributed, and durable PHP applications. It is inspired by
several Microsoft projects:

1. [Orleans](https://dotnet.github.io/orleans/)
2. [Service Fabric](https://docs.microsoft.com/en-us/azure/service-fabric/)
3. [Durable Functions](https://docs.microsoft.com/en-us/azure/azure-functions/durable/durable-functions-overview)
4. [Dapr](https://dapr.io/)

## What is it?

Durable PHP is a framework/library that allows you to build distributed applications that are durable. Durable means
that the application can survive failures of individual components, and that the application can be scaled up and down
without any downtime. Durable PHP is built on top of [Redis Streams](https://redis.io/topics/streams-intro) and uses
[Redis](https://redis.io/) as the underlying storage mechanism.

## How does it work?

The building blocks of Durable PHP are called 'Activities'. An Activity is a stateless class implementing a single
method or callable. Activities are allowed to call other activities, compute, perform I/O, and wait for events.
Activities are executed by a worker process. The worker process is responsible for executing the activities and storing
the state of the activities in Redis.

'Orchestrations' are built on top of Activities. An orchestration is a stateful class or method that can call other
orchestrations and activities. The state of an orchestration is stored in Redis and the state is recreated by '
replaying' the orchestration. Thus, I/O is not recommended by orchestrations, as it will be executed multiple times.

'Actors' are built on top of Orchestrations. An actor is a stateful class or method that can call other actors, perform
I/O and wait for events. The state of an actor is stored in Redis and the state is recreated by hydrating the actor.

## How is this different from regular PHP?

Regular PHP is stateless. This means that every request is handled by a new PHP process. This is great for performance
and scalability, but it makes it hard to build applications that need to maintain state. Durable PHP allows you to build
applications that maintain state, and that can survive failures of individual components. Durable PHP is also designed
to be used in a distributed environment, where multiple PHP processes are running on different machines without
deadlocks.

## How is this different from other PHP frameworks?

Durable PHP encourages rich models and domain-driven design (DDD). This means that you can build your application using
classes and methods that represent your domain. No more anemic models and procedural code.

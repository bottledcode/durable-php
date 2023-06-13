# Durable PHP

This is a framework/library to allow building global, distributed, and durable PHP applications. It is inspired by
several Microsoft projects:

1. [Orleans](https://dotnet.github.io/orleans/)
2. [Service Fabric](https://docs.microsoft.com/en-us/azure/service-fabric/)
3. [Durable Functions](https://docs.microsoft.com/en-us/azure/azure-functions/durable/durable-functions-overview)
4. [Dapr](https://dapr.io/)

Along with Akka, Erlang, and other actor-based frameworks.

## What is it?

Durable PHP is a framework/library that allows you to build distributed applications that are durable. Durable means
that the application can survive failures of individual components, and that the application can be scaled up and down
without any downtime. Durable PHP uses RethinkDb or Redis as the underlying storage.

## How does it work?

The building blocks of Durable PHP are called 'Activities'. An Activity is a stateless class implementing a single
method or callable. Activities are allowed to call other activities, compute, perform I/O, and wait for events.
Activities are executed by a worker process. The worker process is responsible for executing the activities and storing
the state of the activities in the database.

'Orchestrations' are built on top of Activities. An orchestration is a stateful class or method that can call other
orchestrations and activities. The state of an orchestration is stored in the database and the state is recreated by '
replaying' the orchestration. Thus, I/O is not recommended for orchestrations, as it will be executed multiple times.

'Actors' are built on top of Orchestrations. An actor is a stateful class or method that can call other actors, perform
I/O and wait for events. The state of an actor is stored in the database and the state is recreated by hydrating the
actor.

## How is this different from regular PHP?

Regular PHP is stateless. This means that every request is handled by a new PHP process. This is great for performance
and scalability, but it makes it hard to build applications that need to maintain state -- usually involving ORMs and
ceremonies to load state. Durable PHP allows you to build applications that maintain state, and that can survive
failures of individual components. Durable PHP is also designed to be used in a distributed environment, where multiple
PHP processes are running on different machines without deadlocks.

## How is this different from other PHP frameworks?

Durable PHP encourages rich models and domain-driven design (DDD). This means that you can build your application using
classes and methods that represent your domain.

## Can I see an example?

Imagine you want upload a file to S3 and wait for the file to be processed, then send an email to the user or an email
to the admin if something went wrong or it took to long. Without Durable-PHP, this would be quite complex. Here's what
it looks like with Durable-PHP:

```php
# SendEmailActivity.php

class SendEmailActivity {
    public function __invoke(string $to, string $subject, string $body) {
        // Send email
    }
}

# UploadEntityInterface.php

interface UploadEntityInterface {
    public function getFileUrl(): string;
    public function setProcessState(string $state): void;
    public function getProcessState(): string;
}

# UploadEntity.php

class UploadEntity extends \Bottledcode\DurablePhp\State\EntityState implements UploadEntityInterface {
    public function __construct(private string $url, private string $state = 'pending') {}
    public function setProcessState(string $state): void {
        $this->state = $state;
    }
    public function getProcessState(): string {
        return $this->state;
    }
}

# UploadOrchestration.php

class UploadOrchestration {
    public function __invoke(\Bottledcode\DurablePhp\OrchestrationContextInterface $context) {
        $url = $context->getInput();
        $entity = $context->createEntityProxy(UploadEntityInterface::class);
        // get a future that will be resolved when the upload is processed
        $signal = $context->waitForExternalEvent('upload-processed');
        // get a future that will be resolved when the timer expires (one hour from now)
        $timeout = $context->createTimer($context->getCurrentTime()->add(new DateInterval('PT1H')))
        // wait for the upload to be processed or the timer to expire
        $winner = $context->waitAny($signal, $timeout);
        if($winner === $signal) {
            // upload was processed
            $entity->setProcessState('processed');
            $context->callActivity(SendEmailActivity::class, ['to' => 'user', 'subject' => 'Upload processed', 'body' => 'Your upload was processed']);
        } else {
            // upload was not processed
            $entity->setProcessState('timed-out');
            $context->callActivity(SendEmailActivity::class, ['to' => 'admin', 'subject' => 'Upload failed', 'body' => 'The upload timed out']);
        }
    }
}
```

## How do I use it?

This is nowhere near production ready, so if you want to use it, be prepared for things to be broken or change
drastically without much warning. If you are using this though, please let me know in the issues. I'd love to hear about
it and what you are using it for.

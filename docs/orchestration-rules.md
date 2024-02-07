# Orchestration Code Constraints

Orchestrations use composable event-sourcing to provide reliable execution and maintain local state. Thus, it is
**very** important that the code be deterministic.

## Deterministic APIs

It is very important that any function called be deterministic, without any side-effects. Deterministic means that the
result is always the same, given the same inputs, no matter how often it is called.

### Dates and times

The context provides a way to deterministically get the current time. It will always be the time the first replay to
reach that point calls the context for the current time. You can also use regular DateTime objects as well, as long as
you avoid the current time. For example, this will cause an infinite loop:

```php
$context->createTimer(new DateTimeImmutable('+ 5 minutes'));
```

This is because every replay will start a timer from the current time, instead of the time we originally wanted to
construct the timer for. Instead, use the context's current time:

```php
$context->createTimer($context->getCurrentTime()->add($context->createInterval(minutes: 5)));
```

### UUIDs

UUIDs usually contain some time/random components, thus they cannot be used. The context provides a way of getting
deterministically random UUIDs:

```php
$uuid = $context->newGuid();
```

### Random numbers

Random is non-deterministic by definition. However, you can get a random number from the current context:

```php
$context->getRandomBytes(12);
$context->getRandomInt(0, 100);
```

### Static variables

Static variables should be avoided since it is unknown what machine/worker the next invocation will be running on.

### Environment variables

Environment variables can change over time, instead send configuration through input or wrap it in an activity.

### Network calls

Databases or API calls to external systems may change over time, breaking your orchestration.

### Sleeping

Sleeping can cause significant scalability issues, isntead use timers.

### Versioning

Executions can run for days, months, even years. Making code changes might break orchestrations. Make sure to plan
updates carefully and/or version your orchestrations.

### Activities

You can make just about any callable into an activity:

```php
$length = $context->callActivity(strlen(...), ['my string']);
```

So, if you need to call something non-deterministic and want to ensure it will be memoized for replaying, you can simply
make it an activity.

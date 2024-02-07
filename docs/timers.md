# Timers

The orchestration context exposes the concept of timers that allow for you to implement delays, deadlines, or timeouts.
Timers should be used in place of `sleep`.

## How to delay/sleep

This example shows how to use a timer to sleep for 5 minutes:

```php
$context->waitOne($context->createTimer($context->createInterval(minutes: 5)));
```

## As a timeout

This example shows how to use as a timeout:

```php
$timeout = $context->createTimer($context->createInterval(days: 2));
$activity = $context->callActivity('walkTheDog', ['name' => 'Chester']);
$winner = $context->waitAny($timeout, $activity);

if($winner === $timeout) {
    // timed out
    return;
}
$result = $activity->getResult();
```

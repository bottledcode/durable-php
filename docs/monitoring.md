# Monitoring Pattern

This pattern refers to a flexible, recurring process in a workflow, such as monitoring an endpoint's status. You can use
Durable PHP to create flexible recurring intervals, manage task lifetimes, and create multiple monitor processes from a
single orchestration.

In a few lines of code, you can use Durable PHP to create monitors that observe just about anything. The monitors can
end execution when a condition is met, you can even change the interval based on dynamic conditions (such as an
exponential backoff).

## In regular PHP

There isn't an easy way to implement this in regular PHP without built-in framework support, cron jobs, or
infrastructure support. In many cases, there is no way to dynamically configure the interval.

## In Durable PHP

The following code implements a simple monitor:

```php
function monitor(\Bottledcode\DurablePhp\OrchestrationContext $context): void {
    $jobId = $context->getInput();
    $pollingInterval = getPollingInterval();
    $expires = getExpiryTime();
    
    while($context->getCurrentTime() < $expires) {
        $status = $context->callActivity('getJobStatus', [$jobId]);
        if($context->waitOne($status) === 'completed') {
            $context->callActivity('sendSuccessAlert', [$jobId]);
            break;
        }
        
        $next = $context->getCurrentTime()->add($pollingInterval);
        $context->waitOne($context->createTimer($next));
    }
}
```

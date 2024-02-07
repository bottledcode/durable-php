# Human Interaction

Many automated systems require human interaction at some point. Involving humans in an automated process can be tricky,
especially since people aren't always available. An automated process involving humans usually requires some action to
be performed if a human isn't available in time.

An approval process is an example that involves human interaction. For example, a manager may need to enter their
employee code to disburse funds, and if the manager doesn't respond in time, we would want the approval to be escalated.

## In regular PHP

This is extremely difficult to get right in regular PHP, especially without framework support. It usually requires
carefully orchestrating jobs, events, and other bits of infrastructure. Not to mention that you need to handle failures
along the way.

## In Durable PHP

In Durable PHP, this is extremely simple to implement via an [orchestrator](orchestrations.md). We can create a timer to
handle the approval process and escalate once a timeout occurs:

```php
function approvalWorkflow(\Bottledcode\DurablePhp\OrchestrationContext $context): void {
    $context->callActivity('requestApproval');
    
    $dueAt = $context->getCurrentTime()->add(new DateInterval('PT72H'));
    $timeout = $context->createTimer($dueAt);
    $approval = $context->waitForExternalEvent('approved');
    
    if($approval === $context->waitAny($timeout, $approval)) {
        $context->callActivity('processApproval');
    } else {
        $context->callActivity('escalate');
    }
}
```

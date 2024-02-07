# External Events

External events allow you to influence the execution of an orchestrator from clients:

```php
function orchestration(\Bottledcode\DurablePhp\OrchestrationContext $context) {
    $approved = $context->waitForExternalEvent('approval')
    if($context->waitOne($approved)) {
      // handle approval
    } else {
      // handle rejection
    }
}
```

In the above example, we wait for a single event, but we can also wait for the first one:

```php
function orchestration(\Bottledcode\DurablePhp\OrchestrationContext $context) {
    $approvals = [
        $context->waitForExternalEvent('employee-approval'), 
        $context->waitForExternalEvent('manager-approval')
    ];
    if($context->waitAny($approved)) {
      // handle approval
    } else {
      // handle rejection
    }
}
```

Or for all events:

```php
function orchestration(\Bottledcode\DurablePhp\OrchestrationContext $context) {
    $approvals = [
        $context->waitForExternalEvent('employee-approval'), 
        $context->waitForExternalEvent('manager-approval')
    ];
    if($context->waitAll($approved)) {
      // handle approval
    } else {
      // handle rejection
    }
}
```

## Sending Events

Clients may send events via the `DurableClient`'s `raiseEvent` method:

```php
$client->raiseEvent($instance, 'approved', ['by' => 'me']);
```

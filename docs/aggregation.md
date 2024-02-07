# Aggregation

The aggregation pattern is about aggregating data into a single stateful/addressable [entity](entities.md). In this
pattern, the data might come from many sources, might be delivered in batches, or may be scattered over long periods of
time. The aggregator might need to take action on event data as it arrives, or external clients may need to inspect the
aggregated data.

## In regular PHP

This is extremely hard to get right with regular PHP, even with framework support. The tricky part about implementing
this in regular PHP is that there isn't really any concurrency control; not only do you need to worry about modifying
the same data at the same time (database contention, transactional boundaries, and transactional visibility), you also
have to run complex queries in-situ which may slow down response times for clients.

## In Durable PHP

With entities, this is very straightfoward to implement this pattern.

```php
function counter(\Bottledcode\DurablePhp\EntityContext $context): void {
    ['value' => $value] = $context->getState();
    switch($context->getOperation()) {
        case 'add':
            ['amount' => $amount] = $context->getInput();
            $context->setState(['value' => $value + $amount]);
            break;
        case 'reset':
            $context->setState(['value' => 0]);
            break;
        case 'get':
            $context->return($context->getState()['value']);
            break;
    }
}
```

Entities can also be modeled as classes:

```php
class Counter extends \Bottledcode\DurablePhp\State\EntityState {
    private int $value;
    
    public function add(int $amount): void {
        $this->value += $amount;
    }
    
    public function reset(): void {
        $this->value = 0;
    }
    
    public function get(): int {
        return $this->value;
    }
}
```

Clients can enqueue operations (also known as signaling) using the durable client:


```php
function example(\Bottledcode\DurablePhp\DurableClient $client, \Bottledcode\DurablePhp\State\EntityId $id) {
    // explicit
    $client->signalEntity($id, 'add', ['amount' => 1]);
    
    // type-safe
    $client->signal($id, fn(Counter $counter) => $counter->add(1));
}
```

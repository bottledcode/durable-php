# Activities

Activities are executed from orchestrations and can be any callable (including a class that implements `__invoke`). The
result is memoized, therefore making it deterministic. Activities allow you to transform any code into a deterministic
call, even if the code itself isn't deterministic.

## Retries

Once executed, the exact same activity cannot be retried; however, you may call another activity that calls the same
underlying code again.

## Storage

All activity results are stored in the activities table for audit purposes and to ensure at-most-once execution. These
can safely be deleted since the relevant parts are stored as part of the orchestration state.

## Guarantees

Activities are at-most-once; they would only be executed more than once if something
happens between execution and storing the results (which is unlikely). Once the results are persisted into the
activities table, it cannot execute again.

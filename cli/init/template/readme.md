# Durable PHP Template

You are now ready to get started!

Run `dphp composer install` to install the project dependencies then `dphp` to start the server. In another terminal,
you can run `dphp exec tests/integrationTest.php` to see some example usage from a client.

Feel free to inspect the sources:

- Activities/AddOne.php: a dumb activity that emulates a side effect
- Entities/CountInterface.php: an interface for the counter entity
- Entities/CountState.php: a counter entity that uses an orchestration
- Orchestrations/Counter.php: an orchestration that locks the calling entity, adds some numbers, and calls the entity
- Orchestrations/Password.php: an orchestration that uses a DI approach and waits for a password.
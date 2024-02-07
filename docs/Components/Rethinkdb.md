# Rethinkdb

Rethinkdb is used to provide a redundant storage system and simple distributed locks.

## Configuration

Configuration is done via environment variables:

| variable           | default    | description            |
| ------------------ | ---------- | ---------------------- |
| RETHINKDB_DATABASE | durablephp | The database to use    |
| RETHINKDB_HOST     | localhost  | The host to connect to |
| RETHINKDB_PORT     | 28015      | The port to connect to |
| RETHINKDB_USER     | admin      | The user to connect as |
| RETHINKDB_PASSWORD |            | The password to use    |

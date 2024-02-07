# Beanstalkd

Beanstalkd is a highly performant queue, capable of handling insane loads at high speed.
By default, this is the preferred hosting strategy if you are self-hosting.

## Configuration options

There's really not much to configure here, and all options are done through the cli:

| option              | default                            | description                                                 |
| ------------------- | ---------------------------------- | ----------------------------------------------------------- |
| --beanstalk         | localhost:11300                    | The beanstalk instance to connect                           |
| --namespace         | dphp                               | prefix queues with this to isolate instances of Durable PHP |
| --execution-timeout | 60                                 | the amount of time, in seconds, to allow any code to run    |
| --monitor           | activities,entities,orchestrations | the types of queues to monitor                              |

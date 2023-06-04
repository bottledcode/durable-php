# Messages and the message pump

The library must quickly process messages from the user and the system.
This is how it works:

```mermaid
stateDiagram-v2
    Checking: Checking queue
    Dispatching: Dispatching message
    NewMessageOrTermination: Waiting for new message or worker termination
    HandleTermination: Handle worker termination

    [*] --> Checking
    Checking --> NewMessageOrTermination: No message
    Checking --> Dispatching: Message
    Dispatching --> Checking

    NewMessageOrTermination --> Checking: New message
    NewMessageOrTermination --> HandleTermination: Worker termination
    HandleTermination --> Checking
```

And a flow chart:

```mermaid
flowchart TD
    Entry --> queuesizegt1
    Entry --> listen
    queuesizegt1{Queue size > 0} ==>|True| getnext[Get next message]
    evnull{Event is null} ==>|False| dispatch[Dispatch message]
    getnext ==> evnull
    dispatch ==> queuesizegt1
    wait[Wait for event or worker termination]
    evnull -.->|True| wait
    queuesizegt1 --->|False| wait
    newEvent[New event on queue] --> queuesizegt1
    wait -->|event| newEvent
    stop[Stop tracking worker] --> queuesizegt1
    wait -->|worker termination| stop

    listen[Listen for events] ==> enqueue[Enqueue event]
    enqueue ==> size
    size{Is queue size 1} -->|True| already{Already triggered}
    already -->|False| trigger[Trigger event]
    trigger --> listen
    size ==>|False| listen
    already -->|True| listen

    trigger -.- newEvent
```

This will probably change as bugs are fixed upstream. (Right now, a worker can only process a single event.)

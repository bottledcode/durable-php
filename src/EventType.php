<?php

namespace Bottledcode\DurablePhp;

enum EventType
{
    case ExecutionStarted;
    case ExecutionCompleted;
    case ExecutionFailed;
    case ExecutionTerminated;
    case TaskScheduled;
    case TaskCompleted;
    case TaskFailed;
    case SubOrchestrationInstanceCreated;
    case SubOrchestrationInstanceCompleted;
    case SubOrchestrationInstanceFailed;
    case TimerCreated;
    case TimerFired;
    case OrchestratorStarted;
    case OrchestratorCompleted;
    case EventSent;
    case EventRaised;
    case ContinueAsNew;
    case GenericEvent;
    case HistoryState;
    case ExecutionSuspended;
    case ExecutionRenewed;
}

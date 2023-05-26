<?php

namespace Bottledcode\DurablePhp\State;

enum OrchestrationStatus
{
	case Running;
	case Completed;
	case ContinuedAsNew;
	case Failed;
	case Canceled;
	case Terminated;
	case Pending;
	case Suspended;
}

<?php

namespace {{.Name}}\Orchestrations;

use Bottledcode\DurablePhp\OrchestrationContextInterface;
use {{.Name}}\Activities\AddOne;

class Counter {
	public function __invoke(OrchestrationContextInterface $context): void {
		$input = $context->getInput();
		for($i = 0, $sum = 0; $i < $input['count']; $i++) {
			$resultFuture = $context->callActivity(AddOne::class, [$sum]);
			$sum = $context->waitOne($resultFuture);
		}

		$context->entityOp($input['replyTo'], fn({{.Name}}\Entities\CountState $entity) => $entity->receiveResult($sum));
		$context->setCustomStatus($sum);
	}
}
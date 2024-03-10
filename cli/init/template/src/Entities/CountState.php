<?php

namespace {{.Name}}\Entities;

use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\EntityContext;

class CountState extends EntityState {
	public int $count = 0;

	public function countTo(int $number): void {
		EntityContext::current()
			->startNewOrchestration(
				{{.Name}}\Orchestrations\Counter::name,
				['count' => $number, 'replyTo' => EntityContext::current()->getId()]
			);
	}

	public function receiveResult(int $result): void {
		$this->count = $result;
	}
}
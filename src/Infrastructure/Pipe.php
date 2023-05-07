<?php

namespace Bottledcode\DurablePhp\Infrastructure;

use parallel\Channel;
use parallel\Events;
use parallel\Runtime;

class Pipe
{
	private Runtime|null $thread = null;

	public function __construct(Channel $input, Channel ...$output)
	{
		$this->thread = new Runtime();
		$this->thread->run(function (Channel $input, Channel ...$output) {
			$events = new Events();
			$events->addChannel($input);
			$events->setBlocking(true);
			/**
			 * @var Events\Event $event
			 */
			foreach ($events as $event) {
				switch ($event->type) {
					case Events\Event\Type::Error:
						throw new \LogicException('Error event received', previous: $event->value);
					case Events\Event\Type::Close:
					case Events\Event\Type::Cancel:
					case Events\Event\Type::Kill:
						echo "Closing pipe due to input being closed: $event->type\n";
						foreach ($output as $o) {
							$o->close();
						}
						return;
					case Events\Event\Type::Read:
						foreach ($output as $o) {
							$o->send($event->value);
						}
						break;
				}
			}
		}, [$input, ...$output]);
	}

	public function __destruct()
	{
		$this->stop();
	}

	public function stop(): void
	{
		echo 'Stopping pipe...';
		$this->thread?->close();
		$this->thread = null;
	}
}

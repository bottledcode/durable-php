<?php

namespace Bottledcode\DurablePhp\Contexts;

use Amp\Cancellation;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextFactory;

use function Amp\async;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\getStdout;
use function Amp\ByteStream\pipe;

class LoggingContextFactory implements ContextFactory
{
	public function __construct(private readonly ContextFactory $other)
	{
	}

	public function start(array|string $script, ?Cancellation $cancellation = null): Context
	{
		$process = $this->other->start($script, $cancellation);

		async(pipe(...), $process->getStdout(), getStdout())->ignore();
		async(pipe(...), $process->getStderr(), getStderr())->ignore();

		return $process;
	}
}

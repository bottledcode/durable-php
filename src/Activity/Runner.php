<?php

namespace Bottledcode\DurablePhp\Activity;

use Bottledcode\DurablePhp\Activity\Command\ScheduleActivity;
use Bottledcode\DurablePhp\Activity\Command\SendActivityResult;
use Crell\Serde\Serde;
use Generator;
use Throwable;

readonly class Runner
{
    public function __construct(
        private Definition $definition,
        private ScheduleActivity $activity,
        private string $bootstrapScript,
        private Serde $serde,
    ) {
    }

    /**
     * @return Generator<SendActivityResult>
     */
    public function run(): Generator
    {
        // perform some assertions on the activity
        assert($this->activity->name === $this->definition->name);

        // load the code for the activity
        require_once $this->bootstrapScript;

        try {
            switch ($this->definition->returnType) {
                case ReturnType::Generator:
                    /**
                     * @var Generator $results
                     */
                    $results = call_user_func_array(
                        $this->definition->fullName,
                        $this->activity->input,
                    );
                    foreach ($results as $result) {
                        yield new SendActivityResult(
                            $this->serde->serialize($result, format: 'json'),
                            $this->activity->partitionId,
                            $this->activity->id,
                        );
                    }
                    return new SendActivityResult(
                        $this->serde->serialize($results->getReturn(), format: 'json'),
                        $this->activity->partitionId,
                        $this->activity->id,
                    );
                case ReturnType::Wait:
                    $result = call_user_func_array(
                        $this->definition->fullName,
                        $this->activity->input,
                    );
                    return new SendActivityResult(
                        $this->serde->serialize($result, format: 'json'),
                        $this->activity->partitionId,
                        $this->activity->id,
                    );
                case ReturnType::Void:
                    call_user_func_array(
                        $this->definition->fullName,
                        $this->activity->input,
                    );
                    break;
            }
        } catch (Throwable $exception) {
            return new SendActivityResult(
                $this->serde->serialize($exception, format: 'json'),
                $this->activity->partitionId,
                $this->activity->id,
                isError: true,
            );
        }
    }
}

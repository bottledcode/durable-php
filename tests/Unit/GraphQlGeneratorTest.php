<?php

use Bottledcode\DurablePhp\Gateway\Graph\SchemaExtractor;
use Bottledcode\DurablePhp\Gateway\Graph\TypeManager;
use Bottledcode\DurablePhp\Gateway\Graph\Union;

it('can render an entity', function (): void {
    //$this->markTestSkipped('manual verification');
    $testFile = <<<'PHP'
        <?php
        /*
         * Copyright ©2023 Robert Landers
         *
         * Permission is hereby granted, free of charge, to any person obtaining a copy
         * of this software and associated documentation files (the “Software”), to deal
         *  in the Software without restriction, including without limitation the rights
         *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
         *  copies of the Software, and to permit persons to whom the Software is
         *  furnished to do so, subject to the following conditions:
         *
         * The above copyright notice and this permission notice shall be included in
         * all copies or substantial portions of the Software.
         *
         * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
         * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
         * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
         * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
         * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
         * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
         * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
         */
        
        namespace Bottledcode\DurablePhp\Tests\PerformanceTests\Bank;
        
        use Bottledcode\DurablePhp\EntityContextInterface;
        use Bottledcode\DurablePhp\State\Attributes\Entity;use Bottledcode\DurablePhp\State\EntityState;
        
        #[Entity(hidden: false, name: "AccountEntity")]
        class Account extends EntityState implements AccountInterface
        {
            public int|float $balance = 0;
        
            public function __construct(private EntityContextInterface $context) {}
        
            public function add(int $amount): void
            {
                $this->balance += $amount;
            }
        
            public function reset(): void
            {
                $this->balance = 0;
            }
        
            public function get(): int
            {
                return $this->balance;
            }
        
            public function delete(): void
            {
                $this->context->delete();
            }
        }
        PHP;

    $newer = new SchemaExtractor(contents: $testFile);
    $newer->parse();

    expect($newer->getGraphQlName())->toBe('AccountEntity');
    expect($newer->dependsOn())->toBe(['int', 'float', 'void']);
    expect($newer->unions())->toEqual(['FloatOrInt' => new Union(['float', 'int'])]);

    $tm = new TypeManager();
    $tm->addType($newer->getPhpType(), $newer);

    expect(trim($tm->renderTypes()))->toBe(
        <<<'GQL'
            scalar DateTime
            scalar Void
            enum RuntimeStatus {
            	Running
            	Completed
            	ContinuedAsNew
            	Failed
            	Canceled
            	Terminated
            	Pending
            	Suspended
            	Unknown
            }
            type StateId {
            	id: String!
            }
            type AccountEntity {
            	balance: FloatOrInt!
            }
            union FloatOrInt = Float! | Int!
            type AccountEntityEntitySignal {
            	add(amount: Int!): Void
            	reset: Void
            	get: Void
            	delete: Void
            }
            type Mutation {
            	AccountEntity(id: ID!): AccountEntityEntitySignal
            
            }
            type Query {
            	AccountEntity(id: ID!): AccountEntity
            
            }
            GQL,
    );
});

it('can render an orchestration', function (): void {
    //$this->markTestSkipped('manual verification');
    $testFile = <<<'PHP'
        <?php
        /*
         * Copyright ©2024 Robert Landers
         *
         * Permission is hereby granted, free of charge, to any person obtaining a copy
         * of this software and associated documentation files (the “Software”), to deal
         *  in the Software without restriction, including without limitation the rights
         *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
         *  copies of the Software, and to permit persons to whom the Software is
         *  furnished to do so, subject to the following conditions:
         *
         * The above copyright notice and this permission notice shall be included in
         * all copies or substantial portions of the Software.
         *
         * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
         * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
         * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
         * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
         * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
         * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
         * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
         */
        
        namespace Bottledcode\DurablePhp\Tests\PerformanceTests\HelloCities;
        
        use Bottledcode\DurablePhp\OrchestrationContextInterface;
        use Bottledcode\DurablePhp\State\Attributes\AllowCreateAll;
        use Bottledcode\DurablePhp\State\Attributes\Orchestration;
        use Bottledcode\DurablePhp\Tests\Common\SayHello;
        
        #[AllowCreateAll]
        #[Orchestration]
        class HelloSequence
        {
            public int $executions = 0;
        
            /**
            * @param OrchestrationContextInterface $context
            * @return array<string>
            */
            public function __invoke(OrchestrationContextInterface $context): array
            {
                $outputs = [
                    $context->callActivity(SayHello::class, ['Tokyo']),
                    $context->callActivity(SayHello::class, ['Seattle']),
                    $context->callActivity(SayHello::class, ['London']),
                    $context->callActivity(SayHello::class, ['Amsterdam']),
                    $context->callActivity(SayHello::class, ['Seoul']),
                ];
        
                return $context->waitAll(...$outputs);
            }
        }
        PHP;

    $newer = new SchemaExtractor(contents: $testFile);
    $newer->parse();

    expect($newer->getGraphQlName())->toBe('HelloSequence');

    $tm = new TypeManager();
    $tm->addType($newer->getPhpType(), $newer);

    expect(trim($tm->renderTypes()))->toBe(
        <<<'GQL'
            scalar DateTime
            type Status {
            	createdAt: DateTime!
            	customStatus: String!
            	input: [Mixed]!
            	id: StateId!
            	lastUpdated: DateTime!
            	output: [Mixed]
            	runtimeStatus: RuntimeStatus!
            }
            enum RuntimeStatus {
            	Running
            	Completed
            	ContinuedAsNew
            	Failed
            	Canceled
            	Terminated
            	Pending
            	Suspended
            	Unknown
            }
            type StateId {
            	id: String!
            }
            input OrchestrationInstanceInput {
            	instanceId: String!
            	executionId: String!
            }
            type HelloSequenceOrchestration {
            	Start(id: OrchestrationInstanceInput): Status!
            	Signal(id: OrchestrationInstanceInput, name: String!, message: [Mixed]!): Void
            }
            type Mutation {
            	HelloSequence: HelloSequenceOrchestration
            
            }
            type HelloSequenceQuery {
            	Status: Status!
            }
            type Query {
            	HelloSequence(id: OrchestrationInstanceInput): HelloSequenceQuery
            
            }
            GQL,
    );
});

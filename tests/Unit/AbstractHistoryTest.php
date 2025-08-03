<?php

/*
 * Copyright ©2025 Robert Landers
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

use Bottledcode\DurablePhp\DurableLogger;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Glue\Provenance;
use Bottledcode\DurablePhp\State\AbstractHistory;
use Bottledcode\DurablePhp\State\Attributes\AllowAnyOperation;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateAll;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateForAuth;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateForRole;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateForUser;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateFrom;
use Bottledcode\DurablePhp\State\Attributes\DenyAnyOperation;
use Bottledcode\DurablePhp\State\Ids\StateId;

use function Bottledcode\DurablePhp\EntityId;
use function Bottledcode\DurablePhp\OrchestrationInstance;

// Create a concrete implementation of AbstractHistory for testing
class TestableAbstractHistory extends AbstractHistory
{
    public StateId $id;

    public function __construct(StateId $id, ?DurableLogger $logger = null, ?Provenance $user = null)
    {
        // Store the ID for testing
        $this->id = $id;
    }

    public function checkAccessControlPublic(?Provenance $user, StateId $from, ReflectionAttribute ...$accessControls): bool
    {
        return $this->checkAccessControl($user, $from, ...$accessControls);
    }

    public function resetState(): void
    {
        // Not needed for testing checkAccessControl
    }

    public function ackedEvent(Event $event): void
    {
        // Not needed for testing checkAccessControl
    }

    public function setLogger(DurableLogger $logger): void
    {
        // Not needed for testing checkAccessControl
    }

    public function hasAppliedEvent(Event $event): bool
    {
        // Not needed for testing checkAccessControl
        return false;
    }
}

// Helper function to create a ReflectionAttribute for testing
function createAttribute(string $attributeClass, array $args = []): ReflectionAttribute
{
    $class = new class ($attributeClass, $args) extends ReflectionAttribute {
        private string $attributeClass;

        private array $args;

        public function __construct(string $attributeClass, array $args = [])
        {
            $this->attributeClass = $attributeClass;
            $this->args = $args;
        }

        public function getName(): string
        {
            return $this->attributeClass;
        }

        public function getArguments(): array
        {
            return $this->args;
        }

        public function newInstance(): object
        {
            return new $this->attributeClass(...$this->args);
        }
    };

    return $class;
}

// Test with empty access controls
it('returns true with empty access controls', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = new Provenance('user1', ['role1']);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);

    $result = $history->checkAccessControlPublic($user, $from);

    expect($result)->toBeTrue();
});

// Test with AllowCreateAll
it('returns true with AllowCreateAll', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = new Provenance('user1', ['role1']);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateAll::class);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

// Test with AllowCreateForAuth
it('returns true with AllowCreateForAuth when user is authenticated', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = new Provenance('user1', ['role1']);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateForAuth::class);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns false with AllowCreateForAuth when user is not authenticated', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = new Provenance('', []);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateForAuth::class);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

it('returns false with AllowCreateForAuth when user is null', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = null;
    $history = new TestableAbstractHistory($from, new DurableLogger(), new Provenance('', []));
    $attr = createAttribute(AllowCreateForAuth::class);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

// Test with AllowCreateForRole
it('returns true with AllowCreateForRole when user has the role', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = new Provenance('user1', ['role1', 'role2']);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateForRole::class, ['role' => 'role1']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns false with AllowCreateForRole when user does not have the role', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = new Provenance('user1', ['role1', 'role2']);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateForRole::class, ['role' => 'role3']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

// Test with AllowCreateForUser
it('returns true with AllowCreateForUser when user ID matches', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = new Provenance('user1', ['role1']);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateForUser::class, ['user' => 'user1']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns false with AllowCreateForUser when user ID does not match', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = new Provenance('user1', ['role1']);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateForUser::class, ['user' => 'user2']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

it('returns false with AllowCreateForUser when user is null', function (): void {
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $user = null;
    $history = new TestableAbstractHistory($from, new DurableLogger(), new Provenance('', []));
    $attr = createAttribute(AllowCreateForUser::class, ['user' => 'user1']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

// Test with AllowCreateFrom
it('returns true with AllowCreateFrom when entity ID matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $entityId = EntityId('test', 'test');
    $from = StateId::fromEntityId($entityId);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateFrom::class, ['id' => $entityId]);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns true with AllowCreateFrom when entity type matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateFrom::class, ['type' => 'test']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns true with AllowCreateFrom when orchestration ID matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $instance = OrchestrationInstance('test', 'test');
    $from = StateId::fromInstance($instance);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateFrom::class, ['id' => $instance]);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns true with AllowCreateFrom when orchestration instance ID matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromInstance(OrchestrationInstance('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowCreateFrom::class, ['type' => 'test']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

// Test with AllowAnyOperation
it('returns true with AllowAnyOperation when user ID matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowAnyOperation::class, ['fromUser' => 'user1']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns true with AllowAnyOperation when role matches', function (): void {
    $user = new Provenance('user1', ['role1', 'role2']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowAnyOperation::class, ['fromRole' => 'role2']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns true with AllowAnyOperation when entity ID matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $entityId = EntityId('test', 'test');
    $from = StateId::fromEntityId($entityId);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowAnyOperation::class, ['fromId' => $entityId]);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns true with AllowAnyOperation when entity type matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowAnyOperation::class, ['fromType' => 'test']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeTrue();
});

it('returns false with AllowAnyOperation when no conditions match', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(AllowAnyOperation::class, [
        'fromUser' => 'user2',
        'fromRole' => 'role2',
        'fromId' => EntityId('other', 'other'),
        'fromType' => 'other',
    ]);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

// Test with DenyAnyOperation
it('returns false with DenyAnyOperation when user ID matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(DenyAnyOperation::class, ['fromUser' => 'user1']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

it('returns false with DenyAnyOperation when role matches', function (): void {
    $user = new Provenance('user1', ['role1', 'role2']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(DenyAnyOperation::class, ['fromRole' => 'role2']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

it('returns false with DenyAnyOperation when entity ID matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $entityId = EntityId('test', 'test');
    $from = StateId::fromEntityId($entityId);
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(DenyAnyOperation::class, ['fromId' => $entityId]);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

it('returns false with DenyAnyOperation when entity type matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr = createAttribute(DenyAnyOperation::class, ['fromType' => 'test']);

    $result = $history->checkAccessControlPublic($user, $from, $attr);

    expect($result)->toBeFalse();
});

// Test with multiple attributes
it('returns true if any allow attribute matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr1 = createAttribute(AllowCreateForUser::class, ['user' => 'user2']);
    $attr2 = createAttribute(AllowCreateForUser::class, ['user' => 'user1']);

    $result = $history->checkAccessControlPublic($user, $from, $attr1, $attr2);

    expect($result)->toBeTrue();
});

it('returns false if no allow attribute matches', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr1 = createAttribute(AllowCreateForUser::class, ['user' => 'user2']);
    $attr2 = createAttribute(AllowCreateForUser::class, ['user' => 'user3']);

    $result = $history->checkAccessControlPublic($user, $from, $attr1, $attr2);

    expect($result)->toBeFalse();
});

it('returns false if a deny attribute matches even if allow attributes match', function (): void {
    $user = new Provenance('user1', ['role1']);
    $from = StateId::fromEntityId(EntityId('test', 'test'));
    $history = new TestableAbstractHistory($from, new DurableLogger(), $user);
    $attr1 = createAttribute(AllowCreateAll::class);
    $attr2 = createAttribute(DenyAnyOperation::class, ['fromUser' => 'user1']);

    $result = $history->checkAccessControlPublic($user, $from, $attr1, $attr2);

    expect($result)->toBeFalse();
});

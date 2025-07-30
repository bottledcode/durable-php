<?php

namespace Bottledcode\DurablePhp\Contexts\AuthContext;

use Bottledcode\DurablePhp\Contexts\AuthContext\Share\Owner;
use Bottledcode\DurablePhp\Contexts\AuthContext\Share\Role;
use Bottledcode\DurablePhp\Contexts\AuthContext\Share\User;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use ReflectionClass;

function Owner(string $subject): Owner
{
    $ref = new ReflectionClass(Owner::class);

    return $ref->getMethod('fromArgs')->invoke(null, subject: $subject, allowed: [Operation::Owner]);
}

function Role(string $subject, Operation ...$allowed): Role
{
    $ref = new ReflectionClass(Role::class);

    return $ref->getMethod('fromArgs')->invoke(null, subject: $subject, allowed: $allowed);
}

function User(string $subject, Operation ...$allowed): User
{
    $ref = new ReflectionClass(User::class);

    return $ref->getMethod('fromArgs')->invoke(null, subject: $subject, allowed: $allowed);
}

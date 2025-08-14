<?php

/** @generate-class-entries */

namespace Bottledcode\DurablePhp\Ext;

function emit_event(?array $userContext, array $event, string $from): int {}

class Worker {

    public function __construct() {}

    public static function GetCurrent(): ?Worker {}

    public function __destruct() {}

    public function queryState(string $stateId): array {}

    public function getUser(): ?array {}

    public function getSource(): string {}

    public function getCurrentId(): string {}

    public function getCorrelationId(): string {}

    public function getState(): ?array {}

    public function updateState(array $state): void {}

    public function emitEvent(array $eventDescription): void {}

    public function delete(): void {}

}



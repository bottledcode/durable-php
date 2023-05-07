<?php

namespace Bottledcode\DurablePhp\Infrastructure;

interface OrchestrationServiceInterface {

    /**
     * Start the service.
     * @return void
     */
    public function start(): void;

    /**
     * Stop the service.
     * @param bool $force
     * @return void
     */
    public function stop(bool $force = false): void;

    /**
     * Create all resources needed for the service.
     * @param bool $recreateInstanceStore
     * @return void
     */
    public function create(bool $recreateInstanceStore = false): void;

    /**
     * Create all resources needed for the service if they do not exist.
     * @return void
     */
    public function createIfNotExists(): void;

    /**
     * Delete all resources needed for the service.
     * @return void
     */
    public function delete(): void;

    public function getDelayInSecondsAfterOnProcessException(\Throwable $exception): int;

    public function getDelayInSecondsAfterOnFetchException(\Throwable $exception): int;

    public function getTaskOrchestrationDispatchCount(): int;

    public function getMaxConcurrentTaskOrchestrationWorkItems(): int;
}

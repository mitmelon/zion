<?php
namespace ZionXMemory\Contracts;

/**
 * Core Memory Interface
 * All memory operations must go through this contract
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface MemoryInterface {
    public function store(string $tenantId, string $agentId, array $data): string;
    public function retrieve(string $tenantId, array $query): array;
    public function updateEpistemicState(string $tenantId, string $memoryId, array $stateUpdate): bool;
    public function logicalDelete(string $tenantId, string $memoryId, string $reason): bool;
    public function getMemoryLineage(string $tenantId, string $memoryId): array;
}
<?php
namespace ZionXMemory\Contracts;
/**
 * Gnosis Interface
 * Epistemic state management
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface GnosisInterface {
    public function recordBelief(string $tenantId, string $claim, array $confidence, array $provenance): string;
    public function updateBeliefState(string $tenantId, string $beliefId, string $newState, string $reason): bool;
    public function getBeliefHistory(string $tenantId, string $beliefId): array;
    public function findContradictions(string $tenantId, string $beliefId): array;
    public function getEpistemicSnapshot(string $tenantId, int $timestamp): array;
}
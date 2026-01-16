<?php
namespace ZionXMemory\Contracts;
/**
 * Audit Interface
 * Tamper-evident logging
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface AuditInterface {
    public function log(string $tenantId, string $operation, array $data, array $context): string;
    public function verify(string $auditId): bool;
    public function getAuditTrail(string $tenantId, array $filters): array;
    public function replay(string $tenantId, int $fromTimestamp, int $toTimestamp): array;
}
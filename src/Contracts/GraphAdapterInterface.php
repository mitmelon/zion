<?php
namespace ZionXMemory\Contracts;
/**
 * Graph Adapter Interface
 * Temporal property graph operations
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface GraphAdapterInterface {
    public function addNode(string $tenantId, string $nodeId, string $type, array $properties, int $timestamp): bool;
    public function addEdge(string $tenantId, string $fromId, string $toId, string $relationType, array $properties, int $timestamp): bool;
    public function queryGraph(string $tenantId, array $pattern): array;
    public function getTemporalHistory(string $tenantId, string $nodeId): array;
    public function findContradictions(string $tenantId, string $nodeId): array;
}
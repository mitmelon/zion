<?php
namespace ZionXMemory\Contracts;
/**
 * AI Adapter Interface
 * Model-agnostic AI operations
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface AIAdapterInterface {
    public function configure(array $config): void;
    public function summarize(string $content, array $options): string;
    public function extractEntities(string $content): array;
    public function extractRelationships(string $content): array;
    public function extractStructure(string $content): array;
    public function extractEntitiesBatch(array $contents): array;
    public function extractRelationshipsBatch(array $contents): array;
    public function extractStructureBatch(array $contents): array;
    public function extractClaims(string $content): array;
    public function scoreEpistemicConfidence(string $claim, array $context): array;
    public function detectContradiction(string $claim1, string $claim2): ?bool;
    public function processMultimodal(array $inputs): array;
    public function getModelInfo(): array;
}
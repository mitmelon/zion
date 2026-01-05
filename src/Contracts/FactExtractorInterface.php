<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface FactExtractorInterface
 * 
 * Defines the contract for AI-powered fact extraction from responses.
 * Used by Graph RAG to extract structured facts and relationships.
 * 
 * @package Zion\Memory\Contracts
 */
interface FactExtractorInterface
{
    /**
     * Extract structured facts from AI response text.
     *
     * @param string $text Text to extract facts from
     * @param array $context Optional context for better extraction
     * @return array Array of extracted facts with structure:
     *               [
     *                   'entity' => string,
     *                   'type' => string,
     *                   'attributes' => array,
     *                   'confidence' => float,
     *                   'source' => string
     *               ]
     */
    public function extractFacts(string $text, array $context = []): array;

    /**
     * Extract relationships between entities from text.
     *
     * @param string $text Text to extract relationships from
     * @param array $knownEntities Optional array of known entities for context
     * @return array Array of extracted relationships with structure:
     *               [
     *                   'from_entity' => string,
     *                   'to_entity' => string,
     *                   'relation_type' => string,
     *                   'confidence' => float,
     *                   'context' => string
     *               ]
     */
    public function extractRelationships(string $text, array $knownEntities = []): array;

    /**
     * Extract both facts and relationships in a single pass.
     *
     * @param string $text Text to analyze
     * @param array $context Optional context
     * @return array Array with 'facts' and 'relationships' keys
     */
    public function extractAll(string $text, array $context = []): array;

    /**
     * Extract facts with specific entity types focus.
     *
     * @param string $text Text to extract facts from
     * @param array $entityTypes Types of entities to focus on (e.g., 'person', 'account', 'transaction')
     * @return array Array of extracted facts
     */
    public function extractByTypes(string $text, array $entityTypes): array;

    /**
     * Normalize entity names for consistency.
     *
     * @param string $entityName Raw entity name
     * @param string $entityType Entity type for context
     * @return string Normalized entity name
     */
    public function normalizeEntity(string $entityName, string $entityType): string;

    /**
     * Get the extraction model/service being used.
     *
     * @return string Model identifier
     */
    public function getModel(): string;

    /**
     * Set configuration options for the extractor.
     *
     * @param array $config Configuration options
     * @return void
     */
    public function configure(array $config): void;
}

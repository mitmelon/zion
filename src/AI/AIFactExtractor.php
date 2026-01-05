<?php

declare(strict_types=1);

namespace Zion\Memory\AI;

use Zion\Memory\Contracts\FactExtractorInterface;
use Zion\Memory\Contracts\AIProviderInterface;

/**
 * Class AIFactExtractor
 * 
 * AI-powered fact extractor for Graph RAG.
 * Extracts structured facts and relationships from AI responses.
 * 
 * @package Zion\Memory\AI
 */
class AIFactExtractor implements FactExtractorInterface
{
    /**
     * @var AIProviderInterface AI provider
     */
    private AIProviderInterface $aiProvider;

    /**
     * @var array Configuration options
     */
    private array $config = [
        'min_confidence' => 0.7,
        'extract_banking_entities' => true,
        'normalize_entities' => true,
        'entity_types' => [
            'person',
            'organization',
            'account',
            'transaction',
            'amount',
            'date',
            'location',
            'product',
            'policy',
        ],
    ];

    /**
     * Constructor.
     *
     * @param AIProviderInterface $aiProvider AI provider instance
     * @param array $config Optional configuration
     */
    public function __construct(AIProviderInterface $aiProvider, array $config = [])
    {
        $this->aiProvider = $aiProvider;
        $this->config = array_merge($this->config, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function extractFacts(string $text, array $context = []): array
    {
        if (empty(trim($text))) {
            return [];
        }

        $entityTypes = implode(', ', $this->config['entity_types']);
        $contextInfo = !empty($context) ? "\n\nCONTEXT:\n" . json_encode($context, JSON_PRETTY_PRINT) : '';

        $prompt = <<<PROMPT
You are an expert fact extractor for a banking AI system. Extract structured facts from the following text.

TEXT TO ANALYZE:
{$text}
{$contextInfo}

ENTITY TYPES TO EXTRACT: {$entityTypes}

INSTRUCTIONS:
1. Extract all factual information as structured entities
2. Assign confidence scores (0.0-1.0) based on certainty
3. Include only clearly stated facts, not inferences
4. For banking context: prioritize account numbers, transaction amounts, dates, customer names
5. Normalize entity names consistently
6. Include the source text snippet for each fact

OUTPUT FORMAT (JSON):
{
    "facts": [
        {
            "entity": "entity_name",
            "type": "entity_type",
            "attributes": {"key": "value"},
            "confidence": 0.95,
            "source": "relevant text snippet"
        }
    ]
}
PROMPT;

        $result = $this->aiProvider->structuredOutput($prompt, [
            'type' => 'object',
            'properties' => [
                'facts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'entity' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'attributes' => ['type' => 'object'],
                            'confidence' => ['type' => 'number'],
                            'source' => ['type' => 'string']
                        ]
                    ]
                ]
            ]
        ], ['temperature' => 0.2]);

        $facts = $result['facts'] ?? [];
        
        // Filter by minimum confidence
        $facts = array_filter($facts, fn($f) => ($f['confidence'] ?? 0) >= $this->config['min_confidence']);
        
        // Normalize entities if configured
        if ($this->config['normalize_entities']) {
            $facts = array_map(function ($fact) {
                $fact['entity'] = $this->normalizeEntity($fact['entity'], $fact['type'] ?? 'unknown');
                return $fact;
            }, $facts);
        }
        
        // Add extraction metadata
        $facts = array_map(function ($fact) {
            $fact['extracted_at'] = time();
            return $fact;
        }, $facts);

        return array_values($facts);
    }

    /**
     * {@inheritdoc}
     */
    public function extractRelationships(string $text, array $knownEntities = []): array
    {
        if (empty(trim($text))) {
            return [];
        }

        $entitiesContext = !empty($knownEntities) 
            ? "\n\nKNOWN ENTITIES:\n" . json_encode($knownEntities, JSON_PRETTY_PRINT)
            : '';

        $prompt = <<<PROMPT
You are an expert relationship extractor for a banking AI system. Extract relationships between entities from the following text.

TEXT TO ANALYZE:
{$text}
{$entitiesContext}

RELATIONSHIP TYPES TO LOOK FOR:
- owns (person owns account)
- works_at (person works at organization)
- has_transaction (account has transaction)
- transferred_to (transaction transferred to account)
- authorized_for (person authorized for account)
- related_to (general relationship)
- requested (person requested service)
- applied_for (person applied for product)

INSTRUCTIONS:
1. Extract all explicit relationships between entities
2. Assign confidence scores based on how clearly the relationship is stated
3. Include the context/evidence for each relationship
4. Ensure from_entity and to_entity are clearly identified

OUTPUT FORMAT (JSON):
{
    "relationships": [
        {
            "from_entity": "entity_name_1",
            "to_entity": "entity_name_2",
            "relation_type": "relationship_type",
            "confidence": 0.9,
            "context": "relevant text describing the relationship"
        }
    ]
}
PROMPT;

        $result = $this->aiProvider->structuredOutput($prompt, [
            'type' => 'object',
            'properties' => [
                'relationships' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from_entity' => ['type' => 'string'],
                            'to_entity' => ['type' => 'string'],
                            'relation_type' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'context' => ['type' => 'string']
                        ]
                    ]
                ]
            ]
        ], ['temperature' => 0.2]);

        $relationships = $result['relationships'] ?? [];
        
        // Filter by minimum confidence
        $relationships = array_filter(
            $relationships, 
            fn($r) => ($r['confidence'] ?? 0) >= $this->config['min_confidence']
        );
        
        // Add extraction metadata
        $relationships = array_map(function ($rel) {
            $rel['extracted_at'] = time();
            return $rel;
        }, $relationships);

        return array_values($relationships);
    }

    /**
     * {@inheritdoc}
     */
    public function extractAll(string $text, array $context = []): array
    {
        if (empty(trim($text))) {
            return ['facts' => [], 'relationships' => []];
        }

        $entityTypes = implode(', ', $this->config['entity_types']);
        $contextInfo = !empty($context) ? "\n\nCONTEXT:\n" . json_encode($context, JSON_PRETTY_PRINT) : '';

        $prompt = <<<PROMPT
You are an expert knowledge extractor for a banking AI system. Extract both facts and relationships from the following text.

TEXT TO ANALYZE:
{$text}
{$contextInfo}

ENTITY TYPES: {$entityTypes}

RELATIONSHIP TYPES:
- owns, works_at, has_transaction, transferred_to, authorized_for, related_to, requested, applied_for

INSTRUCTIONS:
1. Extract all factual entities with their attributes
2. Extract all relationships between entities
3. Assign confidence scores (0.0-1.0)
4. Include source text snippets
5. Focus on banking-relevant information

OUTPUT FORMAT (JSON):
{
    "facts": [
        {
            "entity": "entity_name",
            "type": "entity_type",
            "attributes": {"key": "value"},
            "confidence": 0.95,
            "source": "text snippet"
        }
    ],
    "relationships": [
        {
            "from_entity": "entity_1",
            "to_entity": "entity_2",
            "relation_type": "type",
            "confidence": 0.9,
            "context": "relationship context"
        }
    ]
}
PROMPT;

        $result = $this->aiProvider->structuredOutput($prompt, [
            'type' => 'object',
            'properties' => [
                'facts' => ['type' => 'array'],
                'relationships' => ['type' => 'array']
            ]
        ], ['temperature' => 0.2]);

        $facts = $result['facts'] ?? [];
        $relationships = $result['relationships'] ?? [];
        
        // Filter and normalize
        $facts = array_filter($facts, fn($f) => ($f['confidence'] ?? 0) >= $this->config['min_confidence']);
        $relationships = array_filter($relationships, fn($r) => ($r['confidence'] ?? 0) >= $this->config['min_confidence']);
        
        if ($this->config['normalize_entities']) {
            $facts = array_map(function ($fact) {
                $fact['entity'] = $this->normalizeEntity($fact['entity'], $fact['type'] ?? 'unknown');
                return $fact;
            }, $facts);
        }
        
        // Add metadata
        $timestamp = time();
        $facts = array_map(fn($f) => array_merge($f, ['extracted_at' => $timestamp]), $facts);
        $relationships = array_map(fn($r) => array_merge($r, ['extracted_at' => $timestamp]), $relationships);

        return [
            'facts' => array_values($facts),
            'relationships' => array_values($relationships),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function extractByTypes(string $text, array $entityTypes): array
    {
        $originalTypes = $this->config['entity_types'];
        $this->config['entity_types'] = $entityTypes;
        
        $facts = $this->extractFacts($text);
        
        $this->config['entity_types'] = $originalTypes;
        
        return $facts;
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeEntity(string $entityName, string $entityType): string
    {
        // Basic normalization rules
        $normalized = trim($entityName);
        
        switch (strtolower($entityType)) {
            case 'person':
                // Capitalize each word
                $normalized = ucwords(strtolower($normalized));
                break;
                
            case 'organization':
                // Preserve original capitalization, just trim
                $normalized = trim($normalized);
                break;
                
            case 'account':
                // Remove spaces, uppercase
                $normalized = strtoupper(preg_replace('/\s+/', '', $normalized));
                break;
                
            case 'amount':
                // Normalize currency format
                $normalized = preg_replace('/[^0-9.,]/', '', $normalized);
                break;
                
            case 'date':
                // Try to parse and format consistently
                $timestamp = strtotime($normalized);
                if ($timestamp !== false) {
                    $normalized = date('Y-m-d', $timestamp);
                }
                break;
                
            default:
                $normalized = trim($normalized);
        }
        
        return $normalized;
    }

    /**
     * {@inheritdoc}
     */
    public function getModel(): string
    {
        return $this->aiProvider->getProviderName();
    }

    /**
     * {@inheritdoc}
     */
    public function configure(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }
}

<?php

declare(strict_types=1);

namespace Zion\Memory\Graph;

use Zion\Memory\Contracts\GraphMemoryAdapter;
use Zion\Memory\Contracts\FactExtractorInterface;
use Zion\Memory\Contracts\FactValidatorInterface;
use Zion\Memory\Contracts\ConflictResolverInterface;
use Zion\Memory\Contracts\AuditLoggerInterface;
use Zion\Memory\Contracts\ValidationResult;
use Ramsey\Uuid\Uuid;

/**
 * Class GraphMemory
 * 
 * Graph RAG implementation for structured factual memory.
 * Stores facts and relationships extracted from AI responses.
 * Supports graph queries for multi-agent reasoning.
 * 
 * @package Zion\Memory\Graph
 */
class GraphMemory
{
    /**
     * @var GraphMemoryAdapter Graph storage adapter
     */
    private GraphMemoryAdapter $storage;

    /**
     * @var FactExtractorInterface Fact extractor
     */
    private FactExtractorInterface $factExtractor;

    /**
     * @var FactValidatorInterface Fact validator
     */
    private FactValidatorInterface $factValidator;

    /**
     * @var ConflictResolverInterface Conflict resolver
     */
    private ConflictResolverInterface $conflictResolver;

    /**
     * @var AuditLoggerInterface|null Audit logger
     */
    private ?AuditLoggerInterface $auditLogger;

    /**
     * @var array Configuration
     */
    private array $config = [
        'auto_extract_facts' => true,
        'auto_validate' => true,
        'conflict_strategy' => ConflictResolverInterface::STRATEGY_LATEST_WINS,
        'min_confidence' => 0.7,
        'store_contradictions' => true,
    ];

    /**
     * Constructor.
     *
     * @param GraphMemoryAdapter $storage Graph storage adapter
     * @param FactExtractorInterface $factExtractor Fact extractor
     * @param FactValidatorInterface $factValidator Fact validator
     * @param ConflictResolverInterface $conflictResolver Conflict resolver
     * @param AuditLoggerInterface|null $auditLogger Optional audit logger
     * @param array $config Configuration options
     */
    public function __construct(
        GraphMemoryAdapter $storage,
        FactExtractorInterface $factExtractor,
        FactValidatorInterface $factValidator,
        ConflictResolverInterface $conflictResolver,
        ?AuditLoggerInterface $auditLogger = null,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->factExtractor = $factExtractor;
        $this->factValidator = $factValidator;
        $this->conflictResolver = $conflictResolver;
        $this->auditLogger = $auditLogger;
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Ingest text and extract/store facts automatically.
     *
     * @param string $tenantId Tenant ID
     * @param string $text Text to extract facts from
     * @param array $context Additional context
     * @return array Extraction result with facts and relationships
     */
    public function ingestText(string $tenantId, string $text, array $context = []): array
    {
        // Extract facts and relationships
        $extracted = $this->factExtractor->extractAll($text, $context);
        
        $result = [
            'facts' => [
                'stored' => [],
                'conflicts' => [],
                'rejected' => [],
            ],
            'relationships' => [
                'stored' => [],
                'rejected' => [],
            ],
        ];

        // Process facts
        foreach ($extracted['facts'] as $fact) {
            $processResult = $this->processFact($tenantId, $fact);
            
            if ($processResult['stored']) {
                $result['facts']['stored'][] = $processResult['fact'];
            } elseif (!empty($processResult['conflict'])) {
                $result['facts']['conflicts'][] = $processResult;
            } else {
                $result['facts']['rejected'][] = [
                    'fact' => $fact,
                    'reason' => $processResult['reason'] ?? 'unknown',
                ];
            }
        }

        // Process relationships
        foreach ($extracted['relationships'] as $relationship) {
            $stored = $this->storeRelationship(
                $tenantId,
                $relationship['from_entity'],
                $relationship['to_entity'],
                $relationship['relation_type'],
                $relationship
            );
            
            if ($stored) {
                $result['relationships']['stored'][] = $relationship;
            } else {
                $result['relationships']['rejected'][] = $relationship;
            }
        }

        $this->log($tenantId, AuditLoggerInterface::ACTION_FACT_EXTRACTED, [
            'text_length' => strlen($text),
            'facts_extracted' => count($extracted['facts']),
            'facts_stored' => count($result['facts']['stored']),
            'relationships_extracted' => count($extracted['relationships']),
            'relationships_stored' => count($result['relationships']['stored']),
        ]);

        return $result;
    }

    /**
     * Store a fact with validation and conflict resolution.
     *
     * @param string $tenantId Tenant ID
     * @param array $fact Fact to store
     * @param bool $skipValidation Skip validation
     * @return array Process result
     */
    public function processFact(string $tenantId, array $fact, bool $skipValidation = false): array
    {
        $result = [
            'stored' => false,
            'fact' => null,
            'conflict' => null,
            'resolution' => null,
            'reason' => null,
        ];

        // Validate the fact
        if (!$skipValidation && $this->config['auto_validate']) {
            $validation = $this->factValidator->validate($tenantId, $fact, $this->storage);
            
            if ($validation->isDuplicate()) {
                $result['reason'] = 'duplicate';
                $result['fact'] = $validation->duplicate;
                return $result;
            }

            if ($validation->hasContradictions()) {
                // Handle contradictions
                foreach ($validation->contradictions as $contradiction) {
                    $resolution = $this->conflictResolver->resolve(
                        $contradiction,
                        $fact,
                        $this->config['conflict_strategy']
                    );

                    $result['conflict'] = [
                        'existing' => $contradiction,
                        'new' => $fact,
                    ];
                    $result['resolution'] = $resolution->toArray();

                    // Log the contradiction
                    $this->log($tenantId, AuditLoggerInterface::ACTION_CONTRADICTION_DETECTED, [
                        'existing_fact' => $contradiction,
                        'new_fact' => $fact,
                        'resolution' => $resolution->toArray(),
                    ]);

                    // Apply resolution
                    if ($resolution->action === 'use_new' || $resolution->action === 'merge') {
                        $fact = $resolution->resolvedFact;
                        
                        // Update the existing fact
                        if (isset($contradiction['id'])) {
                            $this->storage->deleteFact($tenantId, $contradiction['id']);
                        }
                    } elseif ($resolution->action === 'keep_existing') {
                        $result['reason'] = 'conflict_resolved_keep_existing';
                        return $result;
                    }
                }
            }
        }

        // Store the fact
        $fact['id'] = $fact['id'] ?? Uuid::uuid4()->toString();
        $factId = $this->storage->storeFact($tenantId, $fact);
        
        $fact['id'] = $factId;
        $result['stored'] = true;
        $result['fact'] = $fact;

        $this->log($tenantId, AuditLoggerInterface::ACTION_FACT_STORED, [
            'fact_id' => $factId,
            'entity' => $fact['entity'] ?? null,
            'type' => $fact['type'] ?? null,
        ]);

        return $result;
    }

    /**
     * Store a relationship.
     *
     * @param string $tenantId Tenant ID
     * @param string $fromEntity From entity name/ID
     * @param string $toEntity To entity name/ID
     * @param string $relationType Relationship type
     * @param array $metadata Additional metadata
     * @return string|null Relationship ID or null on failure
     */
    public function storeRelationship(
        string $tenantId,
        string $fromEntity,
        string $toEntity,
        string $relationType,
        array $metadata = []
    ): ?string {
        // Resolve entity IDs
        $fromEntityData = $this->storage->queryByEntity($tenantId, $fromEntity);
        $toEntityData = $this->storage->queryByEntity($tenantId, $toEntity);

        // If entities don't exist, create placeholder facts
        if (!$fromEntityData) {
            $fromEntityId = $this->storage->storeFact($tenantId, [
                'entity' => $fromEntity,
                'type' => 'unknown',
                'attributes' => [],
                'auto_created' => true,
            ]);
        } else {
            $fromEntityId = $fromEntityData['id'];
        }

        if (!$toEntityData) {
            $toEntityId = $this->storage->storeFact($tenantId, [
                'entity' => $toEntity,
                'type' => 'unknown',
                'attributes' => [],
                'auto_created' => true,
            ]);
        } else {
            $toEntityId = $toEntityData['id'];
        }

        return $this->storage->storeRelationship(
            $tenantId,
            $fromEntityId,
            $toEntityId,
            $relationType,
            $metadata
        );
    }

    /**
     * Query facts by entity name.
     *
     * @param string $tenantId Tenant ID
     * @param string $entityName Entity name
     * @return array|null Fact or null
     */
    public function queryEntity(string $tenantId, string $entityName): ?array
    {
        $fact = $this->storage->queryByEntity($tenantId, $entityName);
        
        $this->log($tenantId, AuditLoggerInterface::ACTION_GRAPH_QUERY, [
            'query_type' => 'entity',
            'entity' => $entityName,
            'found' => $fact !== null,
        ]);

        return $fact;
    }

    /**
     * Query facts by type.
     *
     * @param string $tenantId Tenant ID
     * @param string $entityType Entity type
     * @param array $filters Additional filters
     * @return array Facts
     */
    public function queryByType(string $tenantId, string $entityType, array $filters = []): array
    {
        $facts = $this->storage->queryByType($tenantId, $entityType, $filters);
        
        $this->log($tenantId, AuditLoggerInterface::ACTION_GRAPH_QUERY, [
            'query_type' => 'type',
            'entity_type' => $entityType,
            'filters' => $filters,
            'result_count' => count($facts),
        ]);

        return $facts;
    }

    /**
     * Search facts.
     *
     * @param string $tenantId Tenant ID
     * @param array $criteria Search criteria
     * @return array Matching facts
     */
    public function search(string $tenantId, array $criteria): array
    {
        return $this->storage->searchFacts($tenantId, $criteria);
    }

    /**
     * Get entity relationships.
     *
     * @param string $tenantId Tenant ID
     * @param string $entityName Entity name
     * @param string|null $relationType Filter by relationship type
     * @return array Relationships
     */
    public function getRelationships(
        string $tenantId,
        string $entityName,
        ?string $relationType = null
    ): array {
        $entity = $this->storage->queryByEntity($tenantId, $entityName);
        
        if (!$entity) {
            return [];
        }

        return $this->storage->getRelationships($tenantId, $entity['id'], $relationType);
    }

    /**
     * Find related entities within N degrees of separation.
     *
     * @param string $tenantId Tenant ID
     * @param string $entityName Starting entity
     * @param int $depth Maximum degrees
     * @param array $relationTypes Filter by types
     * @return array Related entities
     */
    public function findRelated(
        string $tenantId,
        string $entityName,
        int $depth = 2,
        array $relationTypes = []
    ): array {
        $entity = $this->storage->queryByEntity($tenantId, $entityName);
        
        if (!$entity) {
            return [];
        }

        return $this->storage->findRelatedEntities($tenantId, $entity['id'], $depth, $relationTypes);
    }

    /**
     * Build context from graph for AI prompt.
     *
     * @param string $tenantId Tenant ID
     * @param array $entities Entity names to include
     * @param int $depth Relationship depth
     * @return array Context data
     */
    public function buildContext(string $tenantId, array $entities, int $depth = 1): array
    {
        $context = [
            'entities' => [],
            'relationships' => [],
            'metadata' => [
                'tenant_id' => $tenantId,
                'built_at' => time(),
            ],
        ];

        foreach ($entities as $entityName) {
            $entity = $this->storage->queryByEntity($tenantId, $entityName);
            
            if ($entity) {
                $context['entities'][] = $entity;
                
                // Get relationships
                $related = $this->storage->findRelatedEntities(
                    $tenantId,
                    $entity['id'],
                    $depth
                );
                
                foreach ($related as $relatedData) {
                    $context['entities'][] = $relatedData['entity'];
                    foreach ($relatedData['path'] as $relationship) {
                        $context['relationships'][] = $relationship;
                    }
                }
            }
        }

        // Remove duplicates
        $context['entities'] = array_values(array_unique($context['entities'], SORT_REGULAR));
        $context['relationships'] = array_values(array_unique($context['relationships'], SORT_REGULAR));

        return $context;
    }

    /**
     * Build formatted prompt context string from graph.
     *
     * @param string $tenantId Tenant ID
     * @param array $entities Entity names
     * @param int $depth Relationship depth
     * @return string Formatted context
     */
    public function buildPromptContext(string $tenantId, array $entities, int $depth = 1): string
    {
        $context = $this->buildContext($tenantId, $entities, $depth);
        
        $formatted = "KNOWN FACTS:\n";
        
        foreach ($context['entities'] as $entity) {
            $entityName = $entity['entity'] ?? 'Unknown';
            $entityType = $entity['type'] ?? 'unknown';
            $formatted .= "- {$entityName} (type: {$entityType})";
            
            if (!empty($entity['attributes'])) {
                $attrs = [];
                foreach ($entity['attributes'] as $key => $value) {
                    $attrs[] = "{$key}: {$value}";
                }
                $formatted .= " [" . implode(', ', $attrs) . "]";
            }
            $formatted .= "\n";
        }
        
        if (!empty($context['relationships'])) {
            $formatted .= "\nRELATIONSHIPS:\n";
            foreach ($context['relationships'] as $rel) {
                $from = $rel['from_entity_id'] ?? 'unknown';
                $to = $rel['to_entity_id'] ?? 'unknown';
                $type = $rel['relation_type'] ?? 'related_to';
                $formatted .= "- {$from} --[{$type}]--> {$to}\n";
            }
        }
        
        return trim($formatted);
    }

    /**
     * Update a fact.
     *
     * @param string $tenantId Tenant ID
     * @param string $factId Fact ID
     * @param array $attributes Attributes to update
     * @return bool Success
     */
    public function updateFact(string $tenantId, string $factId, array $attributes): bool
    {
        $result = $this->storage->updateFact($tenantId, $factId, $attributes);
        
        if ($result) {
            $this->log($tenantId, AuditLoggerInterface::ACTION_FACT_UPDATED, [
                'fact_id' => $factId,
                'updated_attributes' => array_keys($attributes),
            ]);
        }

        return $result;
    }

    /**
     * Delete a fact.
     *
     * @param string $tenantId Tenant ID
     * @param string $factId Fact ID
     * @return bool Success
     */
    public function deleteFact(string $tenantId, string $factId): bool
    {
        $result = $this->storage->deleteFact($tenantId, $factId);
        
        if ($result) {
            $this->log($tenantId, AuditLoggerInterface::ACTION_FACT_DELETED, [
                'fact_id' => $factId,
            ]);
        }

        return $result;
    }

    /**
     * Get all facts for a tenant.
     *
     * @param string $tenantId Tenant ID
     * @param int $limit Maximum facts
     * @param int $offset Starting offset
     * @return array Facts
     */
    public function getAllFacts(string $tenantId, int $limit = 100, int $offset = 0): array
    {
        return $this->storage->getAllFacts($tenantId, $limit, $offset);
    }

    /**
     * Execute a custom graph query.
     *
     * @param string $tenantId Tenant ID
     * @param string $query Query string
     * @param array $params Query parameters
     * @return array Results
     */
    public function query(string $tenantId, string $query, array $params = []): array
    {
        return $this->storage->executeQuery($tenantId, $query, $params);
    }

    /**
     * Log an action.
     *
     * @param string $tenantId Tenant ID
     * @param string $action Action type
     * @param array $data Action data
     * @return void
     */
    private function log(string $tenantId, string $action, array $data): void
    {
        if ($this->auditLogger) {
            $this->auditLogger->log($tenantId, $action, $data, [
                'component' => 'graph_memory',
            ]);
        }
    }

    /**
     * Get configuration.
     *
     * @return array Configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration.
     *
     * @param array $config New configuration
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }
}

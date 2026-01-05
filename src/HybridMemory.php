<?php

declare(strict_types=1);

namespace Zion\Memory;

use Zion\Memory\Memory\MindscapeMemory;
use Zion\Memory\Graph\GraphMemory;
use Zion\Memory\Agents\MultiAgentCoordinator;
use Zion\Memory\Agents\CoordinatedResponse;
use Zion\Memory\Contracts\AuditLoggerInterface;
use Zion\Memory\Contracts\AgentResponse;

/**
 * Class HybridMemory
 * 
 * Main orchestrator that combines Mindscape RAG (narrative memory) with
 * Graph RAG (structured factual memory) for banking-grade AI applications.
 * 
 * This is the primary interface for interacting with the hybrid memory system.
 * 
 * @package Zion\Memory
 */
class HybridMemory
{
    /**
     * @var MindscapeMemory Narrative memory (Mindscape RAG)
     */
    private MindscapeMemory $mindscapeMemory;

    /**
     * @var GraphMemory Structured memory (Graph RAG)
     */
    private GraphMemory $graphMemory;

    /**
     * @var MultiAgentCoordinator Multi-agent coordinator
     */
    private MultiAgentCoordinator $agentCoordinator;

    /**
     * @var AuditLoggerInterface|null Audit logger
     */
    private ?AuditLoggerInterface $auditLogger;

    /**
     * @var array Configuration
     */
    private array $config = [
        'auto_extract_facts' => true,
        'include_graph_context' => true,
        'max_graph_entities' => 10,
        'graph_depth' => 2,
        'use_multi_agent' => true,
    ];

    /**
     * Constructor.
     *
     * @param MindscapeMemory $mindscapeMemory Narrative memory
     * @param GraphMemory $graphMemory Structured memory
     * @param MultiAgentCoordinator $agentCoordinator Multi-agent coordinator
     * @param AuditLoggerInterface|null $auditLogger Optional audit logger
     * @param array $config Configuration options
     */
    public function __construct(
        MindscapeMemory $mindscapeMemory,
        GraphMemory $graphMemory,
        MultiAgentCoordinator $agentCoordinator,
        ?AuditLoggerInterface $auditLogger = null,
        array $config = []
    ) {
        $this->mindscapeMemory = $mindscapeMemory;
        $this->graphMemory = $graphMemory;
        $this->agentCoordinator = $agentCoordinator;
        $this->auditLogger = $auditLogger;
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Ingest a user message into the memory system.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param string $message User message content
     * @param array $metadata Additional metadata
     * @return array Ingestion result
     */
    public function ingestUserMessage(
        string $tenantId,
        string $sessionId,
        string $message,
        array $metadata = []
    ): array {
        $result = [
            'message_id' => null,
            'facts_extracted' => [],
            'timestamp' => time(),
        ];

        // Store in narrative memory
        $result['message_id'] = $this->mindscapeMemory->storeUserMessage(
            $tenantId,
            $sessionId,
            $message,
            $metadata
        );

        // Extract and store facts from user message
        if ($this->config['auto_extract_facts']) {
            $extraction = $this->graphMemory->ingestText($tenantId, $message, [
                'source' => 'user_message',
                'session_id' => $sessionId,
            ]);
            $result['facts_extracted'] = $extraction['facts']['stored'];
        }

        return $result;
    }

    /**
     * Ingest an AI response into the memory system.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param string $response AI response content
     * @param array $metadata Additional metadata
     * @return array Ingestion result
     */
    public function ingestAIResponse(
        string $tenantId,
        string $sessionId,
        string $response,
        array $metadata = []
    ): array {
        $result = [
            'message_id' => null,
            'facts_extracted' => [],
            'contradictions' => [],
            'resolutions' => [],
            'timestamp' => time(),
        ];

        // Store in narrative memory
        $result['message_id'] = $this->mindscapeMemory->storeAssistantMessage(
            $tenantId,
            $sessionId,
            $response,
            $metadata
        );

        // Extract and store facts from AI response
        if ($this->config['auto_extract_facts']) {
            $extraction = $this->graphMemory->ingestText($tenantId, $response, [
                'source' => 'ai_response',
                'session_id' => $sessionId,
                'agent_id' => $metadata['agent_id'] ?? 'default',
            ]);
            
            $result['facts_extracted'] = $extraction['facts']['stored'];
            $result['contradictions'] = $extraction['facts']['conflicts'];
            
            // Extract resolutions from conflicts
            foreach ($extraction['facts']['conflicts'] as $conflict) {
                if (isset($conflict['resolution'])) {
                    $result['resolutions'][] = $conflict['resolution'];
                }
            }
        }

        return $result;
    }

    /**
     * Build a comprehensive context for AI prompt generation.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param array $entities Relevant entity names to include from graph
     * @param array $options Context building options
     * @return array Complete context
     */
    public function buildContext(
        string $tenantId,
        string $sessionId,
        array $entities = [],
        array $options = []
    ): array {
        $context = [
            'narrative' => null,
            'graph' => null,
            'metadata' => [
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'built_at' => time(),
            ],
        ];

        // Get narrative context (Mindscape RAG)
        $context['narrative'] = $this->mindscapeMemory->buildContext(
            $tenantId,
            $sessionId,
            $options['narrative'] ?? []
        );

        // Get graph context (Graph RAG)
        if ($this->config['include_graph_context']) {
            // If no entities specified, try to extract from recent messages
            if (empty($entities)) {
                $entities = $this->extractRelevantEntities($context['narrative']);
            }

            $context['graph'] = $this->graphMemory->buildContext(
                $tenantId,
                array_slice($entities, 0, $this->config['max_graph_entities']),
                $options['graph_depth'] ?? $this->config['graph_depth']
            );
        }

        return $context;
    }

    /**
     * Build a formatted prompt context string.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param array $entities Relevant entity names
     * @param array $options Options
     * @return string Formatted context string
     */
    public function buildPromptContext(
        string $tenantId,
        string $sessionId,
        array $entities = [],
        array $options = []
    ): string {
        $formatted = '';

        // Narrative context
        $narrativeContext = $this->mindscapeMemory->buildPromptContext(
            $tenantId,
            $sessionId,
            $options['narrative'] ?? []
        );
        
        if (!empty($narrativeContext)) {
            $formatted .= $narrativeContext . "\n\n";
        }

        // Graph context
        if ($this->config['include_graph_context']) {
            if (empty($entities)) {
                $context = $this->mindscapeMemory->buildContext($tenantId, $sessionId);
                $entities = $this->extractRelevantEntities($context);
            }

            $graphContext = $this->graphMemory->buildPromptContext(
                $tenantId,
                array_slice($entities, 0, $this->config['max_graph_entities']),
                $options['graph_depth'] ?? $this->config['graph_depth']
            );
            
            if (!empty($graphContext)) {
                $formatted .= $graphContext;
            }
        }

        return trim($formatted);
    }

    /**
     * Process a query using multi-agent reasoning with hybrid memory context.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param string $query Query to process
     * @param array $options Processing options
     * @return HybridResponse Complete response with context
     */
    public function processQuery(
        string $tenantId,
        string $sessionId,
        string $query,
        array $options = []
    ): HybridResponse {
        // Build hybrid context
        $context = $this->buildContext($tenantId, $sessionId, $options['entities'] ?? []);
        
        // Store user query
        $this->ingestUserMessage($tenantId, $sessionId, $query);

        $response = null;
        $agentResponses = [];

        if ($this->config['use_multi_agent'] && !empty($options['agents'] ?? [])) {
            // Use multi-agent coordination
            $coordinated = $this->agentCoordinator->processQuery(
                $tenantId,
                $query,
                array_merge($context, ['query' => $query]),
                $options['agents'] ?? null
            );
            
            $response = $coordinated->content;
            $agentResponses = $coordinated->agentResponses;
        } else {
            // Use primary agent or default response
            $response = $this->processSingleQuery($query, $context);
        }

        // Ingest AI response
        $ingestionResult = $this->ingestAIResponse($tenantId, $sessionId, $response, [
            'query' => $query,
            'agents' => array_keys($agentResponses),
        ]);

        return new HybridResponse(
            content: $response,
            context: $context,
            factsExtracted: $ingestionResult['facts_extracted'],
            contradictions: $ingestionResult['contradictions'],
            resolutions: $ingestionResult['resolutions'],
            agentResponses: $agentResponses,
            metadata: [
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'query' => $query,
            ]
        );
    }

    /**
     * Generate a summary of the current session.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param bool $force Force regeneration
     * @return string Summary
     */
    public function generateSummary(
        string $tenantId,
        string $sessionId,
        bool $force = false
    ): string {
        return $this->mindscapeMemory->generateSummary($tenantId, $sessionId, $force);
    }

    /**
     * Get the current summary.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return string|null Summary or null
     */
    public function getSummary(string $tenantId, string $sessionId): ?string
    {
        return $this->mindscapeMemory->getSummary($tenantId, $sessionId);
    }

    /**
     * Query the graph memory.
     *
     * @param string $tenantId Tenant ID
     * @param string $entityName Entity to query
     * @return array|null Entity data
     */
    public function queryEntity(string $tenantId, string $entityName): ?array
    {
        return $this->graphMemory->queryEntity($tenantId, $entityName);
    }

    /**
     * Find related entities.
     *
     * @param string $tenantId Tenant ID
     * @param string $entityName Starting entity
     * @param int $depth Relationship depth
     * @return array Related entities
     */
    public function findRelatedEntities(
        string $tenantId,
        string $entityName,
        int $depth = 2
    ): array {
        return $this->graphMemory->findRelated($tenantId, $entityName, $depth);
    }

    /**
     * Get all facts for a tenant.
     *
     * @param string $tenantId Tenant ID
     * @param int $limit Maximum facts
     * @return array Facts
     */
    public function getAllFacts(string $tenantId, int $limit = 100): array
    {
        return $this->graphMemory->getAllFacts($tenantId, $limit);
    }

    /**
     * Store a fact directly.
     *
     * @param string $tenantId Tenant ID
     * @param array $fact Fact to store
     * @return array Storage result
     */
    public function storeFact(string $tenantId, array $fact): array
    {
        return $this->graphMemory->processFact($tenantId, $fact);
    }

    /**
     * Store a relationship.
     *
     * @param string $tenantId Tenant ID
     * @param string $fromEntity From entity
     * @param string $toEntity To entity
     * @param string $relationType Relationship type
     * @param array $metadata Metadata
     * @return string|null Relationship ID
     */
    public function storeRelationship(
        string $tenantId,
        string $fromEntity,
        string $toEntity,
        string $relationType,
        array $metadata = []
    ): ?string {
        return $this->graphMemory->storeRelationship(
            $tenantId,
            $fromEntity,
            $toEntity,
            $relationType,
            $metadata
        );
    }

    /**
     * Get session statistics.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return array Statistics
     */
    public function getSessionStats(string $tenantId, string $sessionId): array
    {
        $narrativeStats = $this->mindscapeMemory->getSessionStats($tenantId, $sessionId);
        $factCount = count($this->graphMemory->getAllFacts($tenantId));

        return array_merge($narrativeStats, [
            'fact_count' => $factCount,
        ]);
    }

    /**
     * Prune old data.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @param int $olderThanDays Days threshold
     * @return array Pruning result
     */
    public function pruneOldData(
        string $tenantId,
        string $sessionId,
        int $olderThanDays = 30
    ): array {
        $messagesPruned = $this->mindscapeMemory->pruneOldMessages(
            $tenantId,
            $sessionId,
            $olderThanDays
        );

        return [
            'messages_pruned' => $messagesPruned,
        ];
    }

    /**
     * Clear a session.
     *
     * @param string $tenantId Tenant ID
     * @param string $sessionId Session ID
     * @return bool Success
     */
    public function clearSession(string $tenantId, string $sessionId): bool
    {
        return $this->mindscapeMemory->clearSession($tenantId, $sessionId);
    }

    /**
     * Get the multi-agent coordinator.
     *
     * @return MultiAgentCoordinator Coordinator
     */
    public function getAgentCoordinator(): MultiAgentCoordinator
    {
        return $this->agentCoordinator;
    }

    /**
     * Get the mindscape memory.
     *
     * @return MindscapeMemory Mindscape memory
     */
    public function getMindscapeMemory(): MindscapeMemory
    {
        return $this->mindscapeMemory;
    }

    /**
     * Get the graph memory.
     *
     * @return GraphMemory Graph memory
     */
    public function getGraphMemory(): GraphMemory
    {
        return $this->graphMemory;
    }

    /**
     * Extract relevant entity names from context.
     *
     * @param array $context Narrative context
     * @return array Entity names
     */
    private function extractRelevantEntities(array $context): array
    {
        $entities = [];
        
        // Extract from messages
        foreach ($context['messages'] ?? [] as $message) {
            $content = $message['content'] ?? '';
            
            // Simple extraction - look for capitalized words and common patterns
            preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/', $content, $matches);
            $entities = array_merge($entities, $matches[0] ?? []);
            
            // Look for account-like patterns
            preg_match_all('/\b[A-Z]{2,}\d+\b/', $content, $accountMatches);
            $entities = array_merge($entities, $accountMatches[0] ?? []);
        }
        
        return array_unique($entities);
    }

    /**
     * Process a single query without multi-agent.
     *
     * @param string $query Query
     * @param array $context Context
     * @return string Response
     */
    private function processSingleQuery(string $query, array $context): string
    {
        // This would typically call an AI provider directly
        // For now, return a placeholder
        return "Query processed: {$query}";
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

/**
 * Class HybridResponse
 * 
 * Represents a response from the hybrid memory system.
 */
class HybridResponse
{
    public function __construct(
        public readonly string $content,
        public readonly array $context,
        public readonly array $factsExtracted,
        public readonly array $contradictions,
        public readonly array $resolutions,
        public readonly array $agentResponses,
        public readonly array $metadata = [],
        public readonly \DateTimeImmutable $generatedAt = new \DateTimeImmutable()
    ) {}

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'context' => $this->context,
            'facts_extracted' => $this->factsExtracted,
            'contradictions' => $this->contradictions,
            'resolutions' => $this->resolutions,
            'agent_responses' => array_map(
                fn($r) => $r instanceof AgentResponse ? $r->toArray() : $r,
                $this->agentResponses
            ),
            'metadata' => $this->metadata,
            'generated_at' => $this->generatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public function hasContradictions(): bool
    {
        return !empty($this->contradictions);
    }

    public function hasFactsExtracted(): bool
    {
        return !empty($this->factsExtracted);
    }
}

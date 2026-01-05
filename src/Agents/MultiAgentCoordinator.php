<?php

declare(strict_types=1);

namespace Zion\Memory\Agents;

use Zion\Memory\Contracts\AgentInterface;
use Zion\Memory\Contracts\AgentResponse;
use Zion\Memory\Contracts\ConflictResolverInterface;
use Zion\Memory\Contracts\AuditLoggerInterface;
use Zion\Memory\Contracts\AIProviderInterface;

/**
 * Class MultiAgentCoordinator
 * 
 * Coordinates multiple AI agents for multi-agent reasoning.
 * Consolidates outputs and resolves conflicts between agents.
 * 
 * @package Zion\Memory\Agents
 */
class MultiAgentCoordinator
{
    /**
     * @var array<string, AgentInterface> Registered agents
     */
    private array $agents = [];

    /**
     * @var ConflictResolverInterface Conflict resolver
     */
    private ConflictResolverInterface $conflictResolver;

    /**
     * @var AuditLoggerInterface|null Audit logger
     */
    private ?AuditLoggerInterface $auditLogger;

    /**
     * @var AIProviderInterface AI provider for consolidation
     */
    private AIProviderInterface $aiProvider;

    /**
     * @var array Configuration
     */
    private array $config = [
        'parallel_processing' => false, // PHP doesn't support true parallelism
        'require_consensus' => false,
        'min_confidence' => 0.6,
        'consolidation_strategy' => 'weighted_merge',
    ];

    /**
     * Constructor.
     *
     * @param ConflictResolverInterface $conflictResolver Conflict resolver
     * @param AIProviderInterface $aiProvider AI provider
     * @param AuditLoggerInterface|null $auditLogger Optional audit logger
     * @param array $config Configuration
     */
    public function __construct(
        ConflictResolverInterface $conflictResolver,
        AIProviderInterface $aiProvider,
        ?AuditLoggerInterface $auditLogger = null,
        array $config = []
    ) {
        $this->conflictResolver = $conflictResolver;
        $this->aiProvider = $aiProvider;
        $this->auditLogger = $auditLogger;
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Register an agent.
     *
     * @param AgentInterface $agent Agent to register
     * @return self
     */
    public function registerAgent(AgentInterface $agent): self
    {
        $this->agents[$agent->getId()] = $agent;
        return $this;
    }

    /**
     * Unregister an agent.
     *
     * @param string $agentId Agent ID to unregister
     * @return self
     */
    public function unregisterAgent(string $agentId): self
    {
        unset($this->agents[$agentId]);
        return $this;
    }

    /**
     * Get all registered agents.
     *
     * @return array<string, AgentInterface> Agents
     */
    public function getAgents(): array
    {
        return $this->agents;
    }

    /**
     * Get an agent by ID.
     *
     * @param string $agentId Agent ID
     * @return AgentInterface|null Agent or null
     */
    public function getAgent(string $agentId): ?AgentInterface
    {
        return $this->agents[$agentId] ?? null;
    }

    /**
     * Process a query through multiple agents and consolidate results.
     *
     * @param string $tenantId Tenant ID
     * @param string $query Query to process
     * @param array $context Context data
     * @param array|null $agentIds Specific agents to use (null = all)
     * @return CoordinatedResponse Consolidated response
     */
    public function processQuery(
        string $tenantId,
        string $query,
        array $context = [],
        ?array $agentIds = null
    ): CoordinatedResponse {
        $agents = $this->selectAgents($agentIds, $query);
        
        if (empty($agents)) {
            throw new \RuntimeException('No agents available to process query');
        }

        $responses = [];
        $startTime = microtime(true);

        // Collect responses from all agents
        foreach ($agents as $agent) {
            try {
                $response = $agent->process($query, $context);
                $responses[$agent->getId()] = $response;
                
                $this->log($tenantId, AuditLoggerInterface::ACTION_AGENT_RESPONSE, [
                    'agent_id' => $agent->getId(),
                    'agent_role' => $agent->getRole(),
                    'confidence' => $response->confidence,
                    'content_length' => strlen($response->content),
                ]);
            } catch (\Throwable $e) {
                // Log error but continue with other agents
                $this->log($tenantId, 'agent_error', [
                    'agent_id' => $agent->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($responses)) {
            throw new \RuntimeException('All agents failed to process query');
        }

        // Consolidate responses
        $consolidated = $this->consolidateResponses($tenantId, $responses, $context);
        
        $processingTime = microtime(true) - $startTime;
        
        $this->log($tenantId, AuditLoggerInterface::ACTION_AGENT_CONSENSUS, [
            'agents_used' => array_keys($responses),
            'processing_time' => $processingTime,
            'final_confidence' => $consolidated->confidence,
        ]);

        return $consolidated;
    }

    /**
     * Process a query through a specific agent.
     *
     * @param string $tenantId Tenant ID
     * @param string $agentId Agent ID
     * @param string $query Query to process
     * @param array $context Context data
     * @return AgentResponse Agent response
     */
    public function processWithAgent(
        string $tenantId,
        string $agentId,
        string $query,
        array $context = []
    ): AgentResponse {
        $agent = $this->agents[$agentId] ?? null;
        
        if (!$agent) {
            throw new \InvalidArgumentException("Agent not found: {$agentId}");
        }

        $response = $agent->process($query, $context);
        
        $this->log($tenantId, AuditLoggerInterface::ACTION_AGENT_RESPONSE, [
            'agent_id' => $agentId,
            'agent_role' => $agent->getRole(),
            'confidence' => $response->confidence,
        ]);

        return $response;
    }

    /**
     * Have agents vote on conflicting facts.
     *
     * @param string $tenantId Tenant ID
     * @param array $conflictingFacts Facts in conflict
     * @param array $context Context data
     * @return array Voting result
     */
    public function voteOnConflict(
        string $tenantId,
        array $conflictingFacts,
        array $context = []
    ): array {
        $votes = [];
        
        foreach ($this->agents as $agent) {
            $vote = $agent->voteOnConflict($conflictingFacts, $context);
            $vote['agent_priority'] = $agent->getPriority();
            $votes[$agent->getId()] = $vote;
        }

        // Resolve using consensus
        $agentPriorities = [];
        foreach ($this->agents as $agent) {
            $agentPriorities[$agent->getRole()] = $agent->getPriority();
        }

        $resolution = $this->conflictResolver->resolveByConsensus($conflictingFacts, $agentPriorities);

        return [
            'votes' => $votes,
            'resolution' => $resolution->toArray(),
            'chosen_fact' => $resolution->resolvedFact,
        ];
    }

    /**
     * Get recommendations from agents for a specific query type.
     *
     * @param string $tenantId Tenant ID
     * @param string $queryType Query type
     * @param array $context Context data
     * @return array Agent recommendations
     */
    public function getRecommendations(
        string $tenantId,
        string $queryType,
        array $context = []
    ): array {
        $recommendations = [];
        
        foreach ($this->agents as $agent) {
            if ($agent->canHandle($queryType)) {
                $response = $agent->process("Provide recommendations for: {$queryType}", $context);
                $recommendations[$agent->getId()] = [
                    'agent_name' => $agent->getName(),
                    'agent_role' => $agent->getRole(),
                    'recommendation' => $response->content,
                    'confidence' => $response->confidence,
                    'priority' => $agent->getPriority(),
                ];
            }
        }

        // Sort by priority
        uasort($recommendations, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $recommendations;
    }

    /**
     * Select agents to use for a query.
     *
     * @param array|null $agentIds Specific agent IDs (null = all)
     * @param string $query Query for capability matching
     * @return AgentInterface[] Selected agents
     */
    private function selectAgents(?array $agentIds, string $query): array
    {
        if ($agentIds !== null) {
            return array_filter(
                $this->agents,
                fn($id) => in_array($id, $agentIds),
                ARRAY_FILTER_USE_KEY
            );
        }

        // Return all agents sorted by priority
        $agents = $this->agents;
        uasort($agents, fn($a, $b) => $b->getPriority() <=> $a->getPriority());
        
        return $agents;
    }

    /**
     * Consolidate responses from multiple agents.
     *
     * @param string $tenantId Tenant ID
     * @param array<string, AgentResponse> $responses Agent responses
     * @param array $context Context data
     * @return CoordinatedResponse Consolidated response
     */
    private function consolidateResponses(
        string $tenantId,
        array $responses,
        array $context
    ): CoordinatedResponse {
        $strategy = $this->config['consolidation_strategy'];
        
        return match ($strategy) {
            'weighted_merge' => $this->weightedMergeConsolidation($responses, $context),
            'highest_confidence' => $this->highestConfidenceConsolidation($responses),
            'ai_synthesis' => $this->aiSynthesisConsolidation($responses, $context),
            default => $this->weightedMergeConsolidation($responses, $context),
        };
    }

    /**
     * Consolidate using weighted merge strategy.
     *
     * @param array $responses Agent responses
     * @param array $context Context
     * @return CoordinatedResponse Consolidated response
     */
    private function weightedMergeConsolidation(array $responses, array $context): CoordinatedResponse
    {
        $weightedContent = [];
        $allFacts = [];
        $allReasoning = [];
        $totalWeight = 0;

        foreach ($responses as $agentId => $response) {
            $agent = $this->agents[$agentId] ?? null;
            $priority = $agent?->getPriority() ?? 50;
            $weight = $priority * $response->confidence;
            
            $weightedContent[] = [
                'content' => $response->content,
                'weight' => $weight,
                'agent_id' => $agentId,
            ];
            
            $totalWeight += $weight;
            
            foreach ($response->extractedFacts as $fact) {
                $fact['source_agent'] = $agentId;
                $allFacts[] = $fact;
            }
            
            $allReasoning = array_merge($allReasoning, $response->reasoning);
        }

        // Sort by weight and take top response as primary
        usort($weightedContent, fn($a, $b) => $b['weight'] <=> $a['weight']);
        $primaryContent = $weightedContent[0]['content'] ?? '';
        
        // Calculate average confidence weighted by priority
        $avgConfidence = 0;
        foreach ($responses as $agentId => $response) {
            $agent = $this->agents[$agentId] ?? null;
            $weight = ($agent?->getPriority() ?? 50) * $response->confidence;
            $avgConfidence += ($weight / $totalWeight) * $response->confidence;
        }

        return new CoordinatedResponse(
            content: $primaryContent,
            agentResponses: $responses,
            consolidatedFacts: $allFacts,
            reasoning: $allReasoning,
            confidence: $avgConfidence,
            strategy: 'weighted_merge',
            metadata: [
                'agents_count' => count($responses),
                'total_weight' => $totalWeight,
            ]
        );
    }

    /**
     * Consolidate using highest confidence strategy.
     *
     * @param array $responses Agent responses
     * @return CoordinatedResponse Consolidated response
     */
    private function highestConfidenceConsolidation(array $responses): CoordinatedResponse
    {
        $best = null;
        $bestConfidence = 0;
        $bestAgentId = null;

        foreach ($responses as $agentId => $response) {
            if ($response->confidence > $bestConfidence) {
                $best = $response;
                $bestConfidence = $response->confidence;
                $bestAgentId = $agentId;
            }
        }

        return new CoordinatedResponse(
            content: $best->content,
            agentResponses: $responses,
            consolidatedFacts: $best->extractedFacts,
            reasoning: $best->reasoning,
            confidence: $best->confidence,
            strategy: 'highest_confidence',
            metadata: [
                'selected_agent' => $bestAgentId,
            ]
        );
    }

    /**
     * Consolidate using AI synthesis strategy.
     *
     * @param array $responses Agent responses
     * @param array $context Context
     * @return CoordinatedResponse Consolidated response
     */
    private function aiSynthesisConsolidation(array $responses, array $context): CoordinatedResponse
    {
        $responsesText = '';
        $allFacts = [];
        
        foreach ($responses as $agentId => $response) {
            $agent = $this->agents[$agentId] ?? null;
            $agentName = $agent?->getName() ?? $agentId;
            $agentRole = $agent?->getRole() ?? 'unknown';
            
            $responsesText .= "--- {$agentName} ({$agentRole}) ---\n";
            $responsesText .= "{$response->content}\n\n";
            
            foreach ($response->extractedFacts as $fact) {
                $fact['source_agent'] = $agentId;
                $allFacts[] = $fact;
            }
        }

        $prompt = <<<PROMPT
You are synthesizing responses from multiple banking AI agents. Create a unified, coherent response.

AGENT RESPONSES:
{$responsesText}

INSTRUCTIONS:
1. Synthesize the key points from all agents
2. Resolve any contradictions by favoring compliance/risk perspectives
3. Present a clear, unified response
4. Maintain all important details and warnings
5. Do not mention specific agents in the output

Synthesized Response:
PROMPT;

        $synthesized = $this->aiProvider->complete($prompt, ['temperature' => 0.3]);

        // Calculate average confidence
        $totalConfidence = 0;
        foreach ($responses as $response) {
            $totalConfidence += $response->confidence;
        }
        $avgConfidence = $totalConfidence / count($responses);

        return new CoordinatedResponse(
            content: $synthesized,
            agentResponses: $responses,
            consolidatedFacts: $allFacts,
            reasoning: ['AI-synthesized from ' . count($responses) . ' agent responses'],
            confidence: $avgConfidence,
            strategy: 'ai_synthesis',
            metadata: [
                'agents_count' => count($responses),
            ]
        );
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
                'component' => 'multi_agent_coordinator',
            ]);
        }
    }
}

/**
 * Class CoordinatedResponse
 * 
 * Represents a consolidated response from multiple agents.
 */
class CoordinatedResponse
{
    public function __construct(
        public readonly string $content,
        public readonly array $agentResponses,
        public readonly array $consolidatedFacts,
        public readonly array $reasoning,
        public readonly float $confidence,
        public readonly string $strategy,
        public readonly array $metadata = [],
        public readonly \DateTimeImmutable $generatedAt = new \DateTimeImmutable()
    ) {}

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'agent_responses' => array_map(fn($r) => $r->toArray(), $this->agentResponses),
            'consolidated_facts' => $this->consolidatedFacts,
            'reasoning' => $this->reasoning,
            'confidence' => $this->confidence,
            'strategy' => $this->strategy,
            'metadata' => $this->metadata,
            'generated_at' => $this->generatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public function getAgentCount(): int
    {
        return count($this->agentResponses);
    }
}

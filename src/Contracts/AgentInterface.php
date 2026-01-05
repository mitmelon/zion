<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface AgentInterface
 * 
 * Defines the contract for AI agents in the multi-agent system.
 * Each agent can have different specializations (compliance, risk, customer-service, etc.)
 * 
 * @package Zion\Memory\Contracts
 */
interface AgentInterface
{
    /**
     * Get the unique identifier of the agent.
     *
     * @return string Agent ID
     */
    public function getId(): string;

    /**
     * Get the human-readable name of the agent.
     *
     * @return string Agent name
     */
    public function getName(): string;

    /**
     * Get the agent's specialization/role.
     *
     * @return string Agent role (e.g., 'compliance', 'risk', 'customer_service')
     */
    public function getRole(): string;

    /**
     * Get the agent's priority score for conflict resolution.
     *
     * @return int Priority score (higher = more authoritative)
     */
    public function getPriority(): int;

    /**
     * Process a query and generate a response.
     *
     * @param string $query The query to process
     * @param array $context Context including memory, facts, etc.
     * @return AgentResponse Agent's response
     */
    public function process(string $query, array $context = []): AgentResponse;

    /**
     * Validate a fact from the agent's perspective.
     *
     * @param array $fact Fact to validate
     * @param array $context Context for validation
     * @return array Validation result with confidence and reasoning
     */
    public function validateFact(array $fact, array $context = []): array;

    /**
     * Vote on a conflict resolution.
     *
     * @param array $conflictingFacts Facts in conflict
     * @param array $context Context for decision
     * @return array Vote result with chosen fact and confidence
     */
    public function voteOnConflict(array $conflictingFacts, array $context = []): array;

    /**
     * Get the agent's capabilities.
     *
     * @return array Array of capability strings
     */
    public function getCapabilities(): array;

    /**
     * Check if the agent can handle a specific type of query.
     *
     * @param string $queryType Type of query
     * @return bool True if agent can handle
     */
    public function canHandle(string $queryType): bool;

    /**
     * Get the agent's configuration.
     *
     * @return array Configuration array
     */
    public function getConfig(): array;
}

/**
 * Class AgentResponse
 * 
 * Represents a response from an AI agent.
 */
class AgentResponse
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $content,
        public readonly array $extractedFacts = [],
        public readonly array $reasoning = [],
        public readonly float $confidence = 1.0,
        public readonly array $metadata = [],
        public readonly \DateTimeImmutable $generatedAt = new \DateTimeImmutable()
    ) {}

    public function toArray(): array
    {
        return [
            'agent_id' => $this->agentId,
            'content' => $this->content,
            'extracted_facts' => $this->extractedFacts,
            'reasoning' => $this->reasoning,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
            'generated_at' => $this->generatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}

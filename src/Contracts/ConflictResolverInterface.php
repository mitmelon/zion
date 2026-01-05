<?php

declare(strict_types=1);

namespace Zion\Memory\Contracts;

/**
 * Interface ConflictResolverInterface
 * 
 * Defines the contract for resolving conflicts between facts.
 * Supports multiple resolution strategies: latest-wins, priority-agent, consensus voting.
 * 
 * @package Zion\Memory\Contracts
 */
interface ConflictResolverInterface
{
    /**
     * Resolution strategy constants.
     */
    public const STRATEGY_LATEST_WINS = 'latest_wins';
    public const STRATEGY_PRIORITY_AGENT = 'priority_agent';
    public const STRATEGY_CONSENSUS = 'consensus';
    public const STRATEGY_MANUAL = 'manual';

    /**
     * Resolve a conflict between two facts.
     *
     * @param array $existingFact The existing fact in the graph
     * @param array $newFact The new conflicting fact
     * @param string $strategy Resolution strategy to use
     * @param array $context Additional context for resolution
     * @return ConflictResolution Resolution result
     */
    public function resolve(
        array $existingFact,
        array $newFact,
        string $strategy = self::STRATEGY_LATEST_WINS,
        array $context = []
    ): ConflictResolution;

    /**
     * Resolve conflicts using multi-agent consensus.
     *
     * @param array $conflictingFacts Array of conflicting facts from different agents
     * @param array $agentPriorities Agent priority scores
     * @return ConflictResolution Resolution result with consensus information
     */
    public function resolveByConsensus(array $conflictingFacts, array $agentPriorities = []): ConflictResolution;

    /**
     * Resolve conflict by agent priority.
     *
     * @param array $existingFact The existing fact
     * @param array $newFact The new conflicting fact
     * @param array $agentPriorities Agent priority mapping
     * @return ConflictResolution Resolution result
     */
    public function resolveByPriority(array $existingFact, array $newFact, array $agentPriorities): ConflictResolution;

    /**
     * Set the default resolution strategy.
     *
     * @param string $strategy Resolution strategy
     * @return void
     */
    public function setDefaultStrategy(string $strategy): void;

    /**
     * Get the default resolution strategy.
     *
     * @return string Current default strategy
     */
    public function getDefaultStrategy(): string;

    /**
     * Register a custom resolution strategy.
     *
     * @param string $name Strategy name
     * @param callable $resolver Resolver function
     * @return void
     */
    public function registerStrategy(string $name, callable $resolver): void;

    /**
     * Get all available strategies.
     *
     * @return array Array of strategy names
     */
    public function getAvailableStrategies(): array;
}

/**
 * Class ConflictResolution
 * 
 * Represents the result of a conflict resolution.
 */
class ConflictResolution
{
    public function __construct(
        public readonly array $resolvedFact,
        public readonly string $strategyUsed,
        public readonly string $action, // 'keep_existing', 'use_new', 'merge', 'defer'
        public readonly array $reasoning,
        public readonly array $originalConflict,
        public readonly float $confidenceScore = 1.0,
        public readonly \DateTimeImmutable $resolvedAt = new \DateTimeImmutable()
    ) {}

    public function toArray(): array
    {
        return [
            'resolved_fact' => $this->resolvedFact,
            'strategy_used' => $this->strategyUsed,
            'action' => $this->action,
            'reasoning' => $this->reasoning,
            'original_conflict' => $this->originalConflict,
            'confidence_score' => $this->confidenceScore,
            'resolved_at' => $this->resolvedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}

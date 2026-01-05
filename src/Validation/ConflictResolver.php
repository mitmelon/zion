<?php

declare(strict_types=1);

namespace Zion\Memory\Validation;

use Zion\Memory\Contracts\ConflictResolverInterface;
use Zion\Memory\Contracts\ConflictResolution;

/**
 * Class ConflictResolver
 * 
 * Resolves conflicts between facts using various strategies.
 * Supports latest-wins, priority-agent, and consensus voting.
 * 
 * @package Zion\Memory\Validation
 */
class ConflictResolver implements ConflictResolverInterface
{
    /**
     * @var string Default resolution strategy
     */
    private string $defaultStrategy = self::STRATEGY_LATEST_WINS;

    /**
     * @var array Custom strategy handlers
     */
    private array $customStrategies = [];

    /**
     * @var array Default agent priorities
     */
    private array $agentPriorities = [
        'compliance' => 100,
        'risk' => 90,
        'security' => 85,
        'customer_service' => 70,
        'general' => 50,
        'unknown' => 10,
    ];

    /**
     * Constructor.
     *
     * @param string $defaultStrategy Default strategy
     * @param array $agentPriorities Agent priority mapping
     */
    public function __construct(
        string $defaultStrategy = self::STRATEGY_LATEST_WINS,
        array $agentPriorities = []
    ) {
        $this->defaultStrategy = $defaultStrategy;
        if (!empty($agentPriorities)) {
            $this->agentPriorities = array_merge($this->agentPriorities, $agentPriorities);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(
        array $existingFact,
        array $newFact,
        string $strategy = self::STRATEGY_LATEST_WINS,
        array $context = []
    ): ConflictResolution {
        $strategy = $strategy ?: $this->defaultStrategy;

        // Check for custom strategy
        if (isset($this->customStrategies[$strategy])) {
            return call_user_func(
                $this->customStrategies[$strategy],
                $existingFact,
                $newFact,
                $context
            );
        }

        // Built-in strategies
        return match ($strategy) {
            self::STRATEGY_LATEST_WINS => $this->resolveLatestWins($existingFact, $newFact, $context),
            self::STRATEGY_PRIORITY_AGENT => $this->resolveByPriority($existingFact, $newFact, $this->agentPriorities),
            self::STRATEGY_CONSENSUS => $this->resolveByConsensus([$existingFact, $newFact], $this->agentPriorities),
            self::STRATEGY_MANUAL => $this->deferResolution($existingFact, $newFact, $context),
            default => $this->resolveLatestWins($existingFact, $newFact, $context),
        };
    }

    /**
     * {@inheritdoc}
     */
    public function resolveByConsensus(array $conflictingFacts, array $agentPriorities = []): ConflictResolution
    {
        if (empty($conflictingFacts)) {
            throw new \InvalidArgumentException('No facts provided for consensus resolution');
        }

        if (count($conflictingFacts) === 1) {
            return new ConflictResolution(
                resolvedFact: $conflictingFacts[0],
                strategyUsed: self::STRATEGY_CONSENSUS,
                action: 'use_new',
                reasoning: ['Single fact provided, using as resolution'],
                originalConflict: ['facts' => $conflictingFacts],
                confidenceScore: 1.0
            );
        }

        $priorities = array_merge($this->agentPriorities, $agentPriorities);
        $votes = [];
        $totalWeight = 0;

        // Collect weighted votes
        foreach ($conflictingFacts as $index => $fact) {
            $agentId = $fact['agent_id'] ?? $fact['metadata']['agent_id'] ?? 'unknown';
            $agentRole = $fact['agent_role'] ?? $fact['metadata']['agent_role'] ?? 'unknown';
            $confidence = $fact['confidence'] ?? 1.0;
            
            $priority = $priorities[$agentRole] ?? $priorities['unknown'] ?? 10;
            $weight = $priority * $confidence;
            
            $votes[$index] = [
                'fact' => $fact,
                'agent_id' => $agentId,
                'agent_role' => $agentRole,
                'priority' => $priority,
                'confidence' => $confidence,
                'weight' => $weight,
            ];
            
            $totalWeight += $weight;
        }

        // Sort by weight descending
        usort($votes, fn($a, $b) => $b['weight'] <=> $a['weight']);

        $winner = $votes[0];
        $consensusScore = $winner['weight'] / $totalWeight;

        // Merge attributes from other facts with lower priority
        $mergedFact = $winner['fact'];
        $reasoning = [
            "Selected fact from agent '{$winner['agent_id']}' (role: {$winner['agent_role']})",
            "Weight: {$winner['weight']} ({$consensusScore}% of total)",
        ];

        // Consider merging non-conflicting attributes
        foreach (array_slice($votes, 1) as $vote) {
            $otherAttrs = $vote['fact']['attributes'] ?? [];
            $mergedAttrs = $mergedFact['attributes'] ?? [];
            
            foreach ($otherAttrs as $key => $value) {
                if (!isset($mergedAttrs[$key])) {
                    $mergedFact['attributes'][$key] = $value;
                    $reasoning[] = "Merged attribute '{$key}' from agent '{$vote['agent_id']}'";
                }
            }
        }

        return new ConflictResolution(
            resolvedFact: $mergedFact,
            strategyUsed: self::STRATEGY_CONSENSUS,
            action: 'merge',
            reasoning: $reasoning,
            originalConflict: ['facts' => $conflictingFacts, 'votes' => $votes],
            confidenceScore: $consensusScore
        );
    }

    /**
     * {@inheritdoc}
     */
    public function resolveByPriority(array $existingFact, array $newFact, array $agentPriorities): ConflictResolution
    {
        $priorities = array_merge($this->agentPriorities, $agentPriorities);

        $existingAgent = $existingFact['agent_role'] ?? $existingFact['metadata']['agent_role'] ?? 'unknown';
        $newAgent = $newFact['agent_role'] ?? $newFact['metadata']['agent_role'] ?? 'unknown';

        $existingPriority = $priorities[$existingAgent] ?? $priorities['unknown'] ?? 10;
        $newPriority = $priorities[$newAgent] ?? $priorities['unknown'] ?? 10;

        // Factor in confidence scores
        $existingConfidence = $existingFact['confidence'] ?? 1.0;
        $newConfidence = $newFact['confidence'] ?? 1.0;

        $existingScore = $existingPriority * $existingConfidence;
        $newScore = $newPriority * $newConfidence;

        if ($newScore > $existingScore) {
            return new ConflictResolution(
                resolvedFact: $newFact,
                strategyUsed: self::STRATEGY_PRIORITY_AGENT,
                action: 'use_new',
                reasoning: [
                    "New fact from '{$newAgent}' (priority: {$newPriority}, confidence: {$newConfidence}) " .
                    "supersedes existing from '{$existingAgent}' (priority: {$existingPriority}, confidence: {$existingConfidence})",
                    "Score comparison: {$newScore} > {$existingScore}",
                ],
                originalConflict: ['existing' => $existingFact, 'new' => $newFact],
                confidenceScore: $newConfidence
            );
        }

        if ($existingScore > $newScore) {
            return new ConflictResolution(
                resolvedFact: $existingFact,
                strategyUsed: self::STRATEGY_PRIORITY_AGENT,
                action: 'keep_existing',
                reasoning: [
                    "Existing fact from '{$existingAgent}' (priority: {$existingPriority}, confidence: {$existingConfidence}) " .
                    "retained over new from '{$newAgent}' (priority: {$newPriority}, confidence: {$newConfidence})",
                    "Score comparison: {$existingScore} > {$newScore}",
                ],
                originalConflict: ['existing' => $existingFact, 'new' => $newFact],
                confidenceScore: $existingConfidence
            );
        }

        // Equal scores - merge
        return $this->mergeFacts($existingFact, $newFact, self::STRATEGY_PRIORITY_AGENT);
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultStrategy(string $strategy): void
    {
        $this->defaultStrategy = $strategy;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultStrategy(): string
    {
        return $this->defaultStrategy;
    }

    /**
     * {@inheritdoc}
     */
    public function registerStrategy(string $name, callable $resolver): void
    {
        $this->customStrategies[$name] = $resolver;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableStrategies(): array
    {
        return array_merge(
            [
                self::STRATEGY_LATEST_WINS,
                self::STRATEGY_PRIORITY_AGENT,
                self::STRATEGY_CONSENSUS,
                self::STRATEGY_MANUAL,
            ],
            array_keys($this->customStrategies)
        );
    }

    /**
     * Set agent priorities.
     *
     * @param array $priorities Agent priority mapping
     * @return void
     */
    public function setAgentPriorities(array $priorities): void
    {
        $this->agentPriorities = array_merge($this->agentPriorities, $priorities);
    }

    /**
     * Get agent priorities.
     *
     * @return array Current priorities
     */
    public function getAgentPriorities(): array
    {
        return $this->agentPriorities;
    }

    /**
     * Resolve using latest-wins strategy.
     *
     * @param array $existingFact Existing fact
     * @param array $newFact New fact
     * @param array $context Context
     * @return ConflictResolution Resolution
     */
    private function resolveLatestWins(array $existingFact, array $newFact, array $context = []): ConflictResolution
    {
        $existingTime = $existingFact['updated_at'] ?? $existingFact['created_at'] ?? 0;
        $newTime = $newFact['updated_at'] ?? $newFact['created_at'] ?? time();

        if ($newTime >= $existingTime) {
            return new ConflictResolution(
                resolvedFact: $newFact,
                strategyUsed: self::STRATEGY_LATEST_WINS,
                action: 'use_new',
                reasoning: [
                    'New fact is more recent',
                    "New timestamp: " . date('Y-m-d H:i:s', $newTime),
                    "Existing timestamp: " . date('Y-m-d H:i:s', $existingTime),
                ],
                originalConflict: ['existing' => $existingFact, 'new' => $newFact],
                confidenceScore: $newFact['confidence'] ?? 1.0
            );
        }

        return new ConflictResolution(
            resolvedFact: $existingFact,
            strategyUsed: self::STRATEGY_LATEST_WINS,
            action: 'keep_existing',
            reasoning: [
                'Existing fact is more recent',
                "Existing timestamp: " . date('Y-m-d H:i:s', $existingTime),
                "New timestamp: " . date('Y-m-d H:i:s', $newTime),
            ],
            originalConflict: ['existing' => $existingFact, 'new' => $newFact],
            confidenceScore: $existingFact['confidence'] ?? 1.0
        );
    }

    /**
     * Defer resolution for manual handling.
     *
     * @param array $existingFact Existing fact
     * @param array $newFact New fact
     * @param array $context Context
     * @return ConflictResolution Resolution
     */
    private function deferResolution(array $existingFact, array $newFact, array $context = []): ConflictResolution
    {
        return new ConflictResolution(
            resolvedFact: $existingFact, // Keep existing until manual resolution
            strategyUsed: self::STRATEGY_MANUAL,
            action: 'defer',
            reasoning: [
                'Conflict deferred for manual resolution',
                'Existing fact retained until conflict is manually resolved',
            ],
            originalConflict: ['existing' => $existingFact, 'new' => $newFact],
            confidenceScore: 0.5
        );
    }

    /**
     * Merge two facts.
     *
     * @param array $existingFact Existing fact
     * @param array $newFact New fact
     * @param string $strategy Strategy used
     * @return ConflictResolution Resolution
     */
    private function mergeFacts(array $existingFact, array $newFact, string $strategy): ConflictResolution
    {
        $mergedFact = $existingFact;
        $mergedAttrs = $existingFact['attributes'] ?? [];
        $newAttrs = $newFact['attributes'] ?? [];
        $mergeLog = [];

        // Merge attributes - new values override existing
        foreach ($newAttrs as $key => $value) {
            if (!isset($mergedAttrs[$key]) || $mergedAttrs[$key] !== $value) {
                $mergeLog[] = "Attribute '{$key}': " . (isset($mergedAttrs[$key]) ? 'updated' : 'added');
            }
            $mergedAttrs[$key] = $value;
        }

        $mergedFact['attributes'] = $mergedAttrs;
        $mergedFact['updated_at'] = time();
        $mergedFact['merged_from'] = [
            'existing_id' => $existingFact['id'] ?? null,
            'new_confidence' => $newFact['confidence'] ?? 1.0,
        ];

        // Average the confidence scores
        $avgConfidence = (($existingFact['confidence'] ?? 1.0) + ($newFact['confidence'] ?? 1.0)) / 2;
        $mergedFact['confidence'] = $avgConfidence;

        return new ConflictResolution(
            resolvedFact: $mergedFact,
            strategyUsed: $strategy,
            action: 'merge',
            reasoning: array_merge(['Facts merged due to equal priority'], $mergeLog),
            originalConflict: ['existing' => $existingFact, 'new' => $newFact],
            confidenceScore: $avgConfidence
        );
    }
}

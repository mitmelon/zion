<?php

/**
 * ZionXMemory v2 - Adaptive Features Examples
 * MIRAS + ATLAS + Hierarchical Compression demonstrations
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ZionXMemory\Orchestrator\HybridMemoryV2;
use ZionXMemory\Storage\RedisAdapter;
use ZionXMemory\AI\Adapters\GeminiAdapter;
use ZionXMemory\Audit\AuditLogger;

// Initialize
$storage = new RedisAdapter();
$storage->connect(['host' => '127.0.0.1', 'port' => 6379]);

$ai = new GeminiAdapter();
$ai->configure([
    'api_key' => getenv('GEMINI_API_KEY'),
    'base_url' => getenv('GEMINI_BASE_URL') ?: null,
    'model' => getenv('GEMINI_MODEL') ?: 'gemini',
    'retry' => ['max_attempts' => 3, 'base_delay_ms' => 200]
]);

$audit = new AuditLogger($storage);
$memory = new HybridMemoryV2($storage, $ai, $audit, ['enable_adaptive' => true]);

$tenantId = 'research_org';
$agentId = 'adaptive_agent';

echo "=== ZionXMemory v2: Adaptive Features Demo ===\n\n";

/**
 * EXAMPLE 1: Storing Memory with Surprise Signals
 */
echo "1. Storing Memory with Surprise Signals\n";
echo "=========================================\n\n";

// Store high-surprise memory
$highSurpriseResult = $memory->storeMemoryAdaptive($tenantId, $agentId, [
    'type' => 'research_breakthrough',
    'content' => 'We discovered a completely unexpected pattern in the data that contradicts all existing theories. The correlation coefficient is 0.95, far exceeding predictions.',
    'claims' => [
        [
            'text' => 'New pattern contradicts existing theories',
            'confidence' => ['min' => 0.8, 'max' => 0.95, 'mean' => 0.9]
        ]
    ],
    'evidence' => [
        ['source' => 'experiment_42', 'quality' => 0.95],
        ['source' => 'peer_review_1', 'quality' => 0.9]
    ]
], [
    'magnitude' => 0.9,  // Agent's own surprise signal
    'momentum' => 0.2,
    'components' => [
        'novelty' => 0.95,
        'contradiction' => 0.85,
        'confidence_shift' => 0.9
    ]
]);

echo "High-surprise memory stored:\n";
echo "- Memory ID: {$highSurpriseResult['memory_id']}\n";
echo "- Adaptive ID: {$highSurpriseResult['adaptive_id']}\n";
echo "- Surprise Score: {$highSurpriseResult['surprise_score']}\n";
echo "- Beliefs created: " . count($highSurpriseResult['beliefs']) . "\n\n";

// Store low-surprise routine memory
$lowSurpriseResult = $memory->storeMemoryAdaptive($tenantId, $agentId, [
    'type' => 'daily_log',
    'content' => 'Routine data collection completed. Results within expected parameters.',
], [
    'magnitude' => 0.2
]);

echo "Low-surprise memory stored:\n";
echo "- Surprise Score: {$lowSurpriseResult['surprise_score']}\n\n";

/**
 * EXAMPLE 2: ATLAS-Prioritized Context Retrieval
 */
echo "2. ATLAS-Prioritized Context Retrieval\n";
echo "=======================================\n\n";

// Store diverse memories
for ($i = 0; $i < 20; $i++) {
    $surprise = rand(20, 90) / 100;
    $memory->storeMemoryAdaptive($tenantId, $agentId, [
        'type' => 'research_note',
        'content' => "Research note {$i}: Finding about topic " . chr(65 + ($i % 5))
    ], [
        'magnitude' => $surprise
    ]);
}

// Build optimized context
$context = $memory->buildOptimizedContext($tenantId, $agentId, [
    'max_tokens' => 4000,
    'query_context' => [
        'query_type' => 'important', // Prioritize important memories
        'text' => 'breakthrough patterns theories'
    ]
]);

echo "Optimized context built:\n";
echo "- Prioritized memories: {$context['adaptive']['prioritized_memories']}\n";
echo "- Surprise distribution:\n";
foreach ($context['adaptive']['surprise_distribution'] as $level => $count) {
    echo "  {$level}: {$count}\n";
}
echo "- Compression ratio: " . round($context['adaptive']['compression_stats']['overall_ratio'], 3) . "\n";
echo "- High-surprise memories flagged: " . count($context['high_surprise_memories']) . "\n\n";

/**
 * EXAMPLE 3: Adaptive Retention Policy
 */
echo "3. Adaptive Retention Policy Configuration\n";
echo "==========================================\n\n";

// Configure custom retention policy
$memory->configureAdaptive($tenantId, [
    'retention_policy' => [
        'name' => 'research_focused',
        'retention_weights' => [
            'surprise' => 0.35,      // High weight on surprise
            'contradiction' => 0.25,  // Preserve contradictions
            'temporal' => 0.10,       // Less emphasis on recency
            'evidence' => 0.20,       // High weight on evidence
            'usage' => 0.10
        ],
        'promotion_threshold' => 0.65,
        'compression_threshold' => 0.35,
        'compression_age_days' => 60, // Compress after 60 days
        'temporal_half_life_days' => 14
    ],
    'surprise_weights' => [
        'novelty' => 0.40,
        'contradiction' => 0.30,
        'evidence' => 0.20,
        'confidence_shift' => 0.10
    ]
]);

echo "Custom retention policy configured\n\n";

/**
 * EXAMPLE 4: Query by Surprise Threshold
 */
echo "4. Query by Surprise Threshold\n";
echo "===============================\n\n";

// Query high-surprise memories
$highSurpriseMemories = $memory->queryAdaptive($tenantId, [
    'surprise_threshold' => 0.7,
    'filters' => ['agent_id' => $agentId],
    'prioritize' => true
]);

echo "High-surprise memories found: " . count($highSurpriseMemories['adaptive_filtered']) . "\n";
echo "Top 3 by surprise:\n";
foreach (array_slice($highSurpriseMemories['adaptive_filtered'], 0, 3) as $mem) {
    $surprise = $mem['surprise_score'] ?? 0;
    $content = substr($mem['content']['content'] ?? 'N/A', 0, 60);
    echo "- [Score: " . round($surprise, 2) . "] {$content}...\n";
}
echo "\n";

/**
 * EXAMPLE 5: Retention Evaluation (Non-Enforcing)
 */
echo "5. Retention Evaluation & Recommendations\n";
echo "==========================================\n\n";

// Simulate aging by storing old memories
for ($i = 0; $i < 10; $i++) {
    $memory->storeMemoryAdaptive($tenantId, $agentId, [
        'type' => 'old_note',
        'content' => "Old routine note from {$i} weeks ago",
        'metadata' => [
            'timestamp' => time() - ($i * 7 * 86400) // Weeks ago
        ]
    ], [
        'magnitude' => 0.2 // Low surprise
    ]);
}

// Evaluate retention
$evaluation = $memory->evaluateRetention($tenantId);

echo "Retention evaluation:\n";
echo "- Memory distribution:\n";
foreach ($evaluation['status']['distribution'] as $layer => $count) {
    echo "  {$layer}: {$count}\n";
}
echo "- Forgetting candidates: {$evaluation['status']['forgetting_candidates']}\n";
echo "- Compression recommendations: " . count($evaluation['recommendations']['compress']) . "\n";
echo "- Promotion recommendations: " . count($evaluation['recommendations']['promote']) . "\n";
echo "\nNote: " . $evaluation['note'] . "\n\n";

/**
 * EXAMPLE 6: Usage-Based Learning (ATLAS)
 */
echo "6. Usage-Based Learning (ATLAS)\n";
echo "================================\n\n";

// Simulate memory access patterns
$accessLog = [];
for ($i = 0; $i < 5; $i++) {
    $memoryId = $highSurpriseResult['adaptive_id'];
    $accessLog[] = [
        'memory_id' => $memoryId,
        'utility' => 0.9, // This memory was very useful
        'timestamp' => time()
    ];
}

// Record usage
$memory->recordMemoryUsage($tenantId, $accessLog);

echo "Recorded {$accessLog[0]['memory_id']} accessed 5 times with high utility\n";
echo "Importance will be boosted via ATLAS learning\n\n";

/**
 * EXAMPLE 7: Hierarchical Compression in Action
 */
echo "7. Hierarchical Compression Demonstration\n";
echo "==========================================\n\n";

// Get comprehensive metrics
$metrics = $memory->getAdaptiveMetrics($tenantId);

echo "Adaptive memory metrics:\n";
echo "- Total memories: {$metrics['narrative_memories']}\n";
echo "- Memory distribution:\n";
foreach ($metrics['memory_distribution'] as $layer => $count) {
    echo "  {$layer} layer: {$count}\n";
}
echo "- Overall compression ratio: " . round($metrics['compression_ratio'], 3) . "\n";
echo "- Storage saved: " . round($metrics['storage_saved_bytes'] / 1024, 2) . " KB\n";
echo "- Surprise statistics:\n";
echo "  Mean: " . round($metrics['surprise_statistics']['mean'], 3) . "\n";
echo "  Median: " . round($metrics['surprise_statistics']['median'], 3) . "\n";
echo "  Std Dev: " . round($metrics['surprise_statistics']['std'], 3) . "\n";
echo "- Forgetting candidates: {$metrics['forgetting_candidates']}\n\n";

/**
 * EXAMPLE 8: Multi-Agent with Adaptive Memory
 */
echo "8. Multi-Agent Adaptive Memory\n";
echo "===============================\n\n";

$agent1 = 'researcher_alice';
$agent2 = 'researcher_bob';

// Alice finds something surprising
$memory->storeMemoryAdaptive($tenantId, $agent1, [
    'type' => 'hypothesis',
    'content' => 'I hypothesize that factor X is the primary driver',
    'claims' => [
        ['text' => 'Factor X drives the outcome', 'confidence' => ['min' => 0.6, 'max' => 0.8, 'mean' => 0.7]]
    ]
], ['magnitude' => 0.7]);

// Bob contradicts with high surprise
$memory->storeMemoryAdaptive($tenantId, $agent2, [
    'type' => 'counter_hypothesis',
    'content' => 'My analysis shows factor Y is actually the driver, not X',
    'claims' => [
        ['text' => 'Factor Y drives the outcome, not X', 'confidence' => ['min' => 0.7, 'max' => 0.9, 'mean' => 0.8]]
    ]
], ['magnitude' => 0.85]); // Higher surprise due to contradiction

// Both agents' contexts will show high-surprise contradicting memories
$aliceContext = $memory->buildOptimizedContext($tenantId, $agent1);
$bobContext = $memory->buildOptimizedContext($tenantId, $agent2);

echo "Agent contexts built:\n";
echo "- Alice sees {$aliceContext['adaptive']['prioritized_memories']} prioritized memories\n";
echo "- Bob sees {$bobContext['adaptive']['prioritized_memories']} prioritized memories\n";
echo "- Both see high-surprise contradictions preserved\n\n";

/**
 * EXAMPLE 9: Long-Term Scaling Demonstration
 */
echo "9. Long-Term Scaling Simulation\n";
echo "================================\n\n";

echo "Storing 1000 memories with varying surprise...\n";
$startTime = microtime(true);

for ($i = 0; $i < 1000; $i++) {
    $surprise = rand(10, 90) / 100;
    $memory->storeMemoryAdaptive($tenantId, $agentId, [
        'type' => 'long_term_memory',
        'content' => "Memory {$i}: " . str_repeat("Data ", rand(10, 50))
    ], ['magnitude' => $surprise]);
    
    if ($i % 100 == 0 && $i > 0) {
        echo "  {$i} memories stored...\n";
    }
}

$duration = microtime(true) - $startTime;

echo "Done in " . round($duration, 2) . " seconds\n";
echo "Average: " . round($duration / 1000, 4) . " sec per memory\n\n";

// Get final metrics
$finalMetrics = $memory->getAdaptiveMetrics($tenantId);

echo "Final system state:\n";
echo "- Total memories: {$finalMetrics['narrative_memories']}\n";
echo "- Compression saved: " . round($finalMetrics['storage_saved_bytes'] / 1024 / 1024, 2) . " MB\n";
echo "- Memory layers:\n";
foreach ($finalMetrics['memory_distribution'] as $layer => $count) {
    $percentage = round(($count / $finalMetrics['narrative_memories']) * 100, 1);
    echo "  {$layer}: {$count} ({$percentage}%)\n";
}
echo "\n";

/**
 * EXAMPLE 10: Integration with Original v1 Features
 */
echo "10. Integration with v1 Core Features\n";
echo "======================================\n\n";

// v2 adaptive features work seamlessly with v1 core
$result = $memory->storeMemoryAdaptive($tenantId, $agentId, [
    'type' => 'integrated_memory',
    'content' => 'This memory uses both v1 core (mindscape, graph, gnosis) and v2 adaptive (surprise, retention, ATLAS)',
    'claims' => [
        ['text' => 'Integration works seamlessly', 'confidence' => ['min' => 0.9, 'max' => 1.0, 'mean' => 0.95]]
    ]
], ['magnitude' => 0.8]);

echo "Memory stored with full integration:\n";
echo "- v1 features: Mindscape ✓ Graph ✓ Gnosis ✓\n";
echo "- v2 features: Adaptive ✓ Surprise ✓ ATLAS ✓\n";
echo "- Memory ID: {$result['memory_id']}\n";
echo "- Beliefs tracked: " . count($result['beliefs']) . "\n";
echo "- Graph nodes: " . count($result['graph']['nodes_created'] ?? []) . "\n";
echo "- Surprise score: {$result['surprise_score']}\n";

echo "\n=== All Examples Complete ===\n";
echo "\nKey Takeaways:\n";
echo "1. Surprise signals help prioritize important memories\n";
echo "2. ATLAS priority optimizes context retrieval\n";
echo "3. Retention policies are configurable but never enforced\n";
echo "4. Hierarchical compression saves storage while preserving semantics\n";
echo "5. Usage patterns improve memory importance over time\n";
echo "6. All v2 features integrate seamlessly with v1 core\n";
echo "7. ZionXMemory remains a substrate—agents control their own behavior\n";
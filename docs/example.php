<?php

require_once __DIR__ . '/vendor/autoload.php';

use ZionXMemory\Orchestrator\HybridMemory;
use ZionXMemory\Storage\RedisAdapter;
use ZionXMemory\AI\Adapters\GeminiAdapter;
use ZionXMemory\Audit\AuditLogger;

// Initialize storage
$storage = new RedisAdapter();
$storage->connect([
    'host' => '127.0.0.1',
    'port' => 6379
]);

// Initialize AI adapter (Gemini)
$ai = new GeminiAdapter();
$ai->configure([
    'api_key' => getenv('GEMINI_API_KEY'),
    'model' => 'gemini-3-flash-preview',
    // Optional: tune retry behaviour for production
    'retry' => ['max_attempts' => 4, 'base_delay_ms' => 250]
]);

// Initialize audit logger
$audit = new AuditLogger($storage);

// Create HybridMemory orchestrator
$memory = new HybridMemory($storage, $ai, $audit);

// Agent configuration
$tenantId = 'company_xyz';
$agentId = 'research_agent_001';

// Store interaction
$result = $memory->storeMemory($tenantId, $agentId, [
    'type' => 'research_finding',
    'content' => 'Based on recent papers, transformer models show 15% improvement in long-context tasks when using hierarchical attention. However, this comes at a 30% computational cost.',
    'claims' => [
        [
            'text' => 'Transformer models with hierarchical attention show 15% improvement in long-context tasks',
            'confidence' => ['min' => 0.7, 'max' => 0.9, 'mean' => 0.8]
        ],
        [
            'text' => 'Hierarchical attention increases computational cost by 30%',
            'confidence' => ['min' => 0.6, 'max' => 0.8, 'mean' => 0.7]
        ]
    ],
    'metadata' => [
        'sources' => ['arxiv:2401.12345', 'arxiv:2402.54321'],
        'research_area' => 'transformer_optimization'
    ]
]);

echo "Memory stored: {$result['memory_id']}\n";
echo "Beliefs created: " . count($result['beliefs']) . "\n";
echo "Graph nodes: " . count($result['graph']['nodes_created']) . "\n\n";

// Later: Retrieve context for the agent
$context = $memory->buildContextSnapshot($tenantId, $agentId, [
    'max_tokens' => 8000,
    'include_graph' => true,
    'include_beliefs' => true,
    'include_contradictions' => true
]);

echo "Context snapshot built:\n";
echo "- Narrative memories: " . count($context['narrative']) . "\n";
echo "- Graph nodes: " . count($context['knowledge_graph']['nodes'] ?? []) . "\n";
echo "- Active beliefs: " . ($context['epistemic_state']['stats']['total'] ?? 0) . "\n";
echo "- Contradictions found: " . count($context['contradictions']) . "\n\n";

// Update belief state when new evidence emerges
if (!empty($result['beliefs'])) {
    $beliefId = $result['beliefs'][0];
    $memory->updateBelief(
        $tenantId,
        $beliefId,
        'accepted',
        'Confirmed by additional peer-reviewed papers',
        $agentId
    );
    echo "Belief updated to 'accepted' state\n";
}

/**
 * EXAMPLE 2: Multi-Agent Collaboration with Disagreements
 */

echo "\n=== Multi-Agent Example ===\n\n";

$agent1 = 'analyst_alice';
$agent2 = 'analyst_bob';

// Agent 1 stores a belief
$result1 = $memory->storeMemory($tenantId, $agent1, [
    'type' => 'market_analysis',
    'content' => 'Q4 revenue projections should be conservative given market volatility. I estimate 10% growth.',
    'claims' => [
        ['text' => 'Q4 revenue will grow by 10%', 'confidence' => ['min' => 0.5, 'max' => 0.7, 'mean' => 0.6]]
    ]
]);

echo "Agent 1 ({$agent1}) belief: " . $result1['beliefs'][0] . "\n";

// Agent 2 stores a contradicting belief
$result2 = $memory->storeMemory($tenantId, $agent2, [
    'type' => 'market_analysis',
    'content' => 'Market indicators suggest strong Q4 performance. My projection is 25% growth.',
    'claims' => [
        ['text' => 'Q4 revenue will grow by 25%', 'confidence' => ['min' => 0.6, 'max' => 0.8, 'mean' => 0.7]]
    ]
]);

echo "Agent 2 ({$agent2}) belief: " . $result2['beliefs'][0] . "\n\n";

// Both beliefs persist - no forced consensus
$contextAgent1 = $memory->buildContextSnapshot($tenantId, $agent1);
$contextAgent2 = $memory->buildContextSnapshot($tenantId, $agent2);

echo "Agent 1 sees contradictions: " . count($contextAgent1['contradictions']) . "\n";
echo "Agent 2 sees contradictions: " . count($contextAgent2['contradictions']) . "\n";
echo "Both agents maintain their own epistemic states\n";

/**
 * EXAMPLE 3: Long-Term Memory Evolution
 */

echo "\n=== Long-Term Memory Evolution ===\n\n";

// Simulate months of interactions
$agentId = 'longterm_agent';

echo "Storing 100 interactions over simulated time...\n";

for ($i = 0; $i < 100; $i++) {
    $memory->storeMemory($tenantId, $agentId, [
        'type' => 'daily_update',
        'content' => "Day {$i}: Processed " . rand(100, 1000) . " documents. Key finding: " . uniqid(),
        'metadata' => [
            'day' => $i,
            'simulated_timestamp' => time() - (100 - $i) * 86400
        ]
    ]);
}

echo "Done. Checking memory stratification...\n\n";

// Get health metrics
$metrics = $memory->getHealthMetrics($tenantId);
echo "Total narrative memories: {$metrics['narrative_memories']}\n";
echo "Graph nodes created: {$metrics['graph_nodes']}\n";
echo "Graph edges created: {$metrics['graph_edges']}\n";
echo "Total beliefs tracked: {$metrics['beliefs']}\n";
echo "Audit records: {$metrics['audit_records']}\n";

// Get context - should use hierarchical summaries
$context = $memory->buildContextSnapshot($tenantId, $agentId, [
    'max_tokens' => 4000
]);

echo "\nContext built with token budget: 4000\n";
echo "Hot layer memories: " . count($context['narrative']['layers']['hot']['memories'] ?? []) . "\n";
echo "Warm layer: " . ($context['narrative']['layers']['warm']['type'] ?? 'N/A') . "\n";
echo "Cold layer: " . ($context['narrative']['layers']['cold']['type'] ?? 'N/A') . "\n";
echo "Frozen layer: " . ($context['narrative']['layers']['frozen']['type'] ?? 'N/A') . "\n";

/**
 * EXAMPLE 4: Memory Lineage and Audit Trail
 */

echo "\n=== Memory Lineage and Audit ===\n\n";

// Store a memory that will evolve
$result = $memory->storeMemory($tenantId, 'researcher', [
    'type' => 'hypothesis',
    'content' => 'Initial hypothesis: Feature X causes outcome Y',
    'claims' => [
        ['text' => 'Feature X causes outcome Y', 'confidence' => ['min' => 0.3, 'max' => 0.5, 'mean' => 0.4]]
    ]
]);

$memoryId = $result['memory_id'];
$beliefId = $result['beliefs'][0];

echo "Created hypothesis: {$memoryId}\n";

// Update belief multiple times as evidence comes in
$memory->updateBelief($tenantId, $beliefId, 'contested', 'Mixed results from experiment 1', 'researcher');
sleep(1);
$memory->updateBelief($tenantId, $beliefId, 'accepted', 'Confirmed by experiments 2 and 3', 'researcher');
sleep(1);
$memory->updateBelief($tenantId, $beliefId, 'deprecated', 'Superseded by more refined model', 'researcher');

echo "Belief updated 3 times\n\n";

// Get complete lineage
$lineage = $memory->getMemoryLineage($tenantId, $memoryId);
echo "Memory lineage:\n";
echo "- Mindscape versions: " . count($lineage['mindscape_lineage']) . "\n";
echo "- Belief state transitions: " . count($lineage['belief_history'][0] ?? []) . "\n";

// Verify audit trail integrity
$integrityCheck = $audit->verifyChainIntegrity($tenantId);
echo "\nAudit chain integrity: " . ($integrityCheck['valid'] ? 'VALID' : 'BROKEN') . "\n";
echo "Total audit records: {$integrityCheck['total_records']}\n";

if (!$integrityCheck['valid']) {
    echo "WARNING: Broken links detected at positions: ";
    foreach ($integrityCheck['broken_links'] as $link) {
        echo $link['position'] . " ";
    }
    echo "\n";
}

/**
 * EXAMPLE 5: Query Across All Layers
 */

echo "\n=== Complex Query Example ===\n\n";

$results = $memory->queryMemory($tenantId, [
    'filters' => [
        'agent_id' => 'researcher',
        'after_timestamp' => time() - 86400 // Last 24 hours
    ],
    'layers' => [
        'mindscape' => true,
        'graph' => true,
        'gnosis' => true
    ],
    'belief_state' => 'accepted',
    'min_confidence' => 0.7
]);

echo "Query results:\n";
echo "- Narrative memories: " . count($results['narrative']) . "\n";
echo "- Graph entities: " . count($results['graph']['nodes'] ?? []) . "\n";
echo "- High-confidence beliefs: " . count($results['beliefs']) . "\n";

echo "\n=== All Examples Complete ===\n";
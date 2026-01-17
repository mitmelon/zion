<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ZionXMemory\Orchestrator\HybridMemory;
use ZionXMemory\Storage\Adapters\RedisAdapter;
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
    'api_key' => '',
    'model' => 'gemini-3-flash-preview', //(or other available model)
    // Optional: tune retry behaviour for production
    //'retry' => ['max_attempts' => 4, 'base_delay_ms' => 250]
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
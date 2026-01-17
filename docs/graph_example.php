<?php

/**
 * ZionXMemory - Knowledge Graph Integration Examples
 * Shows how KG materializes wisdom from memory WITHOUT replacing it
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ZionXMemory\Orchestrator\HybridMemoryV2;
use ZionXMemory\Graph\GraphStore;
use ZionXMemory\Graph\GraphIngestor;
use ZionXMemory\Graph\GraphQueryService;
use ZionXMemory\Graph\GraphConsistencyChecker;
use ZionXMemory\Graph\EpistemicStatusTracker;
use ZionXMemory\Graph\DecisionLineageTracker;
use ZionXMemory\Graph\MinorityOpinionTracker;
use ZionXMemory\Graph\InstitutionalMemorySeparator;
use ZionXMemory\Graph\SelfAuditSystem;
use ZionXMemory\Storage\Adapters\RedisAdapter;
use ZionXMemory\AI\Adapters\GeminiAdapter;
use ZionXMemory\Audit\AuditLogger;

// Initialize base system
$storage = new RedisAdapter();
$storage->connect(['host' => '127.0.0.1', 'port' => 6379]);

$ai = new GeminiAdapter();
$ai->configure([
    'api_key' => getenv('GEMINI_API_KEY'),
    'model' => 'gemini-3-flash-preview',
    // Optional: tune retry behaviour for production
    'retry' => ['max_attempts' => 4, 'base_delay_ms' => 250]
]);

$audit = new AuditLogger($storage);
$memory = new HybridMemoryV2($storage, $ai, $audit);

// Initialize Knowledge Graph components
$graphStore = new GraphStore($storage);
$graphIngestor = new GraphIngestor($graphStore, $storage, $ai, $audit);
$graphQuery = new GraphQueryService($graphStore, $storage);
$consistencyChecker = new GraphConsistencyChecker($graphStore, $storage);

// Initialize epistemic and decision tracking
$epistemicTracker = new EpistemicStatusTracker($storage, $audit);
$decisionTracker = new DecisionLineageTracker($storage, $audit);
$minorityTracker = new MinorityOpinionTracker($storage, $audit);
$institutionalMemory = new InstitutionalMemorySeparator($storage, $audit, $epistemicTracker);
$selfAudit = new SelfAuditSystem($storage, $epistemicTracker, $consistencyChecker);

$tenantId = 'enterprise_xyz';
$sessionId = 'session_2026_01';

echo "=== ZionXMemory Knowledge Graph Integration ===\n\n";

/**
 * EXAMPLE 1: Store Memory → Auto-Ingest to Graph
 */
echo "1. Memory Storage with Graph Ingestion\n";
echo "========================================\n\n";

// Store claim in memory
$claimResult = $memory->storeMemoryAdaptive($tenantId, 'agent_alice', [
    'type' => 'research_claim',
    'content' => 'Blogging in 2026 carries significant legal risk due to new regulations',
    'claims' => [
        [
            'text' => 'Blogging is legally risky in 2026',
            'confidence' => ['min' => 0.7, 'max' => 0.85, 'mean' => 0.78],
            'topic' => 'Blogging in 2026'
        ]
    ],
    'evidence' => [
        ['source' => 'Regulation XYZ-2026', 'quality' => 0.9]
    ]
], ['magnitude' => 0.7]);

// Store in session for later graph ingestion
$sessionKey = "session:{$tenantId}:{$sessionId}:claims";
$sessionClaims = $storage->read($sessionKey) ?? [];
$sessionClaims[] = [
    'id' => $claimResult['memory_id'],
    'claim' => 'Blogging is legally risky in 2026',
    'confidence' => ['min' => 0.7, 'max' => 0.85, 'mean' => 0.78],
    'topic' => 'Blogging in 2026',
    'evidence' => [['source' => 'Regulation XYZ-2026', 'quality' => 0.9]]
];
$storage->write($sessionKey, $sessionClaims, ['tenant' => $tenantId]);

// Ingest into Knowledge Graph (OPTIONAL - doesn't affect memory)
if (true) { // Graph enabled
    $ingestionStats = $graphIngestor->ingestFromSession($tenantId, $sessionId);
    echo "Graph ingestion completed:\n";
    echo "- Entities created: {$ingestionStats['entities_created']}\n";
    echo "- Relations created: {$ingestionStats['relations_created']}\n";
    echo "- Claims processed: {$ingestionStats['claims_processed']}\n\n";
}

/**
 * EXAMPLE 2: Epistemic Status Tracking
 */
echo "2. Epistemic Status Classification\n";
echo "===================================\n\n";

$claimId = $claimResult['memory_id'];

// Initially it's evidence-based
$epistemicTracker->setStatus($tenantId, $claimId, 
    $epistemicTracker::STATUS_EVIDENCE,
    [
        'reason' => 'Based on regulation document',
        'agent_id' => 'agent_alice',
        'confidence' => 0.78
    ]
);

echo "Claim marked as EVIDENCE\n";

// Later, someone contests it
$epistemicTracker->setStatus($tenantId, $claimId,
    $epistemicTracker::STATUS_CONTESTED,
    [
        'reason' => 'Agent Bob disputes interpretation',
        'agent_id' => 'agent_bob'
    ]
);

echo "Claim updated to CONTESTED\n";

// Query: What's our reasoning basis?
$reasoningBasis = $epistemicTracker->getReasoningBasis($tenantId, [$claimId]);
echo "\nReasoning basis analysis:\n";
echo "- Fact ratio: " . round($reasoningBasis['fact_ratio'], 2) . "\n";
echo "- Assumption ratio: " . round($reasoningBasis['assumption_ratio'], 2) . "\n";
echo "- Quality: {$reasoningBasis['reasoning_quality']}\n\n";

/**
 * EXAMPLE 3: Decision Lineage Tracking
 */
echo "3. Decision Lineage for Report Generation\n";
echo "==========================================\n\n";

$decisionId = 'decision_postpone_blog';

$decisionTracker->recordDecision($tenantId, $decisionId, [
    'decision' => 'Postpone blogging initiative until legal clarity',
    'claims_used' => [
        ['claim_id' => $claimId, 'weight' => 0.9]
    ],
    'claims_rejected' => [
        ['claim_id' => 'claim_optimistic', 'reason' => 'Insufficient evidence']
    ],
    'conflicts_unresolved' => [
        ['conflict_id' => 'legal_interpretation_dispute']
    ],
    'reasoning' => [
        'Primary concern is legal risk',
        'Alternative approaches being explored',
        'Regulatory clarity expected Q2 2026'
    ]
]);

echo "Decision recorded with full lineage\n";

// Generate 18-page report-ready structure
$report = $decisionTracker->generateDecisionReport($tenantId, $decisionId);
echo "\nDecision report sections:\n";
foreach (array_keys($report['sections']) as $section) {
    echo "- {$section}\n";
}
echo "\n";

/**
 * EXAMPLE 4: Minority Opinion Preservation
 */
echo "4. Minority Opinion Tracking\n";
echo "============================\n\n";

// Agent Bob dissents
$minorityTracker->recordMinorityOpinion($tenantId, $sessionId, [
    'agent_id' => 'agent_bob',
    'position' => 'Legal risk is overstated, blogging is safe',
    'reasoning' => ['Regulation applies to commercial entities only'],
    'confidence' => 0.7,
    'majority_position' => 'Blogging is legally risky',
    'topic' => 'Blogging in 2026'
]);

echo "Minority opinion recorded for agent_bob\n";

// Later: Outcome shows Bob was correct
$minorityTracker->trackAccuracy($tenantId, 'agent_bob', [
    [
        'opinion_id' => 'minority_...',  // Would be actual ID
        'actual_outcome' => 'Legal risk is overstated, blogging is safe'
    ]
]);

// Get reliable dissenters
$reliableDissenters = $minorityTracker->getReliableDissenters($tenantId, [
    'min_accuracy' => 0.6,
    'min_opinions' => 1
]);

echo "Reliable dissenters found: " . count($reliableDissenters) . "\n";
if (!empty($reliableDissenters)) {
    echo "Top dissenter: {$reliableDissenters[0]['agent_id']} ";
    echo "(accuracy: " . round($reliableDissenters[0]['accuracy'], 2) . ")\n";
}
echo "\n";

/**
 * EXAMPLE 5: Session → Institutional Memory Promotion
 */
echo "5. Institutional Memory Promotion\n";
echo "==================================\n\n";

// Promote session claims that survived debate
$promotionResult = $institutionalMemory->promoteToInstitutional(
    $tenantId,
    $sessionId,
    [
        'min_confidence' => 0.7,
        'min_agreement' => 0.6,
        'require_evidence' => true
    ]
);

echo "Session memory promotion:\n";
echo "- Promoted: " . count($promotionResult['promoted']) . "\n";
echo "- Rejected: " . count($promotionResult['rejected']) . "\n";
echo "- Promotion rate: " . round($promotionResult['promotion_rate'] * 100, 1) . "%\n\n";

/**
 * EXAMPLE 6: Query Historical Facts from Graph
 */
echo "6. Querying Historical Facts\n";
echo "=============================\n\n";

$facts = $graphQuery->getHistoricalFacts('Blogging in 2026', $tenantId, [
    'include_contradictions' => true,
    'min_confidence' => 0.0
]);

if ($facts['found']) {
    echo "Topic: {$facts['topic']}\n";
    echo "Entity confidence: " . round($facts['entity_confidence'], 2) . "\n";
    echo "Facts found: " . count($facts['facts']) . "\n";
    
    foreach ($facts['facts'] as $fact) {
        echo "\n- {$fact['relation']}";
        echo " (confidence: " . round($fact['confidence'], 2) . ")";
        if (!empty($fact['contradictions'])) {
            echo " [HAS CONTRADICTIONS]";
        }
    }
    echo "\n\n";
}

/**
 * EXAMPLE 7: Contradiction Detection
 */
echo "7. Contradiction Detection\n";
echo "==========================\n\n";

// Add conflicting claim
$conflictingClaim = $memory->storeMemoryAdaptive($tenantId, 'agent_charlie', [
    'type' => 'counter_claim',
    'content' => 'Blogging in 2026 is completely safe',
    'claims' => [
        [
            'text' => 'Blogging has no legal risk in 2026',
            'confidence' => ['min' => 0.6, 'max' => 0.8, 'mean' => 0.7],
            'topic' => 'Blogging in 2026'
        ]
    ]
], ['magnitude' => 0.6]);

// Check consistency
$contradictions = $consistencyChecker->getContradictionSummary($tenantId);

echo "Contradiction analysis:\n";
echo "- Total conflicts: {$contradictions['total_conflicts']}\n";
echo "- High severity: {$contradictions['high_severity']}\n";
echo "- Medium severity: {$contradictions['medium_severity']}\n";
echo "- Low severity: {$contradictions['low_severity']}\n\n";

/**
 * EXAMPLE 8: Self-Audit System
 */
echo "8. Self-Audit: Wisdom Compounding\n";
echo "==================================\n\n";

// Find weakly supported beliefs
$weaklySupported = $selfAudit->findWeaklySupported($tenantId, [
    'min_confidence' => 0.7,
    'max_evidence' => 2
]);

echo "Weakly supported claims: " . count($weaklySupported) . "\n";
if (!empty($weaklySupported)) {
    $top = $weaklySupported[0];
    echo "Highest risk: '{$top['claim']}'\n";
    echo "  Confidence: " . round($top['confidence'], 2) . "\n";
    echo "  Evidence: {$top['evidence_count']}\n";
    echo "  Risk score: " . round($top['risk_score'], 2) . "\n";
}

// Get wisdom metrics
echo "\nWisdom metrics:\n";
$wisdom = $selfAudit->getWisdomMetrics($tenantId);
echo "- Institutional memory: {$wisdom['institutional_memory_count']} items\n";
echo "- Evidence-based: {$wisdom['evidence_count']}\n";
echo "- Confirmed: {$wisdom['confirmed_count']}\n";
echo "- Minority accuracy: " . round($wisdom['minority_accuracy'] * 100, 1) . "%\n";
echo "- Wisdom score: " . round($wisdom['wisdom_score'], 2) . "\n";
echo "- Trend: {$wisdom['trending']}\n\n";

/**
 * EXAMPLE 9: Cognitive Load Protection
 */
echo "9. Context Compression with Semantic Preservation\n";
echo "==================================================\n\n";

// Get institutional memory (compressed, but semantically complete)
$institutional = $institutionalMemory->getInstitutional($tenantId, [
    'min_confidence' => 0.6
]);

echo "Institutional memory items: " . count($institutional) . "\n";
echo "Compressed for context efficiency\n";
echo "Logic chains preserved\n";
echo "Conclusions never dropped\n\n";

/**
 * EXAMPLE 10: End-to-End Flow
 */
echo "10. Complete Flow: Memory → Graph → Decision\n";
echo "=============================================\n\n";

echo "STEP 1: Agents deliberate in session\n";
echo "  → Memory stores raw conversations\n";
echo "  → Epistemic status tracked\n";
echo "  → Minority opinions preserved\n\n";

echo "STEP 2: Session concludes\n";
echo "  → Promote high-quality claims to institutional\n";
echo "  → Ingest into Knowledge Graph\n";
echo "  → Update contradiction indices\n\n";

echo "STEP 3: Agents query for decision-making\n";
echo "  → getHistoricalFacts() returns cross-session wisdom\n";
echo "  → Contradictions explicitly surfaced\n";
echo "  → Confidence-weighted consensus available\n\n";

echo "STEP 4: Decision made\n";
echo "  → Full lineage recorded\n";
echo "  → Claims used/rejected tracked\n";
echo "  → 18-page report generateable\n\n";

echo "STEP 5: System self-audits\n";
echo "  → \"What do we believe strongly with weak evidence?\"\n";
echo "  → Minority accuracy tracked over time\n";
echo "  → Wisdom compounds automatically\n\n";

echo "=== Key Advantages ===\n\n";
echo "1. Epistemic Honesty: Facts vs assumptions always clear\n";
echo "2. Minority Wisdom: Dissent preserved, accuracy tracked\n";
echo "3. Decision Lineage: Complete provenance for audits\n";
echo "4. Contradiction Awareness: Never silently ignored\n";
echo "5. Institutional Memory: Only what survived debate\n";
echo "6. Self-Examination: System questions itself\n";
echo "7. Knowledge Graph: Derived, not replacing memory\n\n";

echo "ZionXMemory: A substrate for collective reasoning across time.\n";
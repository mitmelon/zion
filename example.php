<?php

declare(strict_types=1);

/**
 * Banking AI Memory System - Example Usage
 * 
 * This example demonstrates the full capabilities of the Hybrid AI Memory System:
 * - Mindscape RAG (contextual narrative memory)
 * - Graph RAG (structured factual memory)
 * - AI-driven summarization and fact extraction
 * - Fact validation and contradiction detection
 * - Conflict resolution strategies
 * - Multi-agent reasoning
 * - Audit logging for compliance
 */

require_once __DIR__ . '/vendor/autoload.php';

use Zion\Memory\HybridMemory;
use Zion\Memory\Memory\MindscapeMemory;
use Zion\Memory\Graph\GraphMemory;
use Zion\Memory\Storage\FileStorageAdapter;
use Zion\Memory\Storage\FileGraphAdapter;
use Zion\Memory\Storage\InMemoryCache;
use Zion\Memory\AI\GeminiProvider;
use Zion\Memory\AI\AISummarizer;
use Zion\Memory\AI\AIFactExtractor;
use Zion\Memory\Validation\FactValidator;
use Zion\Memory\Validation\ConflictResolver;
use Zion\Memory\Validation\ConflictResolutionStrategy;
use Zion\Memory\Audit\AuditLogger;
use Zion\Memory\Agents\BaseAgent;
use Zion\Memory\Agents\MultiAgentCoordinator;

// ============================================================================
// CONFIGURATION
// ============================================================================

$config = [
    'gemini_api_key' => getenv('GEMINI_API_KEY') ?: 'your-api-key-here',
    'storage_path' => __DIR__ . '/data/storage',
    'graph_path' => __DIR__ . '/data/graph',
    'audit_path' => __DIR__ . '/data/audit',
    'tenant_id' => 'bank_tenant_001',
    'session_id' => 'session_' . date('Y-m-d_H-i-s'),
];

// Ensure directories exist
foreach (['storage_path', 'graph_path', 'audit_path'] as $pathKey) {
    if (!is_dir($config[$pathKey])) {
        mkdir($config[$pathKey], 0755, true);
    }
}

echo "============================================================\n";
echo "       ZION MEMORY SYSTEM - DEMONSTRATION\n";
echo "============================================================\n\n";

// ============================================================================
// STEP 1: INITIALIZE CORE COMPONENTS
// ============================================================================

echo ">>> STEP 1: Initializing Core Components\n";
echo str_repeat('-', 60) . "\n";

// Cache layer for performance
$cache = new InMemoryCache();

// AI Provider (Gemini)
$aiProvider = new GeminiProvider($config['gemini_api_key']);

// Storage adapters
$storageAdapter = new FileStorageAdapter($config['storage_path'], $cache);
$graphAdapter = new FileGraphAdapter($config['graph_path']);

// AI modules
$summarizer = new AISummarizer($aiProvider);
$factExtractor = new AIFactExtractor($aiProvider);

// Validation modules
$factValidator = new FactValidator($aiProvider);
$conflictResolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);

// Audit logger
$auditLogger = new AuditLogger($config['audit_path']);

// Memory systems
$mindscapeMemory = new MindscapeMemory(
    $storageAdapter,
    $summarizer,
    $config['tenant_id'],
    $config['session_id']
);

$graphMemory = new GraphMemory(
    $graphAdapter,
    $factExtractor,
    $factValidator,
    $config['tenant_id']
);

echo "[✓] Cache layer initialized\n";
echo "[✓] AI Provider (Gemini) initialized\n";
echo "[✓] Storage adapters initialized\n";
echo "[✓] Summarizer and Fact Extractor initialized\n";
echo "[✓] Validation modules initialized\n";
echo "[✓] Audit logger initialized\n";
echo "[✓] Mindscape Memory (RAG) initialized\n";
echo "[✓] Graph Memory (RAG) initialized\n\n";

// ============================================================================
// STEP 2: INITIALIZE MULTI-AGENT SYSTEM
// ============================================================================

echo ">>> STEP 2: Initializing Multi-Agent System\n";
echo str_repeat('-', 60) . "\n";

// Create specialized banking agents
class ComplianceAgent extends BaseAgent
{
    public function __construct($aiProvider)
    {
        parent::__construct('compliance_agent', 'compliance', $aiProvider, 100);
    }

    public function process(array $context): array
    {
        return [
            'agent_id' => $this->getAgentId(),
            'agent_type' => $this->getAgentType(),
            'analysis' => 'Compliance check: Transaction patterns reviewed for regulatory alignment.',
            'flags' => [],
            'recommendations' => ['Ensure KYC documentation is current'],
            'confidence' => 0.92,
        ];
    }
}

class RiskAnalysisAgent extends BaseAgent
{
    public function __construct($aiProvider)
    {
        parent::__construct('risk_agent', 'risk_analysis', $aiProvider, 90);
    }

    public function process(array $context): array
    {
        return [
            'agent_id' => $this->getAgentId(),
            'agent_type' => $this->getAgentType(),
            'analysis' => 'Risk assessment: Customer profile indicates low-moderate risk.',
            'risk_score' => 35,
            'risk_factors' => ['New account', 'Standard transaction patterns'],
            'confidence' => 0.88,
        ];
    }
}

class CustomerInsightAgent extends BaseAgent
{
    public function __construct($aiProvider)
    {
        parent::__construct('insight_agent', 'customer_insight', $aiProvider, 80);
    }

    public function process(array $context): array
    {
        return [
            'agent_id' => $this->getAgentId(),
            'agent_type' => $this->getAgentType(),
            'analysis' => 'Customer insight: Premium banking candidate with growth potential.',
            'opportunities' => ['Investment products', 'Premium credit card'],
            'preferences' => ['Digital banking', 'Quick responses'],
            'confidence' => 0.85,
        ];
    }
}

$complianceAgent = new ComplianceAgent($aiProvider);
$riskAgent = new RiskAnalysisAgent($aiProvider);
$insightAgent = new CustomerInsightAgent($aiProvider);

$coordinator = new MultiAgentCoordinator(
    [$complianceAgent, $riskAgent, $insightAgent],
    $conflictResolver
);

echo "[✓] Compliance Agent (priority: 100) initialized\n";
echo "[✓] Risk Analysis Agent (priority: 90) initialized\n";
echo "[✓] Customer Insight Agent (priority: 80) initialized\n";
echo "[✓] Multi-Agent Coordinator initialized\n\n";

// ============================================================================
// STEP 3: INITIALIZE HYBRID MEMORY ORCHESTRATOR
// ============================================================================

echo ">>> STEP 3: Initializing Hybrid Memory Orchestrator\n";
echo str_repeat('-', 60) . "\n";

$hybridMemory = new HybridMemory(
    $mindscapeMemory,
    $graphMemory,
    $coordinator,
    $auditLogger,
    $config['tenant_id']
);

echo "[✓] Hybrid Memory Orchestrator initialized\n";
echo "    - Mindscape RAG for narrative context\n";
echo "    - Graph RAG for structured facts\n";
echo "    - Multi-agent coordination enabled\n";
echo "    - Audit logging active\n\n";

// ============================================================================
// STEP 4: SIMULATE BANKING CONVERSATION
// ============================================================================

echo ">>> STEP 4: Simulating Banking Conversation\n";
echo str_repeat('-', 60) . "\n";

$conversations = [
    [
        'role' => 'user',
        'content' => 'Hello, I\'m John Smith. I\'d like to open a premium checking account. I work as a Senior Software Engineer at TechCorp with an annual income of $180,000.',
    ],
    [
        'role' => 'assistant',
        'content' => 'Welcome, Mr. Smith! I\'d be happy to help you open a premium checking account. Based on your income of $180,000 as a Senior Software Engineer at TechCorp, you qualify for our Platinum Checking account which offers premium benefits including unlimited ATM fee rebates and a dedicated relationship manager.',
    ],
    [
        'role' => 'user',
        'content' => 'That sounds great. I also want to set up automatic transfers of $5,000 monthly to a savings account. My wife Sarah and I are planning to buy a house next year.',
    ],
    [
        'role' => 'assistant',
        'content' => 'Excellent choice! I\'ll set up the automatic transfer of $5,000 monthly to your savings account. Since you and Sarah are planning to purchase a home next year, I\'d also recommend our First-Time Homebuyer savings account which offers a higher interest rate of 4.5% APY for down payment savings.',
    ],
    [
        'role' => 'user',
        'content' => 'Actually, I just got promoted to Engineering Director with a new salary of $220,000. Does this change my account options?',
    ],
];

foreach ($conversations as $index => $message) {
    echo "\n[" . ($index + 1) . "] Processing " . ucfirst($message['role']) . " Message:\n";
    echo "    \"" . substr($message['content'], 0, 80) . "...\"\n";
    
    if ($message['role'] === 'user') {
        $result = $hybridMemory->processUserMessage($message['content']);
    } else {
        $result = $hybridMemory->processAIResponse($message['content']);
    }
    
    echo "    [✓] Message stored in Mindscape Memory\n";
    
    if (!empty($result['facts_extracted'])) {
        echo "    [✓] Facts extracted: " . count($result['facts_extracted']) . "\n";
        foreach (array_slice($result['facts_extracted'], 0, 3) as $fact) {
            echo "        - {$fact['subject']} → {$fact['relation']} → {$fact['object']}\n";
        }
    }
    
    if (!empty($result['contradictions'])) {
        echo "    [!] Contradictions detected: " . count($result['contradictions']) . "\n";
        foreach ($result['contradictions'] as $contradiction) {
            echo "        - {$contradiction['description']}\n";
        }
    }
}

echo "\n";

// ============================================================================
// STEP 5: DEMONSTRATE MINDSCAPE RAG CONTEXT BUILDING
// ============================================================================

echo ">>> STEP 5: Mindscape RAG - Building AI Context\n";
echo str_repeat('-', 60) . "\n";

$aiPrompt = $hybridMemory->buildAIPrompt('What account options do I have?');

echo "Generated AI Prompt with Full Context:\n";
echo str_repeat('-', 40) . "\n";

foreach ($aiPrompt as $promptPart) {
    $role = strtoupper($promptPart['role']);
    $content = substr($promptPart['content'], 0, 200);
    echo "[$role]\n$content...\n\n";
}

// ============================================================================
// STEP 6: DEMONSTRATE GRAPH RAG QUERIES
// ============================================================================

echo ">>> STEP 6: Graph RAG - Structured Fact Queries\n";
echo str_repeat('-', 60) . "\n";

// Query facts about John Smith
$johnFacts = $graphMemory->getFactsForEntity('John Smith');
echo "Facts about 'John Smith':\n";
foreach ($johnFacts as $fact) {
    echo "  • {$fact['subject']} {$fact['relation']} {$fact['object']}";
    if (isset($fact['confidence'])) {
        echo " (confidence: " . number_format($fact['confidence'] * 100, 1) . "%)";
    }
    echo "\n";
}
echo "\n";

// Find related entities
$related = $graphMemory->queryRelated('John Smith', 'works_at');
echo "Entities related to 'John Smith' via 'works_at':\n";
foreach ($related as $entity) {
    echo "  • $entity\n";
}
echo "\n";

// ============================================================================
// STEP 7: DEMONSTRATE CONFLICT DETECTION & RESOLUTION
// ============================================================================

echo ">>> STEP 7: Conflict Detection & Resolution\n";
echo str_repeat('-', 60) . "\n";

// The conversation contains a salary update - demonstrate conflict resolution
$oldFact = [
    'id' => 'fact_salary_old',
    'subject' => 'John Smith',
    'relation' => 'has_income',
    'object' => '$180,000',
    'timestamp' => time() - 3600,
    'source_agent' => 'insight_agent',
];

$newFact = [
    'id' => 'fact_salary_new',
    'subject' => 'John Smith',
    'relation' => 'has_income',
    'object' => '$220,000',
    'timestamp' => time(),
    'source_agent' => 'insight_agent',
];

echo "Conflicting Facts Detected:\n";
echo "  Old: {$oldFact['subject']} {$oldFact['relation']} {$oldFact['object']}\n";
echo "  New: {$newFact['subject']} {$newFact['relation']} {$newFact['object']}\n\n";

$resolved = $conflictResolver->resolve($oldFact, $newFact);
echo "Resolution Strategy: " . $conflictResolver->getResolutionStrategy()->value . "\n";
echo "Resolved Fact: {$resolved['subject']} {$resolved['relation']} {$resolved['object']}\n\n";

// ============================================================================
// STEP 8: DEMONSTRATE MULTI-AGENT REASONING
// ============================================================================

echo ">>> STEP 8: Multi-Agent Reasoning\n";
echo str_repeat('-', 60) . "\n";

$agentContext = [
    'customer_name' => 'John Smith',
    'income' => 220000,
    'position' => 'Engineering Director',
    'employer' => 'TechCorp',
    'planning' => ['home_purchase'],
    'monthly_savings' => 5000,
];

$perspectives = $hybridMemory->getAgentPerspectives($agentContext);

echo "Agent Perspectives:\n\n";
foreach ($perspectives as $agentId => $perspective) {
    echo "[$agentId]\n";
    echo "  Type: {$perspective['agent_type']}\n";
    echo "  Analysis: {$perspective['analysis']}\n";
    echo "  Confidence: " . number_format($perspective['confidence'] * 100, 1) . "%\n\n";
}

// ============================================================================
// STEP 9: DEMONSTRATE AUDIT LOGGING
// ============================================================================

echo ">>> STEP 9: Audit Trail & Compliance\n";
echo str_repeat('-', 60) . "\n";

// Log a sample action
$auditLogger->log(
    $config['tenant_id'],
    'premium_account_opened',
    'system',
    [
        'customer' => 'John Smith',
        'account_type' => 'Platinum Checking',
        'approved_by' => 'automated_system',
        'compliance_verified' => true,
    ]
);

// Retrieve audit trail
$auditTrail = $auditLogger->getAuditTrail($config['tenant_id'], 10);

echo "Recent Audit Trail:\n";
foreach (array_slice($auditTrail, 0, 5) as $entry) {
    $time = date('Y-m-d H:i:s', $entry['timestamp']);
    echo "  [$time] {$entry['action']} by {$entry['actor']}\n";
}
echo "\n";

// Verify integrity
$integrityCheck = $auditLogger->verifyIntegrity($config['tenant_id']);
echo "Audit Log Integrity: " . ($integrityCheck ? "[✓] VERIFIED" : "[✗] COMPROMISED") . "\n\n";

// ============================================================================
// STEP 10: GENERATE COMPLIANCE REPORT
// ============================================================================

echo ">>> STEP 10: Compliance Report\n";
echo str_repeat('-', 60) . "\n";

$report = $auditLogger->generateComplianceReport(
    $config['tenant_id'],
    strtotime('-1 day'),
    time()
);

echo "Compliance Report Summary:\n";
echo "  Period: {$report['period']['start']} to {$report['period']['end']}\n";
echo "  Total Events: {$report['total_events']}\n";
echo "  Data Integrity: " . ($report['integrity_verified'] ? "VERIFIED" : "FAILED") . "\n";
echo "\n";

// ============================================================================
// STEP 11: DEMONSTRATE CACHING
// ============================================================================

echo ">>> STEP 11: Cache Performance\n";
echo str_repeat('-', 60) . "\n";

// Demonstrate cache operations
$cache->set('customer:john_smith:profile', [
    'name' => 'John Smith',
    'segment' => 'premium',
    'risk_score' => 35,
], 3600);

$cachedProfile = $cache->get('customer:john_smith:profile');
echo "Cached Customer Profile:\n";
echo "  Name: {$cachedProfile['name']}\n";
echo "  Segment: {$cachedProfile['segment']}\n";
echo "  Risk Score: {$cachedProfile['risk_score']}\n";
echo "  Cache Status: [✓] HIT\n\n";

// ============================================================================
// SUMMARY
// ============================================================================

echo "============================================================\n";
echo "                    DEMONSTRATION COMPLETE\n";
echo "============================================================\n\n";

echo "Features Demonstrated:\n";
echo "  [✓] Mindscape RAG - Contextual narrative memory\n";
echo "  [✓] Graph RAG - Structured fact storage and queries\n";
echo "  [✓] AI Summarization - Automatic context compression\n";
echo "  [✓] Fact Extraction - Entity and relationship extraction\n";
echo "  [✓] Fact Validation - Confidence scoring\n";
echo "  [✓] Conflict Resolution - Latest-wins strategy\n";
echo "  [✓] Multi-Agent Reasoning - Parallel agent coordination\n";
echo "  [✓] Audit Logging - Tamper-evident compliance trail\n";
echo "  [✓] Multi-Tenant Support - Tenant isolation\n";
echo "  [✓] Caching Layer - Performance optimization\n";
echo "\n";

echo "Data stored in:\n";
echo "  Storage: {$config['storage_path']}\n";
echo "  Graph: {$config['graph_path']}\n";
echo "  Audit: {$config['audit_path']}\n";
echo "\n";

echo "To use in production:\n";
echo "  1. Set GEMINI_API_KEY environment variable\n";
echo "  2. Run: composer install\n";
echo "  3. Configure storage adapters (MySQL, MongoDB, Neo4j)\n";
echo "  4. Implement custom agents for your use case\n";
echo "\n";

# Zion AI Memory System

A production-ready PHP implementation of a **Hybrid AI Memory Architecture** combining **Mindscape RAG** (contextual narrative memory) and **Graph RAG** (structured factual memory) for banking-grade, multi-tenant applications.

## Features

### 🧠 Dual Memory Architecture
- **Mindscape RAG**: Contextual narrative memory with automatic summarization
- **Graph RAG**: Structured fact storage with entity-relationship graphs

### 🤖 AI-Powered Processing
- Automatic summarization of conversation history
- Intelligent fact extraction from natural language
- Semantic validation of extracted facts
- Contradiction detection and resolution

### 🔒 Banking-Grade Security
- Multi-tenant isolation
- Tamper-evident audit logging with SHA-256 hash chains
- Compliance reporting capabilities
- Full audit trail with integrity verification

### 🤝 Multi-Agent Reasoning
- Parallel agent coordination
- Priority-based conflict resolution
- Consensus mechanisms
- Specialized agent types (Compliance, Risk, Customer Insight)

### 📦 Pluggable Architecture
- File-based storage (included)
- Extensible to MySQL, MongoDB, Neo4j
- Caching layer for performance
- PSR-4 autoloading

## Installation

```bash
composer require mitmelon/ai-m
```

Or clone and install dependencies:

```bash
git clone https://github.com/mitmelon/zion.git
cd zion
composer install
```

## Quick Start

```php
<?php

require_once 'vendor/autoload.php';

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
use Zion\Memory\Agents\MultiAgentCoordinator;

// Initialize components
$cache = new InMemoryCache();
$aiProvider = new GeminiProvider(getenv('GEMINI_API_KEY'));

$storageAdapter = new FileStorageAdapter('./data/storage', $cache);
$graphAdapter = new FileGraphAdapter('./data/graph');

$summarizer = new AISummarizer($aiProvider);
$factExtractor = new AIFactExtractor($aiProvider);
$factValidator = new FactValidator($aiProvider);
$conflictResolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
$auditLogger = new AuditLogger('./data/audit');

// Create memory systems
$mindscapeMemory = new MindscapeMemory(
    $storageAdapter,
    $summarizer,
    'tenant_001',
    'session_001'
);

$graphMemory = new GraphMemory(
    $graphAdapter,
    $factExtractor,
    $factValidator,
    'tenant_001'
);

// Create coordinator (add agents as needed)
$coordinator = new MultiAgentCoordinator([], $conflictResolver);

// Create the hybrid memory orchestrator
$hybridMemory = new HybridMemory(
    $mindscapeMemory,
    $graphMemory,
    $coordinator,
    $auditLogger,
    'tenant_001'
);

// Process a user message
$result = $hybridMemory->processUserMessage(
    'I am John Smith, a software engineer earning $150,000 annually.'
);

// Build AI prompt with full context
$prompt = $hybridMemory->buildAIPrompt('What account should I open?');

// Process AI response (extracts facts, validates, logs)
$hybridMemory->processAIResponse(
    'Based on your income, I recommend our Premium Checking account.'
);
```

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      HybridMemory Orchestrator                   │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  │
│  │ MindscapeMemory │  │   GraphMemory   │  │ MultiAgentCoord │  │
│  │   (Narrative)   │  │   (Structured)  │  │   (Reasoning)   │  │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘  │
│           │                    │                    │           │
│  ┌────────▼────────┐  ┌────────▼────────┐  ┌────────▼────────┐  │
│  │  Summarizer     │  │ FactExtractor   │  │    Agents       │  │
│  │  (AI-powered)   │  │ (AI-powered)    │  │ (Specialized)   │  │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘  │
│           │                    │                    │           │
│  ┌────────▼────────┐  ┌────────▼────────┐  ┌────────▼────────┐  │
│  │ StorageAdapter  │  │  GraphAdapter   │  │ ConflictResolver│  │
│  │  (Pluggable)    │  │  (Pluggable)    │  │  (Strategies)   │  │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘  │
├─────────────────────────────────────────────────────────────────┤
│                         AuditLogger                              │
│              (Tamper-evident compliance logging)                 │
└─────────────────────────────────────────────────────────────────┘
```

## Components

### Mindscape Memory (Narrative RAG)
Stores conversation history with automatic summarization:

```php
$mindscapeMemory->addMessage('user', 'Hello, I want to open an account');
$mindscapeMemory->addMessage('assistant', 'I can help with that!');

// Get recent messages
$messages = $mindscapeMemory->getRecentMessages(10);

// Build context for AI (includes summary if available)
$context = $mindscapeMemory->buildContext('What are my options?');

// Trigger summarization when needed
$mindscapeMemory->triggerSummarization();
```

### Graph Memory (Graph RAG)
Stores structured facts as entity-relationship triples:

```php
// Extract and store facts from text
$result = $graphMemory->addFactsFromText(
    'John Smith works at TechCorp as a Senior Engineer earning $180,000'
);
// Extracts: John Smith -> works_at -> TechCorp
//           John Smith -> has_role -> Senior Engineer
//           John Smith -> has_salary -> $180,000

// Query related entities
$employers = $graphMemory->queryRelated('John Smith', 'works_at');

// Get all facts about an entity
$facts = $graphMemory->getFactsForEntity('John Smith');
```

### Multi-Agent System
Coordinate multiple specialized agents:

```php
// Create specialized agents
class ComplianceAgent extends BaseAgent {
    public function process(array $context): array {
        // Compliance analysis logic
        return ['compliant' => true, 'checks' => ['kyc', 'aml']];
    }
}

class RiskAgent extends BaseAgent {
    public function process(array $context): array {
        // Risk assessment logic
        return ['risk_score' => 35, 'risk_level' => 'low'];
    }
}

// Add to coordinator
$coordinator->addAgent(new ComplianceAgent($aiProvider));
$coordinator->addAgent(new RiskAgent($aiProvider));

// Process with all agents
$perspectives = $coordinator->process($context);
$consolidated = $coordinator->consolidateResponses($perspectives);
```

### Conflict Resolution
Handle contradictions between facts:

```php
// Available strategies
ConflictResolutionStrategy::LATEST_WINS      // Most recent fact wins
ConflictResolutionStrategy::PRIORITY_AGENT   // Highest priority agent wins
ConflictResolutionStrategy::CONSENSUS        // Multi-agent voting
ConflictResolutionStrategy::MANUAL_REVIEW    // Flag for human review

$resolver = new ConflictResolver(ConflictResolutionStrategy::LATEST_WINS);
$resolved = $resolver->resolve($oldFact, $newFact);
```

### Audit Logging
Tamper-evident compliance logging:

```php
// Log an action
$auditLogger->log(
    'tenant_001',
    'account_opened',
    'system',
    ['account_type' => 'premium', 'customer' => 'John Smith']
);

// Get audit trail
$trail = $auditLogger->getAuditTrail('tenant_001', 100);

// Verify integrity (hash chain validation)
$valid = $auditLogger->verifyIntegrity('tenant_001');

// Generate compliance report
$report = $auditLogger->generateComplianceReport(
    'tenant_001',
    strtotime('-30 days'),
    time()
);
```

## Configuration

### Environment Variables
```bash
GEMINI_API_KEY=your-api-key-here
```

### Storage Paths
```php
$config = [
    'storage_path' => '/var/data/storage',
    'graph_path' => '/var/data/graph',
    'audit_path' => '/var/data/audit',
];
```

## Testing

Run the test suite:

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Run specific test file
./vendor/bin/phpunit tests/StorageTest.php
```

## Directory Structure

```
├── src/
│   ├── Contracts/           # Interfaces
│   │   ├── AIProviderInterface.php
│   │   ├── AgentInterface.php
│   │   ├── AuditLoggerInterface.php
│   │   ├── CacheInterface.php
│   │   ├── ConflictResolverInterface.php
│   │   ├── FactExtractorInterface.php
│   │   ├── FactValidatorInterface.php
│   │   ├── GraphMemoryAdapter.php
│   │   ├── MemoryStorageAdapter.php
│   │   └── SummarizerInterface.php
│   ├── Storage/             # Storage implementations
│   │   ├── FileStorageAdapter.php
│   │   ├── FileGraphAdapter.php
│   │   └── InMemoryCache.php
│   ├── AI/                  # AI modules
│   │   ├── GeminiProvider.php
│   │   ├── AISummarizer.php
│   │   └── AIFactExtractor.php
│   ├── Memory/              # Memory systems
│   │   └── MindscapeMemory.php
│   ├── Graph/               # Graph RAG
│   │   └── GraphMemory.php
│   ├── Validation/          # Validation & conflicts
│   │   ├── FactValidator.php
│   │   └── ConflictResolver.php
│   ├── Audit/               # Audit logging
│   │   └── AuditLogger.php
│   ├── Agents/              # Multi-agent system
│   │   ├── BaseAgent.php
│   │   └── MultiAgentCoordinator.php
│   └── HybridMemory.php     # Main orchestrator
├── tests/                   # Unit tests
├── example.php              # Usage demonstration
├── composer.json
├── phpunit.xml
└── README.md
```

## Extending the System

### Custom Storage Adapter
```php
class MySQLStorageAdapter implements MemoryStorageAdapter {
    public function getMessages(string $tenantId, string $sessionId, int $limit = 100): array {
        // MySQL implementation
    }
    
    public function addMessage(string $tenantId, string $sessionId, string $role, string $content, array $metadata = []): void {
        // MySQL implementation
    }
    
    // ... other methods
}
```

### Custom Agent
```php
class FraudDetectionAgent extends BaseAgent {
    public function __construct(AIProviderInterface $aiProvider) {
        parent::__construct('fraud_agent', 'fraud_detection', $aiProvider, 95);
    }
    
    public function process(array $context): array {
        // Analyze for fraud indicators
        return [
            'fraud_score' => 0.15,
            'indicators' => [],
            'recommendation' => 'proceed',
        ];
    }
}
```

## License

MIT License - see [LICENSE](LICENSE) for details.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests (`composer test`)
5. Submit a pull request

## Support

For issues and feature requests, please use the GitHub issue tracker.

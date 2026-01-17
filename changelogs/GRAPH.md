# 🧠 ZionXMemory - Knowledge Graph Integration

## A Machine Substrate for Collective Human Reasoning Across Time

**Version:** 1.0.0  
**License:** MIT  

---

## Knowledge Graph Integration

### Core Principle

**The Knowledge Graph is DERIVED from memory, not a replacement.**

```
Memory (source of truth)
    ↓
Knowledge Graph (materialized wisdom)
    ↓
Query Interface (for agents)
```

### Architecture

```
/src/Graph
├── GraphStoreInterface.php          # Storage contract
├── GraphEntity.php                   # Entities with confidence
├── GraphRelation.php                 # Relations with contradictions
├── GraphIngestor.php                 # Memory → Graph transform
├── GraphQueryService.php             # High-level queries
├── GraphConsistencyChecker.php       # Contradiction detection
├── EpistemicStatusTracker.php        # CRITICAL: Facts vs assumptions
├── DecisionLineageTracker.php        # Decision provenance
├── MinorityOpinionTracker.php        # Dissent preservation
├── InstitutionalMemorySeparator.php  # Session vs institutional
└── SelfAuditSystem.php              # Wisdom compounding
```

---

### 1. Epistemic Status Tracking ⭐⭐⭐

**Every stored item has explicit epistemic status:**

```php
STATUS_HYPOTHESIS   // Unconfirmed
STATUS_EVIDENCE     // Grounded in data
STATUS_ASSUMPTION   // Taken as given
STATUS_DECISION     // Derived conclusion
STATUS_REJECTED     // Known false
STATUS_CONFIRMED    // Survived debate
STATUS_CONTESTED    // Under dispute
```

**This enables the critical query:**
```php
$basis = $epistemicTracker->getReasoningBasis($tenantId, $claimIds);

// Returns:
// - Are we reasoning from facts or assumptions?
// - Fact ratio: 0.72 (mostly evidence-based)
// - Assumption ratio: 0.15 (some assumptions)
// - Quality: "strong"
```

**Why this matters:** Most AI systems can't distinguish between what they **know** and what they **assume**. This prevents institutional hallucinations.

---

### 2. Memory Decay & Relevance Weighting ⭐⭐

**Not deletion—epistemic decay:**

```php
// Old claim with no reinforcement → lower influence
// Frequently confirmed claim → higher trust

$influence = $memoryDecay->calculateInfluence($tenantId, $claimId);
```

**Why this matters:** Prevents old, unreinforced beliefs from dominating decisions.

---

### 3. Minority Opinion Preservation ⭐⭐⭐

**Most systems converge too early. ZionXMemory preserves dissent:**

```php
// Record minority opinion
$minorityTracker->recordMinorityOpinion($tenantId, $sessionId, [
    'agent_id' => 'agent_bob',
    'position' => 'Different view',
    'majority_position' => 'Consensus view'
]);

// Track accuracy over time
$minorityTracker->trackAccuracy($tenantId, 'agent_bob', $outcomes);

// Surface "often-right dissenters"
$reliableDissenters = $minorityTracker->getReliableDissenters($tenantId);
```

**Why this matters:** Real intelligence emerges from preserved dissent. Groupthink is prevented.

---

### 4. Decision Lineage Graphs ⭐⭐⭐

**For every decision, track complete provenance:**

```php
Decision: "Postpone initiative"
├── Claims used: [claim_1, claim_2]
├── Claims rejected: [claim_3] + reasons
├── Conflicts unresolved: [conflict_1]
└── Confidence score: 0.78

// Generate 18-page reports automatically
$report = $decisionTracker->generateDecisionReport($tenantId, $decisionId);
```

**Why this matters:** Complete auditability. Every decision can be traced to its sources.

---

### 5. Cognitive Load Protection ⭐⭐

**When context grows too large:**

```php
// Auto-compress old memories
// Preserve logic chains
// Never drop conclusions

$compressed = $compression->compress($memoryUnit, $targetLevel, [
    'preserve_contradictions' => true,
    'preserve_intent' => true
]);
```

**Why this matters:** Infinite context without losing critical information.

---

### 6. Institutional Memory vs Session Memory ⭐⭐⭐

**Two distinct layers:**

```
Session Memory (debate)
    ↓ (promotion criteria)
Institutional Memory (what survived debate)
    ↓
Knowledge Graph (by default)
```

```php
// Promote only high-quality claims
$result = $institutionalMemory->promoteToInstitutional($tenantId, $sessionId, [
    'min_confidence' => 0.7,
    'min_agreement' => 0.6,
    'require_evidence' => true
]);
```

**Why this matters:** Separates ephemeral discussion from enduring knowledge.

---

### 7. Trust Surfaces (Enterprise-Grade) ⭐

**For regulated industries:**

- Memory visibility rules per tenant
- Partial redaction capabilities
- "Black-box reasoning" mode for compliance
- Complete audit trails

**Why this matters:** Unlocks healthcare, finance, legal sectors.

---

### 8. Self-Auditing Intelligence ⭐⭐⭐

**The system periodically asks itself:**

```php
// "What do we believe strongly with weak evidence?"
$weaklySupported = $selfAudit->findWeaklySupported($tenantId, [
    'min_confidence' => 0.7,
    'max_evidence' => 2
]);

// Returns: High confidence claims with insufficient evidence
// Risk score = confidence / evidence_count
```

**Wisdom metrics:**
```php
$wisdom = $selfAudit->getWisdomMetrics($tenantId);
// - Institutional memory count
// - Evidence ratio
// - Minority accuracy
// - Confirmation rate
// - Wisdom score (compound metric)
// - Trending direction
```

**Why this matters:** Wisdom compounds automatically. The system improves itself.

---

## Knowledge Graph Usage

### Storage Flow

```php
// 1. Store memory (always)
$memory->storeMemoryAdaptive($tenantId, $agentId, $data, $surpriseSignal);

// 2. Ingest to graph (optional, doesn't affect memory)
if ($graphEnabled) {
    $graphIngestor->ingestFromSession($tenantId, $sessionId);
}
```

### Ingestion Rules

1. ✅ Graph ingestion occurs **after** memory is stored
2. ✅ Graph ingestion is **idempotent** (safe to repeat)
3. ✅ Graph does **NOT** store raw conversations
4. ✅ Graph only stores **normalized claims**
5. ✅ Confidence **must** be propagated into relations

### Example Mapping

```
Memory Claim:
{
  topic: "Blogging in 2026",
  claim: "Blogging is legally risky",
  confidence: 0.78
}

↓ (ingestion)

Graph:
Blogging_2026 ──has_risk──> Legal_Risk (confidence=0.78)
```

---

## Querying from Agents

### Historical Facts

```php
$facts = $graphQuery->getHistoricalFacts(
    topic: "Blogging in 2026",
    tenantId: $tenantId,
    options: [
        'include_contradictions' => true,
        'min_confidence' => 0.6
    ]
);

// Returns:
// - Cross-session claims
// - Weighted by confidence
// - Including contradictions (never hidden)
```

### Contradiction Detection

```php
$conflicts = $consistencyChecker->detectConflicts($tenantId, $entityId);

// Returns structured ConflictObject[] (NOT text)
// - conflict_type
// - conflicting_relations
// - severity_score (higher when both high confidence)
```

---

## API Reference

### Epistemic Status

```php
interface EpistemicStatusInterface {
    public function setStatus(
        string $tenantId,
        string $claimId,
        string $status,
        array $justification
    ): void;
    
    public function getClaimsByStatus(
        string $tenantId,
        string $status
    ): array;
    
    // CRITICAL: Query reasoning basis
    public function getReasoningBasis(
        string $tenantId,
        array $claimIds
    ): array;
}
```

### Decision Lineage

```php
interface DecisionLineageInterface {
    public function recordDecision(
        string $tenantId,
        string $decisionId,
        array $decision  // includes claims_used, claims_rejected, conflicts
    ): void;
    
    public function generateDecisionReport(
        string $tenantId,
        string $decisionId
    ): array;  // 18-page report structure
    
    public function getDownstreamDecisions(
        string $tenantId,
        string $claimId
    ): array;  // What depends on this claim?
}
```

### Minority Opinion

```php
interface MinorityOpinionInterface {
    public function recordMinorityOpinion(
        string $tenantId,
        string $sessionId,
        array $opinion
    ): void;
    
    public function trackAccuracy(
        string $tenantId,
        string $agentId,
        array $outcomes  // Actual vs predicted
    ): void;
    
    public function getReliableDissenters(
        string $tenantId,
        array $criteria
    ): array;  // Often-right dissenters
}
```

### Institutional Memory

```php
interface InstitutionalMemoryInterface {
    public function promoteToInstitutional(
        string $tenantId,
        string $sessionId,
        array $criteria  // min_confidence, min_agreement, etc.
    ): array;
    
    public function getInstitutional(
        string $tenantId,
        array $filters
    ): array;  // Only what survived debate
}
```

### Self-Audit

```php
interface SelfAuditInterface {
    public function findWeaklySupported(
        string $tenantId,
        array $thresholds
    ): array;  // High confidence, low evidence
    
    public function findHighConfidenceConflicts(
        string $tenantId
    ): array;
    
    public function getWisdomMetrics(
        string $tenantId
    ): array;  // How is wisdom compounding?
}
```

---

## Complete Example Flow

```php
// STEP 1: Agents deliberate in session
$memory->storeMemoryAdaptive($tenantId, 'alice', $claim1);
$memory->storeMemoryAdaptive($tenantId, 'bob', $claim2);

// Track epistemic status
$epistemicTracker->setStatus($tenantId, $claim1['id'], 'evidence', $justification);
$epistemicTracker->setStatus($tenantId, $claim2['id'], 'assumption', $justification);

// Preserve minority opinion
$minorityTracker->recordMinorityOpinion($tenantId, $sessionId, $bobsOpinion);

// STEP 2: Session concludes
$promoted = $institutionalMemory->promoteToInstitutional($tenantId, $sessionId, $criteria);

// Ingest to graph (optional)
$graphIngestor->ingestFromSession($tenantId, $sessionId);

// STEP 3: Later decision-making
$facts = $graphQuery->getHistoricalFacts('topic', $tenantId);
$basis = $epistemicTracker->getReasoningBasis($tenantId, $claimIds);

// STEP 4: Record decision
$decisionTracker->recordDecision($tenantId, $decisionId, [
    'decision' => 'Postpone initiative',
    'claims_used' => $facts,
    'reasoning' => $basis
]);

// STEP 5: System self-audits
$weakClaims = $selfAudit->findWeaklySupported($tenantId, $thresholds);
$wisdom = $selfAudit->getWisdomMetrics($tenantId);

// Generate report
$report = $decisionTracker->generateDecisionReport($tenantId, $decisionId);
```

---

## Migration Path

### From v2.0 (Adaptive Memory Only)

```php
// v2.0 continues to work
$memory = new HybridMemoryV2($storage, $ai, $audit);

// v2.1 adds Knowledge Graph (optional)
$graphStore = new GraphStore($storage);
$graphIngestor = new GraphIngestor($graphStore, $storage, $ai, $audit);

// Enable graph ingestion
if ($graphEnabled) {
    $graphIngestor->ingestFromSession($tenantId, $sessionId);
}
```

### Existing APIs Unchanged

All v1 and v2 APIs remain unchanged. Knowledge Graph is **additive**.

---

## Constraints & Non-Goals

### ❌ We Do NOT:
- Embed LLMs into the graph layer
- Change memory schemas
- Auto-resolve contradictions
- Enforce ontology rigidity
- Make decisions for agents
- Control agent behavior

### ✅ We DO:
- Materialize wisdom from memory
- Track epistemic status explicitly
- Preserve minority opinions systematically
- Enable self-examination
- Provide complete provenance
- Support long-term reasoning

---

## License

MIT License - see [LICENSE](LICENSE)

---

**Built for institutions that need to remember, deliberate, and grow wiser over years—not just store documents.**

**ZionXMemory: Where wisdom compounds.**
<?php
namespace ZionXMemory\Memory;

use ZionXMemory\Contracts\MemoryInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Contracts\AuditInterface;

/**
 * MindscapeMemory - Narrative Memory Layer
 * Stores raw interactions with hierarchical summarization
 * Supports epistemic state management and versioning
 * Implements lazy summarization and temporal stratification
 * Append-only storage for immutability
 * Multi-tenant and multi-agent support
 * Audit logging for all operations
 * Designed for production-grade applications
 * Integrates with AI adapters for summarization
 * Optimized for retrieval with context building
 * Handles logical deletion via epistemic states
 * Provides memory lineage tracking
 * Scalable storage via pluggable adapters
 * 
 * @package ZionXMemory\Memory
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class MindscapeMemory implements MemoryInterface {
    private StorageAdapterInterface $storage;
    private AIAdapterInterface $ai;
    private AuditInterface $audit;
    private NarrativeSummarizer $summarizer;
    private TemporalStratifier $stratifier;
    
    public function __construct(
        StorageAdapterInterface $storage,
        AIAdapterInterface $ai,
        AuditInterface $audit
    ) {
        $this->storage = $storage;
        $this->ai = $ai;
        $this->audit = $audit;
        $this->summarizer = new NarrativeSummarizer($ai);
        $this->stratifier = new TemporalStratifier($storage);
    }
    
    public function store(string $tenantId, string $agentId, array $data): string {
        $memoryId = $this->generateMemoryId();
        $timestamp = time();
        
        $memoryRecord = [
            'id' => $memoryId,
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'timestamp' => $timestamp,
            'type' => $data['type'] ?? 'interaction',
            'content' => $data['content'],
            'metadata' => $data['metadata'] ?? [],
            'parent_id' => $data['parent_id'] ?? null,
            'epistemic_state' => 'active',
            'deleted' => false,
        ];
        
        // Store raw memory (immutable)
        $key = $this->buildKey($tenantId, 'raw', $memoryId);
        $this->storage->write($key, $memoryRecord, [
            'tenant' => $tenantId,
            'agent' => $agentId,
            'timestamp' => $timestamp,
            'immutable' => true
        ]);
        
        // Trigger lazy summarization check
        $this->stratifier->checkSummarizationNeeded($tenantId, $agentId);
        
        // Audit log
        $this->audit->log($tenantId, 'memory_store', [
            'memory_id' => $memoryId,
            'agent_id' => $agentId,
            'type' => $memoryRecord['type']
        ], ['timestamp' => $timestamp]);
        
        return $memoryId;
    }
    
    public function retrieve(string $tenantId, array $query): array {
        $filters = $query['filters'] ?? [];
        $includeContext = $query['include_context'] ?? true;
        $maxTokens = $query['max_tokens'] ?? 8000;
        
        // Retrieve relevant memories
        $memories = $this->queryMemories($tenantId, $filters);
        
        if ($includeContext) {
            // Build hierarchical context using summaries
            $context = $this->buildHierarchicalContext($tenantId, $memories, $maxTokens);
            return $context;
        }
        
        return $memories;
    }
    
    public function updateEpistemicState(string $tenantId, string $memoryId, array $stateUpdate): bool {
        // Create new version with updated epistemic state (append-only)
        $originalKey = $this->buildKey($tenantId, 'raw', $memoryId);
        $original = $this->storage->read($originalKey);
        
        if (!$original) {
            return false;
        }
        
        $versionId = $this->generateVersionId();
        $versionKey = $this->buildKey($tenantId, 'versions', $memoryId . '_' . $versionId);
        
        $versionRecord = array_merge($original, [
            'version_id' => $versionId,
            'previous_state' => $original['epistemic_state'],
            'new_state' => $stateUpdate['state'],
            'reason' => $stateUpdate['reason'],
            'updated_at' => time(),
            'updated_by' => $stateUpdate['agent_id'] ?? 'system'
        ]);
        
        $this->storage->write($versionKey, $versionRecord, [
            'tenant' => $tenantId,
            'version' => true
        ]);
        
        $this->audit->log($tenantId, 'epistemic_update', [
            'memory_id' => $memoryId,
            'version_id' => $versionId,
            'state_change' => [
                'from' => $original['epistemic_state'],
                'to' => $stateUpdate['state']
            ]
        ], ['timestamp' => time()]);
        
        return true;
    }
    
    public function logicalDelete(string $tenantId, string $memoryId, string $reason): bool {
        return $this->updateEpistemicState($tenantId, $memoryId, [
            'state' => 'deleted',
            'reason' => $reason
        ]);
    }
    
    public function getMemoryLineage(string $tenantId, string $memoryId): array {
        $lineage = [];
        
        // Get original
        $originalKey = $this->buildKey($tenantId, 'raw', $memoryId);
        $original = $this->storage->read($originalKey);
        
        if ($original) {
            $lineage[] = [
                'version' => 'original',
                'data' => $original
            ];
        }
        
        // Get all versions
        $versionPattern = $this->buildKey($tenantId, 'versions', $memoryId . '_*');
        $versions = $this->storage->query(['pattern' => $versionPattern]);
        
        foreach ($versions as $version) {
            $lineage[] = [
                'version' => $version['version_id'],
                'data' => $version
            ];
        }
        
        return $lineage;
    }
    
    private function queryMemories(string $tenantId, array $filters): array {
        $pattern = $this->buildKey($tenantId, 'raw', '*');
        $allMemories = $this->storage->query(['pattern' => $pattern]);
        
        // Apply filters
        $filtered = array_filter($allMemories, function($memory) use ($filters) {
            if (isset($filters['agent_id']) && $memory['agent_id'] !== $filters['agent_id']) {
                return false;
            }
            
            if (isset($filters['type']) && $memory['type'] !== $filters['type']) {
                return false;
            }
            
            if (isset($filters['after_timestamp']) && $memory['timestamp'] < $filters['after_timestamp']) {
                return false;
            }
            
            if (isset($filters['before_timestamp']) && $memory['timestamp'] > $filters['before_timestamp']) {
                return false;
            }
            
            if ($memory['deleted'] ?? false) {
                return false;
            }
            
            return true;
        });
        
        return array_values($filtered);
    }
    
    private function buildHierarchicalContext(string $tenantId, array $memories, int $maxTokens): array {
        // Use stratifier to get appropriate level summaries
        return $this->stratifier->buildContext($tenantId, $memories, $maxTokens);
    }
    
    private function buildKey(string $tenantId, string $type, string $id): string {
        return "mindscape:{$tenantId}:{$type}:{$id}";
    }
    
    private function generateMemoryId(): string {
        return 'mem_' . bin2hex(random_bytes(16));
    }
    
    private function generateVersionId(): string {
        return 'ver_' . bin2hex(random_bytes(8));
    }
}
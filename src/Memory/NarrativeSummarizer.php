<?php
namespace ZionXMemory\Memory;

use ZionXMemory\Contracts\AIAdapterInterface;
use ZionXMemory\Utils\MDLCompressor;

/**
 * NarrativeSummarizer
 * Creates hierarchical summaries using AI while preserving semantic meaning
 * Uses MDL principles for optimal compression
 * 
 * @package ZionXMemory\Memory
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class NarrativeSummarizer {
    private AIAdapterInterface $ai;
    private MDLCompressor $mdl;
    private array $summaryCache = [];
    
    public function __construct(AIAdapterInterface $ai) {
        $this->ai = $ai;
        $this->mdl = new MDLCompressor();
    }
    
    /**
     * Create hierarchical summary of memories
     */
    public function summarize(array $memories, int $targetLevel = 1): array {
        if (empty($memories)) {
            return [];
        }
        
        // Sort by timestamp
        usort($memories, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        
        // Group memories into chunks for summarization
        $chunks = $this->chunkMemories($memories, $targetLevel);
        
        $summaries = [];
        foreach ($chunks as $chunk) {
            $summary = $this->summarizeChunk($chunk, $targetLevel);
            $summaries[] = $summary;
        }
        
        return $summaries;
    }
    
    /**
     * Summarize a single chunk of memories
     */
    private function summarizeChunk(array $chunk, int $level): array {
        $chunkId = $this->generateChunkId($chunk);
        
        // Check cache
        if (isset($this->summaryCache[$chunkId])) {
            return $this->summaryCache[$chunkId];
        }
        
        // Prepare content for AI
        $content = $this->prepareContentForSummarization($chunk);
        
        // Calculate optimal compression using MDL
        $mdlScore = $this->mdl->calculateOptimalCompression($content);
        
        // AI summarization with explicit instructions
        $summary = $this->ai->summarize($content, [
            'level' => $level,
            'preserve_intent' => true,
            'preserve_contradictions' => true,
            'preserve_rejected_ideas' => true,
            'include_key_decisions' => true,
            'target_compression' => $mdlScore
        ]);
        
        $summaryRecord = [
            'id' => $chunkId,
            'level' => $level,
            'summary' => $summary,
            'source_memories' => array_map(fn($m) => $m['id'], $chunk),
            'timestamp_range' => [
                'start' => $chunk[0]['timestamp'],
                'end' => $chunk[count($chunk) - 1]['timestamp']
            ],
            'created_at' => time(),
            'mdl_score' => $mdlScore,
            'original_tokens' => $this->estimateTokens($content),
            'summary_tokens' => $this->estimateTokens($summary)
        ];
        
        $this->summaryCache[$chunkId] = $summaryRecord;
        
        return $summaryRecord;
    }
    
    /**
     * Create delta summary - only what changed
     */
    public function createDeltaSummary(array $newMemories, string $previousSummary): array {
        $newContent = $this->prepareContentForSummarization($newMemories);
        
        $deltaSummary = $this->ai->summarize($newContent, [
            'delta_mode' => true,
            'previous_summary' => $previousSummary,
            'focus_on_changes' => true,
            'preserve_contradictions' => true
        ]);
        
        return [
            'delta_summary' => $deltaSummary,
            'new_memories' => array_map(fn($m) => $m['id'], $newMemories),
            'created_at' => time()
        ];
    }
    
    /**
     * Chunk memories by time windows and content similarity
     */
    private function chunkMemories(array $memories, int $level): array {
        $chunkSize = $this->calculateChunkSize($level);
        $chunks = [];
        $currentChunk = [];
        
        foreach ($memories as $memory) {
            $currentChunk[] = $memory;
            
            if (count($currentChunk) >= $chunkSize) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
            }
        }
        
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }
    
    /**
     * Calculate optimal chunk size based on level
     * Level 1: 10-20 memories
     * Level 2: 50-100 memories
     * Level 3: 200-500 memories
     */
    private function calculateChunkSize(int $level): int {
        return match($level) {
            1 => 15,
            2 => 75,
            3 => 300,
            default => 15
        };
    }
    
    /**
     * Prepare content for AI summarization
     */
    private function prepareContentForSummarization(array $memories): string {
        $content = "Memory Context:\n\n";
        
        foreach ($memories as $memory) {
            $timestamp = date('Y-m-d H:i:s', $memory['timestamp']);
            $content .= "[{$timestamp}] [{$memory['agent_id']}] [{$memory['type']}]\n";
            $content .= $memory['content'] . "\n\n";
            
            // Include intent if available
            if (isset($memory['metadata']['intent'])) {
                $content .= "Intent: " . $memory['metadata']['intent'] . "\n\n";
            }
        }
        
        return $content;
    }
    
    private function generateChunkId(array $chunk): string {
        $ids = array_map(fn($m) => $m['id'], $chunk);
        return 'chunk_' . hash('sha256', implode(':', $ids));
    }
    
    private function estimateTokens(string $content): int {
        // Rough estimation: 1 token ≈ 4 characters
        return (int) ceil(strlen($content) / 4);
    }
}
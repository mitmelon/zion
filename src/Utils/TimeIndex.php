<?php
namespace ZionXMemory\Utils;

/**
 * TimeIndex - Time-based indexing for efficient temporal queries
 * Supports adding entries with timestamps and metadata
 * Enables querying entries within time ranges
 * Provides basic statistics on indexed data
 * 
 * @package ZionXMemory\Utils
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class TimeIndex {
    private array $index = [];
    
    /**
     * Add entry to time index
     */
    public function add(int $timestamp, string $id, array $metadata = []): void {
        $bucket = $this->getBucket($timestamp);
        
        if (!isset($this->index[$bucket])) {
            $this->index[$bucket] = [];
        }
        
        $this->index[$bucket][] = [
            'id' => $id,
            'timestamp' => $timestamp,
            'metadata' => $metadata
        ];
    }
    
    /**
     * Query entries in time range
     */
    public function query(int $fromTimestamp, int $toTimestamp): array {
        $results = [];
        
        $fromBucket = $this->getBucket($fromTimestamp);
        $toBucket = $this->getBucket($toTimestamp);
        
        for ($bucket = $fromBucket; $bucket <= $toBucket; $bucket++) {
            if (isset($this->index[$bucket])) {
                foreach ($this->index[$bucket] as $entry) {
                    if ($entry['timestamp'] >= $fromTimestamp && $entry['timestamp'] <= $toTimestamp) {
                        $results[] = $entry;
                    }
                }
            }
        }
        
        usort($results, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        
        return $results;
    }
    
    /**
     * Get bucket for timestamp (day-level buckets)
     */
    private function getBucket(int $timestamp): int {
        return (int) floor($timestamp / 86400); // 86400 seconds in a day
    }
    
    /**
     * Get statistics
     */
    public function getStats(): array {
        $totalEntries = 0;
        foreach ($this->index as $bucket => $entries) {
            $totalEntries += count($entries);
        }
        
        return [
            'total_buckets' => count($this->index),
            'total_entries' => $totalEntries,
            'oldest_bucket' => !empty($this->index) ? min(array_keys($this->index)) : null,
            'newest_bucket' => !empty($this->index) ? max(array_keys($this->index)) : null
        ];
    }
}
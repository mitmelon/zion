<?php
namespace ZionXMemory\Utils;

/**
 * MDLCompressor - Minimum Description Length compression
 * Calculates optimal compression ratios for semantic preservation
 * 
 * @package ZionXMemory\Utils
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class MDLCompressor {
    /**
     * Calculate optimal compression for content
     * Returns target compression ratio (0.0-1.0)
     */
    public function calculateOptimalCompression(string $content): float {
        $contentLength = strlen($content);
        
        // Calculate complexity metrics
        $entropy = $this->calculateEntropy($content);
        $redundancy = $this->calculateRedundancy($content);
        $structureScore = $this->calculateStructureScore($content);
        
        // MDL principle: minimize (compressed_length + model_complexity)
        // Higher entropy = less compressible
        // Higher redundancy = more compressible
        // Higher structure = preserve more
        
        $baseCompression = 0.3; // Target 30% of original
        
        // Adjust based on metrics
        $entropyAdjustment = ($entropy - 3.5) * 0.05; // Higher entropy = keep more
        $redundancyAdjustment = ($redundancy - 0.5) * -0.1; // Higher redundancy = compress more
        $structureAdjustment = $structureScore * 0.1; // More structure = keep more
        
        $optimalRatio = $baseCompression + $entropyAdjustment + $redundancyAdjustment + $structureAdjustment;
        
        // Clamp between 0.2 and 0.8
        return max(0.2, min(0.8, $optimalRatio));
    }
    
    /**
     * Calculate Shannon entropy
     */
    private function calculateEntropy(string $content): float {
        $length = strlen($content);
        if ($length === 0) return 0;
        
        $frequencies = [];
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            $frequencies[$char] = ($frequencies[$char] ?? 0) + 1;
        }
        
        $entropy = 0;
        foreach ($frequencies as $count) {
            $probability = $count / $length;
            $entropy -= $probability * log($probability, 2);
        }
        
        return $entropy;
    }
    
    /**
     * Calculate redundancy (repeated patterns)
     */
    private function calculateRedundancy(string $content): float {
        $words = str_word_count(strtolower($content), 1);
        $totalWords = count($words);
        
        if ($totalWords === 0) return 0;
        
        $uniqueWords = count(array_unique($words));
        
        return 1 - ($uniqueWords / $totalWords);
    }
    
    /**
     * Calculate structure score (formatting, lists, code, etc.)
     */
    private function calculateStructureScore(string $content): float {
        $score = 0;
        
        // Check for code blocks
        if (preg_match_all('/```[\s\S]*?```/', $content)) {
            $score += 0.3;
        }
        
        // Check for lists
        if (preg_match_all('/^\s*[-*+]\s+/m', $content)) {
            $score += 0.2;
        }
        
        // Check for numbered lists
        if (preg_match_all('/^\s*\d+\.\s+/m', $content)) {
            $score += 0.2;
        }
        
        // Check for headers
        if (preg_match_all('/^#+\s+/m', $content)) {
            $score += 0.3;
        }
        
        return min(1.0, $score);
    }
}
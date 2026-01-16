<?php
namespace ZionXMemory\Contracts;

/**
 * AdaptiveConfigInterface
 * Configuration management for adaptive features
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 2.0.0
 * @since 1.0.0
 */

interface AdaptiveConfigInterface {
    /**
     * Get adaptive configuration for tenant
     */
    public function getConfig(string $tenantId): array;
    
    /**
     * Update configuration
     */
    public function updateConfig(string $tenantId, array $config): bool;
    
    /**
     * Get default configuration
     */
    public function getDefaultConfig(): array;
    
    /**
     * Validate configuration
     */
    public function validateConfig(array $config): bool;
}
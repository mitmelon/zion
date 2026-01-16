<?php
namespace ZionXMemory\Contracts;

/**
 * JobDispatcherInterface
 * Dispatches background jobs (e.g. summarization) to a job queue or storage
 * 
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 */

interface JobDispatcherInterface {
    /**
     * Dispatch a summarization job and return a job id, or null on failure
     *
     * @param string $tenantId
     * @param string $agentId
     * @param string $layer
     * @return string|null
     */
    public function dispatchSummarization(string $tenantId, string $agentId, string $layer): ?string;

    /**
     * Dispatch a retention evaluation job for a tenant and return a job id, or null on failure
     *
     * @param string $tenantId
     * @return string|null
     */
    public function dispatchRetentionEvaluation(string $tenantId): ?string;
}

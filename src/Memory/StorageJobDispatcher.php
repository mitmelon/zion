<?php
namespace ZionXMemory\Memory;

use ZionXMemory\Contracts\JobDispatcherInterface;
use ZionXMemory\Contracts\StorageAdapterInterface;

/**
 * StorageJobDispatcher
 * Simple dispatcher that enqueues a job by writing a job object to storage.
 * This avoids heavy external dependencies and keeps dispatch non-blocking
 * for the caller since workers can process job keys asynchronously.
 * 
 * @package ZionXMemory\Memory
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

class StorageJobDispatcher implements JobDispatcherInterface {
    private StorageAdapterInterface $storage;

    public function __construct(StorageAdapterInterface $storage) {
        $this->storage = $storage;
    }

    public function dispatchSummarization(string $tenantId, string $agentId, string $layer): ?string {
        try {
            $jobId = bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            return null;
        }

        $key = "job:{$jobId}";

        $payload = [
            'id' => $jobId,
            'type' => 'summarization',
            'tenant' => $tenantId,
            'agent' => $agentId,
            'layer' => $layer,
            'created_at' => time()
        ];

        $meta = ['tenant' => $tenantId, 'job' => 'summarization'];

        $ok = $this->storage->write($key, $payload, $meta);

        return $ok ? $jobId : null;
    }

    public function dispatchRetentionEvaluation(string $tenantId): ?string {
        try {
            $jobId = bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            return null;
        }

        $key = "job:{$jobId}";

        $payload = [
            'id' => $jobId,
            'type' => 'retention_evaluation',
            'tenant' => $tenantId,
            'created_at' => time()
        ];

        $meta = ['tenant' => $tenantId, 'job' => 'retention_evaluation'];

        $ok = $this->storage->write($key, $payload, $meta);

        return $ok ? $jobId : null;
    }
}

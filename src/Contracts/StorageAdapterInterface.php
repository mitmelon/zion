<?php
namespace ZionXMemory\Contracts;
/**
 * Storage Adapter Interface
 * Enables pluggable storage backends
 * @package ZionXMemory\Contracts
 * @author Manomite Limited
 * @license MIT
 * @version 1.0.0
 * @since 1.0.0
 */

interface StorageAdapterInterface {
    public function connect(array $config): bool;
    public function write(string $key, mixed $value, array $metadata): bool;
    public function writeMulti(array $items): bool;
    public function read(string $key): mixed;
    public function readMulti(array $keys): array;
    public function query(array $criteria): array;
    public function exists(string $key): bool;
    public function getMetadata(string $key): array;

    // Set operations
    public function addToSet(string $key, string $value, array $metadata = []): bool;
    public function removeFromSet(string $key, string $value, array $metadata = []): bool;
    public function getSetMembers(string $key): array;
    public function isSetMember(string $key, string $value): bool;
}
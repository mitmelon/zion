<?php

declare(strict_types=1);

namespace Zion\Memory\Tests;

use PHPUnit\Framework\TestCase;
use Zion\Memory\Audit\AuditLogger;

/**
 * Unit tests for Audit Logger
 */
class AuditLoggerTest extends TestCase
{
    private string $testAuditPath;

    protected function setUp(): void
    {
        $this->testAuditPath = sys_get_temp_dir() . '/banking_ai_test_audit_' . uniqid();
        mkdir($this->testAuditPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testAuditPath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testLogEntry(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $logger->log(
            'tenant1',
            'user_login',
            'user_123',
            ['ip_address' => '192.168.1.1']
        );
        
        $trail = $logger->getAuditTrail('tenant1', 10);
        
        $this->assertCount(1, $trail);
        $this->assertEquals('user_login', $trail[0]['action']);
        $this->assertEquals('user_123', $trail[0]['actor']);
        $this->assertEquals('192.168.1.1', $trail[0]['metadata']['ip_address']);
    }

    public function testMultipleLogEntries(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $logger->log('tenant1', 'action_1', 'actor_1', []);
        $logger->log('tenant1', 'action_2', 'actor_2', []);
        $logger->log('tenant1', 'action_3', 'actor_3', []);
        
        $trail = $logger->getAuditTrail('tenant1', 10);
        
        $this->assertCount(3, $trail);
    }

    public function testAuditTrailLimit(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        for ($i = 1; $i <= 20; $i++) {
            $logger->log('tenant1', "action_$i", 'actor', []);
        }
        
        $trail = $logger->getAuditTrail('tenant1', 5);
        
        $this->assertCount(5, $trail);
    }

    public function testTenantIsolation(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $logger->log('tenant1', 'tenant1_action', 'actor1', []);
        $logger->log('tenant2', 'tenant2_action', 'actor2', []);
        
        $trail1 = $logger->getAuditTrail('tenant1', 10);
        $trail2 = $logger->getAuditTrail('tenant2', 10);
        
        $this->assertCount(1, $trail1);
        $this->assertCount(1, $trail2);
        $this->assertEquals('tenant1_action', $trail1[0]['action']);
        $this->assertEquals('tenant2_action', $trail2[0]['action']);
    }

    public function testIntegrityVerificationSuccess(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $logger->log('tenant1', 'action_1', 'actor', []);
        $logger->log('tenant1', 'action_2', 'actor', []);
        $logger->log('tenant1', 'action_3', 'actor', []);
        
        $integrity = $logger->verifyIntegrity('tenant1');
        
        $this->assertTrue($integrity);
    }

    public function testIntegrityVerificationEmptyLog(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        // No entries yet
        $integrity = $logger->verifyIntegrity('nonexistent_tenant');
        
        $this->assertTrue($integrity); // Empty log is valid
    }

    public function testHashChain(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $logger->log('tenant1', 'action_1', 'actor', []);
        $logger->log('tenant1', 'action_2', 'actor', []);
        
        $trail = $logger->getAuditTrail('tenant1', 10);
        
        // Each entry should have a hash
        foreach ($trail as $entry) {
            $this->assertArrayHasKey('hash', $entry);
            $this->assertNotEmpty($entry['hash']);
        }
        
        // Second entry should reference first entry's hash
        $this->assertArrayHasKey('previous_hash', $trail[1]);
    }

    public function testComplianceReport(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $startTime = time() - 3600;
        $endTime = time() + 3600;
        
        $logger->log('tenant1', 'account_opened', 'system', ['type' => 'checking']);
        $logger->log('tenant1', 'transaction_processed', 'system', ['amount' => 1000]);
        $logger->log('tenant1', 'compliance_check', 'compliance_agent', ['passed' => true]);
        
        $report = $logger->generateComplianceReport('tenant1', $startTime, $endTime);
        
        $this->assertArrayHasKey('period', $report);
        $this->assertArrayHasKey('total_events', $report);
        $this->assertArrayHasKey('events_by_action', $report);
        $this->assertArrayHasKey('events_by_actor', $report);
        $this->assertArrayHasKey('integrity_verified', $report);
        
        $this->assertEquals(3, $report['total_events']);
        $this->assertTrue($report['integrity_verified']);
    }

    public function testComplianceReportTimeRange(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        // Log entries at different times (simulated)
        $logger->log('tenant1', 'early_action', 'actor', []);
        $logger->log('tenant1', 'current_action', 'actor', []);
        
        // Get report for a narrow time range
        $report = $logger->generateComplianceReport(
            'tenant1',
            time() - 60,
            time() + 60
        );
        
        // Should include recent events
        $this->assertGreaterThanOrEqual(1, $report['total_events']);
    }

    public function testLogEntryContainsTimestamp(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $beforeLog = time();
        $logger->log('tenant1', 'timed_action', 'actor', []);
        $afterLog = time();
        
        $trail = $logger->getAuditTrail('tenant1', 1);
        
        $this->assertArrayHasKey('timestamp', $trail[0]);
        $this->assertGreaterThanOrEqual($beforeLog, $trail[0]['timestamp']);
        $this->assertLessThanOrEqual($afterLog, $trail[0]['timestamp']);
    }

    public function testMetadataPreservation(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $complexMetadata = [
            'customer' => [
                'id' => 'cust_123',
                'name' => 'John Smith',
            ],
            'transaction' => [
                'amount' => 50000,
                'currency' => 'USD',
                'type' => 'wire_transfer',
            ],
            'compliance' => [
                'aml_checked' => true,
                'kyc_verified' => true,
            ],
        ];
        
        $logger->log('tenant1', 'large_transaction', 'system', $complexMetadata);
        
        $trail = $logger->getAuditTrail('tenant1', 1);
        
        $this->assertEquals($complexMetadata, $trail[0]['metadata']);
    }

    public function testActorTracking(): void
    {
        $logger = new AuditLogger($this->testAuditPath);
        
        $logger->log('tenant1', 'action', 'user_alice', []);
        $logger->log('tenant1', 'action', 'user_bob', []);
        $logger->log('tenant1', 'action', 'system', []);
        $logger->log('tenant1', 'action', 'compliance_agent', []);
        
        $report = $logger->generateComplianceReport('tenant1', time() - 3600, time() + 3600);
        
        $this->assertCount(4, $report['events_by_actor']);
        $this->assertArrayHasKey('user_alice', $report['events_by_actor']);
        $this->assertArrayHasKey('system', $report['events_by_actor']);
    }
}

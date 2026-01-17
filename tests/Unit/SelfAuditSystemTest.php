<?php

namespace ZionXMemory\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZionXMemory\Graph\SelfAuditSystem;
use ZionXMemory\Contracts\StorageAdapterInterface;
use ZionXMemory\Contracts\EpistemicStatusInterface;
use ZionXMemory\Contracts\GraphConsistencyCheckerInterface;

class SelfAuditSystemTest extends TestCase
{
    public function testCalculateTrendPersistsSummaryAndReturnsIncreasing(): void
    {
        $tenantId = 'test_tenant_trend';
        $now = time();

        // Create institutional items: 5 in last week, 2 in previous week
        $items = [];
        // last week: 5 items (2 days ago to 1 day ago)
        for ($i = 0; $i < 5; $i++) {
            $items[] = ['id' => "it_last_{$i}", 'promoted_at' => $now - (2 * 86400) + $i];
        }
        // prev week: 2 items (10-12 days ago)
        for ($i = 0; $i < 2; $i++) {
            $items[] = ['id' => "it_prev_{$i}", 'promoted_at' => $now - (10 * 86400) - $i];
        }

        // Minority opinions - empty
        $minorities = [];

        // Mock StorageAdapterInterface
        $storage = $this->createMock(StorageAdapterInterface::class);
        // Expect query called for institutional and minority patterns
        $storage->expects($this->exactly(2))
            ->method('query')
            ->withConsecutive(
                [['pattern' => "institutional:{$tenantId}:*"]],
                [['pattern' => "minority_opinion:{$tenantId}:*"]]
            )
            ->willReturnOnConsecutiveCalls($items, $minorities);

        // Expect write called twice: summary and history (history key contains timestamp)
        $storage->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive(
                ["wisdom_trend:{$tenantId}", $this->isType('array'), ['tenant' => $tenantId, 'type' => 'wisdom_trend_summary']],
                [$this->callback(function ($key) use ($tenantId) {
                    return is_string($key) && strpos($key, "wisdom_trend_history:{$tenantId}:") === 0;
                }), $this->isType('array'), ['tenant' => $tenantId, 'type' => 'wisdom_trend_history']]
            )
            ->willReturn(true);

        // Mock EpistemicStatusInterface to return counts for statuses
        $epistemic = $this->createMock(EpistemicStatusInterface::class);
        $epistemic->method('getClaimsByStatus')->willReturnOnConsecutiveCalls(
            ['c1', 'c2'], // hypotheses
            ['e1', 'e2', 'e3', 'e4'], // evidence
            ['f1', 'f2', 'f3'] // confirmed
        );

        // Mock GraphConsistencyCheckerInterface (not used by getWisdomMetrics)
        $consistency = $this->createMock(GraphConsistencyCheckerInterface::class);

        $sut = new SelfAuditSystem($storage, $epistemic, $consistency);

        $result = $sut->getWisdomMetrics($tenantId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('trending', $result);
        // With 5 vs 2 we expect an "increasing" trend (not "volatile" since max > 3)
        $this->assertEquals('increasing', $result['trending']);
    }
}

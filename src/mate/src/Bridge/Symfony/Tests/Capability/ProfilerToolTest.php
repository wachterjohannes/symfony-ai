<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Capability;

use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Capability\ProfilerTool;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Exception\InvalidCollectorException;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Exception\ProfileNotFoundException;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\AI\Mate\Bridge\Symfony\Tests\Fixtures\TestCollector;
use Symfony\AI\Mate\Bridge\Symfony\Tests\Fixtures\TestCollectorFormatter;
use Symfony\AI\Mate\Exception\InvalidArgumentException;
use Symfony\AI\Mate\Exception\RuntimeException;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ProfilerToolTest extends TestCase
{
    private ProfilerTool $tool;
    private string $fixtureDir;

    /**
     * @var list<string>
     */
    private array $temporaryDirs = [];

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__.'/../Fixtures/profiler';
        $registry = new CollectorRegistry([]);
        $provider = new ProfilerDataProvider($this->fixtureDir, $registry);

        $this->tool = new ProfilerTool($provider);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->temporaryDirs = [];
    }

    public function testListProfilesReturnsAllProfiles()
    {
        $result = Toon::decode($this->tool->listProfiles());

        $this->assertArrayHasKey('profiles', $result);
        $profiles = $result['profiles'];
        $this->assertCount(3, $profiles);
        $this->assertArrayHasKey('token', $profiles[0]);
        $this->assertArrayHasKey('time_formatted', $profiles[0]);
        $this->assertSame('ghi789', $profiles[0]['token']);
    }

    public function testListProfilesWithLimit()
    {
        $result = Toon::decode($this->tool->listProfiles(limit: 2));

        $this->assertArrayHasKey('profiles', $result);
        $this->assertCount(2, $result['profiles']);
    }

    public function testListProfilesFilterByMethod()
    {
        $result = Toon::decode($this->tool->listProfiles(method: 'POST'));

        $this->assertArrayHasKey('profiles', $result);
        $profiles = $result['profiles'];
        $this->assertCount(1, $profiles);
        $this->assertSame('def456', $profiles[0]['token']);
    }

    public function testListProfilesFilterByStatusCode()
    {
        $result = Toon::decode($this->tool->listProfiles(statusCode: 404));

        $this->assertArrayHasKey('profiles', $result);
        $profiles = $result['profiles'];
        $this->assertCount(1, $profiles);
        $this->assertSame('ghi789', $profiles[0]['token']);
    }

    public function testListProfilesFilterByUrl()
    {
        $result = Toon::decode($this->tool->listProfiles(url: 'users'));

        $this->assertArrayHasKey('profiles', $result);
        $this->assertCount(2, $result['profiles']);
    }

    public function testListProfilesFilterByIp()
    {
        $result = Toon::decode($this->tool->listProfiles(ip: '127.0.0.1'));

        $this->assertArrayHasKey('profiles', $result);
        $this->assertCount(2, $result['profiles']);
    }

    public function testListProfilesFilterByRoute()
    {
        $result = Toon::decode($this->tool->listProfiles(url: '/api/users'));

        $this->assertArrayHasKey('profiles', $result);
        $this->assertCount(2, $result['profiles']);
    }

    public function testGetProfileReturnsProfileWithResourceUri()
    {
        $profile = Toon::decode($this->tool->getProfile('abc123'));

        $this->assertArrayHasKey('token', $profile);
        $this->assertArrayHasKey('resource_uri', $profile);
        $this->assertSame('abc123', $profile['token']);
        $this->assertSame('symfony-profiler://profile/abc123', $profile['resource_uri']);
    }

    public function testGetProfileThrowsExceptionForNonExistentToken()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Profile with token "nonexistent" not found');

        $this->tool->getProfile('nonexistent');
    }

    public function testListProfilesIncludesResourceUri()
    {
        $result = Toon::decode($this->tool->listProfiles());

        $this->assertArrayHasKey('profiles', $result);
        $profiles = $result['profiles'];
        $this->assertCount(3, $profiles);
        foreach ($profiles as $profile) {
            $this->assertArrayHasKey('resource_uri', $profile);
            $this->assertStringStartsWith('symfony-profiler://profile/', $profile['resource_uri']);
            $this->assertSame(
                'symfony-profiler://profile/'.$profile['token'],
                $profile['resource_uri']
            );
        }
    }

    public function testListProfilesReturnsIntegerKeys()
    {
        $result = Toon::decode($this->tool->listProfiles());

        $this->assertArrayHasKey('profiles', $result);
        $keys = array_keys($result['profiles']);
        $this->assertSame([0, 1, 2], $keys);
    }

    public function testCompareReportsImprovementWhenQueryCountDrops()
    {
        $tool = $this->createComparableTool(
            ['query_count' => 42, 'total_time_ms' => 120.5, 'duplicate_query_count' => 7],
            ['query_count' => 3, 'total_time_ms' => 18.25, 'duplicate_query_count' => 0],
        );

        $result = Toon::decode($tool->compare('baseline', 'current'));

        $this->assertSame('db', $result['collector']);
        $this->assertSame('improved', $result['verdict']);
        $this->assertSame('baseline', $result['baseline']['token']);
        $this->assertSame(42, $result['baseline']['query_count']);
        $this->assertSame('current', $result['current']['token']);
        $this->assertSame(3, $result['current']['query_count']);
        $this->assertSame(-39, $result['delta']['query_count']);
        $this->assertSame(-102.25, $result['delta']['total_time_ms']);
        $this->assertSame(-7, $result['delta']['duplicate_query_count']);
    }

    public function testCompareReportsUnchangedForIdenticalSummaries()
    {
        $summary = ['query_count' => 12, 'total_time_ms' => 33.0, 'duplicate_query_count' => 2];
        $tool = $this->createComparableTool($summary, $summary);

        $result = Toon::decode($tool->compare('baseline', 'current'));

        $this->assertSame('unchanged', $result['verdict']);
        foreach ($result['delta'] as $value) {
            $this->assertEquals(0, $value);
        }
    }

    public function testCompareReportsRegressionWhenQueryCountRises()
    {
        $tool = $this->createComparableTool(
            ['query_count' => 4],
            ['query_count' => 61],
        );

        $result = Toon::decode($tool->compare('baseline', 'current'));

        $this->assertSame('regressed', $result['verdict']);
        $this->assertSame(57, $result['delta']['query_count']);
    }

    public function testCompareSkipsNonNumericSummaryFields()
    {
        $tool = $this->createComparableTool(
            ['query_count' => 8, 'connection' => 'default', 'truncated' => false],
            ['query_count' => 8, 'connection' => 'replica', 'truncated' => true],
        );

        $result = Toon::decode($tool->compare('baseline', 'current'));

        $this->assertSame(['query_count'], array_keys($result['delta']));
        $this->assertSame('replica', $result['current']['connection']);
    }

    public function testCompareUsesFirstNumericMetricForCollectorsWithoutLeadingMetric()
    {
        $tool = $this->createComparableTool(
            ['duration_ms' => 250.0],
            ['duration_ms' => 100.0],
            'time',
        );

        $result = Toon::decode($tool->compare('baseline', 'current', 'time'));

        $this->assertSame('time', $result['collector']);
        $this->assertSame('improved', $result['verdict']);
        $this->assertEquals(-150.0, $result['delta']['duration_ms']);
    }

    public function testCompareThrowsExceptionForUnknownToken()
    {
        $tool = $this->createComparableTool(['query_count' => 1], ['query_count' => 1]);

        $this->expectException(ProfileNotFoundException::class);
        $this->expectExceptionMessage('Profile not found for token: "nonexistent"');

        $tool->compare('baseline', 'nonexistent');
    }

    public function testCompareThrowsExceptionForUnknownCollector()
    {
        $tool = $this->createComparableTool(['query_count' => 1], ['query_count' => 1]);

        $this->expectException(InvalidCollectorException::class);

        $tool->compare('baseline', 'current', 'unknown');
    }

    public function testCompareThrowsExceptionForCollectorWithoutSummary()
    {
        $tool = $this->createComparableTool(['query_count' => 1], ['query_count' => 1]);

        $this->expectException(InvalidCollectorException::class);
        $this->expectExceptionMessage('Collector "unformatted" of profile "baseline" does not provide a summary to compare');

        $tool->compare('baseline', 'current', 'unformatted');
    }

    public function testAssertPassesWhenEveryExpectationIsMet()
    {
        $tool = $this->createAssertTool();

        $result = Toon::decode($tool->assertProfile(token: 'fast', maxQueries: 10, maxDurationMs: 200.0, maxDuplicates: 0, expectNoException: true));

        $this->assertTrue($result['passed']);
        $this->assertSame('fast', $result['token']);
        $this->assertCount(4, $result['checks']);
        foreach ($result['checks'] as $check) {
            $this->assertTrue($check['passed'], $check['metric'].' should pass');
        }
        $this->assertArrayNotHasKey('remaining_query_sources', $result);
    }

    public function testAssertReportsEachExpectationAsActualAgainstLimit()
    {
        $tool = $this->createAssertTool();

        $result = Toon::decode($tool->assertProfile(token: 'slow', maxQueries: 20));

        $this->assertFalse($result['passed']);
        $this->assertSame('query_count', $result['checks'][0]['metric']);
        $this->assertSame(54, $result['checks'][0]['actual']);
        $this->assertSame(20, $result['checks'][0]['limit']);
        $this->assertFalse($result['checks'][0]['passed']);
    }

    public function testAssertNamesWhatIsLeftWhenAQueryBudgetIsMissed()
    {
        $tool = $this->createAssertTool();

        $result = Toon::decode($tool->assertProfile(token: 'slow', maxQueries: 20));

        $remaining = $result['remaining_query_sources'];
        $this->assertCount(5, $remaining);
        $this->assertTrue($result['remaining_query_sources_truncated']);
        // Ordered by repetitions, not by total time: an N+1 is the most repeated group.
        $this->assertSame([40, 9, 8, 7, 6], array_column($remaining, 'count'));
        $this->assertSame('SELECT * FROM n_plus_one', $remaining[0]['sql']);
    }

    public function testAssertNamesWhatIsLeftWhenTheDuplicateBudgetIsMissed()
    {
        $tool = $this->createAssertTool();

        $result = Toon::decode($tool->assertProfile(token: 'slow', maxDuplicates: 2));

        $this->assertFalse($result['passed']);
        $this->assertSame('duplicate_query_count', $result['checks'][0]['metric']);
        $this->assertArrayHasKey('remaining_query_sources', $result);
    }

    public function testAssertDoesNotListRemainingSourcesWhenOnlyANonQueryBudgetIsMissed()
    {
        $tool = $this->createAssertTool();

        $result = Toon::decode($tool->assertProfile(token: 'slow', maxQueries: 100, maxDurationMs: 100.0));

        $this->assertFalse($result['passed']);
        $this->assertArrayNotHasKey('remaining_query_sources', $result);
    }

    public function testAssertTreatsAMissAsAResultNotAnError()
    {
        $tool = $this->createAssertTool();

        // No exception is thrown even though every expectation is missed.
        $result = Toon::decode($tool->assertProfile(token: 'slow', maxQueries: 1, maxDurationMs: 1.0, maxDuplicates: 0, expectNoException: true));

        $this->assertFalse($result['passed']);
        $this->assertCount(4, $result['checks']);
        $this->assertTrue($result['checks'][3]['actual']);
        $this->assertFalse($result['checks'][3]['passed']);
    }

    public function testAssertPassesOnTheBoundary()
    {
        $tool = $this->createAssertTool();

        $result = Toon::decode($tool->assertProfile(token: 'slow', maxQueries: 54));

        $this->assertTrue($result['passed']);
    }

    public function testAssertResolvesTheProfileByUrl()
    {
        $tool = $this->createAssertTool();

        $result = Toon::decode($tool->assertProfile(url: '/checkout', maxQueries: 1000));

        $this->assertSame('slow', $result['token']);
        $this->assertSame('/checkout', $result['url']);
    }

    public function testAssertRequiresAtLeastOneExpectation()
    {
        $tool = $this->createAssertTool();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('symfony-profiler-assert needs at least one expectation');

        $tool->assertProfile(token: 'fast');
    }

    public function testAssertThrowsExceptionForUnknownToken()
    {
        $tool = $this->createAssertTool();

        $this->expectException(ProfileNotFoundException::class);

        $tool->assertProfile(token: 'nonexistent', maxQueries: 10);
    }

    public function testAssertThrowsExceptionWhenTheAssertedCollectorIsMissing()
    {
        $tool = $this->createAssertTool();

        $this->expectException(InvalidCollectorException::class);

        $tool->assertProfile(token: 'bare', maxQueries: 10);
    }

    public function testProfilerToolsFailClearlyWhenProfilerSupportIsUnavailable()
    {
        $tool = new ProfilerTool();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Symfony profiler tools are not available in this Mate workspace.');

        $tool->listProfiles();
    }

    /**
     * Builds a tool over three profiles: "slow" (/checkout, misses every budget), "fast"
     * (/health, meets every budget) and "bare" (no collectors at all).
     */
    private function createAssertTool(): ProfilerTool
    {
        $dir = sys_get_temp_dir().'/mate-profiler-assert-'.bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        $this->temporaryDirs[] = $dir;

        $now = time();
        $storage = new FileProfilerStorage('file:'.$dir);

        // Sorted by total time, as the Doctrine formatter emits it — the N+1 sits in the middle
        // by time but is by far the most repeated group.
        $queries = [
            ['sql' => 'SELECT * FROM report', 'count' => 1, 'total_time_ms' => 900.0],
            ['sql' => 'SELECT * FROM n_plus_one', 'count' => 40, 'total_time_ms' => 800.0],
            ['sql' => 'SELECT * FROM other_3', 'count' => 6, 'total_time_ms' => 700.0],
            ['sql' => 'SELECT * FROM other_2', 'count' => 7, 'total_time_ms' => 600.0],
            ['sql' => 'SELECT * FROM other_1', 'count' => 8, 'total_time_ms' => 500.0],
            ['sql' => 'SELECT * FROM other_0', 'count' => 9, 'total_time_ms' => 400.0],
        ];

        $slow = $this->createAssertProfile('slow', '/checkout', $now, [
            new TestCollector('db', ['query_count' => 54, 'queries' => $queries], [
                'query_count' => 54,
                'total_time_ms' => 3900.0,
                'duplicate_query_count' => 31,
            ]),
            new TestCollector('time', ['events' => []], ['duration_ms' => 820.5, 'init_time_ms' => 12.25]),
            new TestCollector('exception', [], [
                'has_exception' => true,
                'class' => 'RuntimeException',
                'message' => 'Boom',
            ]),
        ]);

        $fast = $this->createAssertProfile('fast', '/health', $now - 60, [
            new TestCollector('db', ['query_count' => 3, 'queries' => [
                ['sql' => 'SELECT 1', 'count' => 1, 'total_time_ms' => 1.0],
            ]], ['query_count' => 3, 'total_time_ms' => 4.0, 'duplicate_query_count' => 0]),
            new TestCollector('time', ['events' => []], ['duration_ms' => 40.0, 'init_time_ms' => 5.0]),
            new TestCollector('exception', [], ['has_exception' => false]),
        ]);

        $bare = $this->createAssertProfile('bare', '/static', $now - 120, []);

        foreach ([$slow, $fast, $bare] as $profile) {
            $this->assertTrue($storage->write($profile));
        }

        clearstatcache();

        $registry = new CollectorRegistry([
            new TestCollectorFormatter('db'),
            new TestCollectorFormatter('time'),
            new TestCollectorFormatter('exception'),
        ]);

        return new ProfilerTool(new ProfilerDataProvider($dir, $registry));
    }

    /**
     * @param list<TestCollector> $collectors
     */
    private function createAssertProfile(string $token, string $url, int $time, array $collectors): Profile
    {
        $profile = new Profile($token);
        $profile->setIp('127.0.0.1');
        $profile->setMethod('GET');
        $profile->setUrl($url);
        $profile->setStatusCode(200);
        $profile->setTime($time);
        $profile->setCollectors($collectors);

        return $profile;
    }

    /**
     * Builds a tool over two profiles named "baseline" and "current", each carrying the given
     * summary in the given collector.
     *
     * @param array<string, mixed> $baselineSummary
     * @param array<string, mixed> $currentSummary
     */
    private function createComparableTool(array $baselineSummary, array $currentSummary, string $collector = 'db'): ProfilerTool
    {
        $dir = sys_get_temp_dir().'/mate-profiler-compare-'.bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        $this->temporaryDirs[] = $dir;

        $storage = new FileProfilerStorage('file:'.$dir);
        $this->assertTrue($storage->write($this->createProfile('baseline', $collector, $baselineSummary)));
        $this->assertTrue($storage->write($this->createProfile('current', $collector, $currentSummary)));
        clearstatcache();

        $registry = new CollectorRegistry([new TestCollectorFormatter($collector)]);

        return new ProfilerTool(new ProfilerDataProvider($dir, $registry));
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function createProfile(string $token, string $collector, array $summary): Profile
    {
        $profile = new Profile($token);
        $profile->setIp('127.0.0.1');
        $profile->setMethod('GET');
        $profile->setUrl('/api/users');
        $profile->setStatusCode(200);
        // FileProfilerStorage::write() randomly prunes profiles older than two days.
        $profile->setTime(time());
        $profile->setCollectors([
            new TestCollector($collector, $summary),
            new TestCollector('unformatted', ['irrelevant' => true]),
        ]);

        return $profile;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

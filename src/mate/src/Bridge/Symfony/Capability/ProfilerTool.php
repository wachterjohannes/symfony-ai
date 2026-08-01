<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Capability;

use Symfony\AI\Mate\Attribute\AsTool;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Exception\InvalidCollectorException;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Exception\ProfileNotFoundException;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Model\ProfileIndex;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use Symfony\AI\Mate\Exception\InvalidArgumentException;
use Symfony\AI\Mate\Exception\RuntimeException;

/**
 * tools for accessing Symfony profiler data.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ProfilerTool
{
    /**
     * Metric that decides the verdict of a comparison, per collector. Collectors without an
     * entry fall back to the first numeric metric of their summary.
     *
     * @var array<string, string>
     */
    private const LEADING_METRICS = [
        'db' => 'query_count',
    ];

    /**
     * Number of statement groups returned when a query budget is missed. Enough to name what is
     * still there, not the whole list — that stays behind the collector resource.
     */
    private const ASSERT_SOURCE_LIMIT = 5;

    public function __construct(
        private readonly ?ProfilerDataProvider $dataProvider = null,
    ) {
    }

    /**
     * @param int         $limit      Maximum number of profiles to return (use limit=1 to get the latest profile)
     * @param string|null $method     Filter by HTTP method (GET, POST, PUT, DELETE, PATCH)
     * @param string|null $url        Filter by URL path (partial match supported)
     * @param string|null $ip         Filter by client IP address
     * @param int|null    $statusCode Filter by HTTP response status code (e.g. 200, 404, 500)
     * @param string|null $context    Filter by Symfony kernel context
     * @param string|null $from       Start date filter for profile creation time
     * @param string|null $to         End date filter for profile creation time
     */
    #[AsTool(name: 'symfony-profiler-list', title: 'Symfony Profiler List', description: 'List and filter Symfony profiler profiles by HTTP method, URL, IP, status code, date range, or context. Profiles are sorted by most recent first, so limit=1 returns the latest profile. Returns summary data with resource_uri for fetching full details via the resource template.')]
    public function listProfiles(
        int $limit = 20,
        ?string $method = null,
        ?string $url = null,
        ?string $ip = null,
        ?int $statusCode = null,
        ?string $context = null,
        ?string $from = null,
        ?string $to = null,
    ): string {
        $dataProvider = $this->getDataProvider();
        $criteria = [
            'context' => $context,
            'method' => $method,
            'url' => $url,
            'ip' => $ip,
            'statusCode' => $statusCode,
            'from' => $from,
            'to' => $to,
        ];

        $profiles = $dataProvider->searchProfiles(array_filter($criteria), $limit);

        return ResponseEncoder::encode([
            'profiles' => array_values(array_map(
                static fn (ProfileIndex $profile): array => $profile->toArray(),
                $profiles,
            )),
        ]);
    }

    /**
     * @param string $token The unique profiler token identifying the profile
     */
    #[AsTool(name: 'symfony-profiler-get', title: 'Symfony Profiler Get', description: 'Get a specific profiler profile by its token. Returns detailed profile data including available collectors and resource_uri for accessing collector-specific data.')]
    public function getProfile(string $token): string
    {
        $profileData = $this->getDataProvider()->findProfile($token);

        if (null === $profileData) {
            throw new InvalidArgumentException(\sprintf('Profile with token "%s" not found', $token));
        }

        $profile = $profileData->getProfile();
        $data = [
            'token' => $profile->getToken(),
            'ip' => $profile->getIp(),
            'method' => $profile->getMethod(),
            'url' => $profile->getUrl(),
            'time' => $profile->getTime(),
            'time_formatted' => date(\DateTimeInterface::ATOM, $profile->getTime()),
            'status_code' => $profile->getStatusCode(),
            'parent_token' => $profile->getParentToken(),
            'resource_uri' => \sprintf('symfony-profiler://profile/%s', $profile->getToken()),
        ];

        if (null !== $profileData->getContext()) {
            $data['context'] = $profileData->getContext();
        }

        return ResponseEncoder::encode($data);
    }

    /**
     * @param string|null $url               URL path of the request to check (partial match); the newest matching profile is used
     * @param string|null $token             Exact profiler token to check; takes precedence over url
     * @param int|null    $maxQueries        Fail when the profile ran more than this many database queries
     * @param float|null  $maxDurationMs     Fail when the request took longer than this many milliseconds
     * @param int|null    $maxDuplicates     Fail when more than this many statements were executed repeatedly
     * @param bool        $expectNoException Fail when the request produced an exception
     */
    #[AsTool(name: 'symfony-profiler-assert', title: 'Symfony Profiler Assert', description: 'Check one profile against an acceptance criterion instead of just reporting numbers. Give at least one expectation (maxQueries, maxDurationMs, maxDuplicates, expectNoException) and it returns passed plus every expectation as actual against limit. A missed expectation is a result, not an error: on a query miss it also returns the statement groups with the most repetitions, so what is still left is named. Run this after a change; the work is done when passed is true.')]
    public function assertProfile(
        ?string $url = null,
        ?string $token = null,
        ?int $maxQueries = null,
        ?float $maxDurationMs = null,
        ?int $maxDuplicates = null,
        bool $expectNoException = false,
    ): string {
        if (null === $maxQueries && null === $maxDurationMs && null === $maxDuplicates && false === $expectNoException) {
            throw new InvalidArgumentException('symfony-profiler-assert needs at least one expectation: maxQueries, maxDurationMs, maxDuplicates or expectNoException.');
        }

        $resolvedToken = $this->resolveProfileToken($url, $token);
        $dataProvider = $this->getDataProvider();

        $profileData = $dataProvider->findProfile($resolvedToken);
        if (null === $profileData) {
            throw new ProfileNotFoundException(\sprintf('Profile not found for token: "%s"', $resolvedToken));
        }

        $profile = $profileData->getProfile();
        $checks = [];
        $queryCheckMissed = false;

        if (null !== $maxQueries || null !== $maxDuplicates) {
            $dbSummary = $this->getAssertSummary($resolvedToken, 'db');

            if (null !== $maxQueries) {
                $check = $this->buildLimitCheck('query_count', $this->getAssertMetric($dbSummary, 'query_count', 'db', $resolvedToken), $maxQueries);
                if (!$check['passed']) {
                    $queryCheckMissed = true;
                }

                $checks[] = $check;
            }

            if (null !== $maxDuplicates) {
                $check = $this->buildLimitCheck('duplicate_query_count', $this->getAssertMetric($dbSummary, 'duplicate_query_count', 'db', $resolvedToken), $maxDuplicates);
                if (!$check['passed']) {
                    $queryCheckMissed = true;
                }

                $checks[] = $check;
            }
        }

        if (null !== $maxDurationMs) {
            $timeSummary = $this->getAssertSummary($resolvedToken, 'time');
            $checks[] = $this->buildLimitCheck('duration_ms', $this->getAssertMetric($timeSummary, 'duration_ms', 'time', $resolvedToken), $maxDurationMs);
        }

        if (true === $expectNoException) {
            $exceptionSummary = $this->getAssertSummary($resolvedToken, 'exception');
            $hasException = true === ($exceptionSummary['has_exception'] ?? null);
            $checks[] = [
                'metric' => 'has_exception',
                'actual' => $hasException,
                'expected' => false,
                'passed' => !$hasException,
            ];
        }

        $passed = true;
        foreach ($checks as $check) {
            if (!$check['passed']) {
                $passed = false;
            }
        }

        $result = [
            'passed' => $passed,
            'token' => $profile->getToken(),
            'method' => $profile->getMethod(),
            'url' => $profile->getUrl(),
            'checks' => $checks,
            'resource_uri' => \sprintf('symfony-profiler://profile/%s', $profile->getToken()),
        ];

        // A missed query budget is only actionable when the load that is still there is named.
        if ($queryCheckMissed) {
            $queries = $dataProvider->getCollectorData($resolvedToken, 'db')['data']['queries'] ?? null;
            if (\is_array($queries)) {
                $result['remaining_query_sources'] = $this->buildMostRepeatedQueries($queries);
                $result['remaining_query_sources_truncated'] = \count($queries) > self::ASSERT_SOURCE_LIMIT;
            }
        }

        return ResponseEncoder::encode($result);
    }

    /**
     * @param string $baseline  The profiler token of the profile measured before the change
     * @param string $current   The profiler token of the profile measured after the change
     * @param string $collector The collector to compare (e.g. db, time, memory, logger)
     */
    #[AsTool(name: 'symfony-profiler-compare', title: 'Symfony Profiler Compare', description: 'Compare the collector summary of two profiler profiles to prove whether a change actually improved a measurement. Reproduce the request after your fix, then compare the new token against the token you captured before. Returns both summaries, the difference for every numeric metric and a verdict (improved, unchanged, regressed).')]
    public function compare(string $baseline, string $current, string $collector = 'db'): string
    {
        $baselineSummary = $this->getCollectorSummary($baseline, $collector);
        $currentSummary = $this->getCollectorSummary($current, $collector);

        $delta = [];
        foreach ($currentSummary as $key => $currentValue) {
            if (!\is_int($currentValue) && !\is_float($currentValue)) {
                continue;
            }

            $baselineValue = $baselineSummary[$key] ?? null;
            if (!\is_int($baselineValue) && !\is_float($baselineValue)) {
                continue;
            }

            $difference = $currentValue - $baselineValue;
            $delta[$key] = \is_float($difference) ? round($difference, 2) : $difference;
        }

        return ResponseEncoder::encode([
            'collector' => $collector,
            'baseline' => array_merge(['token' => $baseline], $baselineSummary),
            'current' => array_merge(['token' => $current], $currentSummary),
            'delta' => $delta,
            'verdict' => $this->buildVerdict($collector, $delta),
        ]);
    }

    private function resolveProfileToken(?string $url, ?string $token): string
    {
        if (null !== $token && '' !== $token) {
            return $token;
        }

        $dataProvider = $this->getDataProvider();

        if (null !== $url && '' !== $url) {
            $profiles = $dataProvider->searchProfiles(['url' => $url], 1);
            if ([] === $profiles) {
                throw new ProfileNotFoundException(\sprintf('No profile found for url "%s"', $url));
            }

            return $profiles[0]->getToken();
        }

        $latest = $dataProvider->getLatestProfile();
        if (null === $latest) {
            throw new ProfileNotFoundException('No profiles recorded; the profiler storage is empty.');
        }

        return $latest->getToken();
    }

    /**
     * @return array{metric: string, actual: float|int, limit: float|int, passed: bool}
     */
    private function buildLimitCheck(string $metric, float|int $actual, float|int $limit): array
    {
        return [
            'metric' => $metric,
            'actual' => $actual,
            'limit' => $limit,
            'passed' => $actual <= $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAssertSummary(string $token, string $collector): array
    {
        $summary = $this->getDataProvider()->getCollectorData($token, $collector)['summary'];
        if ([] === $summary) {
            throw new InvalidCollectorException(\sprintf('Collector "%s" of profile "%s" provides no summary to assert against', $collector, $token));
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function getAssertMetric(array $summary, string $key, string $collector, string $token): float|int
    {
        $value = $summary[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw new InvalidCollectorException(\sprintf('Collector "%s" of profile "%s" does not report "%s"', $collector, $token, $key));
        }

        return $value;
    }

    /**
     * The grouped list arrives sorted by total time; what is left of a query budget is answered
     * by repetition, so it is re-ordered by count here.
     *
     * @param array<mixed> $queries Grouped queries from the Doctrine formatter
     *
     * @return list<array{sql: string, count: int, total_time_ms: float}>
     */
    private function buildMostRepeatedQueries(array $queries): array
    {
        $groups = [];
        foreach ($queries as $query) {
            if (!\is_array($query)) {
                continue;
            }

            $groups[] = [
                'sql' => (string) ($query['sql'] ?? ''),
                'count' => (int) ($query['count'] ?? 0),
                'total_time_ms' => (float) ($query['total_time_ms'] ?? 0.0),
            ];
        }

        usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return \array_slice($groups, 0, self::ASSERT_SOURCE_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCollectorSummary(string $token, string $collector): array
    {
        $summary = $this->getDataProvider()->getCollectorData($token, $collector)['summary'];

        if ([] === $summary) {
            throw new InvalidCollectorException(\sprintf('Collector "%s" of profile "%s" does not provide a summary to compare', $collector, $token));
        }

        return $summary;
    }

    /**
     * @param array<string, float|int> $delta
     */
    private function buildVerdict(string $collector, array $delta): string
    {
        $leadingMetric = self::LEADING_METRICS[$collector] ?? array_key_first($delta);
        if (null === $leadingMetric || !isset($delta[$leadingMetric])) {
            return 'unchanged';
        }

        if ($delta[$leadingMetric] < 0) {
            return 'improved';
        }

        if ($delta[$leadingMetric] > 0) {
            return 'regressed';
        }

        return 'unchanged';
    }

    private function getDataProvider(): ProfilerDataProvider
    {
        if (null === $this->dataProvider) {
            throw new RuntimeException('Symfony profiler tools are not available in this Mate workspace.');
        }

        return $this->dataProvider;
    }
}

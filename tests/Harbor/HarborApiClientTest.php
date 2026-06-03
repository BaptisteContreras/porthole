<?php

namespace Porthole\Tests\Harbor;

use PHPUnit\Framework\TestCase;
use Porthole\Harbor\HarborApiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class HarborApiClientTest extends TestCase
{
    public function testPaginatesThroughAllPages(): void
    {
        $page1 = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
        ]);
        $page2 = json_encode([
            ['username' => 'bob', 'resource' => 'library/redis:7', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-02T11:00:00.000Z'],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($page1, ['response_headers' => ['Link' => '</api/v2.0/audit-logs?page=2&page_size=100>; rel="next"']]),
            new MockResponse($page2, ['response_headers' => ['Link' => '</api/v2.0/audit-logs?page=1&page_size=100>; rel="prev"']]),
        ]);

        $client = new HarborApiClient('https://registry.example.com', 'my-token', $httpClient);
        $entries = $client->fetchAuditLogs();

        $this->assertCount(2, $entries);
        $this->assertSame('alice', $entries[0]->username);
        $this->assertSame('bob', $entries[1]->username);
    }

    public function testPassesDateFilterQueryParam(): void
    {
        $body = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-15T10:00:00.000Z'],
        ]);

        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($body, &$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse($body);
        });

        $client = new HarborApiClient('https://registry.example.com', 'my-token', $httpClient);
        $client->fetchAuditLogs(
            new \DateTimeImmutable('2025-06-01T00:00:00.000Z'),
            new \DateTimeImmutable('2025-06-30T23:59:59.000Z'),
        );

        $this->assertStringContainsString('q=', $capturedUrl);
        $this->assertStringContainsString('2025-06-01T00:00:00.000Z', $capturedUrl);
        $this->assertStringContainsString('2025-06-30T23:59:59.000Z', $capturedUrl);
    }

    public function testPassesOnlyFromDateFilterQueryParam(): void
    {
        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse('[]');
        });

        $client = new HarborApiClient('https://registry.example.com', 'my-token', $httpClient);
        $client->fetchAuditLogs(new \DateTimeImmutable('2025-06-01T00:00:00.000Z'));

        $this->assertStringContainsString('2025-06-01T00:00:00.000Z', $capturedUrl);
        $this->assertStringContainsString('~]', $capturedUrl);
    }

    public function testPassesOnlyToDateFilterQueryParam(): void
    {
        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse('[]');
        });

        $client = new HarborApiClient('https://registry.example.com', 'my-token', $httpClient);
        $client->fetchAuditLogs(null, new \DateTimeImmutable('2025-06-30T23:59:59.000Z'));

        $this->assertStringContainsString('[~', $capturedUrl);
        $this->assertStringContainsString('2025-06-30T23:59:59.000Z', $capturedUrl);
    }

    public function testCallsOnPageFetchedWithPageNumberForEachPage(): void
    {
        $page1 = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
        ]);
        $page2 = json_encode([
            ['username' => 'bob', 'resource' => 'library/redis:7', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-02T11:00:00.000Z'],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($page1, ['response_headers' => ['Link' => '</api/v2.0/audit-logs?page=2&page_size=100>; rel="next"']]),
            new MockResponse($page2, ['response_headers' => ['Link' => '</api/v2.0/audit-logs?page=1&page_size=100>; rel="prev"']]),
        ]);

        $client = new HarborApiClient('https://registry.example.com', 'my-token', $httpClient);
        $pages = [];
        $client->fetchAuditLogs(onPageFetched: function (int $page) use (&$pages) {
            $pages[] = $page;
        });

        $this->assertSame([1, 2], $pages);
    }

    public function testNullOnPageFetchedDoesNotThrow(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
            ])),
        ]);

        $client = new HarborApiClient('https://registry.example.com', 'my-token', $httpClient);
        $entries = $client->fetchAuditLogs(); // null onPageFetched — no error

        $this->assertCount(1, $entries);
    }
}

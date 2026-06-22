<?php

namespace Porthole\Tests\Harbor;

use PHPUnit\Framework\TestCase;
use Porthole\Harbor\HarborApiClient;
use Porthole\Harbor\HarborContext;
use Porthole\Harbor\LegacyAuditLogEndpointStrategy;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class HarborApiClientTest extends TestCase
{
    private HarborContext $context;

    protected function setUp(): void
    {
        $this->context = new HarborContext(
            url: 'https://registry.example.com',
            token: 'my-token',
            username: null,
            verifySsl: true,
        );
    }

    public function testPaginatesThroughAllPages(): void
    {
        $page1 = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
        ]);
        assert(is_string($page1));
        $page2 = json_encode([
            ['username' => 'bob', 'resource' => 'library/redis:7', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-02T11:00:00.000Z'],
        ]);
        assert(is_string($page2));

        $httpClient = new MockHttpClient([
            new MockResponse($page1, ['response_headers' => ['Link' => '</api/v2.0/audit-logs?page=2&page_size=100>; rel="next"']]),
            new MockResponse($page2, ['response_headers' => ['Link' => '</api/v2.0/audit-logs?page=1&page_size=100>; rel="prev"']]),
        ]);

        $client = new HarborApiClient($httpClient);
        $entries = [];
        foreach ($client->streamAuditLogs($this->context) as $pageEntries) {
            array_push($entries, ...$pageEntries);
        }

        $this->assertCount(2, $entries);
        $this->assertSame('alice', $entries[0]->username);
        $this->assertSame('bob', $entries[1]->username);
    }

    public function testPassesDateFilterQueryParam(): void
    {
        $body = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-15T10:00:00.000Z'],
        ]);
        assert(is_string($body));

        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($body, &$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse($body);
        });

        $client = new HarborApiClient($httpClient);
        foreach ($client->streamAuditLogs(
            $this->context,
            new \DateTimeImmutable('2025-06-01T00:00:00.000Z'),
            new \DateTimeImmutable('2025-06-30T23:59:59.000Z'),
        ) as $_) {
        }

        $this->assertIsString($capturedUrl);
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

        $client = new HarborApiClient($httpClient);
        foreach ($client->streamAuditLogs(
            $this->context,
            new \DateTimeImmutable('2025-06-01T00:00:00.000Z'),
        ) as $_) {
        }

        $this->assertIsString($capturedUrl);
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

        $client = new HarborApiClient($httpClient);
        foreach ($client->streamAuditLogs(
            $this->context,
            null,
            new \DateTimeImmutable('2025-06-30T23:59:59.000Z'),
        ) as $_) {
        }

        $this->assertIsString($capturedUrl);
        $this->assertStringContainsString('[~', $capturedUrl);
        $this->assertStringContainsString('2025-06-30T23:59:59.000Z', $capturedUrl);
    }

    public function testUsesExtendedEndpointUrlByDefault(): void
    {
        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse('[]');
        });

        $client = new HarborApiClient($httpClient);
        foreach ($client->streamAuditLogs($this->context) as $_) {
        }

        $this->assertIsString($capturedUrl);
        $this->assertStringContainsString('/api/v2.0/auditlog-exts', $capturedUrl);
    }

    public function testUsesLegacyEndpointUrlWhenConfigured(): void
    {
        $context = new HarborContext(
            url: 'https://registry.example.com',
            token: 'my-token',
            username: null,
            verifySsl: true,
            auditLogEndpointStrategy: new LegacyAuditLogEndpointStrategy(),
        );

        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse('[]');
        });

        $client = new HarborApiClient($httpClient);
        foreach ($client->streamAuditLogs($context) as $_) {
        }

        $this->assertIsString($capturedUrl);
        $this->assertStringContainsString('/api/v2.0/audit-logs', $capturedUrl);
    }

    public function testYieldsOneBasedPageNumberAsKey(): void
    {
        $page1 = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
        ]);
        assert(is_string($page1));
        $page2 = json_encode([
            ['username' => 'bob', 'resource' => 'library/redis:7', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-02T11:00:00.000Z'],
        ]);
        assert(is_string($page2));

        $httpClient = new MockHttpClient([
            new MockResponse($page1, ['response_headers' => ['Link' => '</api/v2.0/audit-logs?page=2&page_size=100>; rel="next"']]),
            new MockResponse($page2),
        ]);

        $client = new HarborApiClient($httpClient);
        $pages = [];
        foreach ($client->streamAuditLogs($this->context) as $page => $_) {
            $pages[] = $page;
        }

        $this->assertSame([1, 2], $pages);
    }
}

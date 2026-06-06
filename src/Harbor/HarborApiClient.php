<?php

namespace Porthole\Harbor;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HarborApiClient
{
    private const int PAGE_SIZE = 100;
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.000\Z';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Streams audit log entries page by page.
     * The generator key is the 1-based page number.
     *
     * @return \Generator<int, list<AuditLogEntry>>
     */
    public function streamAuditLogs(
        HarborContext $context,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): \Generator {
        $httpClient = $context->verifySsl
            ? $this->httpClient
            : $this->httpClient->withOptions(['verify_peer' => false, 'verify_host' => false]);

        $filterQuery = null;
        if (null !== $from || null !== $to) {
            $filterQuery = sprintf(
                'op_time=[%s~%s]',
                $from?->format(self::DATE_FORMAT) ?? '',
                $to?->format(self::DATE_FORMAT) ?? '',
            );
        }

        $page = 1;
        $query = ['page' => $page, 'page_size' => self::PAGE_SIZE];
        if (null !== $filterQuery) {
            $query['q'] = $filterQuery;
        }

        $url = sprintf('%s/api/v2.0/audit-logs', $context->url);

        do {
            $response = $httpClient->request('GET', $url, [
                'headers' => ['Authorization' => $this->buildAuthorizationHeader($context)],
                'query' => $query,
            ]);

            /** @var list<array{username: string, resource: string, resource_type: string, operation: string, op_time: string}> $data */
            $data = $response->toArray();

            yield $page => array_map(
                [AuditLogBuilder::class, 'buildFromApiResponseItem'],
                $data,
            );

            ++$page;
            $nextPath = $this->extractNextLink($response->getHeaders()['link'][0] ?? null);
            $url = null !== $nextPath ? sprintf('%s%s', $context->url, $nextPath) : null;
            $query = [];
            if (null !== $filterQuery) {
                $query['q'] = $filterQuery;
            }
        } while (null !== $url);
    }

    private function buildAuthorizationHeader(HarborContext $context): string
    {
        if (null !== $context->username) {
            return sprintf('Basic %s', base64_encode(sprintf('%s:%s', $context->username, $context->token)));
        }

        return sprintf('Bearer %s', $context->token);
    }

    private function extractNextLink(?string $linkHeader): ?string
    {
        if (null === $linkHeader) {
            return null;
        }

        if (1 === preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

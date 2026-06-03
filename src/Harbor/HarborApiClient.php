<?php

namespace Porthole\Harbor;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HarborApiClient
{
    private const int PAGE_SIZE = 100;
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.000\Z';

    public function __construct(
        private readonly string $harborUrl,
        private readonly string $token,
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $username = null,
    ) {
    }

    /**
     * @return AuditLogEntry[]
     */
    public function fetchAuditLogs(
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?callable $onPageFetched = null,
    ): array {
        $entries = [];
        $page = 0;
        $query = ['page' => 1, 'page_size' => self::PAGE_SIZE];

        if (null !== $from || null !== $to) {
            $query['q'] = sprintf('op_time=[%s~%s]', $from?->format(self::DATE_FORMAT) ?? '', $to?->format(self::DATE_FORMAT) ?? '');
        }

        $url = sprintf('%s/api/v2.0/audit-logs', $this->harborUrl);

        do {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => $this->buildAuthorizationHeader()],
                'query' => $query,
            ]);

            /** @var list<array{username: string, resource: string, resource_type: string, operation: string, op_time: string}> $data */
            $data = $response->toArray();

            foreach ($data as $item) {
                $entries[] = AuditLogBuilder::buildFromApiResponseItem($item);
            }

            ++$page;
            if (null !== $onPageFetched) {
                ($onPageFetched)($page);
            }

            $nextPath = $this->extractNextLink($response->getHeaders()['link'][0] ?? null);
            $url = null !== $nextPath ? sprintf('%s%s', $this->harborUrl, $nextPath) : null;
            $query = [];
        } while (null !== $url);

        return $entries;
    }

    private function buildAuthorizationHeader(): string
    {
        if (null !== $this->username) {
            return sprintf('Basic %s', base64_encode(sprintf('%s:%s', $this->username, $this->token)));
        }

        return sprintf('Bearer %s', $this->token);
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

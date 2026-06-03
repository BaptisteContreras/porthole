<?php

namespace Porthole\Report;

use Porthole\Harbor\AuditLogEntry;

final class ReportBuilder
{
    private const string OPERATION_PULL = 'pull';
    private const string KEY_SEPARATOR = "\0";
    private const int IMAGE_TAG_SPLIT_LIMIT = 2;
    private const int USERNAME_IMAGE_TAG_SPLIT_LIMIT = 3;

    /**
     * @param array<AuditLogEntry> $entries
     */
    public function buildImagesReport(array $entries): ImageReport
    {
        $counts = [];

        foreach ($entries as $entry) {
            if (self::OPERATION_PULL !== $entry->operation) {
                continue;
            }

            [$image, $tag] = $this->parseResource($entry->resource);
            $key = sprintf('%s%s%s', $image, self::KEY_SEPARATOR, $tag);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return new ImageReport(array_values(array_map(
            static function (string $key, int $count): ImageReportRow {
                [$image, $tag] = explode(self::KEY_SEPARATOR, $key, self::IMAGE_TAG_SPLIT_LIMIT);

                return new ImageReportRow(
                    image: $image,
                    tag: $tag,
                    pullCount: $count
                );
            },
            array_keys($counts),
            $counts,
        )));
    }

    /**
     * @param array<AuditLogEntry> $entries
     */
    public function buildUsersReport(array $entries): UserReport
    {
        $counts = [];

        foreach ($entries as $entry) {
            if (self::OPERATION_PULL !== $entry->operation) {
                continue;
            }

            [$image, $tag] = $this->parseResource($entry->resource);
            $key = sprintf(
                '%s%s%s%s%s',
                $entry->username,
                self::KEY_SEPARATOR,
                $image,
                self::KEY_SEPARATOR,
                $tag
            );
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return new UserReport(array_values(array_map(
            static function (string $key, int $count): UserReportRow {
                [$username, $image, $tag] = explode(
                    self::KEY_SEPARATOR,
                    $key,
                    self::USERNAME_IMAGE_TAG_SPLIT_LIMIT
                );

                return new UserReportRow(
                    username: $username,
                    image: $image,
                    tag: $tag,
                    pullCount: $count
                );
            },
            array_keys($counts),
            $counts,
        )));
    }

    /**
     * @return array{string, string}
     */
    private function parseResource(string $resource): array
    {
        $pos = strrpos($resource, ':');

        if (false === $pos) {
            return [$resource, ''];
        }

        return [substr($resource, 0, $pos), substr($resource, $pos + 1)];
    }
}

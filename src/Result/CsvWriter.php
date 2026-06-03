<?php

namespace Porthole\Result;

final class CsvWriter
{
    /**
     * @param list<string>           $header
     * @param list<list<int|string>> $rows
     */
    public function write(string $outputPath, array $header, array $rows): int
    {
        $handle = @fopen($outputPath, 'w');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot open file for writing: %s', $outputPath));
        }

        fputcsv($handle, $header, separator: ';', escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, separator: ';', escape: '');
        }

        fclose($handle);

        return count($rows);
    }
}

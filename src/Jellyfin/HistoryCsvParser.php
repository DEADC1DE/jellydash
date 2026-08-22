<?php

declare(strict_types=1);

namespace Mk\Framework\Jellyfin;

/** Converts the documented Jellydash History CSV format into database rows. */
final class HistoryCsvParser
{
    /** @var array<string, int> */
    private const MAX_LENGTHS = [
        'session_key' => 128,
        'user_id' => 64,
        'user_name' => 128,
        'item_id' => 64,
        'item_type' => 16,
        'series_name' => 255,
        'item_name' => 255,
        'season_ep' => 32,
        'library' => 64,
        'play_method' => 32,
        'play_method_detail' => 64,
        'client' => 64,
        'device' => 64,
        'source_video_codec' => 64,
        'source_audio_codec' => 64,
        'source_container' => 64,
        'target_video_codec' => 64,
        'target_audio_codec' => 64,
        'target_container' => 64,
    ];

    /** @return \Generator<int, array<string, mixed>> */
    public function iterateFile(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the Jellydash History CSV.');
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($header)) {
                throw new \InvalidArgumentException('The CSV does not contain a header row.');
            }
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
            }
            if ($header !== HistoryCsvExporter::COLUMNS) {
                throw new \InvalidArgumentException('This is not a supported Jellydash History CSV.');
            }

            $line = 1;
            $seen = [];
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($this->isEmptyRow($values)) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new \InvalidArgumentException('CSV row ' . $line . ' has the wrong number of columns.');
                }

                $record = array_combine($header, array_map(static fn (mixed $value): string => (string) $value, $values));
                $row = $this->mapRow($record, $line);
                $identity = (string) $row['session_key'] . "\0" . (string) $row['item_id'];
                if (isset($seen[$identity])) {
                    throw new \InvalidArgumentException('CSV row ' . $line . ' duplicates an earlier play.');
                }
                $seen[$identity] = true;

                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<mixed> $values */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $record
     * @return array<string, mixed>
     */
    private function mapRow(array $record, int $line): array
    {
        if (($record['jellydash_history_version'] ?? '') !== HistoryCsvExporter::FORMAT_VERSION) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' uses an unsupported History format version.');
        }

        $timezoneName = trim($record['jellydash_timezone'] ?? '');
        try {
            $sourceTimezone = new \DateTimeZone($timezoneName);
        } catch (\Exception) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid timezone.');
        }
        $targetTimezone = new \DateTimeZone(date_default_timezone_get());

        $row = [];
        foreach (self::MAX_LENGTHS as $column => $maxLength) {
            $value = $this->restoreCell($record[$column] ?? '');
            if (mb_strlen($value) > $maxLength) {
                throw new \InvalidArgumentException('CSV row ' . $line . ' has an overlong ' . $column . ' value.');
            }
            $row[$column] = $value === '' && !in_array($column, ['session_key', 'item_id', 'item_type', 'play_method'], true)
                ? null
                : $value;
        }

        if ($row['session_key'] === '' || $row['item_id'] === '') {
            throw new \InvalidArgumentException('CSV row ' . $line . ' is missing its play identity.');
        }

        foreach (['started_at', 'updated_at'] as $column) {
            $row[$column] = $this->date($record[$column] ?? '', $sourceTimezone, $targetTimezone, $line, $column, false);
        }
        foreach (['ended_at', 'library_resolved_at'] as $column) {
            $row[$column] = $this->date($record[$column] ?? '', $sourceTimezone, $targetTimezone, $line, $column, true);
        }

        foreach (['watched_sec', 'runtime_sec'] as $column) {
            $row[$column] = $this->unsignedInteger($record[$column] ?? '', $line, $column);
        }
        $row['is_video_direct'] = $this->nullableBoolean($record['is_video_direct'] ?? '', $line, 'is_video_direct');
        $row['is_audio_direct'] = $this->nullableBoolean($record['is_audio_direct'] ?? '', $line, 'is_audio_direct');
        $row['is_finished'] = $this->requiredBoolean($record['is_finished'] ?? '', $line, 'is_finished');
        $row['transcode_reasons'] = $this->reasons($record['transcode_reasons'] ?? '', $line);
        $row['notified'] = 1;

        return $row;
    }

    private function restoreCell(string $value): string
    {
        return str_starts_with($value, "'") ? substr($value, 1) : $value;
    }

    private function date(
        string $value,
        \DateTimeZone $source,
        \DateTimeZone $target,
        int $line,
        string $column,
        bool $nullable,
    ): ?string {
        $value = $this->restoreCell(trim($value));
        if ($value === '' && $nullable) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $source);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $value
        ) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid ' . $column . ' value.');
        }

        return $date->setTimezone($target)->format('Y-m-d H:i:s');
    }

    private function unsignedInteger(string $value, int $line, string $column): int
    {
        $value = $this->restoreCell(trim($value));
        if (preg_match('/^\d+$/', $value) !== 1 || (float) $value > 2147483647) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid ' . $column . ' value.');
        }

        return (int) $value;
    }

    private function nullableBoolean(string $value, int $line, string $column): ?int
    {
        $value = $this->restoreCell(trim($value));
        if ($value === '') {
            return null;
        }

        return $this->requiredBoolean($value, $line, $column);
    }

    private function requiredBoolean(string $value, int $line, string $column): int
    {
        $value = $this->restoreCell(trim($value));
        if (!in_array($value, ['0', '1'], true)) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has an invalid ' . $column . ' value.');
        }

        return (int) $value;
    }

    private function reasons(string $value, int $line): ?string
    {
        $value = $this->restoreCell($value);
        if ($value === '') {
            return null;
        }

        try {
            $reasons = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has invalid transcode reasons.');
        }
        if (!is_array($reasons) || !array_is_list($reasons)) {
            throw new \InvalidArgumentException('CSV row ' . $line . ' has invalid transcode reasons.');
        }
        foreach ($reasons as $reason) {
            if (!is_string($reason)) {
                throw new \InvalidArgumentException('CSV row ' . $line . ' has invalid transcode reasons.');
            }
        }

        return json_encode($reasons, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

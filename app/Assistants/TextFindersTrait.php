<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Utility functions for pdf data extraction
 */
trait TextFindersTrait
{
    protected function findLine(string $textToFind, int $startFrom = 0, int $endAt = 0, bool $exactMatch = false): ?int
    {
        if ($textToFind && ($lcount = count($this->lines))) {
            if (! $endAt) {
                $endAt = $lcount;
            }
            for ($i = $startFrom; $i < $endAt; $i++) {
                if (! $exactMatch && stripos($this->lines[$i], $textToFind) !== false) {
                    return $i;
                } elseif ($exactMatch && ($this->lines[$i] === $textToFind)) {
                    return $i;
                }
            }
        }

        return null;
    }

    protected function findAllLines(string $textToFind, int $startFrom = 0, bool $exactMatch = false): array
    {
        $indexes = [];
        if ($textToFind && ($lcount = count($this->lines))) {
            for ($i = $startFrom; $i < $lcount; $i++) {
                if (! $exactMatch && stripos($this->lines[$i], $textToFind) !== false) {
                    $indexes[] = $i;
                } elseif ($exactMatch && ($this->lines[$i] === $textToFind)) {
                    $indexes[] = $i;
                }
            }
        }

        return $indexes;
    }

    protected function findDate(string $pattern, int $startFrom = 0): ?int
    {
        if ($pattern && ($lcount = count($this->lines))) {
            for ($i = $startFrom; $i < $lcount; $i++) {

                if (preg_match($pattern, $this->lines[$i]) != false) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return array [string $postal_code, string $city]
     */
    protected function extractPostalAndCity(string $address): array
    {
        // remove commas and extra spaces
        $address = preg_replace('/\s+/', ' ', str_replace(',', '', trim($address)));

        // match alphanumeric chunks (with both letters & numbers)
        if (preg_match_all('/\b(?:(?=\S*[A-Z])(?=\S*\d)\S+|\d{4,6})\b/i', $address, $matches)) {
            // join them if there are 2 parts (like "SS5" + "4JL")
            $postal_code = strtoupper(implode(' ', $matches[0]));
            // remove postal from address → remaining part is city
            $city = trim(str_ireplace($postal_code, '', $address));
        } else {
            $postal_code = '';
            $city = strtoupper($address);
        }

        return [$postal_code, $city];
    }

    protected function parseHour(string $hourString): int
    {
        return (int) (Str::endsWith($hourString, '0')
            ? rtrim($hourString, '0')
            : Carbon::parse($hourString)->format('H'));
    }
}

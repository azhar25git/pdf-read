<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

class ZieglerPdfAssistant extends PdfClient
{
    protected array $lines;

    protected int $index = 0;

    protected string $datePattern = '/^(0[1-9]|[1-2][0-9]|3[0-1])\/(0[1-9]|1[0-2])\/[0-9]{4}$/';

    protected string $dateFormat = 'd/m/Y';

    protected array $roadNames = [
        'road', 'way', 'chem', 'lane', 'rue', 'street',
    ];

    public static function validateFormat(array $lines)
    {
        return Str::startsWith($lines[0], 'ZIEGLER UK LTD')
            && $lines[1] == 'LONDON GATEWAY LOGISTICS PARK'
            && $lines[2] == 'NORTH 4, NORTH SEA CROSSING'
            && $lines[3] == 'STANFORD LE HOPE';
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $this->lines = array_values(array_filter(array_map('trim', $lines)));

        if (! static::validateFormat($lines)) {
            throw new \Exception('Invalid Ziegler PDF');
        }

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

        $datetime_from_line = $this->findLine('Date', exactMatch: true);
        $datetime_from = Carbon::createFromFormat($this->dateFormat, $this->lines[$datetime_from_line + 1])->startOfDay()->toISOString();

        $customer = [
            'side' => 'sender',
            'details' => [
                'company' => 'Ziegler UK Ltd',
                'street_address' => 'NORTH 4, NORTH SEA CROSSING',
                'city' => 'Stanford Le Hope',
                'postal_code' => 'SS17 9FJ',
                'country' => 'GB',
                'time_interval' => [
                    'datetime_from' => $datetime_from,
                ],
            ],
        ];

        [$loading_locations, $cargos] = $this->extractLoadingLocations($this->findAllLines('Collection', exactMatch: true));

        $clearance_locations = $this->extractDestinationLocations($this->findAllLines('Clearance', exactMatch: true));

        foreach ($clearance_locations as &$loc) {
            $loc['company_address']['comment'] = 'clearance';
        }

        $delivery_locations = $this->extractDestinationLocations($this->findAllLines('Delivery', exactMatch: true));

        foreach ($delivery_locations as &$loc) {
            $loc['company_address']['comment'] = 'delivery';
        }
        $destination_locations = array_merge($clearance_locations, $delivery_locations);

        $order_reference_line = $this->findLine('Ziegler Ref');
        $order_reference = $this->lines[$order_reference_line + 1];

        $telephone_line = $this->findLine('Telephone: ');
        $customer_number = ltrim($this->lines[$telephone_line], 'Telephone: ');

        $freight_price_line = $this->findLine('Rate');
        $freight_price = uncomma(preg_replace('/[^0-9.]/', '', $this->lines[$freight_price_line + 1]));
        $freight_currency = 'EUR';

        $data = compact(
            'attachment_filenames',
            'customer',
            'loading_locations',
            'destination_locations',
            'cargos',
            'order_reference',
            'customer_number',
            'freight_price',
            'freight_currency'
        );

        $this->createOrder($data);
    }

    protected function extractLoadingLocations(array $loading_locations_lines): array
    {
        $locationResults = [];
        $cargoResults = [];

        foreach ($loading_locations_lines as $i => $linesIndex) {
            $date_line = $this->findDate($this->datePattern, $linesIndex);

            foreach ($this->roadNames as $name) {
                if ($street_address_line = $this->findLine($name, $linesIndex + 1, $linesIndex + 9, exactMatch: false)) {
                    break;
                }
            }

            $city_postcode_line = (
                ($this->findLine('Collection', $linesIndex + 2, exactMatch: true)
                ?? $this->findLine('Clearance', $linesIndex + 2, exactMatch: true)
                ?? $this->findLine('Delivery', $linesIndex + 2, exactMatch: true)) - 1
            );
            [$postal, $city] = $this->extractPostalAndCity($this->lines[$city_postcode_line]);

            $locationResults[$i]['company_address'] = [
                'company' => $this->lines[$linesIndex + 1],
                'street_address' => $this->lines[$street_address_line] ?? $this->lines[$linesIndex + 3] ?? '',
                'city' => $city,
                'postal_code' => $postal,
                'country' => 'GB',
            ];

            $timerange_line = $this->findLine('-', $linesIndex);
            $times = explode('-', $this->lines[$timerange_line]);
            if (count($times) < 2) {
                $times = explode('TimeTo', Str::replace([':', ' '], '', $this->lines[$timerange_line]));
            }
            $carbonDate = Carbon::createFromFormat($this->dateFormat, $this->lines[$date_line]);

            $locationResults[$i]['time'] = [
                'datetime_from' => $carbonDate->startOfDay()
                    ->addHours($this->parseHour($times[0]))
                    ->toISOString(),
                'datetime_to' => $carbonDate->startOfDay()->addHours($this->parseHour($times[1]))->toISOString(),
            ];

            $cargo = [];
            $cargo_line = $this->findLine('pallets', $linesIndex + 1, $linesIndex + 9);  // max cargo lines 9

            if (! $cargo_line) {
                $cargo['package_count'] = 1;
                $cargo_line = $this->findLine('PICK UP', $linesIndex + 1, $linesIndex + 9);
                $cargo['package_type'] = 'other';
            } else {
                $cargo['package_count'] = uncomma(explode(' ', $this->lines[$cargo_line])[0]);
                $cargo['package_type'] = 'pallet';
            }

            $cargoResults[$i] = $cargo;
        }

        return [$locationResults, $cargoResults];
    }

    protected function extractDestinationLocations(array $destination_locations_lines): array
    {
        $locationResults = [];

        foreach ($destination_locations_lines as $i => $linesIndex) {

            $date_line = $this->findDate($this->datePattern, $linesIndex);

            foreach ($this->roadNames as $name) {
                if ($street_address_line = $this->findLine($name, $linesIndex + 1, $linesIndex + 9, exactMatch: false)) {
                    break;
                }
            }

            $city_postcode_line = ($this->findLine('Delivery', $linesIndex + 1, exactMatch: true) ?? $this->findLine('- Payment', $linesIndex + 1, exactMatch: false)) - 1;

            $city_postcode_line = (stripos($this->lines[$city_postcode_line], 'pallets') !== false) ? $city_postcode_line - 1 : $city_postcode_line;

            [$postal, $city] = $this->extractPostalAndCity($this->lines[$city_postcode_line]);

            $locationResults[$i]['company_address'] = [
                'company' => $this->lines[$linesIndex + 1],
                'street_address' => $this->lines[$street_address_line],
                'city' => Str::replace(',', '', $city),
                'postal_code' => Str::replace(',', '', $postal),
            ];

            $timerange_line = $this->findLine('Time To:', $linesIndex);

            if ($timerange_line) {
                $carbonDate = Carbon::createFromFormat($this->dateFormat, $this->lines[$date_line]);

                $times = explode('-', $this->lines[$timerange_line]);
                if (count($times) < 2) {
                    $times = explode('TimeTo', Str::replace([':', ' '], '', $this->lines[$timerange_line]));
                }

                $locationResults[$i]['time'] = [
                    'datetime_from' => $carbonDate->startOfDay()->addHours($this->parseHour($times[0]))->toISOString(),
                    'datetime_to' => $carbonDate->startOfDay()->addHours($this->parseHour($times[1]))->toISOString(),
                ];
            }
        }

        return $locationResults;
    }

    // -------------------------------
    // Utility functions
    // -------------------------------

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

    protected function parseHour(string $hourString): int
    {
        return (int) (Str::endsWith($hourString, '0')
            ? rtrim($hourString, '0')
            : Carbon::parse($hourString)->format('H'));
    }

    protected function extractPostalAndCity(string $address): array
    {
        // remove commas and extra spaces
        $address = preg_replace('/\s+/', ' ', str_replace(',', '', trim($address)));

        // match alphanumeric chunks (with both letters & numbers)
        if (preg_match_all('/\b(?:(?=\S*[A-Z])(?=\S*\d)\S+|\d{4,6})\b/i', $address, $matches)) {
            // join them if there are 2 parts (like "SS5" + "4JL")
            $postal = strtoupper(implode(' ', $matches[0]));
            // remove postal from address → remaining part is city
            $city = trim(str_ireplace($postal, '', $address));
        } else {
            $postal = '';
            $city = strtoupper($address);
        }

        return [$postal, $city];
    }
}

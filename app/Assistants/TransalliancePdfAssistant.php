<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TransalliancePdfAssistant extends PdfClient
{
    protected array $lines;

    protected int $index = 0;

    protected string $datePattern = '/^(0[1-9]|[1-2][0-9]|3[0-1])\/(0[1-9]|1[0-2])\/[0-9]{2}$/';

    protected string $timePattern = '/^(2[0-3]|[0-1]?[0-9]):([0-5]?[0-9]|[0-5][0-9]):([0-5]?[0-9]|[0-5][0-9])$/';

    protected string $timeRangePattern = '/^(?:(?:[0-1]?[0-9]|2[0-3])h[0-5][0-9])\s*-\s*(?:(?:[0-1]?[0-9]|2[0-3])h[0-5][0-9])$/';

    protected array $roadNames = [
        'road', 'way', 'chem.', 'lane', 'rue', 'street', 'rugiu', 'suite', 'blvd', 'RD',
    ];

    public static function validateFormat(array $lines)
    {
        return Str::startsWith($lines[0], 'Date/Time')
            && array_find_key($lines, fn ($line) => Str::startsWith(trim($line), 'TRANSALLIANCE TS LTD'));
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $this->lines = array_values(array_filter(array_map('trim', $lines)));

        if (! static::validateFormat($lines)) {
            throw new \Exception('Invalid Ziegler PDF');
        }

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

        $date_from_line = $this->findLine('Date/Time :', exactMatch: true);
        $time_from_line = $this->findDate($this->timePattern);
        $datetime_from = Carbon::createFromFormat('d/m/Y', $this->lines[$date_from_line + 1])->startOfDay();
        $datetime_from = $datetime_from->setTimeFromTimeString($time_from_line)->toISOString();

        $customer = [
            'side' => 'sender',
            'details' => [
                'company' => 'TRANSALLIANCE TS LTD',
                'vat_code' => 'GB712061386',
                'email' => 'teresa.hopkins@transalliance.eu',
                'contact_person' => 'TERESA HOPKINS',
                'street_address' => 'SUITE 8/9 FARADAY COURT',
                'city' => 'BURTON UPON TRENT',
                'postal_code' => 'GB-DE14 2WX',
                'country' => 'GB',
                'time_interval' => [
                    'datetime_from' => $datetime_from,
                ],
            ],
        ];

        $loading_locations = $this->extractLocations($this->findAllLines('Loading', exactMatch: true));
        $cargos = $this->extractCargo($this->findAllLines('Loading', exactMatch: true));

        $destination_locations = $this->extractLocations($this->findAllLines('Delivery', exactMatch: true));

        $order_reference_line = $this->findLine('REF.:');
        $order_reference = trim(Str::replace('REF.:', '', $this->lines[$order_reference_line]));

        $freight_price_line = $this->findLine('SHIPPING PRICE');
        $freight_price = uncomma($this->lines[$freight_price_line + 1]);
        $freight_currency = 'EUR';

        $telephone_line = $this->findLine('Tel');
        $customer_number = ltrim($this->lines[$telephone_line + 1], ': ');

        $data = compact(
            'attachment_filenames',
            'customer',
            'loading_locations',
            'destination_locations',
            'cargos',
            'order_reference',
            'freight_price',
            'freight_currency',
            'customer_number'
        );

        $this->createOrder($data);
    }

    protected function extractCargo(array $loading_locations_lines): array
    {
        $cargoResults = [];

        foreach ($loading_locations_lines as $i => $linesIndex) {
            $cargo = [];
            $cargo_line = $this->findLine('OT :', $linesIndex + 1);
            if ($this->lines[$cargo_line + 2] == 'OT :') {
                $cargo_line = $cargo_line + 2;
            }

            $cargo['ldm'] = uncomma(Str::replace(' ', '', $this->lines[$cargo_line + 2]));
            $cargo['weight'] = uncomma(Str::replace(' ', '', $this->lines[$cargo_line + 3]));
            $cargo['package_type'] = 'other';
            $cargo['package_count'] = 1;

            $cargoResults[$i] = $cargo;
        }

        return $cargoResults;
    }

    protected function extractLocations(array $loading_locations_lines): array
    {
        $locationResults = [];

        foreach ($loading_locations_lines as $i => $linesIndex) {
            $address_line = $this->findLine('ON:', startFrom: $linesIndex, exactMatch: true);
            $contact_line = $this->findLine('Contact:', startFrom: $linesIndex, exactMatch: true);

            $company = $this->lines[$address_line + 1];

            foreach ($this->roadNames as $name) {
                if ($street_address_line = $this->findLine($name, $address_line + 2, $contact_line, exactMatch: false)) {
                    break;
                }
            }

            $street_address = $this->lines[$street_address_line] ?? $this->lines[$contact_line - 2];
            [$postal_code, $city] = $this->extractPostalAndCity($this->lines[$contact_line - 1]);

            $country_name_arr = explode(' ', $company);
            $country_name = ucwords(strtolower($country_name_arr[max(count($country_name_arr) - 1, 0)] ?? ''));
            $country = GeonamesCountry::getIso($country_name) ?? null;

            $locationResults[$i]['company_address'] = compact(
                'company',
                'street_address',
                'city',
                'postal_code'
            );

            if ($country) {
                $locationResults[$i]['company_address']['country'] = $country;
            }

            $date_line = $this->findDate($this->datePattern, $linesIndex + 1);

            $carbonDate = Carbon::createFromFormat('d/m/y', $this->lines[$date_line]);
            $datetime_from = $carbonDate->startOfDay()->toISOString();
            $datetime_to = null;

            $timerange_line = $this->findDate($this->timeRangePattern, $linesIndex, $linesIndex + 13);

            if ($timerange_line) {
                $times = explode('-', Str::replace(' ', '', $this->lines[$timerange_line]));

                $datetime_from = $carbonDate->startOfDay()
                    ->addHours($this->parseHour($times[0]))
                    ->toISOString();

                $datetime_to = $carbonDate->startOfDay()
                    ->addHours($this->parseHour($times[1]))
                    ->toISOString();
            }

            $locationResults[$i]['time']['datetime_from'] = $datetime_from;

            if ($datetime_to) {
                $locationResults[$i]['time']['datetime_to'] = $datetime_to;
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
        $hourString = (int) (Str::endsWith($hourString, '0')
            ? rtrim($hourString, '0')
            : Carbon::parse($hourString)->format('H'));

        return Str::endsWith($hourString, 'h') ? Str::replace('h', '', $hourString) : $hourString;
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
            $city = trim(trim(str_ireplace($postal, '', $address), '-'));
        } else {
            $postal = '';
            $city = strtoupper($address);
        }

        return [$postal, $city];
    }
}

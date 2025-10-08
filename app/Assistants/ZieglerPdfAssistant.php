<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ZieglerPdfAssistant extends PdfClient
{
    protected array $lines;
    protected int $index = 0;

    public static function validateFormat(array $lines) {
        return Str::startsWith($lines[0], 'ZIEGLER UK LTD')
            && $lines[1] == "LONDON GATEWAY LOGISTICS PARK"
            && $lines[2] == "NORTH 4, NORTH SEA CROSSING"
            && $lines[3] == "STANFORD LE HOPE";
    }

    public function processLines(array $lines, ?string $attachment_filename = null) {
        $this->lines = array_values(array_filter(array_map('trim', $lines)));

        if (!static::validateFormat($lines)) {
            throw new \Exception("Invalid Ziegler PDF");
        }

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

        $datetime_from_line = $this->findLine('Date');
        $datetime_from = $this->parseDate($this->lines[$datetime_from_line + 1])->toISOString();

        $customer = [
            'side' => 'sender',
            'details' => [
                'company' => 'Ziegler UK Ltd',
                'street_address' => 'NORTH 4, NORTH SEA CROSSING',
                'city' => 'Stanford Le Hope',
                'postal_code' => 'SS17 9FJ',
                'country' => 'GB',
                'time_interval' => [
                    'datetime_from' => $datetime_from
                ]
            ],
        ];

        $loading_locations = $this->extractLoadingLocations($this->findAllLines('Collection'));
        $destination_locations = $this->extractDestinationLocations($this->findAllLines('Delivery'));
        $customer_number = ltrim($this->lines[7], 'Telephone: ');

        $data = [
            'attachment_filenames' => $attachment_filenames,
            'customer' => $customer,
            'loading_locations' => $loading_locations,
            'destination_locations' => $destination_locations,
            // 'cargos' => $this->extractCargos(),
            // 'order_reference' => $this->extractReference(),
            'customer_number' => $customer_number,
        ];
        var_export($data, false);
        // $this->createOrder($data);
    }

    protected function extractLoadingLocations(array $loading_locations_lines): array
    {
        $locations = [];

        foreach($loading_locations_lines as $i => $val) {
            $timerange_line = $this->findLine('-', $val);
            
            $times = explode('-', $this->lines[$timerange_line]);
            $date_line = $this->findLine('/', $val);
            $carbonDate = $this->parseDate($this->lines[$date_line]);
            $company_line = max(($this->findLine('Collection', $val+1) ?? $this->findLine('Clearance', $val+1)) - 1, 0);

            $loading_locations[$i] = [
                'company_address' => $this->lines[$company_line],
                'time' => [
                    'datetime_from' => $carbonDate->addHours($times[0])->toISOString(),
                    'datetime_to' => $carbonDate->addHours($times[1])->toISOString()
                ]
            ];
        }

        return $locations;
    }

    protected function extractDestinationLocations(array $destination_locations_lines): array
    {
        $locations = [];
        var_dump($destination_locations_lines);
        foreach($destination_locations_lines as $i => $val) {
            
            
            $date_line = $this->findLine('/', $val);
            $carbonDate = $this->parseDate($this->lines[$date_line]);
            $company_line = max(($this->findLine('Collection', $val+1) ?? $this->findLine('Clearance', $val+1)) - 1, 0);

            $loading_locations[$i]['company_address'] = $this->lines[$company_line];

            $timerange_line = $this->findLine('Time To:', $val);
            if($timerange_line) {
                $times = explode('-', $this->lines[$timerange_line]);
                $loading_locations[$i]['time'] = [
                    'datetime_from' => $carbonDate->addHours($times[0])->toISOString(),
                    'datetime_to' => $carbonDate->addHours($times[1])->toISOString()
                ];
            }
        }
        
        return $locations;
    }

    // -------------------------------
    // Utility functions
    // -------------------------------

    protected function findLine(string $text, int $start = 0): ?int
    {
        if($text) {
            for($i = $start; $i <= count($this->lines); $i++) {
                if (stripos($this->lines[$i], $text) !== false) {
                    return $i;
                }
            }
        }
        return null;
    }

    protected function findAllLines(string $text, int $start = 0): array
    {
        $indexes = [];
        if($text) {
            for($i = $start; $i <= count($this->lines); $i++) {
                if (stripos($this->lines[$i], $text) !== false) {
                    $indexes[] = $i;
                }
            }
        }   
        return $indexes;
    }

    protected function parseDate(string $dateString): Carbon {
        return Carbon::createFromFormat('d/m/Y', $dateString);
    }

}

<?php

namespace App\Services\Coverage;

use Illuminate\Validation\ValidationException;
use ZipArchive;

class CoverageImport
{
    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $contents, string $name): array
    {
        $lower = strtolower($name);

        if (str_ends_with($lower, '.csv') || str_ends_with($lower, '.txt')) {
            return $this->csv($contents);
        }

        if (str_ends_with($lower, '.geojson') || str_ends_with($lower, '.json')) {
            return $this->geojson($contents);
        }

        if (str_ends_with($lower, '.kml')) {
            return $this->kml($contents);
        }

        if (str_ends_with($lower, '.kmz')) {
            return $this->kmz($contents);
        }

        throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function csv(string $contents): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($contents)) ?: [];
        $header = array_map(fn ($cell) => strtolower(trim((string) $cell)), str_getcsv((string) array_shift($lines)));
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $row = [];

            foreach ($header as $index => $key) {
                $row[$key] = $cells[$index] ?? null;
            }

            if (! is_numeric($row['lat'] ?? null) || ! is_numeric($row['lng'] ?? null) || trim((string) ($row['name'] ?? '')) === '') {
                throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
            }

            $rows[] = [
                'name' => trim((string) $row['name']),
                'kind' => $this->kind((string) ($row['type'] ?? 'fat')),
                'latitude' => (float) $row['lat'],
                'longitude' => (float) $row['lng'],
                'capacity_total' => (int) ($row['capacity'] ?? 0),
                'service_type' => (string) ($row['service'] ?? 'ftth'),
                'source' => 'csv',
                'status' => 'live',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function geojson(string $contents): array
    {
        $json = json_decode($contents, true);

        if (! is_array($json) || ! isset($json['features']) || ! is_array($json['features'])) {
            throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
        }

        $rows = [];

        foreach ($json['features'] as $feature) {
            $geometry = $feature['geometry'] ?? null;
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

            if (! is_array($geometry) || ! isset($geometry['type'], $geometry['coordinates'])) {
                throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
            }

            if ($geometry['type'] === 'Point') {
                $rows[] = [
                    'name' => (string) ($props['name'] ?? 'Point'),
                    'kind' => $this->kind((string) ($props['type'] ?? 'fat')),
                    'longitude' => (float) $geometry['coordinates'][0],
                    'latitude' => (float) $geometry['coordinates'][1],
                    'capacity_total' => (int) ($props['capacity'] ?? 0),
                    'service_type' => (string) ($props['service'] ?? 'ftth'),
                    'source' => 'geojson',
                    'status' => 'live',
                ];
            }

            if ($geometry['type'] === 'Polygon') {
                $ring = [];

                foreach ($geometry['coordinates'][0] as $pair) {
                    $ring[] = [(float) $pair[1], (float) $pair[0]];
                }

                $rows[] = [
                    'name' => (string) ($props['name'] ?? 'Area'),
                    'kind' => 'building',
                    'latitude' => $ring[0][0],
                    'longitude' => $ring[0][1],
                    'polygon' => $ring,
                    'capacity_total' => (int) ($props['capacity'] ?? 1),
                    'service_type' => (string) ($props['service'] ?? 'ftth'),
                    'source' => 'geojson',
                    'status' => 'live',
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kml(string $contents): array
    {
        $xml = @simplexml_load_string($contents);

        if ($xml === false) {
            throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
        }

        $xml->registerXPathNamespace('k', 'http://www.opengis.net/kml/2.2');
        $marks = $xml->xpath('//k:Placemark') ?: $xml->xpath('//Placemark') ?: [];
        $rows = [];

        foreach ($marks as $mark) {
            $name = trim((string) ($mark->name ?? 'Place'));
            $coords = trim((string) ($mark->Point->coordinates ?? ''));

            if ($coords === '') {
                $coords = trim((string) ($mark->Polygon->outerBoundaryIs->LinearRing->coordinates ?? ''));
            }

            if ($coords === '') {
                throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
            }

            $pairs = preg_split('/\s+/', trim($coords)) ?: [];
            $ring = [];

            foreach ($pairs as $pair) {
                $bits = explode(',', $pair);

                if (count($bits) < 2 || ! is_numeric($bits[0]) || ! is_numeric($bits[1])) {
                    throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
                }

                $ring[] = [(float) $bits[1], (float) $bits[0]];
            }

            $rows[] = [
                'name' => $name,
                'kind' => count($ring) > 1 ? 'building' : 'fat',
                'latitude' => $ring[0][0],
                'longitude' => $ring[0][1],
                'polygon' => count($ring) > 1 ? $ring : null,
                'capacity_total' => 8,
                'service_type' => 'ftth',
                'source' => 'kmz',
                'status' => 'live',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kmz(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kmz');

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
        }

        file_put_contents($path, $contents);
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            @unlink($path);
            throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
        }

        $kml = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if (is_string($entry) && str_ends_with(strtolower($entry), '.kml')) {
                $kml = (string) $zip->getFromIndex($i);
                break;
            }
        }

        $zip->close();
        @unlink($path);

        if ($kml === '') {
            throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
        }

        return $this->kml($kml);
    }

    private function kind(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['building', 'polygon', 'fat', 'olt', 'tower', 'sector'], true) ? $type : 'fat';
    }
}

<?php

namespace App\Services\Coverage;

class Geo
{
    public function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    /**
     * @return array{0: float, 1: float}
     */
    public function destination(float $lat, float $lng, float $meters, float $bearing): array
    {
        $earth = 6371000.0;
        $bearingRad = deg2rad($bearing);
        $latRad = deg2rad($lat);
        $lngRad = deg2rad($lng);
        $angle = $meters / $earth;
        $lat2 = asin(sin($latRad) * cos($angle) + cos($latRad) * sin($angle) * cos($bearingRad));
        $lng2 = $lngRad + atan2(
            sin($bearingRad) * sin($angle) * cos($latRad),
            cos($angle) - sin($latRad) * sin($lat2),
        );

        return [rad2deg($lat2), rad2deg($lng2)];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    public function contains(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $count = count($ring);

        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = (float) $ring[$i][0];
            $lngI = (float) $ring[$i][1];
            $latJ = (float) $ring[$j][0];
            $lngJ = (float) $ring[$j][1];
            $crosses = ($latI > $lat) !== ($latJ > $lat);
            $edge = ($lngJ - $lngI) * ($lat - $latI) / (($latJ - $latI) ?: 1e-12) + $lngI;

            if ($crosses && $lng < $edge) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public function sector(float $lat, float $lng, float $azimuth, float $beam, float $range): array
    {
        $steps = 12;
        $half = $beam / 2;
        $ring = [[$lat, $lng]];

        for ($step = 0; $step <= $steps; $step++) {
            $bearing = $azimuth - $half + ($beam * $step / $steps);
            $ring[] = $this->destination($lat, $lng, $range, $bearing);
        }

        $ring[] = [$lat, $lng];

        return $ring;
    }

    /**
     * @param  list<array{id: int|string, lat: float, lng: float}>  $points
     * @return list<int|string>
     */
    public function nearestNeighbour(array $points, float $startLat, float $startLng): array
    {
        $remaining = array_values($points);
        $ordered = [];
        $lat = $startLat;
        $lng = $startLng;

        while ($remaining !== []) {
            $best = 0;
            $bestMeters = null;

            foreach ($remaining as $index => $point) {
                $meters = $this->meters($lat, $lng, (float) $point['lat'], (float) $point['lng']);

                if ($bestMeters === null || $meters < $bestMeters || ($meters === $bestMeters && (string) $point['id'] < (string) $remaining[$best]['id'])) {
                    $best = $index;
                    $bestMeters = $meters;
                }
            }

            $next = $remaining[$best];
            array_splice($remaining, $best, 1);
            $ordered[] = $next['id'];
            $lat = (float) $next['lat'];
            $lng = (float) $next['lng'];
        }

        return $ordered;
    }
}

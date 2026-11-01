<?php

namespace App\Services\Coverage;

class FiberDesk
{
    public function __construct(private CoverageImport $imports) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $contents): array
    {
        if (! config('fibes.coverage.fiberdesk')) {
            return [];
        }

        return $this->imports->parse($contents, 'fiberdesk.geojson');
    }
}

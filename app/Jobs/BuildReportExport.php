<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\ReportExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildReportExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $exportId, public string $from, public string $to) {}

    public function handle(ReportExporter $exporter): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if ($export instanceof ReportExport) {
            $exporter->build($export, $this->from, $this->to);
        }
    }
}

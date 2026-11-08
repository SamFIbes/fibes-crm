<?php

namespace App\Services\Reports;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\CommercialSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use ZipArchive;

class ReportExporter
{
    public function __construct(
        private ReportDesk $reports,
        private CommercialSettings $commercial,
        private AuditLogger $audit,
    ) {}

    public function build(ReportExport $export, string $from, string $to, ?Request $request = null): ReportExport
    {
        $user = User::query()->findOrFail($export->user_id);
        $rows = $this->reports->rows((string) $export->report_key, $user, $from, $to);
        $value = $this->reports->value((string) $export->report_key, $user, $from, $to);
        $lines = array_merge([['label' => 'value', 'href' => $value]], $rows);
        $body = match ($export->format) {
            'xlsx' => $this->xlsx($lines),
            'pdf' => $this->pdf((string) $export->report_key, $lines),
            default => $this->csv($lines),
        };
        $path = 'reports/'.$export->ulid.'.'.$export->format;
        Storage::disk('local')->put($path, $body);
        $hours = max(1, (int) $this->commercial->get('report_link_hours'));
        $export->path = $path;
        $export->rows = count($rows);
        $export->status = 'ready';
        $export->expires_at = now()->addHours($hours);
        $export->save();
        $this->audit->record('report.exported', $user, [
            'report' => $export->report_key,
            'format' => $export->format,
            'rows' => $export->rows,
        ], $request);

        return $export;
    }

    public function link(ReportExport $export): string
    {
        return URL::temporarySignedRoute('reports.download', $export->expires_at ?? now()->addDay(), ['export' => $export->id]);
    }

    /**
     * @param  list<array{label: string, href: string}>  $lines
     */
    private function csv(array $lines): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['label', 'href']);

        foreach ($lines as $line) {
            fputcsv($handle, [$line['label'], $line['href']]);
        }

        rewind($handle);
        $body = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $body;
    }

    /**
     * @param  list<array{label: string, href: string}>  $lines
     */
    private function xlsx(array $lines): string
    {
        $rows = '';
        $index = 1;

        foreach (array_merge([['label' => 'label', 'href' => 'href']], $lines) as $line) {
            $rows .= '<row r="'.$index.'"><c t="inlineStr"><is><t>'.$this->xml($line['label']).'</t></is></c><c t="inlineStr"><is><t>'.$this->xml($line['href']).'</t></is></c></row>';
            $index++;
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>';
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $body = (string) file_get_contents($tmp);
        unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use

        return $body;
    }

    /**
     * @param  list<array{label: string, href: string}>  $lines
     */
    private function pdf(string $title, array $lines): string
    {
        $text = ['BT /F1 12 Tf 48 760 Td'];
        $first = true;

        foreach (array_merge([$title], array_map(fn ($line) => $line['label'].' '.$line['href'], $lines)) as $line) {
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '', $line) ?? '');

            if (! $first) {
                $text[] = '0 -16 Td';
            }

            $text[] = '('.$safe.') Tj';
            $first = false;
        }

        $text[] = 'ET';
        $stream = implode("\n", $text);
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
            '4 0 obj << /Length '.strlen($stream)." >> stream\n{$stream}\nendstream endobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }

        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1);
    }
}

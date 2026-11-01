<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Imports\ImportDesk;
use App\Services\Imports\TableExport;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportController extends Controller
{
    public function index(Request $request, AccessGate $access): Response
    {
        $this->actor($request, $access);

        return Inertia::render('Imports/Index', [
            'batches' => DB::table('import_batches')->latest('id')->limit(12)->get(),
            'types' => array_keys(ImportDesk::REQUIRED),
        ]);
    }

    public function preview(Request $request, AccessGate $access, ImportDesk $imports): RedirectResponse
    {
        $this->actor($request, $access);
        $data = $this->payload($request);
        $counts = $imports->preview($data['type'], $data['csv'], $data['mapping']);

        return back()->with('status', $this->summary($counts));
    }

    public function apply(Request $request, AccessGate $access, ImportDesk $imports): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $this->payload($request);
        $dry = $imports->preview($data['type'], $data['csv'], $data['mapping']);
        $batch = $imports->apply($data['type'], $data['csv'], $data['mapping'], $actor, $request);
        $applied = json_decode((string) $batch->counts, true);

        return back()->with('status', $this->summary($dry).' / '.$this->summary($applied));
    }

    public function undo(Request $request, int $batch, AccessGate $access, ImportDesk $imports): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $imports->undo($batch, $actor, $request);

        return back()->with('status', __('ui.imports.undone'));
    }

    public function krypton(Request $request, AccessGate $access, ImportDesk $imports): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate(['csv' => ['required', 'string'], 'apply' => ['nullable', 'boolean']]);
        $preview = $imports->kryptonPreview($data['csv']);

        if ($request->boolean('apply')) {
            $imports->kryptonApply($data['csv'], $actor, $request);
        }

        $line = 'new '.$preview['new'].', changed '.$preview['changed'].', missing '.$preview['missing'];
        $unlinked = collect($preview['worklist'])->take(5)->map(fn (array $row) => trim($row['name'].' '.$row['phone'].($row['suggestion'] ? ' → '.$row['suggestion'] : '')))->implode(', ');

        return back()->with('status', $unlinked === '' ? $line : $line.' / '.$unlinked);
    }

    public function export(Request $request, AccessGate $access, TableExport $exports): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        abort_unless($access->allows($actor, 'reports.export') || $access->allows($actor, 'customers.view'), 403);
        $job = $exports->customers($actor, $request);

        if ($job->status !== 'ready') {
            return back()->with('status', __('ui.imports.queued'));
        }

        return back()->with('link', url('/exports/'.$job->token));
    }

    public function download(string $token, TableExport $exports): BinaryFileResponse
    {
        return response()->download($exports->download($token));
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * @return array{type: string, csv: string, mapping: array<string, string>}
     */
    private function payload(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'csv' => ['required', 'string'],
            'mapping' => ['required', 'array'],
        ]);

        return ['type' => $data['type'], 'csv' => $data['csv'], 'mapping' => $data['mapping']];
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    private function summary(array $counts): string
    {
        return 'created '.(int) ($counts['created'] ?? 0).', skipped '.(int) ($counts['skipped'] ?? 0).', errors '.count($counts['errors'] ?? []);
    }
}

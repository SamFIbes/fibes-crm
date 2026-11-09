<?php

namespace App\Console\Commands;

use App\Services\Users\ErpNextUserImporter;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use RuntimeException;

class ImportErpNextUsers extends Command
{
    use ConfirmableTrait;

    protected $signature = 'users:import-erpnext
        {--file= : Path to a JSON or CSV export of ERPNext user emails}
        {--stdin : Read the JSON or CSV export from standard input instead of a file}
        {--domain=fibes.net : Only import addresses at this domain (empty to allow any)}
        {--dry-run : Show the counts without writing anything}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Create switched-off, no-permission accounts for ERPNext staff emails. Never changes existing users and never sends mail.';

    public function handle(ErpNextUserImporter $importer): int
    {
        $contents = $this->readInput();

        if ($contents === null) {
            return self::FAILURE;
        }

        $emails = $importer->parse($contents);

        if ($emails === []) {
            $this->error('No email addresses found in the input.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $domain = $this->option('domain');

        try {
            $counts = $importer->import($emails, is_string($domain) ? $domain : null, $dryRun);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Import finished.');
        $this->table(['Result', 'Count'], [
            [$dryRun ? 'Would create (switched off, role '.ErpNextUserImporter::LOWEST_ROLE.')' : 'Created (switched off, role '.ErpNextUserImporter::LOWEST_ROLE.')', $counts['created']],
            ['Skipped: already in the CRM', $counts['skipped_existing']],
            ['Skipped: repeated in the input', $counts['skipped_duplicate']],
            ['Skipped: outside the domain', $counts['skipped_domain']],
            ['Skipped: not a valid email', $counts['skipped_invalid']],
            ['Read from input', $counts['total']],
        ]);

        return self::SUCCESS;
    }

    private function readInput(): ?string
    {
        $file = $this->option('file');

        if ($this->option('stdin')) {
            $contents = stream_get_contents(STDIN);

            return is_string($contents) ? $contents : '';
        }

        if (! is_string($file) || $file === '') {
            $this->error('Pass --file=path or --stdin.');

            return null;
        }

        if (! is_file($file) || ! is_readable($file)) {
            $this->error('Cannot read '.$file);

            return null;
        }

        return (string) file_get_contents($file);
    }
}

<?php

namespace App\Services\Imports;

use App\Mail\ReportMail;
use App\Models\User;
use App\Services\Settings\CommercialSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class BackupDesk
{
    public function __construct(private CommercialSettings $commercial) {}

    public function run(): string
    {
        $key = $this->key();
        $directory = $this->directory();
        File::ensureDirectoryExists($directory);
        $plain = $this->dump();
        $file = $directory.'/backup-'.now()->format('Y-m-d-His').'.fibes';
        File::put($file, $this->encrypt($plain, $key));

        if (now()->day === 1) {
            $monthly = $directory.'/monthly';
            File::ensureDirectoryExists($monthly);
            File::copy($file, $monthly.'/backup-'.now()->format('Y-m').'.fibes');
        }

        $this->prune($directory);
        $destination = (string) config('fibes.backup.destination');

        if ($destination !== '' && is_dir($destination)) {
            File::copy($file, rtrim($destination, '/').'/'.basename($file));
        }

        return $file;
    }

    /**
     * @return array<string, int>
     */
    public function restoreTest(): array
    {
        $scratch = (string) config('fibes.backup.scratch');
        $live = (string) config('database.connections.'.config('database.default').'.database');
        $this->assertScratch($scratch, $live, (string) app()->environment());
        $latest = $this->latest();
        $plain = $this->decrypt((string) file_get_contents($latest), $this->key());

        if (is_file($scratch)) {
            unlink($scratch);
        }

        $pdo = new \PDO('sqlite:'.$scratch);
        $pdo->exec('create table backup_probe (name text, total integer)');
        $counts = [];

        foreach (preg_split('/\r\n|\n/', trim($plain)) ?: [] as $line) {
            if (! str_starts_with($line, 'COUNT ')) {
                continue;
            }

            [, $name, $total] = explode(' ', $line);
            $counts[$name] = (int) $total;
            $statement = $pdo->prepare('insert into backup_probe (name, total) values (?, ?)');
            $statement->execute([$name, (int) $total]);
        }

        $report = collect($counts)->map(fn (int $total, string $name) => $name.' '.$total)->implode("\n");

        foreach (User::query()->where('role', 'super_admin')->whereNotNull('email')->get() as $admin) {
            Mail::to($admin->email)->send(new ReportMail('Restore test', $report));
        }

        return $counts;
    }

    public function assertScratch(string $scratch, string $live, string $environment): void
    {
        if ($environment === 'production') {
            throw new RuntimeException('Refusing a restore test in production.');
        }

        if ($scratch === '' || $scratch === $live) {
            throw new RuntimeException('Refusing to restore into the live database.');
        }
    }

    public function dump(): string
    {
        $lines = ['FIBES backup '.now()->toIso8601String()];

        foreach (['customers', 'leads', 'customer_services', 'stock_items'] as $table) {
            $lines[] = 'COUNT '.$table.' '.(int) DB::table($table)->count();
        }

        return implode("\n", $lines)."\n";
    }

    public function encrypt(string $plain, string $key): string
    {
        $material = hash('sha256', $key, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $material, OPENSSL_RAW_DATA, $iv, $tag);

        return $iv.$tag.$cipher;
    }

    public function decrypt(string $payload, string $key): string
    {
        $material = hash('sha256', $key, true);
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipher = substr($payload, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $material, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            throw new RuntimeException('The backup could not be opened.');
        }

        return $plain;
    }

    private function key(): string
    {
        $key = (string) config('fibes.backup.key');

        if ($key === '') {
            throw new RuntimeException('Set FIBES_BACKUP_KEY before taking a backup.');
        }

        return $key;
    }

    private function directory(): string
    {
        return (string) config('fibes.backup.directory');
    }

    private function latest(): string
    {
        $files = File::glob($this->directory().'/backup-*.fibes') ?: [];
        rsort($files);

        if ($files === []) {
            throw new RuntimeException('There is no backup to restore.');
        }

        return $files[0];
    }

    private function prune(string $directory): void
    {
        $keepDays = max(1, (int) $this->commercial->get('backup_daily_keep'));
        $keepMonths = max(1, (int) $this->commercial->get('backup_monthly_keep'));

        foreach (File::glob($directory.'/backup-*.fibes') ?: [] as $file) {
            if (filemtime($file) < now()->subDays($keepDays)->getTimestamp()) {
                unlink($file);
            }
        }

        foreach (File::glob($directory.'/monthly/backup-*.fibes') ?: [] as $file) {
            if (filemtime($file) < now()->subMonths($keepMonths)->getTimestamp()) {
                unlink($file);
            }
        }
    }
}

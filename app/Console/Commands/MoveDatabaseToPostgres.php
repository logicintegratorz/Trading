<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MoveDatabaseToPostgres extends Command
{
    protected $signature = 'db:move-to-postgres {--verify : Verify source and PostgreSQL row counts without copying}';

    protected $description = 'Create the PostgreSQL schema and safely copy all Tradeflow business data';

    /** @var array<string, bool> */
    private array $tables = [
        'users' => true,
        'password_reset_tokens' => false,
        'group_heads' => true,
        'parties' => true,
        'products' => true,
        'saudas' => true,
        'records' => true,
        'order_items' => true,
    ];

    public function handle(): int
    {
        $source = DB::getDefaultConnection();

        if ($source === 'pgsql') {
            $this->error('The default connection is already PostgreSQL. Nothing was copied.');

            return self::FAILURE;
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->error('The pdo_pgsql PHP extension is not enabled.');

            return self::FAILURE;
        }

        try {
            DB::connection($source)->getPdo();
            DB::connection('pgsql')->getPdo();
        } catch (Throwable $exception) {
            $this->error('Database connection failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('verify')) {
            return $this->verify($source) ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Creating/updating the PostgreSQL schema...');
        $exitCode = Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);
        $this->output->write(Artisan::output());

        if ($exitCode !== self::SUCCESS) {
            $this->error('PostgreSQL migrations failed. No source data was changed.');

            return self::FAILURE;
        }

        foreach (array_keys($this->tables) as $table) {
            if (Schema::connection('pgsql')->hasTable($table) && DB::connection('pgsql')->table($table)->exists()) {
                $this->error("PostgreSQL table [{$table}] is not empty. Copy aborted to prevent duplicate or overwritten data.");

                return self::FAILURE;
            }
        }

        DB::connection('pgsql')->transaction(function () use ($source): void {
            foreach ($this->tables as $table => $hasId) {
                if (! Schema::connection($source)->hasTable($table) || ! Schema::connection('pgsql')->hasTable($table)) {
                    continue;
                }

                $count = 0;
                DB::connection($source)->table($table)->orderBy($hasId ? 'id' : 'email')->chunk(500, function ($rows) use ($table, &$count): void {
                    $payload = $rows->map(fn (object $row): array => (array) $row)->all();
                    if ($payload !== []) {
                        DB::connection('pgsql')->table($table)->insert($payload);
                        $count += count($payload);
                    }
                });

                if ($hasId && $count > 0) {
                    DB::connection('pgsql')->statement(
                        "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT MAX(id) FROM {$table}))"
                    );
                }

                $this->line("Copied {$table}: {$count} rows");
            }
        });

        if (! $this->verify($source)) {
            $this->error('Copy completed but verification failed. Keep using the source database until the mismatch is resolved.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('PostgreSQL copy verified. Set DB_CONNECTION=pgsql and the DB_* PostgreSQL credentials, then run: php artisan optimize:clear');

        return self::SUCCESS;
    }

    private function verify(string $source): bool
    {
        $valid = true;
        $this->newLine();
        $this->info('Verifying row counts...');

        foreach (array_keys($this->tables) as $table) {
            if (! Schema::connection($source)->hasTable($table) || ! Schema::connection('pgsql')->hasTable($table)) {
                continue;
            }

            $sourceCount = DB::connection($source)->table($table)->count();
            $targetCount = DB::connection('pgsql')->table($table)->count();
            $matches = $sourceCount === $targetCount;
            $valid = $valid && $matches;
            $this->line(sprintf('%-24s source=%d postgres=%d %s', $table, $sourceCount, $targetCount, $matches ? 'OK' : 'MISMATCH'));
        }

        return $valid;
    }
}

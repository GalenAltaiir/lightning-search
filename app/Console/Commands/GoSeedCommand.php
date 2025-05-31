<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class GoSeedCommand extends Command
{
    protected $signature = 'go:seed {count=1000000 : Number of companies to seed} {--skip-compile : Skip binary compilation}';
    protected $description = 'Seed the database with companies using Go';

    public function handle()
    {
        $count = $this->argument('count');
        $skipCompile = $this->option('skip-compile');
        $goScriptPath = base_path('GoScripts/Seeder');

        // Determine which binary to use based on current OS
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $binaryName = $isWindows ? 'go-seeder.exe' : 'go-seeder';
        $goBinaryPath = $goScriptPath . '/' . $binaryName;

        // Compile binaries for both platforms if needed
        if (!$skipCompile) {
            $this->compileBinaries($goScriptPath);
        }

        // Run the seeder with the appropriate binary
        $this->runGoSeeder($goBinaryPath, $count);

        return Command::SUCCESS;
    }

    protected function compileBinaries(string $goScriptPath): void
    {
        $this->info('Compiling Go binaries for multiple platforms...');

        // Compile for Linux
        $this->compileForPlatform($goScriptPath, 'linux', 'amd64', 'go-seeder');

        // Compile for Windows
        $this->compileForPlatform($goScriptPath, 'windows', 'amd64', 'go-seeder.exe');

        $this->info('Go binaries compiled successfully');
    }

    protected function compileForPlatform(string $goScriptPath, string $os, string $arch, string $output): void
    {
        $this->line("  - Compiling for $os/$arch...");

        $env = [
            'GOOS' => $os,
            'GOARCH' => $arch,
        ];

        $process = new Process(['go', 'build', '-o', $output], $goScriptPath, $env);
        $process->setTimeout(null);

        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                $this->error($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->error("Failed to compile Go binary for $os/$arch");
            throw new \RuntimeException('Go compilation failed');
        }
    }

    protected function runGoSeeder(string $goBinaryPath, int $count): void
    {
        $this->info("Starting to seed $count companies...");
        $startTime = microtime(true);

        // Pass database credentials from Laravel's config
        $env = [
            'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'),
            'DB_HOST' => config('database.connections.mysql.host'),
            'DB_PORT' => config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'),
        ];

        $process = new Process([$goBinaryPath, (string) $count]);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->setEnv($env);

        // For tracking progress on a single line
        $lastLine = '';

        $process->run(function ($type, $buffer) use (&$lastLine) {
            if ($type === Process::OUT) {
                // Check if this is a progress update
                if (strpos($buffer, 'Progress:') !== false) {
                    // Clear the previous line and write the new one
                    if (!empty($lastLine)) {
                        $this->output->write("\r");
                    }
                    $this->output->write(trim($buffer));
                    $lastLine = $buffer;
                } else {
                    // For non-progress messages, print normally
                    if (!empty($lastLine)) {
                        $this->output->writeln(''); // Ensure we're on a new line
                    }
                    $this->output->write($buffer);
                    $lastLine = '';
                }
            } else {
                $this->error($buffer);
            }
        });

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        if (!$process->isSuccessful()) {
            $this->error("Go seeder failed.");
        } else {
            $this->newLine();
            $this->info("Go seeder finished successfully in " . round($duration, 2) . " seconds.");
            $this->info("Average rate: " . round($count / $duration, 2) . " records/second");
        }
    }
}

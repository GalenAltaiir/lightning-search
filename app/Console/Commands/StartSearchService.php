<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class StartSearchService extends Command
{
    protected $signature = 'search:start';
    protected $description = 'Start the Go search service';

    public function handle()
    {
        $goScriptPath = base_path('GoScripts');
        $goBinaryPath = $goScriptPath . '/search-service';

        // Check if we need to compile
       /*  if (!file_exists($goBinaryPath) && !file_exists($goBinaryPath . '.exe')) { */
            $this->info('Compiling Go search service...');
            $this->compileSearchService($goScriptPath);
       /*  } */

        // Determine which binary to use based on current OS
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $binaryName = $isWindows ? 'search-service.exe' : 'search-service';
        $fullPath = $goScriptPath . '/' . $binaryName;

        // Set environment variables
        $env = [
            'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'),
            'DB_HOST' => config('database.connections.mysql.host'),
            'DB_PORT' => config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'PORT' => '3001',
        ];

        $this->info('Starting Go search service...');

        $process = new Process([$fullPath]);
        $process->setEnv($env);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $process->start();

        $this->info('Search service started on port 3001');
        $this->info('Press Ctrl+C to stop');

        // Output process output in real-time
        foreach ($process as $type => $data) {
            if ($type === Process::OUT) {
                $this->info($data);
            } else {
                $this->error($data);
            }
        }

        return Command::SUCCESS;
    }

    protected function compileSearchService(string $goScriptPath): void
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $outputName = $isWindows ? 'search-service.exe' : 'search-service';

        $process = new Process(['go', 'build', '-o', $outputName, 'search-service.go'], $goScriptPath);
        $process->setTimeout(null);

        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                $this->error($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->error('Failed to compile Go search service');
            throw new \RuntimeException('Go compilation failed');
        }

        $this->info('Go search service compiled successfully');
    }
}

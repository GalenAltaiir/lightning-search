<?php

namespace GalenAltaiir\LightningSearch\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class StartSearchCommand extends Command
{
    protected $signature = 'lightning-search:start {--daemon : Run in daemon mode}';
    protected $description = 'Start the Lightning Search Go service';

    public function handle()
    {
        $binary = PHP_OS_FAMILY === 'Windows' ? 'lightning-search.exe' : 'lightning-search';
        $binaryPath = base_path('vendor/bin/' . $binary);

        if (!File::exists($binaryPath)) {
            $this->error('Search service binary not found. Please run php artisan lightning-search:install first.');
            return 1;
        }

        $process = new Process([$binaryPath]);
        $process->setTimeout(null);

        if ($this->option('daemon')) {
            $this->info('Starting Lightning Search service in daemon mode...');
            $process->setOptions(['create_new_console' => true]);
            $process->start();

            $this->info('Service started! Process ID: ' . $process->getPid());
            return 0;
        }

        $this->info('Starting Lightning Search service...');
        $this->info('Press Ctrl+C to stop the service.');

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->getExitCode();
    }
}

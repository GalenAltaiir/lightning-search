<?php

namespace GalenAltaiir\LightningSearch\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class StopSearchCommand extends Command
{
    protected $signature = 'lightning-search:stop';
    protected $description = 'Stop the Lightning Search service';

    public function handle()
    {
        $this->info('Stopping Lightning Search service...');

        if (PHP_OS_FAMILY === 'Windows') {
            // On Windows, we need to find and kill the process
            exec('taskkill /F /IM lightning-search.exe 2>nul', $output, $returnVar);
            if ($returnVar === 0) {
                $this->info('Search service stopped successfully.');
            } else {
                $this->info('Search service was not running.');
            }
        } else {
            // On Unix-like systems, we can use pkill
            exec('pkill -f lightning-search 2>/dev/null', $output, $returnVar);
            if ($returnVar === 0) {
                $this->info('Search service stopped successfully.');
            } else {
                $this->info('Search service was not running.');
            }
        }
    }
}

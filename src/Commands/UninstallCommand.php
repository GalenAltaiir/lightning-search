<?php

namespace GalenAltaiir\LightningSearch\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class UninstallCommand extends Command
{
    protected $signature = 'lightning-search:uninstall {--force : Force the operation to run without confirmation}';
    protected $description = 'Remove Lightning Search package files and configuration';

    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('This will remove all Lightning Search files and configuration. Continue?')) {
            $this->info('Operation cancelled.');
            return;
        }

        $this->info('Uninstalling Lightning Search...');

        // Stop the search service if it's running
        $this->call('lightning-search:stop');

        // Remove the binary
        $binary = PHP_OS_FAMILY === 'Windows' ? 'lightning-search.exe' : 'lightning-search';
        $binaryPath = base_path('vendor/bin/' . $binary);
        if (File::exists($binaryPath)) {
            File::delete($binaryPath);
            $this->info('Removed search service binary.');
        }

        // Remove the config file
        $configPath = config_path('lightning-search.php');
        if (File::exists($configPath)) {
            File::delete($configPath);
            $this->info('Removed configuration file.');
        }

        $this->info('Lightning Search has been uninstalled successfully!');
        $this->info('Note: Environment variables in .env have been left intact for reference.');
    }
}

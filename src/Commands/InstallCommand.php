<?php

namespace GalenAltaiir\LightningSearch\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'lightning-search:install';
    protected $description = 'Install the Lightning Search package';

    public function handle()
    {
        $this->info('Installing Lightning Search...');

        // Publish configuration
        $this->call('vendor:publish', [
            '--provider' => 'GalenAltaiir\\LightningSearch\\LightningSearchServiceProvider',
            '--tag' => 'config'
        ]);

        // Create .env entries if they don't exist
        $this->updateEnvironmentFile();

        // Check Go installation
        if (!$this->isGoInstalled()) {
            $this->warn('Go is not installed. Please install Go 1.21 or later to use the high-performance search features.');
            $this->warn('You can still use the package with Eloquent-only mode.');

            // Update config to use Eloquent by default
            $configPath = config_path('lightning-search.php');
            if (File::exists($configPath)) {
                $config = File::get($configPath);
                $config = str_replace(
                    "'default' => env('LIGHTNING_SEARCH_DEFAULT_MODE', 'go')",
                    "'default' => env('LIGHTNING_SEARCH_DEFAULT_MODE', 'eloquent')",
                    $config
                );
                File::put($configPath, $config);
            }
        } else {
            // Build and install Go binary
            $this->info('Building Go search service...');
            $this->buildGoService();
        }

        $this->info('Lightning Search has been installed successfully!');

        $this->info('Next steps:');
        $this->line('1. Add the Searchable interface and HasLightningSearch trait to your models');
        $this->line('2. Configure your searchable models in config/lightning-search.php');
        $this->line('3. Run php artisan lightning-search:start to start the Go search service');
    }

    protected function updateEnvironmentFile()
    {
        $envFile = base_path('.env');
        $envExample = base_path('.env.example');

        $envVars = [
            'LIGHTNING_SEARCH_HOST' => '127.0.0.1',
            'LIGHTNING_SEARCH_PORT' => '8081',
            'LIGHTNING_SEARCH_DEFAULT_MODE' => 'go',
            'LIGHTNING_SEARCH_FALLBACK_MODE' => 'eloquent',
            'LIGHTNING_SEARCH_CPU_CORES' => '1',
            'LIGHTNING_SEARCH_MAX_CONNECTIONS' => '10',
            'LIGHTNING_SEARCH_CACHE_DURATION' => '300',
            'LIGHTNING_SEARCH_RESULT_LIMIT' => '1000',
        ];

        foreach ($envVars as $key => $value) {
            if (!$this->envHasVariable($envFile, $key)) {
                File::append($envFile, "\n{$key}={$value}");
            }
            if (!$this->envHasVariable($envExample, $key)) {
                File::append($envExample, "\n{$key}={$value}");
            }
        }
    }

    protected function envHasVariable(string $file, string $key): bool
    {
        if (!File::exists($file)) {
            return false;
        }

        $contents = File::get($file);
        return strpos($contents, $key . '=') !== false;
    }

    protected function isGoInstalled(): bool
    {
        exec('go version', $output, $returnVar);
        return $returnVar === 0;
    }

    protected function buildGoService()
    {
        $goDir = base_path('vendor/galenaltaiir/lightning-search/go');

        // Build for the current platform
        $binary = PHP_OS_FAMILY === 'Windows' ? 'lightning-search.exe' : 'lightning-search';

        // The go.sum file should already exist in our package
        if (!file_exists($goDir . '/go.sum')) {
            $this->error('Package installation error: go.sum file is missing. Please report this issue to the package maintainer.');
            return;
        }

        exec('cd ' . $goDir . ' && go build -o ' . $binary . ' .', $output, $returnVar);
        if ($returnVar !== 0) {
            $this->error('Failed to build Go search service. Please ensure Go 1.21+ is installed and try again.');
            $this->line('If the issue persists, you can:');
            $this->line('1. Try running `cd ' . $goDir . ' && go build` manually');
            $this->line('2. Check if Go is properly installed with `go version`');
            $this->line('3. Report the issue to the package maintainer');
            return;
        }

        // Copy binary to vendor/bin
        $vendorBin = base_path('vendor/bin');
        if (!File::exists($vendorBin)) {
            File::makeDirectory($vendorBin, 0755, true);
        }

        File::copy(
            $goDir . '/' . $binary,
            $vendorBin . '/' . $binary
        );

        chmod($vendorBin . '/' . $binary, 0755);
    }
}

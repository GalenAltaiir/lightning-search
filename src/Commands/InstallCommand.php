<?php

namespace GalenAltaiir\LightningSearch\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'lightning-search:install';
    protected $description = 'Install the Lightning Search package';

    protected $requiredEnvVars = [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => null,
        'DB_USERNAME' => null,
    ];

    public function handle()
    {
        $this->info('Installing Lightning Search...');

        // Check Go installation first
        if (!$this->isGoInstalled()) {
            $this->error('Go 1.21+ is required but not found.');
            $this->line('Please install Go from https://golang.org/dl/');
            $this->line('After installing Go, run this command again.');
            return;
        }

        // Validate environment
        if (!$this->validateEnvironment()) {
            return;
        }

        // Publish configuration
        $this->call('vendor:publish', [
            '--provider' => 'GalenAltaiir\\LightningSearch\\LightningSearchServiceProvider',
            '--tag' => 'config'
        ]);

        // Create .env entries if they don't exist
        $this->updateEnvironmentFile();

        // Build and install Go binary
        $this->info('Building Go search service...');
        if (!$this->buildGoService()) {
            return;
        }

        $this->info('Lightning Search has been installed successfully!');

        $this->info('Next steps:');
        $this->line('1. Add the Searchable interface and HasLightningSearch trait to your models');
        $this->line('2. Configure your searchable models in config/lightning-search.php');
        $this->line('3. Run php artisan lightning-search:start to start the Go search service');
    }

    protected function validateEnvironment(): bool
    {
        $missingVars = [];
        foreach ($this->requiredEnvVars as $var => $default) {
            if (!env($var) && $default === null) {
                $missingVars[] = $var;
            }
        }

        // Special handling for DB_PASSWORD
        $dbHost = env('DB_HOST', '127.0.0.1');
        $dbPassword = env('DB_PASSWORD');
        $isLocalhost = in_array($dbHost, ['127.0.0.1', 'localhost', '::1']);

        if (!$dbPassword && !$isLocalhost) {
            $this->warn('⚠️  Warning: DB_PASSWORD is not set, but you are connecting to a non-local database.');
            $this->warn('This is a security risk if this is a production or staging environment.');
            $this->line('');
        }

        if (!empty($missingVars)) {
            $this->error('Missing required environment variables:');
            foreach ($missingVars as $var) {
                $this->line("- $var");
            }
            $this->line('');
            $this->info('Please set these variables in your .env file and try again.');
            return false;
        }

        return true;
    }

    protected function isGoInstalled(): bool
    {
        exec('go version', $output, $returnVar);
        if ($returnVar !== 0) {
            return false;
        }

        // Extract version number and check if it's 1.21 or higher
        $version = implode(' ', $output);
        if (preg_match('/go(\d+\.\d+)/', $version, $matches)) {
            return version_compare($matches[1], '1.21', '>=');
        }

        return false;
    }

    protected function buildGoService(): bool
    {
        $goDir = base_path('vendor/galenaltaiir/lightning-search/go');
        $binary = PHP_OS_FAMILY === 'Windows' ? 'lightning-search.exe' : 'lightning-search';

        // Ensure Go files exist
        if (!File::exists($goDir . '/go.mod') || !File::exists($goDir . '/search-service.go')) {
            $this->error('Go source files not found. Please reinstall the package.');
            return false;
        }

        // Check for go.sum
        if (!File::exists($goDir . '/go.sum')) {
            $this->warn('go.sum not found. Attempting to download dependencies...');
            exec('cd ' . $goDir . ' && go mod download', $output, $returnVar);
            if ($returnVar !== 0) {
                $this->error('Failed to download Go dependencies.');
                $this->showGoTroubleshooting();
                return false;
            }
        }

        // Build the binary
        exec('cd ' . $goDir . ' && go build -o ' . $binary . ' .', $output, $returnVar);
        if ($returnVar !== 0) {
            $this->error('Failed to build Go search service.');
            $this->showGoTroubleshooting();
            return false;
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
        return true;
    }

    protected function showGoTroubleshooting()
    {
        $this->line('Troubleshooting steps:');
        $this->line('1. Ensure Go 1.21+ is installed: go version');
        $this->line('2. Check if GOPATH is set correctly: go env GOPATH');
        $this->line('3. Try running these commands manually:');
        $this->line('   cd ' . base_path('vendor/galenaltaiir/lightning-search/go'));
        $this->line('   go mod download');
        $this->line('   go build');
        $this->line('4. Check the Go build output above for specific errors');
        $this->line('If the issue persists, please report it at:');
        $this->line('https://github.com/GalenAltaiir/lightning-search/issues');
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
}

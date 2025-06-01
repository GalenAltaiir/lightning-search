<?php

namespace GalenAltaiir\LightningSearch;

use Illuminate\Support\ServiceProvider;

class LightningSearchServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/lightning-search.php' => config_path('lightning-search.php'),
        ], 'config');

        // Publish Go binary and scripts
        $this->publishes([
            __DIR__.'/../go/lightning-search' => base_path('vendor/bin/lightning-search'),
            __DIR__.'/../go/lightning-search.exe' => base_path('vendor/bin/lightning-search.exe'),
        ], 'binaries');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallCommand::class,
                Commands\StartSearchCommand::class,
                Commands\IndexModelsCommand::class,
            ]);
        }
    }

    public function register()
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../config/lightning-search.php', 'lightning-search'
        );

        // Register main service
        $this->app->singleton('lightning-search', function ($app) {
            return new LightningSearch($app);
        });
    }
}

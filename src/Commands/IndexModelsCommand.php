<?php

namespace GalenAltaiir\LightningSearch\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IndexModelsCommand extends Command
{
    protected $signature = 'lightning-search:index {model? : The model to index}';
    protected $description = 'Index models for Lightning Search';

    public function handle()
    {
        $modelName = $this->argument('model');
        $models = $modelName ? [$modelName] : array_keys(Config::get('lightning-search.models', []));

        if (empty($models)) {
            $this->error('No models configured for indexing. Please add models to your lightning-search config file.');
            return 1;
        }

        foreach ($models as $modelClass) {
            $this->info("Indexing {$modelClass}...");

            try {
                $model = new $modelClass;
                $table = $model->getSearchableTable();
                $searchableFields = $model->getSearchableFields();

                if (empty($searchableFields)) {
                    $this->warn("No searchable fields defined for {$modelClass}");
                    continue;
                }

                // Create fulltext index
                $indexName = 'lightning_search_' . $table;

                // Drop existing index if it exists
                if ($this->hasFulltextIndex($table, $indexName)) {
                    Schema::table($table, function ($table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                }

                // Create new index
                Schema::table($table, function ($table) use ($searchableFields, $indexName) {
                    $table->fullText($searchableFields, $indexName);
                });

                $this->info("Created fulltext index on " . implode(', ', $searchableFields));

            } catch (\Exception $e) {
                $this->error("Failed to index {$modelClass}: " . $e->getMessage());
                continue;
            }
        }

        $this->info('Indexing complete!');
        return 0;
    }

    protected function hasFulltextIndex(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table}");
        foreach ($indexes as $index) {
            if ($index->Key_name === $indexName && $index->Index_type === 'FULLTEXT') {
                return true;
            }
        }
        return false;
    }
}

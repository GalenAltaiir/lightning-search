<?php

namespace GalenAltaiir\LightningSearch;

use GalenAltaiir\LightningSearch\Contracts\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use RuntimeException;

class LightningSearch
{
    /**
     * Search using the model's query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $search
     * @param  string|null  $mode
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function search(Builder $query, string $search, ?string $mode = null)
    {
        $model = $query->getModel();

        if (!$model instanceof Searchable) {
            throw new RuntimeException(sprintf(
                'Model [%s] must implement the Searchable interface.',
                get_class($model)
            ));
        }

        $mode = $mode ?? Config::get('lightning-search.modes.default', 'go');

        if ($mode === 'eloquent') {
            return $this->searchWithEloquent($query, $search, $model);
        }

        return $this->searchWithGo($query, $search, $model);
    }

    /**
     * Search using the Go service.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $search
     * @param  \GalenAltaiir\LightningSearch\Contracts\Searchable  $model
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function searchWithGo(Builder $query, string $search, Searchable $model)
    {
        try {
            $response = Http::post($this->getGoServiceUrl() . '/search', [
                'table' => $model->getSearchableTable(),
                'query' => $search,
                'mode' => 'fulltext', // Default to fulltext search for Go service
            ]);

            if (!$response->successful()) {
                // Fallback to Eloquent if Go service fails
                if (Config::get('lightning-search.modes.fallback') === 'eloquent') {
                    return $this->searchWithEloquent($query, $search, $model);
                }
                throw new RuntimeException('Go search service request failed: ' . $response->body());
            }

            $data = $response->json();
            $ids = collect($data['results'])->pluck('id')->all();

            // Return a query that will fetch the models in the same order as the search results
            return $query->whereIn($model->getKeyName(), $ids)
                        ->orderByRaw("FIELD({$model->getKeyName()}, " . implode(',', $ids) . ")");

        } catch (\Exception $e) {
            // Fallback to Eloquent on any error if configured
            if (Config::get('lightning-search.modes.fallback') === 'eloquent') {
                return $this->searchWithEloquent($query, $search, $model);
            }
            throw $e;
        }
    }

    /**
     * Search using Eloquent's where clauses.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $search
     * @param  \GalenAltaiir\LightningSearch\Contracts\Searchable  $model
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function searchWithEloquent(Builder $query, string $search, Searchable $model)
    {
        return $query->where(function ($query) use ($search, $model) {
            foreach ($model->getSearchableFields() as $field) {
                $query->orWhere($field, 'LIKE', "%{$search}%");
            }
        });
    }

    /**
     * Get the URL for the Go search service.
     *
     * @return string
     */
    protected function getGoServiceUrl(): string
    {
        $host = Config::get('lightning-search.service.host', '127.0.0.1');
        $port = Config::get('lightning-search.service.port', 8081);

        return "http://{$host}:{$port}";
    }
}

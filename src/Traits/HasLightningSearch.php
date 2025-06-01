<?php

namespace GalenAltaiir\LightningSearch\Traits;

use GalenAltaiir\LightningSearch\Contracts\Searchable;
use Illuminate\Support\Facades\Config;

trait HasLightningSearch
{
    /**
     * Get the fields that should be searchable.
     *
     * @return array<string>
     */
    public function getSearchableFields(): array
    {
        if (property_exists($this, 'searchable')) {
            return $this->searchable;
        }

        // Get from config if set
        $modelClass = get_class($this);
        $config = Config::get('lightning-search.models.' . $modelClass);
        if ($config && isset($config['searchable_fields'])) {
            return $config['searchable_fields'];
        }

        // Default to fillable fields
        return $this->getFillable();
    }

    /**
     * Get the fields that should be included in the search index.
     *
     * @return array<string>
     */
    public function getIndexFields(): array
    {
        if (property_exists($this, 'indexFields')) {
            return $this->indexFields;
        }

        // Get from config if set
        $modelClass = get_class($this);
        $config = Config::get('lightning-search.models.' . $modelClass);
        if ($config && isset($config['index_fields'])) {
            return $config['index_fields'];
        }

        // Default to all fillable fields plus primary key
        return array_merge([$this->getKeyName()], $this->getFillable());
    }

    /**
     * Get the table name for the model.
     */
    public function getSearchableTable(): string
    {
        if (property_exists($this, 'searchableTable')) {
            return $this->searchableTable;
        }

        // Get from config if set
        $modelClass = get_class($this);
        $config = Config::get('lightning-search.models.' . $modelClass);
        if ($config && isset($config['table'])) {
            return $config['table'];
        }

        // Default to model's table name
        return $this->getTable();
    }

    /**
     * Transform the model instance into a searchable array.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $indexFields = $this->getIndexFields();

        return collect($this->toArray())
            ->only($indexFields)
            ->toArray();
    }

    /**
     * Scope a query to search through the model.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $search
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, string $search)
    {
        return app('lightning-search')->search($query, $search);
    }
}

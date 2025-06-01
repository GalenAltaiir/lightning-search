<?php

namespace GalenAltaiir\LightningSearch\Contracts;

interface Searchable
{
    /**
     * Get the fields that should be searchable.
     *
     * @return array<string>
     */
    public function getSearchableFields(): array;

    /**
     * Get the fields that should be included in the search index.
     *
     * @return array<string>
     */
    public function getIndexFields(): array;

    /**
     * Get the table name for the model.
     */
    public function getSearchableTable(): string;

    /**
     * Transform the model instance into a searchable array.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array;
}

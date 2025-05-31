<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Http;

class Search extends Component
{
    public string $query = '';
    public array $results = [];
    public int $searchTime = 0;
    public int $resultCount = 0;
    public bool $isSearching = false;

    public function updatedQuery()
    {
        if (strlen($this->query) < 3) {
            $this->resetSearch();
            return;
        }

        $this->search();
    }

    public function search()
    {
        $this->isSearching = true;
        $this->dispatch('render');

        usleep(50000);

        try {
            $response = Http::timeout(60)->get('http://localhost:3001/search', [
                'q' => $this->query,
            ]);

            // Debug the response
            logger()->info('Search response status: ' . $response->status());
            logger()->info('Search response body: ' . $response->body());

            if ($response->successful()) {
                $data = $response->json();
                $this->results = $data['results'] ?? [];
                $this->searchTime = $data['time_ms'] ?? 0;
                $this->resultCount = $data['count'] ?? 0;

                // Debug the parsed data
                logger()->info('Search results count: ' . count($this->results));
            } else {
                $this->resetSearch();
                logger()->error('Search failed with status: ' . $response->status());
            }
        } catch (\Exception $e) {
            logger()->error('Search service error: ' . $e->getMessage());
            $this->resetSearch();
        }

        $this->isSearching = false;
    }



    private function resetSearch()
    {
        $this->results = [];
        $this->searchTime = 0;
        $this->resultCount = 0;
    }

    public function render()
    {
        return view('livewire.search');
    }
}

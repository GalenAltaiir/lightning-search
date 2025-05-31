<div class="p-4 max-w-5xl mx-auto">
    <div class="mb-4">
        <input type="text" wire:model.debounce.300ms="query" wire:keydown.enter="search"
            placeholder="Search companies..."
            class="w-full px-4 py-2 border border-gray-300 rounded shadow-sm focus:outline-none focus:ring focus:border-blue-300" />
        <button wire:click="search" class="px-4 py-2 bg-blue-500 text-white rounded mt-2">
            Search
        </button>
    </div>

    <!-- Debug isSearching -->
    <div class="text-sm text-gray-600 mb-2">
        isSearching: {{ $isSearching ? 'true' : 'false' }}
    </div>

    @if ($isSearching)
        <div class="flex justify-center my-4">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
            <span class="ml-2 text-blue-500">Searching...</span>
        </div>
    @endif

    <!-- Debug info -->
    <div class="text-sm text-gray-600 mb-2">
        <div>Query: "{{ $query }}"</div>
        <div>Results: {{ count($results) }}</div>
        <div>Search time: {{ $searchTime }}ms</div>
    </div>

    @if (strlen($query) >= 3 && !$isSearching)
        <div class="text-sm text-gray-600 mb-2">
            Found {{ $resultCount }} results in {{ $searchTime }}ms
        </div>
    @endif

    <table class="w-full text-sm text-left border border-gray-200">
        <thead class="bg-gray-100">
            <tr>
                <th class="px-2 py-1 border">ID</th>
                <th class="px-2 py-1 border">Name</th>
                <th class="px-2 py-1 border">Status</th>
                <th class="px-2 py-1 border">City</th>
                <th class="px-2 py-1 border">Country</th>
                <th class="px-2 py-1 border">Revenue</th>
            </tr>
        </thead>
        <tbody>
            @forelse($results as $company)
                <tr class="hover:bg-gray-50">
                    <td class="px-2 py-1 border">{{ $company['id'] }}</td>
                    <td class="px-2 py-1 border">{{ $company['name'] }}</td>
                    <td class="px-2 py-1 border">{{ $company['status'] }}</td>
                    <td class="px-2 py-1 border">{{ $company['city'] }}</td>
                    <td class="px-2 py-1 border">{{ $company['country'] }}</td>
                    <td class="px-2 py-1 border">${{ number_format($company['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center py-2">No results</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

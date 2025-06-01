# Lightning Search for Laravel

A high-performance search package for Laravel that combines the power of Go with the flexibility of Laravel's Eloquent ORM.

## Features

- 🚀 High-performance search powered by Go
- 🔄 Seamless fallback to Eloquent when needed
- 🎯 Full-text search support
- ⚡ Built-in caching
- 🛠️ Easy configuration
- 🔌 Simple model integration
- 🔍 Multiple search modes (Go or Eloquent)

## Requirements

- PHP 8.2+
- Laravel 10.0+
- Go 1.21+ (optional, for high-performance search)
- MySQL/MariaDB (for full-text search capabilities)

## Installation

```bash
composer require galenaltaiir/lightning-search
```

After installation, run:

```bash
php artisan lightning-search:install
```

This will:
- Publish the configuration file
- Set up environment variables
- Build and install the Go search service (if Go is installed)

## Configuration

### Environment Variables

The installer will add these variables to your `.env` file:

```env
LIGHTNING_SEARCH_HOST=127.0.0.1
LIGHTNING_SEARCH_PORT=8081
LIGHTNING_SEARCH_DEFAULT_MODE=go
LIGHTNING_SEARCH_FALLBACK_MODE=eloquent
LIGHTNING_SEARCH_CPU_CORES=1
LIGHTNING_SEARCH_MAX_CONNECTIONS=10
LIGHTNING_SEARCH_CACHE_DURATION=300
LIGHTNING_SEARCH_RESULT_LIMIT=1000
```

### Model Configuration

1. Implement the `Searchable` interface and use the `HasLightningSearch` trait in your model:

```php
use GalenAltaiir\LightningSearch\Contracts\Searchable;
use GalenAltaiir\LightningSearch\Traits\HasLightningSearch;

class YourModel extends Model implements Searchable
{
    use HasLightningSearch;

    // Optional: Define searchable fields explicitly
    protected $searchable = [
        'name',
        'description'
    ];

    // Optional: Define fields to include in search results
    protected $indexFields = [
        'id',
        'name',
        'description',
        'created_at'
    ];
}
```

2. Configure your models in `config/lightning-search.php`:

```php
'models' => [
    \App\Models\YourModel::class => [
        'searchable_fields' => ['name', 'description'],
        'index_fields' => ['id', 'name', 'description', 'created_at'],
        // Optional: specify table name if different from model's table
        'table' => 'your_models',
    ],
],
```

### Creating Search Indexes

Run the following command to create full-text search indexes for your models:

```bash
php artisan lightning-search:index
```

To index a specific model:

```bash
php artisan lightning-search:index "App\Models\YourModel"
```

## Usage

### Starting the Search Service

Start the Go search service:

```bash
php artisan lightning-search:start
```

For production, use daemon mode:

```bash
php artisan lightning-search:start --daemon
```

### Performing Searches

#### Using the Model Scope

```php
// Simple search
$results = YourModel::search('query')->get();

// With pagination
$results = YourModel::search('query')->paginate(15);

// Specify search mode
$results = YourModel::search('query', 'eloquent')->get(); // Forces Eloquent mode
```

#### Using the Facade

```php
use GalenAltaiir\LightningSearch\Facades\LightningSearch;

$results = LightningSearch::search(YourModel::query(), 'query')->get();
```

#### Chaining with Other Query Builder Methods

```php
$results = YourModel::where('status', 'active')
    ->search('query')
    ->orderBy('created_at', 'desc')
    ->get();
```

## Performance Tuning

### Go Service Configuration

Adjust these environment variables for optimal performance:

```env
# Number of CPU cores to use
LIGHTNING_SEARCH_CPU_CORES=4

# Maximum database connections
LIGHTNING_SEARCH_MAX_CONNECTIONS=20

# Cache duration in seconds
LIGHTNING_SEARCH_CACHE_DURATION=300

# Maximum results per query
LIGHTNING_SEARCH_RESULT_LIMIT=1000
```

### Search Modes

- `go`: Uses the high-performance Go service with full-text search
- `eloquent`: Uses Laravel's Eloquent with LIKE queries

Configure the default and fallback modes in your `.env`:

```env
LIGHTNING_SEARCH_DEFAULT_MODE=go
LIGHTNING_SEARCH_FALLBACK_MODE=eloquent
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This package is open-sourced software licensed under the MIT license. 

package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/joho/godotenv"
)

type SearchConfig struct {
	Host           string `json:"host"`
	Port           int    `json:"port"`
	DBConnection   string `json:"db_connection"`
	DBHost         string `json:"db_host"`
	DBPort         string `json:"db_port"`
	DBName         string `json:"db_name"`
	DBUser         string `json:"db_user"`
	DBPass         string `json:"db_pass"`
	CPUCores       int    `json:"cpu_cores"`
	MaxConnections int    `json:"max_connections"`
	CacheDuration  int    `json:"cache_duration"`
	ResultLimit    int    `json:"result_limit"`
}

type TableConfig struct {
	Name            string   `json:"name"`
	SearchableFields []string `json:"searchable_fields"`
	IndexFields     []string `json:"index_fields"`
}

type SearchRequest struct {
	Table string `json:"table"`
	Query string `json:"query"`
	Mode  string `json:"mode"` // "like" or "fulltext"
}

type SearchResponse struct {
	Results    []map[string]interface{} `json:"results"`
	Count      int                      `json:"count"`
	TimeMs     int64                    `json:"time_ms"`
	FromCache  bool                     `json:"from_cache"`
}

// Cache implementation
type Cache struct {
	items map[string]cacheItem
	mutex sync.RWMutex
}

type cacheItem struct {
	data       []map[string]interface{}
	count      int
	timeMs     int64
	expiration time.Time
}

func NewCache() *Cache {
	return &Cache{
		items: make(map[string]cacheItem),
	}
}

func (c *Cache) Set(key string, data []map[string]interface{}, count int, timeMs int64, duration time.Duration) {
	c.mutex.Lock()
	defer c.mutex.Unlock()
	c.items[key] = cacheItem{
		data:       data,
		count:      count,
		timeMs:     timeMs,
		expiration: time.Now().Add(duration),
	}
}

func (c *Cache) Get(key string) ([]map[string]interface{}, int, int64, bool) {
	c.mutex.RLock()
	defer c.mutex.RUnlock()
	item, found := c.items[key]
	if !found {
		return nil, 0, 0, false
	}
	if time.Now().After(item.expiration) {
		delete(c.items, key)
		return nil, 0, 0, false
	}
	return item.data, item.count, item.timeMs, true
}

var envPath string // Global variable to store .env path

func loadConfig() (*SearchConfig, error) {
	// Try to find Laravel root by looking for .env file
	execPath, err := os.Executable()
	if err != nil {
		return nil, fmt.Errorf("failed to get executable path: %v", err)
	}

	// Start from the binary location and traverse up until we find .env
	currentDir := filepath.Dir(execPath)
	maxDepth := 5 // Prevent infinite loop

	for i := 0; i < maxDepth; i++ {
		testPath := filepath.Join(currentDir, ".env")
		if _, err := os.Stat(testPath); err == nil {
			envPath = testPath
			break
		}
		parentDir := filepath.Dir(currentDir)
		if parentDir == currentDir {
			break
		}
		currentDir = parentDir
	}

	if envPath == "" {
		return nil, fmt.Errorf("could not find .env file in parent directories")
	}

	err = godotenv.Load(envPath)
	if err != nil {
		log.Printf("Warning: Error loading .env file: %v", err)
	}

	// Get number of CPU cores
	cpuCores := runtime.NumCPU()
	defaultCores := cpuCores
	if defaultCores > 4 {
		defaultCores = 4 // Default to 4 cores max, can be overridden
	}

	return &SearchConfig{
		Host:           getEnv("LIGHTNING_SEARCH_HOST", "127.0.0.1"),
		Port:           getEnvInt("LIGHTNING_SEARCH_PORT", 8081),
		DBConnection:   getEnv("LIGHTNING_SEARCH_DB_CONNECTION", getEnv("DB_CONNECTION", "mysql")),
		DBHost:         getEnv("LIGHTNING_SEARCH_DB_HOST", getEnv("DB_HOST", "127.0.0.1")),
		DBPort:         getEnv("LIGHTNING_SEARCH_DB_PORT", getEnv("DB_PORT", "3306")),
		DBName:         getEnv("LIGHTNING_SEARCH_DB_DATABASE", getEnv("DB_DATABASE", "")),
		DBUser:         getEnv("LIGHTNING_SEARCH_DB_USERNAME", getEnv("DB_USERNAME", "root")),
		DBPass:         getEnv("LIGHTNING_SEARCH_DB_PASSWORD", getEnv("DB_PASSWORD", "")),
		CPUCores:       getEnvInt("LIGHTNING_SEARCH_CPU_CORES", defaultCores),
		MaxConnections: getEnvInt("LIGHTNING_SEARCH_MAX_CONNECTIONS", cpuCores * 5), // 5 connections per core
		CacheDuration:  getEnvInt("LIGHTNING_SEARCH_CACHE_DURATION", 300),
		ResultLimit:    getEnvInt("LIGHTNING_SEARCH_RESULT_LIMIT", 1000),
	}, nil
}

func getEnv(key, fallback string) string {
	if value, exists := os.LookupEnv(key); exists {
		return value
	}
	return fallback
}

func getEnvInt(key string, fallback int) int {
	if value, exists := os.LookupEnv(key); exists {
		if i, err := strconv.Atoi(value); err == nil {
			return i
		}
	}
	return fallback
}

func loadTableConfig(table string) (*TableConfig, error) {
	// Try to load config from Laravel's config directory
	configPath := filepath.Join(filepath.Dir(filepath.Dir(os.Args[0])), "config", "lightning-search.php")
	if _, err := os.Stat(configPath); os.IsNotExist(err) {
		return nil, fmt.Errorf("config file not found at %s - please run php artisan lightning-search:install first", configPath)
	}

	// For now, return a default config - in future versions we'll parse the PHP config file
	return &TableConfig{
		Name:            table,
		SearchableFields: []string{"name", "description", "content"}, // Default searchable fields
		IndexFields:     []string{"id"},
	}, nil
}

func main() {
	config, err := loadConfig()
	if err != nil {
		log.Fatal("Configuration error: ", err)
	}

	// Validate environment
	if config.DBName == "" {
		log.Fatal("Database configuration missing. Please check your .env file and ensure DB_DATABASE is set.")
	}

	// Additional validation for non-local connections without password
	isLocalhost := config.DBHost == "127.0.0.1" || config.DBHost == "localhost" || config.DBHost == "::1"
	if config.DBPass == "" && !isLocalhost {
		log.Printf("⚠️  Warning: Database password is not set, but you are connecting to a non-local database (%s).", config.DBHost)
		log.Printf("This is a security risk if this is a production or staging environment.")
	}

	// Set CPU cores
	runtime.GOMAXPROCS(config.CPUCores)

	// Print technical setup
	log.Printf("=== Lightning Search Service ===")
	log.Printf("CPU Cores: %d/%d", config.CPUCores, runtime.NumCPU())
	log.Printf("Max DB Connections: %d", config.MaxConnections)
	log.Printf("Cache Duration: %ds", config.CacheDuration)
	log.Printf("Result Limit: %d", config.ResultLimit)
	log.Printf("Go Version: %s", runtime.Version())
	log.Printf("OS/Arch: %s/%s", runtime.GOOS, runtime.GOARCH)
	log.Printf("Environment: %s", envPath)
	log.Printf("=============================")

	// Create cache
	cache := NewCache()

	// Database connection
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true",
		config.DBUser,
		config.DBPass,
		config.DBHost,
		config.DBPort,
		config.DBName,
	)

	db, err := sql.Open(config.DBConnection, dsn)
	if err != nil {
		log.Fatal(err)
	}
	defer db.Close()

	// Configure connection pool
	db.SetMaxOpenConns(config.MaxConnections)
	db.SetMaxIdleConns(config.MaxConnections / 2)
	db.SetConnMaxLifetime(5 * time.Minute)

	// Test connection
	if err := db.Ping(); err != nil {
		log.Fatalf("Failed to connect to database: %v", err)
	}

	// Define HTTP handler for search
	http.HandleFunc("/search", func(w http.ResponseWriter, r *http.Request) {
		// Set CORS headers
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "POST, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		if r.Method != "POST" {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		// Read request body
		body, err := ioutil.ReadAll(r.Body)
		if err != nil {
			http.Error(w, "Error reading request body", http.StatusBadRequest)
			return
		}

		// Parse request
		var req SearchRequest
		if err := json.Unmarshal(body, &req); err != nil {
			http.Error(w, "Error parsing request JSON", http.StatusBadRequest)
			return
		}

		// Load table configuration
		tableConfig, err := loadTableConfig(req.Table)
		if err != nil {
			http.Error(w, fmt.Sprintf("Configuration error: %v", err), http.StatusInternalServerError)
			return
		}

		// Validate request
		if req.Table == "" || req.Query == "" {
			http.Error(w, "Missing required fields", http.StatusBadRequest)
			return
		}

		startTime := time.Now()

		// Check cache
		cacheKey := fmt.Sprintf("%s:%s:%s", req.Table, req.Query, req.Mode)
		if results, count, timeMs, found := cache.Get(cacheKey); found {
			response := SearchResponse{
				Results:   results,
				Count:    count,
				TimeMs:   timeMs,
				FromCache: true,
			}
			json.NewEncoder(w).Encode(response)
			return
		}

		// Build query based on mode
		var query string
		var args []interface{}

		switch req.Mode {
		case "fulltext":
			// Use MATCH AGAINST with relevance scoring
			query = fmt.Sprintf(
				"SELECT *, MATCH(%s) AGAINST(? IN BOOLEAN MODE) as relevance FROM %s WHERE MATCH(%s) AGAINST(? IN BOOLEAN MODE) ORDER BY relevance DESC LIMIT %d",
				strings.Join(tableConfig.SearchableFields, ","),
				req.Table,
				strings.Join(tableConfig.SearchableFields, ","),
				config.ResultLimit,
			)
			args = []interface{}{req.Query, req.Query}
		default: // "like" mode
			// Use UNION ALL for better performance with multiple fields
			conditions := make([]string, len(tableConfig.SearchableFields))
			args = make([]interface{}, len(tableConfig.SearchableFields))
			for i, field := range tableConfig.SearchableFields {
				conditions[i] = fmt.Sprintf("SELECT *, 1 as relevance FROM %s WHERE %s LIKE ?", req.Table, field)
				args[i] = "%" + req.Query + "%"
			}
			query = fmt.Sprintf(
				"%s ORDER BY relevance DESC LIMIT %d",
				strings.Join(conditions, " UNION ALL "),
				config.ResultLimit,
			)
		}

		// Execute query
		rows, err := db.Query(query, args...)
		if err != nil {
			http.Error(w, fmt.Sprintf("Database error: %v", err), http.StatusInternalServerError)
			return
		}
		defer rows.Close()

		// Get column names
		columns, err := rows.Columns()
		if err != nil {
			http.Error(w, "Error getting column names", http.StatusInternalServerError)
			return
		}

		// Prepare result slice
		var results []map[string]interface{}
		count := 0

		// Scan rows
		for rows.Next() {
			// Create a slice of interface{} to hold the values
			values := make([]interface{}, len(columns))
			valuePtrs := make([]interface{}, len(columns))
			for i := range columns {
				valuePtrs[i] = &values[i]
			}

			// Scan the row into the values
			if err := rows.Scan(valuePtrs...); err != nil {
				http.Error(w, fmt.Sprintf("Error scanning row: %v", err), http.StatusInternalServerError)
				return
			}

			// Create a map for this row
			row := make(map[string]interface{})
			for i, col := range columns {
				var v interface{}
				val := values[i]
				b, ok := val.([]byte)
				if ok {
					v = string(b)
				} else {
					v = val
				}
				row[col] = v
			}

			results = append(results, row)
			count++
		}

		// Calculate execution time
		executionTime := time.Since(startTime).Milliseconds()

		// Cache results
		cache.Set(cacheKey, results, count, executionTime, time.Duration(config.CacheDuration)*time.Second)

		// Return response
		response := SearchResponse{
			Results:   results,
			Count:    count,
			TimeMs:   executionTime,
			FromCache: false,
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	})

	// Start server
	addr := fmt.Sprintf("%s:%d", config.Host, config.Port)
	log.Printf("Starting server on %s", addr)
	if err := http.ListenAndServe(addr, nil); err != nil {
		log.Fatal(err)
	}
}

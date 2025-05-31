// search-service.go
package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"runtime"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

type Company struct {
	ID             string  `json:"id"`
	Name           string  `json:"name"`
	Status         string  `json:"status"`
	AddressLine1   string  `json:"address_line_1"`
	AddressLine2   string  `json:"address_line_2"`
	City           string  `json:"city"`
	Region         string  `json:"region"`
	PostalCode     string  `json:"postal_code"`
	Country        string  `json:"country"`
	Revenue        float64 `json:"revenue"`
	Employees      int     `json:"employees"`
	IncorporatedOn string  `json:"incorporated_on"`
	LastFiledOn    string  `json:"last_filed_on"`
}

// Simple cache implementation
type Cache struct {
	items map[string]cacheItem
	mutex sync.RWMutex
}

type cacheItem struct {
	data       []Company
	count      int
	timeMs     int64
	expiration time.Time
}

func NewCache() *Cache {
	return &Cache{
		items: make(map[string]cacheItem),
	}
}

func (c *Cache) Set(key string, data []Company, count int, timeMs int64, duration time.Duration) {
	c.mutex.Lock()
	defer c.mutex.Unlock()
	c.items[key] = cacheItem{
		data:       data,
		count:      count,
		timeMs:     timeMs,
		expiration: time.Now().Add(duration),
	}
}

func (c *Cache) Get(key string) ([]Company, int, int64, bool) {
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

func main() {
	// Simulation configuration - hardcoded values
	// Change these values to simulate different environments
	cpuCores := 1      // $5 VPS typically has 1 core
	maxConns := 10     // Limit DB connections
	dbLatency := 5     // Add artificial DB latency in ms
	resultLimit := 1000 // Maximum number of results to return

	// Set CPU cores
	runtime.GOMAXPROCS(cpuCores)

	// Print technical setup
	fmt.Println("=== Search Service Technical Setup ===")
	fmt.Printf("CPU Cores: %d\n", cpuCores)
	fmt.Printf("Max DB Connections: %d\n", maxConns)
	fmt.Printf("Simulated DB Latency: %dms\n", dbLatency)
	fmt.Printf("Result Limit: %d\n", resultLimit)
	fmt.Printf("Go Version: %s\n", runtime.Version())
	fmt.Printf("OS/Arch: %s/%s\n", runtime.GOOS, runtime.GOARCH)
	fmt.Println("=====================================")

	// Create cache with 5-minute expiration
	cache := NewCache()

	// Database connection
	dbUser := getEnv("DB_USERNAME", "root")
	dbPass := getEnv("DB_PASSWORD", "")
	dbHost := getEnv("DB_HOST", "127.0.0.1")
	dbPort := getEnv("DB_PORT", "3306")
	dbName := getEnv("DB_DATABASE", "lightning_search")

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true", dbUser, dbPass, dbHost, dbPort, dbName)
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Fatal(err)
	}
	defer db.Close()

	// Configure connection pool for low-end server
	db.SetMaxOpenConns(maxConns)
	db.SetMaxIdleConns(maxConns / 2)
	db.SetConnMaxLifetime(5 * time.Minute)

	// Test connection
	if err := db.Ping(); err != nil {
		log.Fatalf("Failed to connect to database: %v", err)
	}

	// Create prepared statements for different search types with dynamic limit
	nameStmt, err := db.Prepare(fmt.Sprintf(`
		SELECT
			company_id, name, status, address_line_1, address_line_2,
			city, region, postal_code, country, revenue,
			employees, incorporated_on, last_filed_on
		FROM search
		WHERE MATCH(name) AGAINST(? IN BOOLEAN MODE)
		LIMIT %d
	`, resultLimit))
	if err != nil {
		log.Fatalf("Failed to prepare name statement: %v", err)
	}
	defer nameStmt.Close()

	idStmt, err := db.Prepare(fmt.Sprintf(`
		SELECT
			company_id, name, status, address_line_1, address_line_2,
			city, region, postal_code, country, revenue,
			employees, incorporated_on, last_filed_on
		FROM search
		WHERE company_id LIKE ?
		LIMIT %d
	`, resultLimit))
	if err != nil {
		log.Fatalf("Failed to prepare id statement: %v", err)
	}
	defer idStmt.Close()

	cityStmt, err := db.Prepare(fmt.Sprintf(`
		SELECT
			company_id, name, status, address_line_1, address_line_2,
			city, region, postal_code, country, revenue,
			employees, incorporated_on, last_filed_on
		FROM search
		WHERE city LIKE ?
		LIMIT %d
	`, resultLimit))
	if err != nil {
		log.Fatalf("Failed to prepare city statement: %v", err)
	}
	defer cityStmt.Close()

	postalStmt, err := db.Prepare(fmt.Sprintf(`
		SELECT
			company_id, name, status, address_line_1, address_line_2,
			city, region, postal_code, country, revenue,
			employees, incorporated_on, last_filed_on
		FROM search
		WHERE postal_code LIKE ?
		LIMIT %d
	`, resultLimit))
	if err != nil {
		log.Fatalf("Failed to prepare postal statement: %v", err)
	}
	defer postalStmt.Close()

	// Define HTTP handler for search
	http.HandleFunc("/search", func(w http.ResponseWriter, r *http.Request) {
		// Set CORS headers for all responses
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		// Handle preflight requests
		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		startTime := time.Now()

		// Get query parameter
		query := r.URL.Query().Get("q")
		if len(query) < 3 {
			w.Header().Set("Content-Type", "application/json")
			json.NewEncoder(w).Encode(map[string]interface{}{
				"results": []Company{},
				"time_ms": 0,
				"count":   0,
			})
			return
		}

		// Check cache first
		if results, count, timeMs, found := cache.Get(query); found {
			log.Printf("Cache hit for query: %s (found %d results in %dms)", query, count, timeMs)
			w.Header().Set("Content-Type", "application/json")
			json.NewEncoder(w).Encode(map[string]interface{}{
				"results": results,
				"time_ms": timeMs,
				"count":   count,
				"cached":  true,
				"server_info": map[string]interface{}{
					"cpu_cores":    cpuCores,
					"max_db_conns": maxConns,
					"db_latency":   dbLatency,
					"result_limit": resultLimit,
					"go_version":   runtime.Version(),
					"os_arch":      fmt.Sprintf("%s/%s", runtime.GOOS, runtime.GOARCH),
				},
			})
			return
		}

		log.Printf("Search query: %s", query)

		// Simulate DB latency for a cheap VPS
		if dbLatency > 0 {
			time.Sleep(time.Duration(dbLatency) * time.Millisecond)
		}

		// Prepare search terms
		nameSearchTerm := query + "*" // For FULLTEXT search
		likeSearchTerm := query + "%" // For LIKE search (better performance than %query%)

		// Execute parallel searches
		var wg sync.WaitGroup
		var mutex sync.Mutex
		var results []Company

		// Search by name (FULLTEXT)
		wg.Add(1)
		go func() {
			defer wg.Done()

			// Simulate DB latency for a cheap VPS
			if dbLatency > 0 {
				time.Sleep(time.Duration(dbLatency) * time.Millisecond)
			}

			queryStart := time.Now()
			rows, err := nameStmt.Query(nameSearchTerm)
			if err != nil {
				log.Printf("Name search error: %v", err)
				return
			}
			defer rows.Close()

			var nameResults []Company
			for rows.Next() {
				var c Company
				err := rows.Scan(
					&c.ID, &c.Name, &c.Status, &c.AddressLine1, &c.AddressLine2,
					&c.City, &c.Region, &c.PostalCode, &c.Country, &c.Revenue,
					&c.Employees, &c.IncorporatedOn, &c.LastFiledOn,
				)
				if err != nil {
					log.Printf("Row scan error: %v", err)
					continue
				}
				nameResults = append(nameResults, c)
			}

			queryTime := time.Since(queryStart).Milliseconds()
			log.Printf("Name search found %d results in %dms", len(nameResults), queryTime)

			mutex.Lock()
			results = append(results, nameResults...)
			mutex.Unlock()
		}()

		// Search by ID
		wg.Add(1)
		go func() {
			defer wg.Done()

			// Simulate DB latency for a cheap VPS
			if dbLatency > 0 {
				time.Sleep(time.Duration(dbLatency) * time.Millisecond)
			}

			queryStart := time.Now()
			rows, err := idStmt.Query(likeSearchTerm)
			if err != nil {
				log.Printf("ID search error: %v", err)
				return
			}
			defer rows.Close()

			var idResults []Company
			for rows.Next() {
				var c Company
				err := rows.Scan(
					&c.ID, &c.Name, &c.Status, &c.AddressLine1, &c.AddressLine2,
					&c.City, &c.Region, &c.PostalCode, &c.Country, &c.Revenue,
					&c.Employees, &c.IncorporatedOn, &c.LastFiledOn,
				)
				if err != nil {
					log.Printf("Row scan error: %v", err)
					continue
				}
				idResults = append(idResults, c)
			}

			queryTime := time.Since(queryStart).Milliseconds()
			log.Printf("ID search found %d results in %dms", len(idResults), queryTime)

			mutex.Lock()
			results = append(results, idResults...)
			mutex.Unlock()
		}()

		// Wait for all searches to complete
		wg.Wait()

		// Deduplicate results based on ID
		seen := make(map[string]bool)
		uniqueResults := []Company{}
		for _, company := range results {
			if !seen[company.ID] {
				seen[company.ID] = true
				uniqueResults = append(uniqueResults, company)
			}
		}

		// Limit to configured result limit
		if len(uniqueResults) > resultLimit {
			uniqueResults = uniqueResults[:resultLimit]
		}

		// Calculate query time
		elapsed := time.Since(startTime).Milliseconds()

		log.Printf("Total: Found %d unique results in %dms", len(uniqueResults), elapsed)

		// Cache the results
		cache.Set(query, uniqueResults, len(uniqueResults), elapsed, 5*time.Minute)

		// Return JSON response
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]interface{}{
			"results": uniqueResults,
			"time_ms": elapsed,
			"count":   len(uniqueResults),
			"cached":  false,
			"server_info": map[string]interface{}{
				"cpu_cores":    cpuCores,
				"max_db_conns": maxConns,
				"db_latency":   dbLatency,
				"result_limit": resultLimit,
				"go_version":   runtime.Version(),
				"os_arch":      fmt.Sprintf("%s/%s", runtime.GOOS, runtime.GOARCH),
			},
		})
	})

	// Add a simple ping endpoint for testing
	http.HandleFunc("/ping", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]interface{}{
			"status": "ok",
			"time":   time.Now().String(),
			"server_info": map[string]interface{}{
				"cpu_cores":    cpuCores,
				"max_db_conns": maxConns,
				"db_latency":   dbLatency,
				"result_limit": resultLimit,
				"go_version":   runtime.Version(),
				"os_arch":      fmt.Sprintf("%s/%s", runtime.GOOS, runtime.GOARCH),
			},
		})
	})

	// Start server
	port := getEnv("PORT", "3001")
	fmt.Printf("Search service listening on port %s...\n", port)
	log.Fatal(http.ListenAndServe(":"+port, nil))
}

func getEnv(key, fallback string) string {
	if value, exists := os.LookupEnv(key); exists {
		return value
	}
	return fallback
}

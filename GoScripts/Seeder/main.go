package main

import (
	"database/sql"
	"fmt"
	"log"
	"os"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/brianvoe/gofakeit/v6"
	_ "github.com/go-sql-driver/mysql"
)

// Global variables for progress tracking
var (
	recordsInserted int64
	startTime       time.Time
)

func main() {
	// =====================================================================
	// CONFIGURATION SECTION
	// =====================================================================

	// Batch size is the most critical parameter for performance
	batchSize := 100000

	// How many batches to include in a single transaction before committing
	commitInterval := 8

	// Number of parallel workers (adjust based on CPU cores)
	numWorkers := runtime.NumCPU()

	// =====================================================================
	// MAIN PROGRAM
	// =====================================================================

	startTime = time.Now()

	// Parse command line arguments
	count := 100
	if len(os.Args) > 1 {
		if c, err := strconv.Atoi(os.Args[1]); err == nil && c > 0 {
			count = c
		}
	}

	// Get database connection from environment or use default
	dbUser := getEnv("DB_USERNAME", "root")
	dbPass := getEnv("DB_PASSWORD", "")
	dbHost := getEnv("DB_HOST", "127.0.0.1")
	dbPort := getEnv("DB_PORT", "3306")
	dbName := getEnv("DB_DATABASE", "lightning_search")

	// Configure MySQL connection string
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?maxAllowedPacket=0&interpolateParams=true",
		dbUser, dbPass, dbHost, dbPort, dbName)

	// Test database connection
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Fatal(err)
	}
	defer db.Close()

	if err := db.Ping(); err != nil {
		log.Fatalf("Failed to connect to database: %v", err)
	}

	// Optimize MySQL for bulk inserts
	fmt.Println("Optimizing MySQL settings for bulk insert...")
	optimizations := []string{
		"SET GLOBAL innodb_flush_log_at_trx_commit = 2",
		"SET autocommit=0",
		"SET unique_checks=0",
		"SET foreign_key_checks=0",
	}

	for _, opt := range optimizations {
		_, err = db.Exec(opt)
		if err != nil {
			fmt.Printf("Warning: Could not set %s: %v\n", opt, err)
		}
	}

	fmt.Printf("Using batch size: %d with %d workers for %d records\n",
		batchSize, numWorkers, count)

	// Start progress reporting in a separate goroutine
	stopProgress := make(chan bool)
	var wg sync.WaitGroup
	wg.Add(1)
	go reportProgress(count, stopProgress, &wg)

	// Divide work among workers
	recordsPerWorker := count / numWorkers
	var workerWg sync.WaitGroup

	for w := 0; w < numWorkers; w++ {
		workerWg.Add(1)
		startID := w * recordsPerWorker
		endID := (w + 1) * recordsPerWorker
		if w == numWorkers-1 {
			endID = count // Make sure the last worker gets any remainder
		}

		go func(workerID, start, end int) {
			defer workerWg.Done()
			seedWorker(workerID, start, end, batchSize, commitInterval, dsn)
		}(w, startID, endID)
	}

	// Wait for all workers to complete
	workerWg.Wait()

	// Stop progress reporting
	close(stopProgress)
	wg.Wait()

	// Restore database settings
	restoreSettings := []string{
		"SET autocommit=1",
		"SET unique_checks=1",
		"SET foreign_key_checks=1",
	}

	for _, opt := range restoreSettings {
		_, err = db.Exec(opt)
		if err != nil {
			fmt.Printf("Warning: Could not restore setting %s: %v\n", opt, err)
		}
	}

	totalTime := time.Since(startTime)
	fmt.Printf("\n✅ Seeded %d companies in %s (%.2f records/sec)\n",
		count, formatDuration(totalTime), float64(count)/totalTime.Seconds())
}

func seedWorker(workerID, start, end, batchSize, commitInterval int, dsn string) {
	// Each worker gets its own database connection
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Fatalf("Worker %d: Failed to connect to database: %v", workerID, err)
	}
	defer db.Close()

	db.SetMaxOpenConns(1)
	db.SetMaxIdleConns(1)

	// Start a transaction
	tx, err := db.Begin()
	if err != nil {
		log.Fatalf("Worker %d: Failed to begin transaction: %v", workerID, err)
	}

	batch := make([]string, 0, batchSize*13)
	const prefix = "GB"
	batchCounter := 0

	for i := start; i < end; i++ {
		// Increment number only, padded to 12 digits for 1B+ records
		numPart := fmt.Sprintf("%012d", i+1)
		companyID := prefix + numPart

		batch = append(batch,
			companyID,
			gofakeit.Company(),
			gofakeit.RandomString([]string{"active", "dissolved", "liquidation"}),
			gofakeit.Street(),
			gofakeit.Street()+" Apt "+strconv.Itoa(gofakeit.Number(1, 99)),
			gofakeit.City(),
			gofakeit.State(),
			gofakeit.Zip(),
			gofakeit.Country(),
			fmt.Sprintf("%.2f", gofakeit.Price(100000, 50000000)),
			strconv.Itoa(gofakeit.Number(1, 5000)),
			gofakeit.Date().Format("2006-01-02"),
			gofakeit.Date().Format("2006-01-02"),
		)

		if len(batch)/13 >= batchSize || i+1 == end {
			rowsCount := len(batch) / 13
			values := make([]string, rowsCount)
			for j := 0; j < rowsCount; j++ {
				values[j] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
			}

			query := "INSERT INTO search (company_id, name, status, address_line_1, address_line_2, city, region, postal_code, country, revenue, employees, incorporated_on, last_filed_on, created_at, updated_at) VALUES " + strings.Join(values, ",")

			args := make([]any, len(batch))
			for k, v := range batch {
				args[k] = v
			}

			_, err := tx.Exec(query, args...)
			if err != nil {
				tx.Rollback()
				log.Fatalf("Worker %d: Insert failed: %v", workerID, err)
			}

			// Update global counter
			atomic.AddInt64(&recordsInserted, int64(rowsCount))

			batch = batch[:0]
			batchCounter++

			// Commit periodically to avoid transaction getting too large
			if batchCounter >= commitInterval || i+1 == end {
				if err := tx.Commit(); err != nil {
					log.Fatalf("Worker %d: Commit failed: %v", workerID, err)
				}
				tx, err = db.Begin()
				if err != nil {
					log.Fatalf("Worker %d: Failed to begin new transaction: %v", workerID, err)
				}
				batchCounter = 0
			}
		}
	}
}

func reportProgress(totalCount int, stop chan bool, wg *sync.WaitGroup) {
	defer wg.Done()

	lastProgressUpdate := time.Now()
	ticker := time.NewTicker(1 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ticker.C:
			current := atomic.LoadInt64(&recordsInserted)
			elapsed := time.Since(startTime)
			rate := float64(current) / elapsed.Seconds()

			if time.Since(lastProgressUpdate) > time.Second {
				remaining := ""
				if current < int64(totalCount) {
					remainingTime := time.Duration(float64(totalCount-int(current))/rate) * time.Second
					remaining = fmt.Sprintf(", ETA: %s", formatDuration(remainingTime))
				}

				fmt.Printf("\rProgress: %d / %d companies seeded (%.2f%%, %.2f records/sec%s)",
					current, totalCount, float64(current)*100/float64(totalCount), rate, remaining)

				lastProgressUpdate = time.Now()
			}

		case <-stop:
			// Final progress update
			current := atomic.LoadInt64(&recordsInserted)
			elapsed := time.Since(startTime)
			rate := float64(current) / elapsed.Seconds()

			fmt.Printf("\rProgress: %d / %d companies seeded (%.2f%%, %.2f records/sec)",
				current, totalCount, float64(current)*100/float64(totalCount), rate)

			return
		}
	}
}

func getEnv(key, fallback string) string {
	if value, exists := os.LookupEnv(key); exists {
		return value
	}
	return fallback
}

func formatDuration(d time.Duration) string {
	d = d.Round(time.Second)
	h := d / time.Hour
	d -= h * time.Hour
	m := d / time.Minute
	d -= m * time.Minute
	s := d / time.Second

	if h > 0 {
		return fmt.Sprintf("%dh %dm %ds", h, m, s)
	} else if m > 0 {
		return fmt.Sprintf("%dm %ds", m, s)
	}
	return fmt.Sprintf("%ds", s)
}

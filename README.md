# Email Extraction System

A high-performance PHP email extraction system with parallel worker processing and advanced database connection management.

## Features

- **Parallel Worker Execution**: Spawn up to 1000 workers for maximum throughput
- **Database Connection Pooling**: Intelligent connection management with retry logic
- **Exponential Backoff**: Automatic retry on connection failures
- **Batch Operations**: Optimized bulk inserts for high performance
- **Email Filtering**: Support for Gmail, Yahoo, and business emails
- **Country Targeting**: Extract emails by geographic region
- **BloomFilter Deduplication**: Efficient duplicate detection

## Database Configuration

### Recommended MySQL Settings for High Concurrency

To handle 500-1000 concurrent workers, update your MySQL configuration (`my.cnf` or `my.ini`):

```ini
[mysqld]
# Connection settings
max_connections = 1000          # Allow more concurrent connections (default: 151)
max_connect_errors = 100000     # Prevent blocking after failed attempts
connect_timeout = 10            # Connection timeout in seconds

# Performance optimizations
table_open_cache = 4000         # Cache for table file descriptors
innodb_buffer_pool_size = 2G    # InnoDB buffer pool (adjust to 70-80% of RAM)
innodb_log_file_size = 512M     # Transaction log size

# Thread settings
thread_cache_size = 100         # Cache threads for connection reuse
thread_stack = 256K             # Stack size per thread

# Query cache (optional, deprecated in MySQL 8.0)
query_cache_size = 0            # Disable if using MySQL 8.0+
query_cache_type = 0
```

### Connection Management Features

The system includes advanced connection management to handle "Too many connections" errors:

1. **Automatic Retry**: Up to 5 retry attempts with exponential backoff
2. **Connection Pooling**: Singleton pattern reuses connections across requests
3. **Connection Cleanup**: Automatic closing after batch operations
4. **Health Checks**: Automatic reconnection on stale connections
5. **Jitter**: Random delay added to prevent thundering herd problem

### Error Recovery

When encountering database connection issues:

- **Error 1040 (Too many connections)**: System automatically retries with increasing delays
- **Connection lost**: Automatic reconnection on next query
- **Transaction failures**: Proper rollback and retry logic

## Scaling Guidelines

### Email Volume vs Workers

**NEW**: System now uses **staggered worker spawning** with rate limiting to prevent overwhelming MySQL!

The system automatically calculates optimal worker count with intelligent connection rate limiting:

- **1,000 emails** → 100 workers (10 emails/worker)
- **10,000 emails** → 1,000 workers (10 emails/worker)
- **100,000 emails** → 1,000 workers (100 emails/worker, capped at AUTO_MAX_WORKERS)

### How Connection Rate Limiting Works

Workers are spawned with a **~6.6ms delay** between each spawn, which equals approximately **151 workers per second**. This matches MySQL's default `max_connections` limit and prevents the "Too many connections" error.

**Example**: For 10,000 emails:
- Spawns: 1,000 workers
- Spawn time: ~6.6 seconds
- Rate: ~151 workers/second
- Result: ✅ No connection exhaustion!

### Performance Expectations

With rate-limited spawning (default settings):
- **100 Workers**: ~200-500 emails/second
- **1,000 Workers**: ~2,000-5,000 emails/second

**Key Benefit**: More workers = faster processing, without database connection issues!

Actual performance depends on:
- Database server resources
- Network latency
- API rate limits
- Deep scraping settings
- MySQL max_connections configuration

## Troubleshooting

### "Too many connections" Error

**SOLVED!** The system now uses **connection rate limiting** to prevent this error.

Workers are spawned with staggered delays (~6.6ms between each), limiting the connection rate to ~151 workers/second. This matches MySQL's default `max_connections` and prevents overwhelming the database.

**If you still see this error:**

1. **Check your MySQL max_connections**:
   ```sql
   SHOW VARIABLES LIKE 'max_connections';
   ```
   
2. **If it's below 151**, increase it:
   ```sql
   SET GLOBAL max_connections = 200;
   ```

3. **For very large jobs**, increase further:
   ```sql
   SET GLOBAL max_connections = 500;  -- For 1000+ workers
   ```

4. **Make it permanent** (edit MySQL config):
   ```ini
   [mysqld]
   max_connections = 500
   ```
   Then restart MySQL.

### Adjusting Worker Settings

The defaults work for most use cases:
```php
// Current defaults in Worker class
private const AUTO_MAX_WORKERS = 1000;          // Max workers to spawn
private const WORKERS_PER_1000_EMAILS = 100;    // 100 workers per 1000 emails
private const WORKER_SPAWN_DELAY_MS = 6.6;      // ~151 workers/second
```

**To spawn workers faster** (if MySQL max_connections is high):
```php
private const WORKER_SPAWN_DELAY_MS = 3.3;      // ~303 workers/second
```

**To spawn workers slower** (if MySQL max_connections is low):
```php
private const WORKER_SPAWN_DELAY_MS = 13.2;     // ~76 workers/second
```

2. **Check current connections**:
   ```sql
   SHOW STATUS WHERE `variable_name` = 'Threads_connected';
   SHOW PROCESSLIST;
   ```

3. **Verify configuration**:
   ```sql
   SHOW VARIABLES LIKE 'max_connections';
   ```

### High Memory Usage

If workers consume too much memory:

1. Reduce `BULK_INSERT_BATCH_SIZE` (default: 1000)
2. Lower `BloomFilter` cache size
3. Increase `php.ini` memory_limit if needed

### Worker Crashes

Check logs in `/php_errors.log` for:
- Database connection timeouts
- Memory exhaustion
- PHP fatal errors

## Installation

1. Configure database settings in `app.php`
2. Set up MySQL with recommended settings above
3. Ensure PHP 8.0+ is installed
4. Run the application: `php app.php`

## Technical Details

### Connection Retry Strategy

Exponential backoff with 30% random jitter (increased delays for "Too many connections" errors):

```
Normal connection errors:
Attempt 1: Wait 500ms + jitter (0-150ms)     = 500-650ms
Attempt 2: Wait 1000ms + jitter (0-300ms)    = 1000-1300ms
Attempt 3: Wait 2000ms + jitter (0-600ms)    = 2000-2600ms
Attempt 4: Wait 4000ms + jitter (0-1200ms)   = 4000-5200ms
Attempt 5: Wait 8000ms + jitter (0-2400ms)   = 8000-10400ms (capped at 10s)

"Too many connections" errors (doubled delays):
Attempt 1: Wait 1000ms + jitter              = 1000-1300ms
Attempt 2: Wait 2000ms + jitter              = 2000-2600ms
Attempt 3: Wait 4000ms + jitter              = 4000-5200ms
Attempt 4: Wait 8000ms + jitter              = 8000-10400ms (capped at 10s)
Attempt 5: Wait 10000ms + jitter             = 10000-13000ms (capped at 10s)
```

Maximum retry delay is capped at 10 seconds. The 30% jitter prevents thundering herd problem where all workers retry simultaneously.

**Note**: Longer delays give other workers time to finish and release connections.

### Worker Spawn Rate Limiting

**NEW**: Prevents "Too many connections" by staggering worker spawns:

```
Default: 6.6ms delay between spawns
Rate: ~151 workers/second
Matches: MySQL default max_connections (151)

For 1,000 workers:
- Spawn time: ~6.6 seconds
- Connection rate: Never exceeds 151/second
- Result: No database overload!
```

This rate limiting happens at spawn time, so workers can all run in parallel once started, but they don't all try to connect to the database at the exact same moment.

### Batch Processing

- Emails are inserted in batches of 1000
- BloomFilter checks in batches of 1000
- Parallel URL scraping in batches of 100
- Workers spawned with rate limiting (~151/second)

## License

MIT
# MySQL Performance Tuning for High-Concurrency Email Extraction

This guide helps you configure MySQL to handle 500-1000 concurrent workers without encountering "Too many connections" errors.

## Quick Start

For most users, these settings will work:

```sql
-- Increase max connections (requires restart)
SET GLOBAL max_connections = 1000;

-- Prevent connection blocking
SET GLOBAL max_connect_errors = 100000;

-- Check current settings
SHOW VARIABLES LIKE 'max_connections';
SHOW STATUS WHERE `variable_name` = 'Threads_connected';
```

## Detailed Configuration

### 1. Edit MySQL Configuration File

Location depends on your system:
- Linux: `/etc/mysql/my.cnf` or `/etc/my.cnf`
- Windows: `C:\ProgramData\MySQL\MySQL Server X.X\my.ini`
- macOS: `/usr/local/etc/my.cnf`

### 2. Add/Update These Settings

```ini
[mysqld]
# ============================================================================
# CONNECTION SETTINGS
# ============================================================================

# Maximum number of concurrent connections
# Default: 151
# For 1000 workers: Set to 1000-1500 (includes system connections)
max_connections = 1000

# Maximum errors before blocking a host
# Increase to prevent blocking after connection failures
max_connect_errors = 100000

# Connection timeout (seconds)
# How long to wait for initial connection
connect_timeout = 10

# Wait timeout (seconds)
# How long to keep idle connections
wait_timeout = 300
interactive_timeout = 300

# ============================================================================
# PERFORMANCE TUNING
# ============================================================================

# InnoDB Buffer Pool
# Set to 70-80% of available RAM for dedicated MySQL server
# Example: For 8GB RAM server: 5G-6G
innodb_buffer_pool_size = 2G

# InnoDB Log Files
# Larger = better write performance, slower recovery
innodb_log_file_size = 512M
innodb_log_buffer_size = 16M

# Table Cache
# Number of open tables to cache
table_open_cache = 4000
table_definition_cache = 2000

# Thread Settings
# Cache threads to avoid creation overhead
thread_cache_size = 100
thread_stack = 256K

# Query Cache (Deprecated in MySQL 8.0+)
# Disable if using MySQL 8.0 or higher
query_cache_size = 0
query_cache_type = 0

# ============================================================================
# MEMORY OPTIMIZATION
# ============================================================================

# Per-connection memory allocation
# Be careful: max_connections * these values = total memory
sort_buffer_size = 2M          # For ORDER BY operations
join_buffer_size = 2M          # For JOIN operations
read_buffer_size = 1M          # Sequential scan buffer
read_rnd_buffer_size = 1M      # Random read buffer

# ============================================================================
# TEMPORARY TABLES
# ============================================================================

# Temporary table settings
tmp_table_size = 64M
max_heap_table_size = 64M

# ============================================================================
# INNODB SPECIFIC
# ============================================================================

# File per table (recommended)
innodb_file_per_table = 1

# Flush method (Linux only)
# O_DIRECT reduces double buffering
innodb_flush_method = O_DIRECT

# IO capacity
# Adjust based on disk performance
# SSD: 2000-5000, HDD: 200-500
innodb_io_capacity = 2000
innodb_io_capacity_max = 4000

# Buffer pool instances
# Use 1 instance per GB of buffer pool size (max 64)
innodb_buffer_pool_instances = 2
```

### 3. Restart MySQL

After editing the configuration file:

```bash
# Linux (systemd)
sudo systemctl restart mysql

# Linux (init.d)
sudo service mysql restart

# macOS
brew services restart mysql

# Windows (as Administrator)
net stop MySQL
net start MySQL
```

## Verify Configuration

```sql
-- Check max_connections
SHOW VARIABLES LIKE 'max_connections';

-- Check current connection count
SHOW STATUS WHERE `variable_name` = 'Threads_connected';

-- Check max connections ever used
SHOW STATUS WHERE `variable_name` = 'Max_used_connections';

-- List all connections
SHOW PROCESSLIST;

-- Check buffer pool size
SHOW VARIABLES LIKE 'innodb_buffer_pool_size';
```

## Monitoring Queries

### Connection Usage

```sql
-- Current connections vs max
SELECT 
    (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Threads_connected') as current_connections,
    (SELECT VARIABLE_VALUE FROM performance_schema.global_variables WHERE VARIABLE_NAME='max_connections') as max_connections,
    ROUND(
        ((SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Threads_connected') / 
         (SELECT VARIABLE_VALUE FROM performance_schema.global_variables WHERE VARIABLE_NAME='max_connections')) * 100, 2
    ) as usage_percent;
```

### Connection History

```sql
-- Peak connection usage
SELECT 
    VARIABLE_VALUE as max_used_connections 
FROM performance_schema.global_status 
WHERE VARIABLE_NAME = 'Max_used_connections';

-- Connection errors
SELECT 
    VARIABLE_VALUE as connection_errors 
FROM performance_schema.global_status 
WHERE VARIABLE_NAME = 'Connection_errors_max_connections';
```

### Active Queries

```sql
-- Find long-running queries
SELECT 
    id, 
    user, 
    host, 
    db, 
    command, 
    time, 
    state, 
    info 
FROM information_schema.processlist 
WHERE time > 10 
ORDER BY time DESC;
```

## Memory Calculation

Estimate total memory usage:

```
Per-connection memory = 
    sort_buffer_size + 
    join_buffer_size + 
    read_buffer_size + 
    read_rnd_buffer_size +
    thread_stack

Example:
    2M + 2M + 1M + 1M + 0.25M = ~6.25MB per connection

For 1000 connections:
    1000 * 6.25MB = 6.25GB just for connections

Total MySQL memory =
    innodb_buffer_pool_size + 
    (max_connections * per-connection memory) +
    key_buffer_size +
    query_cache_size
```

## Troubleshooting

### Error: "Too many connections"

1. **Immediate fix** (temporary):
   ```sql
   SET GLOBAL max_connections = 1000;
   ```

2. **Check who's using connections**:
   ```sql
   SELECT user, host, COUNT(*) as connections
   FROM information_schema.processlist
   GROUP BY user, host
   ORDER BY connections DESC;
   ```

3. **Kill stuck connections**:
   ```sql
   -- View processes
   SHOW PROCESSLIST;
   
   -- Kill specific process
   KILL <process_id>;
   ```

### Error: "Can't connect to MySQL server"

1. Check if MySQL is running:
   ```bash
   sudo systemctl status mysql
   ```

2. Check connection limit per host:
   ```sql
   SHOW VARIABLES LIKE 'max_connect_errors';
   SET GLOBAL max_connect_errors = 100000;
   ```

3. Check host blocking:
   ```sql
   -- Flush host cache to unblock
   FLUSH HOSTS;
   ```

### Performance Issues

1. **Enable slow query log**:
   ```sql
   SET GLOBAL slow_query_log = 'ON';
   SET GLOBAL long_query_time = 2;
   SET GLOBAL slow_query_log_file = '/var/log/mysql/slow.log';
   ```

2. **Analyze table statistics**:
   ```sql
   SHOW TABLE STATUS;
   ANALYZE TABLE emails, jobs, workers;
   ```

3. **Check for missing indexes**:
   ```sql
   -- This query finds tables without indexes
   SELECT 
       table_name,
       table_rows
   FROM information_schema.tables
   WHERE table_schema = 'your_database_name'
       AND table_type = 'BASE TABLE';
   ```

## Cloud Provider Specific Settings

### AWS RDS

Edit parameter group:
- `max_connections`: Set to {DBInstanceClassMemory/12582880}
- Default: ~{instance_memory_MB / 12}
- For db.t3.medium (4GB): ~340 connections

### Google Cloud SQL

- Edit instance configuration
- Set max_connections in flags
- Recommended: Use connection pooler (Cloud SQL Proxy)

### Azure MySQL

- Navigate to Server parameters
- Increase max_connections
- Note: Limited by pricing tier

## Best Practices

1. **Use Connection Pooling**: The application implements this via singleton pattern
2. **Close Connections**: Workers close connections after batch operations
3. **Monitor Usage**: Set up alerts at 80% of max_connections
4. **Regular Maintenance**: Run OPTIMIZE TABLE monthly
5. **Backup Settings**: Document all changes to configuration
6. **Test Before Production**: Test with expected load

## Application-Level Optimizations

The email extraction system implements these strategies:

1. **Automatic Retry**: 5 attempts with exponential backoff
2. **Connection Reuse**: Singleton PDO instance per worker
3. **Batch Operations**: Insert 1000 emails at a time
4. **Connection Cleanup**: Close after each page of results
5. **Health Checks**: Verify connection before each query
6. **Error Recovery**: Graceful handling of connection failures

## Additional Resources

- [MySQL Connection Handling](https://dev.mysql.com/doc/refman/8.0/en/connection-management.html)
- [InnoDB Buffer Pool](https://dev.mysql.com/doc/refman/8.0/en/innodb-buffer-pool.html)
- [MySQL Performance Tuning](https://dev.mysql.com/doc/refman/8.0/en/optimization.html)
- [Connection Pool Best Practices](https://dev.mysql.com/doc/connector-j/8.0/en/connector-j-usagenotes-j2ee-concepts-connection-pooling.html)

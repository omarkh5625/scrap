# Scrap - PHP Email Extraction System

A powerful PHP 8.0+ email extraction system with advanced worker management and real-time monitoring.

## Features

- 🚀 **Setup Wizard** - Easy installation with database configuration
- 🔐 **Authentication System** - Secure user management
- 📊 **Dashboard** - Real-time statistics and job monitoring
- 📧 **Email Extraction** - Intelligent email harvesting from search results
- 👥 **Worker Management** - Advanced parallel processing with real-time status
- 🔄 **Async Background Workers** - Support for 1-1000 parallel workers
- 🎯 **Email Type Filtering** - Gmail, Yahoo, Business domains
- 🌍 **Country Targeting** - Geographic search result filtering
- 📤 **Results Export** - CSV and JSON export formats
- 🔍 **Google Serper.dev Integration** - Professional search API
- 🛡️ **BloomFilter Deduplication** - Efficient duplicate prevention
- ⚡ **CLI Worker Support** - Command-line worker processes
- 🎨 **Modern UI** - Clean, responsive interface

## Worker Status Monitoring

### Enhanced Worker Management

The system now includes comprehensive worker monitoring inspired by professional mail sender systems:

#### Real-Time Statistics
- **Active Workers** - Count of currently running workers
- **Idle Workers** - Count of workers waiting for jobs
- **Pages Processed** - Total number of search result pages scraped
- **Emails Extracted** - Total emails found by all workers
- **Average Runtime** - Mean runtime across all workers
- **Performance Metrics** - Individual worker statistics

#### Worker Details
Each worker tracks:
- Current status (idle/running/stopped)
- Current job ID (if processing)
- Pages processed count
- Emails extracted count
- Total runtime
- Last heartbeat timestamp

### Accessing Worker Status

Navigate to **Workers** page from the sidebar to view:
- Live worker statistics dashboard
- Active worker table with real-time updates
- Performance metrics
- Status indicators

The page auto-refreshes every 3 seconds to show current system state.

## Installation

1. Upload `app.php` to your web server
2. Navigate to the file in your browser
3. Follow the setup wizard:
   - Enter database credentials
   - Create admin account
   - Complete installation

## Usage

### Creating a Job

1. Go to **New Email Job** page
2. Enter search query (e.g., "real estate agents california")
3. Provide Serper.dev API key
4. Configure options:
   - Maximum emails to extract
   - Country target (optional)
   - Email type filter (all/gmail/yahoo/business)
   - Number of parallel workers (1-1000)
5. Click **Start Processing Immediately**

### Monitoring Workers

**Option 1: Web Interface**
- Go to **Workers** page to see real-time status
- View statistics: active workers, pages processed, emails extracted
- Monitor individual worker performance

**Option 2: CLI Workers**
```bash
php app.php worker-name
```

Example:
```bash
php app.php worker-1
php app.php worker-2
php app.php worker-3
```

### Viewing Results

1. Go to **Dashboard** to see all jobs
2. Click **View** on a job to see extracted emails
3. Export results in CSV or JSON format

## Configuration

### System Settings

Access **Settings** page to configure:
- Default API key
- Default max results
- Rate limiting (seconds between requests)
- Deep scraping (fetch full page content)
- Deep scraping threshold

### Worker Configuration

Workers automatically:
- Register on startup
- Track performance metrics
- Update heartbeat every 3 seconds
- Process jobs with dynamic task assignment
- Report statistics in real-time

## Technical Details

### Database Tables

- `users` - User accounts
- `jobs` - Extraction jobs
- `emails` - Extracted email results
- `workers` - Worker registration and statistics
- `bloomfilter` - Deduplication hash storage
- `settings` - System configuration

### Worker Statistics Schema

```sql
CREATE TABLE workers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_name VARCHAR(100) UNIQUE NOT NULL,
    status ENUM('idle', 'running', 'stopped'),
    current_job_id INT NULL,
    last_heartbeat TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    pages_processed INT DEFAULT 0,
    emails_extracted INT DEFAULT 0,
    runtime_seconds INT DEFAULT 0,
    INDEX idx_status (status)
);
```

### API Endpoints

- `?page=api&action=stats` - Job statistics
- `?page=api&action=workers` - Worker list
- `?page=api&action=worker-stats` - Worker statistics
- `?page=api&action=jobs` - Job list

## Requirements

- PHP 8.0 or higher
- MySQL 5.7 or higher
- cURL extension
- PDO MySQL extension
- Serper.dev API key

## Performance

- Supports 1-1000 parallel workers
- Automatic load distribution
- Real-time progress tracking
- Efficient memory usage with BloomFilter
- Rate limiting to prevent API throttling
- Background processing with instant UI response

## Security

- Password hashing with bcrypt
- SQL injection prevention with PDO
- CSRF protection
- Session management
- Input validation and sanitization

## Support

For issues or questions, please refer to the repository documentation or create an issue.

## License

See repository for license information.

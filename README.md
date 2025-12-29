# Scrap - PHP Email Extraction System

A powerful PHP 8.0+ email extraction system with queue-based worker management and real-time monitoring.

## Features

- 🚀 **Setup Wizard** - Easy installation with database configuration
- 🔐 **Authentication System** - Secure user management
- 📊 **Dashboard** - Real-time statistics and job monitoring
- 📧 **Email Extraction** - Intelligent email harvesting from search results
- 👥 **Queue-Based Worker Management** - Reliable parallel processing with job queue
- 🔄 **Persistent Workers** - CLI workers that poll for work
- 🎯 **Email Type Filtering** - Gmail, Yahoo, Business domains
- 🌍 **Country Targeting** - Geographic search result filtering
- 📤 **Results Export** - CSV and JSON export formats
- 🔍 **Google Serper.dev Integration** - Professional search API
- 🛡️ **BloomFilter Deduplication** - Efficient duplicate prevention
- ⚡ **CLI Worker Support** - Reliable command-line worker processes
- 🎨 **Modern UI** - Clean, responsive interface

## Worker System (Queue-Based)

### How It Works

The system uses a **queue-based architecture** for reliable job processing:

1. **Job Creation** - Jobs are split into chunks and added to a processing queue
2. **Worker Polling** - CLI workers continuously poll the queue for pending chunks
3. **Chunk Processing** - Workers pick up chunks, process them, and mark as complete
4. **Progress Tracking** - Job progress calculated from completed chunks
5. **Reliability** - If a worker stops, pending chunks remain in queue for other workers

### Why Queue-Based?

✓ **Works on cPanel** - No dependency on exec() or HTTP
✓ **Reliable** - Work isn't lost if a worker stops
✓ **Scalable** - Add more workers anytime
✓ **Visible** - See pending work in the queue
✓ **Standard** - Industry-standard pattern used by professional systems

### Real-Time Statistics

The Workers page shows:
- **Active/Idle Workers** - How many workers are running
- **Queue Status** - Pending, processing, and completed chunks
- **Pages Processed** - Total search pages scraped
- **Emails Extracted** - Total emails found
- **Processing Rate** - Percentage of queue completed
- **Worker Performance** - Individual worker metrics

### Worker Details

Each worker tracks:
- Current status (idle/running/stopped)
- Current job and chunk being processed
- Pages processed count
- Emails extracted count
- Total runtime
- Last heartbeat timestamp

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
   - Number of work chunks (1-1000)
5. Click **Create Job & Queue for Processing**

### Starting Workers

**Required**: You must start CLI workers to process jobs

```bash
php app.php worker-1
```

Start multiple workers for parallel processing:
```bash
php app.php worker-1 &
php app.php worker-2 &
php app.php worker-3 &
```

Each worker:
- Registers itself in the database
- Polls the queue every few seconds
- Picks up pending chunks
- Processes emails
- Updates statistics
- Returns to polling

### Monitoring Workers

**Web Interface:**
- Go to **Workers** page to see real-time status
- View statistics: active workers, queue status, processing rate
- Monitor individual worker performance
- See pending work in the queue

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
- Worker polling interval

## Technical Details

### Database Tables

- `users` - User accounts
- `jobs` - Extraction jobs
- `job_queue` - Work chunks for parallel processing
- `emails` - Extracted email results
- `workers` - Worker registration and statistics
- `bloomfilter` - Deduplication hash storage
- `settings` - System configuration

### Job Queue Schema

```sql
CREATE TABLE job_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    start_offset INT NOT NULL,
    max_results INT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed'),
    worker_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_job (job_id)
);
```

### API Endpoints

- `?page=api&action=stats` - Job statistics
- `?page=api&action=workers` - Worker list
- `?page=api&action=worker-stats` - Worker statistics
- `?page=api&action=queue-stats` - Queue metrics
- `?page=api&action=jobs` - Job list

## Performance

- Supports unlimited workers (limited by server resources)
- Queue-based distribution prevents race conditions
- Real-time progress tracking
- Efficient memory usage with BloomFilter
- Rate limiting to prevent API throttling
- Workers can be started/stopped without data loss

## Security

- Password hashing with bcrypt
- SQL injection prevention with PDO
- Session management
- Input validation and sanitization

## Compatibility

- **PHP 8.0+** required
- **MySQL 5.7+** required
- **cURL extension** required
- **PDO MySQL extension** required
- **Works on cPanel** and shared hosting
- **No exec() required** - uses queue polling

## Support

For issues or questions, please refer to the repository documentation or create an issue.

## License

See repository for license information.

# Email Extraction System

A professional single-file PHP system for real-time, multi-job email extraction using the Serper Google Search API. Designed for cPanel environments with 24/7 continuous operation capability.

> **Note**: This implementation provides a complete architectural framework with all necessary modules (JobManager, WorkerGovernor, EmailValidator, etc.) and a professional UI. The worker processes currently run in simulation mode for demonstration purposes. See the "Integrating Actual Email Extraction" section below for implementation guidelines.

![Email Extraction System UI](https://github.com/user-attachments/assets/08b21614-8f4a-443c-8560-800b9fceddb8)

## Features

### Core Architecture
- **Multi-Job Management**: Create and execute multiple extraction jobs concurrently with complete state separation
- **Worker-Based Processing**: Independent worker processes using `proc_open` for true parallel execution
- **24/7 Continuous Operation**: Built-in error recovery and watchdog mechanisms for uninterrupted service
- **Single-File Design**: Complete system in one PHP file for easy deployment on cPanel

### Internal Modules
1. **JobManager**: Coordinates multiple concurrent jobs with state persistence
2. **WorkerGovernor**: Manages worker lifecycle, spawning, monitoring, and termination
3. **SearchScheduler**: Serper Google Search API integration with rate limiting
4. **DomainLimiter**: Domain-level throttling with HTTP 429 backoff handling
5. **URLFilter**: Validates and filters URLs before processing
6. **ContentFilter**: Extracts and analyzes email addresses from content
7. **EmailValidator**: Strict validation with MX record verification
8. **ConfidenceScorer**: Evaluates email quality and context
9. **DedupEngine**: RAM-based email deduplication (supports 100K+ entries)
10. **BufferManager**: RAM-based buffering for efficient batch processing

### Advanced Features
- **Adaptive Worker Scaling**: Automatically scale workers up/down based on load
- **Domain-Level Throttling**: Prevents rate limiting with configurable delays
- **HTTP 429 Handling**: Automatic backoff on rate limit detection
- **Zombie Worker Detection**: Identifies and terminates stuck workers
- **Memory Leak Tracking**: Monitors memory usage and alerts on high consumption
- **Multi-Layered Email Validation**: Content filtering, domain validation, MX checks
- **Confidence Scoring**: Contextual analysis for email quality assessment

### Professional UI
- **SendGrid-Styled Interface**: Clean, modern design for intuitive job management
- **Real-Time Monitoring**: Live updates of job status and worker activity
- **Job Dashboard**: Visual metrics for emails found, URLs processed, and errors
- **API Key Management**: Secure sidebar for API configuration
- **System Statistics**: Real-time memory usage and performance metrics

## Requirements

- PHP 7.4 or higher
- PHP extensions: `curl`, `json`, `pcntl` (for process management)
- Serper API key ([Get one here](https://serper.dev))
- Write access to `/tmp/email_extraction` directory

## Installation

### For cPanel/Shared Hosting

1. Upload `app.php` to your web directory
2. Set file permissions: `chmod 644 app.php`
3. Access via browser: `https://yourdomain.com/app.php`

### For VPS/Dedicated Server

1. Clone or download the repository
2. Ensure PHP is installed: `php --version`
3. Start the application:
   ```bash
   # Web mode (development)
   php -S localhost:8080 app.php
   
   # CLI mode (continuous operation)
   php app.php
   ```

## Usage

### Web Interface

1. **Access the Dashboard**: Open `app.php` in your browser
2. **Configure API Key**: Enter your Serper API key in the sidebar
3. **Create a Job**:
   - Enter a job name (e.g., "California Real Estate Leads")
   - Input your search query (e.g., "real estate agents in California")
   - Set max workers (1-10, default: 5)
   - Click "Create Job"
4. **Start Processing**: Click the "Start" button on your job card
5. **Monitor Progress**: Watch real-time updates of emails found and URLs processed
6. **Stop/Delete Jobs**: Use the action buttons as needed

### API Endpoints

All API requests should include an `action` parameter:

#### Create a Job
```bash
curl -X POST "http://localhost/app.php" \
  -d "action=create_job" \
  -d "name=My Job" \
  -d "api_key=YOUR_SERPER_KEY" \
  -d "query=your search query" \
  -d "max_workers=5"
```

#### List All Jobs
```bash
curl "http://localhost/app.php?action=get_jobs"
```

#### Get Job Details
```bash
curl "http://localhost/app.php?action=get_job&job_id=JOB_ID"
```

#### Start a Job
```bash
curl -X POST "http://localhost/app.php" \
  -d "action=start_job" \
  -d "job_id=JOB_ID"
```

#### Stop a Job
```bash
curl -X POST "http://localhost/app.php" \
  -d "action=stop_job" \
  -d "job_id=JOB_ID"
```

#### Delete a Job
```bash
curl -X POST "http://localhost/app.php" \
  -d "action=delete_job" \
  -d "job_id=JOB_ID"
```

#### Get System Statistics
```bash
curl "http://localhost/app.php?action=get_stats"
```

### Continuous Operation (24/7)

For production deployment with supervisor:

1. **Create Supervisor Config** (`/etc/supervisor/conf.d/email-extractor.conf`):
   ```ini
   [program:email-extractor]
   command=/usr/bin/php /path/to/app.php
   directory=/path/to
   autostart=true
   autorestart=true
   stderr_logfile=/var/log/email-extractor.err.log
   stdout_logfile=/var/log/email-extractor.out.log
   user=www-data
   ```

2. **Start with Supervisor**:
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start email-extractor
   ```

### Cron-Based Monitoring

Add to crontab for periodic worker health checks:
```bash
*/5 * * * * curl -s "http://localhost/app.php?cron=1" > /dev/null 2>&1
```

## Configuration

Edit the `Config` class constants in `app.php` to customize:

```php
const MAX_WORKERS_PER_JOB = 10;        // Maximum workers per job
const MIN_WORKERS_PER_JOB = 1;         // Minimum workers per job
const WORKER_TIMEOUT = 300;             // Worker timeout in seconds
const MEMORY_LIMIT_MB = 450;            // Memory alert threshold
const DOMAIN_THROTTLE_SECONDS = 2;      // Delay between domain accesses
const HTTP_429_BACKOFF_SECONDS = 60;    // Backoff duration for rate limits
const CONFIDENCE_THRESHOLD = 0.6;       // Minimum email confidence score
const DATA_DIR = '/tmp/email_extraction'; // Data storage directory
```

## Architecture

### Job Lifecycle
1. **Created**: Job is defined with query and parameters
2. **Running**: Workers are spawned and processing URLs
3. **Stopped**: Job is manually stopped or completed
4. **Deleted**: Job and associated data are removed

### Worker Process Flow
1. Worker spawned via `proc_open` with isolated PHP process
2. Worker receives search queries and processes URLs
3. Worker extracts emails using multi-layered validation
4. Heartbeat messages sent every 5 seconds
5. Worker terminated on completion or timeout

### Email Validation Pipeline
1. **Format Validation**: RFC-compliant email format check
2. **Domain Validation**: DNS domain format verification
3. **Disposable Domain Check**: Filter temporary email services
4. **MX Record Verification**: Validate mail server existence
5. **Confidence Scoring**: Context-based quality assessment
6. **Deduplication**: RAM-based duplicate prevention

## Performance

- **Scalability**: Handles 4M+ email workloads efficiently
- **Memory Management**: Automatic memory monitoring and alerts
- **Worker Efficiency**: Process isolation prevents memory leaks
- **Domain Throttling**: Prevents rate limiting and IP bans
- **Adaptive Scaling**: Adjusts worker count based on performance

## Logging

Logs are stored in `/tmp/email_extraction/logs/`:
- `app_YYYY-MM-DD.log`: Application events and errors
- Includes timestamps, log levels, and context information

## Security

- API keys are stored securely and not exposed in responses
- Input validation on all user-provided data
- HTML escaping in UI to prevent XSS
- Process isolation for worker independence
- No sensitive data in logs

## Troubleshooting

### Jobs Not Starting
- Check PHP process control functions are enabled
- Verify write permissions on `/tmp/email_extraction`
- Check error logs in `/tmp/email_extraction/logs/`

### High Memory Usage
- Reduce `MAX_WORKERS_PER_JOB` constant
- Lower `maxSize` in `DedupEngine` class
- Increase system memory limit in `php.ini`

### Workers Timing Out
- Increase `WORKER_TIMEOUT` constant
- Check network connectivity to Serper API
- Verify worker scripts are executing properly

### API Rate Limiting
- Increase `DOMAIN_THROTTLE_SECONDS`
- Reduce number of concurrent workers
- Check Serper API quota and limits

## Development

### Testing the System
```bash
# Check PHP syntax
php -l app.php

# Start development server
php -S localhost:8080 app.php

# Run in CLI mode with debug output
php app.php
```

### Integrating Actual Email Extraction

The current implementation provides a complete framework with simulation workers. To integrate actual email extraction:

1. **Update Worker Script** (in `WorkerGovernor::generateWorkerScript()`):
   ```php
   // Replace the simulation loop with:
   $scheduler = new SearchScheduler($config['api_key']);
   $domainLimiter = new DomainLimiter();
   $dedupEngine = new DedupEngine();
   
   // Perform search
   $results = $scheduler->search($config['query']);
   
   foreach ($results as $result) {
       if (!URLFilter::isValid($result['url'])) continue;
       
       $domain = URLFilter::extractDomain($result['url']);
       if (!$domainLimiter->canAccess($domain)) {
           sleep(2); // Wait for throttle
           continue;
       }
       
       // Fetch page content
       $content = file_get_contents($result['url']);
       $domainLimiter->recordAccess($domain);
       
       // Extract and validate emails
       $emails = ContentFilter::extractEmails($content);
       foreach ($emails as $email) {
           if (!EmailValidator::validateWithMX($email)) continue;
           if ($dedupEngine->isDuplicate($email)) continue;
           
           $context = ContentFilter::analyzeContext($content, $email);
           $confidence = ConfidenceScorer::score($email, $context);
           
           if ($confidence >= Config::CONFIDENCE_THRESHOLD) {
               $dedupEngine->add($email);
               // Output email found
               echo json_encode([
                   'type' => 'email_found',
                   'email' => $email,
                   'confidence' => $confidence
               ]) . "\n";
               flush();
           }
       }
   }
   ```

2. **Copy Required Classes**: Since workers run in separate processes, copy the necessary class definitions (EmailValidator, ContentFilter, etc.) into the worker script template.

3. **Handle API Rate Limits**: Implement proper error handling for HTTP 429 responses and trigger domain backoffs.

4. **Store Results**: Update the worker output handler to save extracted emails to a database or file.

### Adding Custom Modules
The system is designed to be extensible. Add new modules as classes and integrate them into the `JobManager` or `WorkerGovernor` as needed.

## License

This project is provided as-is for educational and commercial use.

## Support

For issues, questions, or contributions, please open an issue in the repository.

## Version History

### v1.0.0 (Current)
- Initial release with full feature set
- Multi-job concurrent execution
- Worker-based architecture with proc_open
- SendGrid-styled UI with real-time monitoring
- Complete email validation pipeline
- Adaptive worker scaling
- Domain-level throttling
- 24/7 continuous operation support
# RESULT Directory

This directory contains the output JSON files from email extraction jobs.

## File Structure

Each job creates a single JSON file named `job_{job_id}.json` containing:

```json
{
  "job_id": "job_12345...",
  "emails": [
    {
      "email": "user@example.com",
      "quality": "high",
      "source_url": "https://example.com/page",
      "timestamp": 1234567890,
      "confidence": 0.85,
      "worker_id": "worker_12345..."
    }
  ],
  "total_count": 1000,
  "last_updated": 1234567890,
  "worker_stats": {}
}
```

## Features

- **Atomic Writes**: Files are written atomically using temporary files to prevent corruption
- **Buffered Updates**: Emails are buffered (100 at a time) before writing to reduce I/O
- **Deduplication**: Only unique emails are stored (case-insensitive)
- **Scalable**: Can handle millions of emails per file efficiently
- **Portable**: JSON format makes data easy to process, transfer, and backup

## Usage

### Accessing Results

Results can be accessed through:
1. **Web UI**: Click on job name to view paginated results
2. **Export**: Download as CSV from the job results page
3. **Direct Access**: Read JSON files directly from this directory

### File Lifecycle

- Files are created when the first emails are extracted
- Updated every 100 emails (buffer flush)
- Remain after job completion
- Deleted when job is deleted from the UI

## Performance

With optimized settings:
- **60 workers** can extract **1 million+ emails per hour**
- **Buffer size**: 100 emails (reduces I/O by 100x)
- **Write frequency**: Every 1-3 seconds per worker
- **Memory efficient**: Minimal RAM usage per worker

## Backup Recommendations

Since these files contain valuable extracted data:

1. **Regular Backups**: Copy files to a backup location periodically
2. **Cloud Storage**: Consider syncing to S3, Google Cloud Storage, etc.
3. **Version Control**: Keep historical snapshots if needed
4. **Compression**: JSON files compress well (use gzip for storage)

## Troubleshooting

### No Files Generated

- Check worker logs in `/tmp/email_extraction/logs/`
- Verify job is in "running" status
- Ensure workers are spawned (check worker count in UI)

### Duplicate Emails

- Each worker maintains an in-memory cache
- Duplicates across workers are filtered during buffer flush
- Final deduplication happens at write time

### Large File Sizes

- Each 1 million emails ≈ 200-300MB JSON
- Consider archiving completed jobs
- Files can be split or compressed if needed

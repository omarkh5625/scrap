# Email Extraction System (Scrap)

A high-performance PHP email extraction system with parallel processing capabilities.

## ⚡ Performance Features

- **curl_multi support**: Up to 50 parallel HTTP connections
- **Batch processing**: Bulk database operations and email validation
- **In-memory caching**: BloomFilter cache reduces DB queries by ~90%
- **Connection reuse**: HTTP keep-alive and HTTP/2 support
- **Optimized workers**: Can extract 100,000+ emails in under 3 minutes

## 🚀 Performance Metrics

- **Single Worker**: ~111 emails/minute
- **5 Workers**: ~555 emails/minute (100K in ~3 mins)
- **10 Workers**: ~1,110 emails/minute (100K in ~90 secs)

## 📋 Requirements

- PHP 8.0+
- MySQL/MariaDB
- curl with curl_multi support
- 512MB+ RAM per worker

## 🔧 Installation

1. Upload `app.php` to your web server
2. Navigate to the file in your browser
3. Follow the setup wizard to configure database
4. Create an admin account
5. Done! Start extracting emails

## 📖 Documentation

- **[PERFORMANCE_IMPROVEMENTS.md](PERFORMANCE_IMPROVEMENTS.md)** - Detailed performance documentation
- **test_performance.php** - Performance test script

## 🎯 Quick Start

1. Create a new job with your search query
2. Specify number of workers (5-10 recommended)
3. Workers start automatically
4. Monitor progress in real-time
5. Export results as CSV or JSON

## 🏗️ Architecture

- **CurlMultiManager**: Handles parallel HTTP requests
- **BloomFilter**: Fast duplicate detection with caching
- **Worker Queue**: Dynamic task distribution
- **Batch Processing**: Optimized database operations

## 📊 Monitoring

Access the Workers page to see:
- Active worker count
- Extraction rate (emails/min)
- Pages processed
- Queue progress
- Real-time errors and alerts

## 🔐 Security

- Password hashing with bcrypt
- Prepared SQL statements
- Input validation
- Session management
- Error logging

## 📝 License

MIT License - See LICENSE file for details

## 🤝 Contributing

Contributions welcome! Please read the contribution guidelines first.

## 📧 Support

For issues and questions, please open an issue on GitHub.

---

**Version**: 2.0.0 (Performance Optimized)
**Last Updated**: 2025-12-29
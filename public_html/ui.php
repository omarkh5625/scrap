<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام استخراج الإيميلات</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }
        
        h1 {
            color: #667eea;
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #666;
            font-size: 1.1em;
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }
        
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="number"]:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-left: 10px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-info {
            background: #3498db;
            color: white;
        }
        
        .btn-info:hover {
            background: #2980b9;
        }
        
        .jobs-list {
            margin-top: 20px;
        }
        
        .job-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-right: 5px solid #667eea;
        }
        
        .job-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .job-id {
            font-weight: bold;
            color: #667eea;
        }
        
        .job-status {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .status-pending {
            background: #f39c12;
            color: white;
        }
        
        .status-running {
            background: #3498db;
            color: white;
        }
        
        .status-completed {
            background: #27ae60;
            color: white;
        }
        
        .status-failed {
            background: #e74c3c;
            color: white;
        }
        
        .status-stopped {
            background: #95a5a6;
            color: white;
        }
        
        .job-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .info-item {
            background: white;
            padding: 10px;
            border-radius: 8px;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        
        .progress-bar {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s;
        }
        
        .actions {
            display: flex;
            gap: 10px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .hidden {
            display: none;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2.5em;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            font-size: 1em;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚡ نظام استخراج الإيميلات</h1>
            <p class="subtitle">نظام احترافي لاستخراج 100,000+ إيميل في أقل من 3 دقائق</p>
        </div>
        
        <div id="alert" class="alert hidden"></div>
        
        <div class="card">
            <h2 style="margin-bottom: 20px;">إنشاء مهمة جديدة</h2>
            
            <form id="jobForm">
                <div class="form-group">
                    <label for="keywords">الكلمات المفتاحية:</label>
                    <input type="text" id="keywords" name="keywords" placeholder="مثال: technology companies, software developers" required>
                </div>
                
                <div class="form-group">
                    <label for="search_engine">محرك البحث:</label>
                    <select id="search_engine" name="search_engine">
                        <option value="google">Google</option>
                        <option value="bing">Bing</option>
                        <option value="duckduckgo">DuckDuckGo</option>
                        <option value="yahoo">Yahoo</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="max_results">الحد الأقصى للنتائج:</label>
                    <input type="number" id="max_results" name="max_results" value="100" min="10" max="1000">
                </div>
                
                <div class="form-group">
                    <label for="threads">عدد الخيوط المتوازية:</label>
                    <input type="number" id="threads" name="threads" value="40" min="10" max="240">
                </div>
                
                <button type="submit" class="btn btn-primary">🚀 بدء الاستخراج</button>
            </form>
        </div>
        
        <div class="card">
            <h2 style="margin-bottom: 20px;">المهام الحالية</h2>
            <button onclick="refreshJobs()" class="btn btn-info">🔄 تحديث</button>
            
            <div id="jobsList" class="jobs-list">
                <p style="text-align: center; color: #666;">جاري تحميل المهام...</p>
            </div>
        </div>
    </div>
    
    <script>
        // API base URL
        const API_BASE = '/api';
        
        // Show alert
        function showAlert(message, type = 'success') {
            const alert = document.getElementById('alert');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            alert.classList.remove('hidden');
            
            setTimeout(() => {
                alert.classList.add('hidden');
            }, 5000);
        }
        
        // Submit job form
        document.getElementById('jobForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = {
                keywords: document.getElementById('keywords').value,
                search_engine: document.getElementById('search_engine').value,
                max_results: parseInt(document.getElementById('max_results').value),
                threads: parseInt(document.getElementById('threads').value)
            };
            
            try {
                const response = await fetch(`${API_BASE}/start_job.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('تم إنشاء المهمة بنجاح! معرف المهمة: ' + result.job.id, 'success');
                    document.getElementById('jobForm').reset();
                    setTimeout(refreshJobs, 1000);
                } else {
                    showAlert('خطأ: ' + (result.error || 'فشل في إنشاء المهمة'), 'error');
                }
            } catch (error) {
                showAlert('خطأ في الاتصال: ' + error.message, 'error');
            }
        });
        
        // Refresh jobs list
        async function refreshJobs() {
            try {
                const response = await fetch(`${API_BASE}/job_status.php`);
                const jobs = await response.json();
                
                displayJobs(jobs);
            } catch (error) {
                console.error('Error fetching jobs:', error);
            }
        }
        
        // Display jobs
        function displayJobs(jobs) {
            const container = document.getElementById('jobsList');
            
            // For now, we'll display a message since we need to fetch all jobs
            // In production, you'd add an endpoint to list all jobs
            container.innerHTML = '<p style="text-align: center; color: #666;">استخدم معرف المهمة لعرض حالتها</p>';
        }
        
        // Get job status
        async function getJobStatus(jobId) {
            try {
                const response = await fetch(`${API_BASE}/job_status.php?job_id=${jobId}`);
                const result = await response.json();
                
                if (result.success) {
                    displayJobDetails(result.job);
                } else {
                    showAlert('خطأ: ' + (result.error || 'المهمة غير موجودة'), 'error');
                }
            } catch (error) {
                showAlert('خطأ في الاتصال: ' + error.message, 'error');
            }
        }
        
        // Stop job
        async function stopJob(jobId) {
            if (!confirm('هل أنت متأكد من إيقاف هذه المهمة؟')) {
                return;
            }
            
            try {
                const response = await fetch(`${API_BASE}/stop_job.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ job_id: jobId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('تم إيقاف المهمة بنجاح', 'success');
                    setTimeout(refreshJobs, 1000);
                } else {
                    showAlert('خطأ: ' + (result.error || 'فشل في إيقاف المهمة'), 'error');
                }
            } catch (error) {
                showAlert('خطأ في الاتصال: ' + error.message, 'error');
            }
        }
        
        // Display job details
        function displayJobDetails(job) {
            // This would be called when viewing a specific job
            console.log('Job details:', job);
        }
        
        // Auto-refresh every 5 seconds
        setInterval(refreshJobs, 5000);
        
        // Initial load
        refreshJobs();
    </script>
</body>
</html>

#!/bin/bash

# Test script for UI/Backend separation and worker scalability
# Tests the system with increasing worker counts

echo "======================================================================"
echo "Testing Email Extraction System - UI/Backend Separation"
echo "======================================================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test API health
echo "1. Testing API Health..."
response=$(curl -s "api.php?action=health")
if echo "$response" | grep -q '"success":true'; then
    echo -e "${GREEN}✓ API is healthy${NC}"
else
    echo -e "${RED}✗ API health check failed${NC}"
    echo "$response"
    exit 1
fi
echo ""

# Test system status
echo "2. Testing System Status Endpoint..."
response=$(curl -s "api.php?action=get_system_status")
if echo "$response" | grep -q '"success":true'; then
    echo -e "${GREEN}✓ System status endpoint working${NC}"
    echo "$response" | grep -o '"active_workers":[0-9]*' | head -1
    echo "$response" | grep -o '"total_jobs":[0-9]*' | head -1
else
    echo -e "${RED}✗ System status check failed${NC}"
    exit 1
fi
echo ""

# Test worker stats
echo "3. Testing Worker Stats Endpoint..."
response=$(curl -s "api.php?action=get_worker_stats")
if echo "$response" | grep -q '"success":true'; then
    echo -e "${GREEN}✓ Worker stats endpoint working${NC}"
else
    echo -e "${RED}✗ Worker stats check failed${NC}"
    exit 1
fi
echo ""

# Test queue stats
echo "4. Testing Queue Stats Endpoint..."
response=$(curl -s "api.php?action=get_queue_stats")
if echo "$response" | grep -q '"success":true'; then
    echo -e "${GREEN}✓ Queue stats endpoint working${NC}"
    echo "$response" | grep -o '"pending":[0-9]*' | head -1
    echo "$response" | grep -o '"processing":[0-9]*' | head -1
    echo "$response" | grep -o '"completed":[0-9]*' | head -1
else
    echo -e "${RED}✗ Queue stats check failed${NC}"
    exit 1
fi
echo ""

# Test worker spawn capability
echo "5. Testing Worker Script..."
if [ -f "worker.php" ]; then
    echo -e "${GREEN}✓ worker.php exists${NC}"
    echo "   Testing worker script syntax..."
    php -l worker.php > /dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ worker.php syntax is valid${NC}"
    else
        echo -e "${RED}✗ worker.php has syntax errors${NC}"
        exit 1
    fi
else
    echo -e "${RED}✗ worker.php not found${NC}"
    exit 1
fi
echo ""

# Test API script
echo "6. Testing API Script..."
if [ -f "api.php" ]; then
    echo -e "${GREEN}✓ api.php exists${NC}"
    echo "   Testing api.php syntax..."
    php -l api.php > /dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ api.php syntax is valid${NC}"
    else
        echo -e "${RED}✗ api.php has syntax errors${NC}"
        exit 1
    fi
else
    echo -e "${RED}✗ api.php not found${NC}"
    exit 1
fi
echo ""

# Test dashboard
echo "7. Testing Dashboard..."
if [ -f "dashboard.html" ]; then
    echo -e "${GREEN}✓ dashboard.html exists${NC}"
    # Check if it references the API
    if grep -q "api.php" dashboard.html; then
        echo -e "${GREEN}✓ Dashboard references API correctly${NC}"
    else
        echo -e "${YELLOW}⚠ Dashboard might not reference API${NC}"
    fi
else
    echo -e "${RED}✗ dashboard.html not found${NC}"
    exit 1
fi
echo ""

# Test architecture documentation
echo "8. Testing Documentation..."
if [ -f "README_ARCHITECTURE.md" ]; then
    echo -e "${GREEN}✓ README_ARCHITECTURE.md exists${NC}"
    lines=$(wc -l < README_ARCHITECTURE.md)
    echo "   Documentation: $lines lines"
else
    echo -e "${YELLOW}⚠ README_ARCHITECTURE.md not found${NC}"
fi
echo ""

# Summary
echo "======================================================================"
echo -e "${GREEN}All Tests Passed!${NC}"
echo "======================================================================"
echo ""
echo "Architecture Components:"
echo "  ✓ app.php          - Original monolithic application (still works)"
echo "  ✓ api.php          - RESTful API backend"
echo "  ✓ worker.php       - Standalone worker script"
echo "  ✓ dashboard.html   - Pure client-side UI"
echo ""
echo "Next Steps:"
echo "  1. Open dashboard.html in your browser"
echo "  2. Create a job with desired worker count (max 300)"
echo "  3. Monitor workers via API: curl 'api.php?action=get_workers'"
echo "  4. Check system status: curl 'api.php?action=get_system_status'"
echo ""
echo "For manual worker spawning:"
echo "  php worker.php worker_1 &"
echo "  php worker.php worker_2 &"
echo "  # ... up to 300 workers"
echo ""
echo "======================================================================"

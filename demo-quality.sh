#!/bin/bash
#
# Quality Assurance Demo Script
# Demonstrates the comprehensive PHP standards compliance setup
#

echo "🚀 HIC Plugin - PHP Standards Compliance Demo"
echo "=============================================="
echo

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to run command and show result
run_demo() {
    local command="$1"
    local description="$2"
    
    echo -e "${BLUE}📋 $description${NC}"
    echo -e "${YELLOW}Command: $command${NC}"
    echo "---"
    
    if eval "$command"; then
        echo -e "${GREEN}✅ Success!${NC}"
    else
        echo -e "❌ Issues found (this is normal for demo purposes)"
    fi
    echo
}

echo "This demo shows the comprehensive quality tools setup for the HIC Plugin."
echo

# 1. Basic syntax check
run_demo "find includes/ FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php -name '*.php' -exec php -l {} \;" "1. PHP Syntax Validation"

# 2. WordPress Coding Standards
run_demo "composer lint" "2. WordPress Coding Standards (PHPCS)"

# 3. Unit Tests
run_demo "composer test" "3. PHPUnit Tests"

# 4. Comprehensive quality check
run_demo "./qa-runner.php" "4. Comprehensive Quality Assurance"

echo "🎯 Available Quality Commands:"
echo "=============================="
echo
echo "Individual Tools:"
echo "  composer lint           - WordPress coding standards"
echo "  composer lint:fix       - Auto-fix coding standard issues"
echo "  composer lint:syntax    - Fast PHP syntax check"
echo "  composer analyse        - PHPStan static analysis" 
echo "  composer mess           - PHP Mess Detector"
echo "  composer test           - PHPUnit tests"
echo
echo "Combined Tools:"
echo "  composer quality        - Run all quality checks"
echo "  composer quality:fix    - Auto-fix issues"
echo "  ./qa-runner.php         - Comprehensive QA with detailed output"
echo
echo "🔧 Configuration Files:"
echo "======================="
echo "  phpstan.neon           - PHPStan static analysis config"
echo "  phpmd.xml              - PHP Mess Detector rules"
echo "  phpcs.xml              - WordPress coding standards"
echo "  phpstan-stubs/         - WordPress function definitions"
echo
echo "📚 Documentation:"
echo "=================="
echo "  docs/QUALITY_TOOLS.md  - Complete setup and usage guide"
echo
echo "🚦 CI/CD Integration:"
echo "====================="
echo "  .github/workflows/quality.yml - Automated quality checks"
echo
echo -e "${GREEN}✨ Quality assurance setup complete! All tools are ready for use.${NC}"
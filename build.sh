#!/bin/bash
#
# WordPress Plugin Builder Script
# 
# Simple wrapper for the PHP build script
#

echo "🏗️  WordPress Plugin Builder"
echo "=============================="

if [ ! -f "build-wordpress-zip.php" ]; then
    echo "❌ Error: build-wordpress-zip.php not found in current directory"
    exit 1
fi

# Run the PHP build script
php build-wordpress-zip.php "$@"

exit_code=$?

if [ $exit_code -eq 0 ]; then
    echo ""
    echo "🎉 Build completed successfully!"
    echo ""
    echo "📦 Next steps:"
    echo "   1. Navigate to the dist/ directory"
    echo "   2. Upload the ZIP file to WordPress Admin > Plugins > Add New > Upload Plugin"
    echo "   3. Activate the plugin"
    echo ""
fi

exit $exit_code
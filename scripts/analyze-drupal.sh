#!/bin/bash
# Drupal Site Analyzer and Update Script Generator
# This script analyzes your Drupal site and creates a tailored update script

# Make sure we exit on any errors
set -e

echo "===== Analyzing your Drupal installation ====="

# Create a directory for analysis
mkdir -p drupal_upgrade_analysis

# Get a list of all currently installed modules with their versions
echo "Getting list of all installed modules..."
composer info "drupal/*" > drupal_upgrade_analysis/installed_modules.txt

# Get dependency information for drupal/core
echo "Analyzing module dependencies on Drupal core..."
composer why drupal/core > drupal_upgrade_analysis/core_dependencies.txt

# Generate a list of modules with their current versions and constraints
echo "Generating detailed module information..."
cat drupal_upgrade_analysis/installed_modules.txt | grep "drupal/" | awk '{print $1 " " $2}' > drupal_upgrade_analysis/module_versions.txt

# Generate the update script
echo "Generating update script based on analysis..."
cat > drupal_upgrade_update.sh << 'EOF'
#!/bin/bash
# Drupal Gradual Update Script - Generated $(date)
# This script updates modules to their latest versions compatible with Drupal 9
# while preparing for an eventual Drupal 10 migration

# Make sure we exit on any errors
set -e

echo "===== Starting Drupal module updates ====="
echo "Backing up composer.json first..."
cp composer.json composer.json.bak-$(date +%Y%m%d%H%M%S)

# First, update non-core modules without updating core
echo "Updating all contributed modules while staying on Drupal 9..."
composer update "drupal/*" --with-dependencies --with-all-dependencies --exclude drupal/core --exclude drupal/core-recommended

# Update Drupal 9 core to latest version
echo "Updating Drupal 9 core to the latest version..."
composer update drupal/core-recommended drupal/core-composer-scaffold drupal/core-project-message --with-dependencies

# Special handling for known problematic modules
echo "Checking status of modules with known Drupal 10 compatibility issues..."
if composer show drupal/cer &>/dev/null; then
  echo "CER module found - staying on Drupal 9 compatible version"
  # No action needed, just noting it's present
fi

if composer show drupal/lb_claro &>/dev/null; then
  echo "LB Claro module found - may need special handling for Drupal 10"
fi

# Clear Drupal caches and run database updates
echo "===== Updates completed ====="
echo "Next steps (to be run manually):"
echo "1. drush updatedb"
echo "2. drush cache:rebuild"
echo "3. drush config:import (if you use configuration management)"
echo "4. Test your site thoroughly"
echo "5. Monitor problematic modules for Drupal 10 compatibility:"
if grep -q "drupal/cer" drupal_upgrade_analysis/installed_modules.txt; then
  echo "   - CER: https://www.drupal.org/project/cer/issues"
fi
if grep -q "drupal/lb_claro" drupal_upgrade_analysis/installed_modules.txt; then
  echo "   - LB Claro: https://www.drupal.org/project/lb_claro/issues"
fi
echo "6. Once all modules are compatible, run: composer require drupal/core-recommended:^10.0 --update-with-all-dependencies"
EOF

chmod +x drupal_upgrade_update.sh

echo "===== Analysis complete ====="
echo "Analysis files saved in ./drupal_upgrade_analysis/"
echo "Generated update script: ./drupal_upgrade_update.sh"
echo ""
echo "Review the analysis files, then run the update script to begin the update process."

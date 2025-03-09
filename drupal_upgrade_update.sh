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
composer update $(composer info "drupal/*" | grep -v "drupal/core" | grep -v "drupal/core-recommended" | awk '{print $1}') --with-dependencies --with-all-dependencies

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

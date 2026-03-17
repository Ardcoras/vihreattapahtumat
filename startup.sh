#!/bin/bash

# 1. Point the nginx root to Drupal's web/ subdirectory
sed -i "s|root /home/site/wwwroot;|root /home/site/wwwroot/web;|g" /etc/nginx/sites-available/default

# 2. Add try_files for Drupal clean URLs inside the location / block
# This makes nginx pass unmatched paths to index.php (Drupal's front controller)
sed -i '/location \/ {/a\        try_files $uri $uri\/ /index.php?$query_string;' /etc/nginx/sites-available/default

# 3. Reload nginx to apply the changes
service nginx reload

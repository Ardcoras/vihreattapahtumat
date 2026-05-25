#!/bin/bash
set -euo pipefail

NGINX_CONF="/etc/nginx/sites-available/default"
PUBLIC_FILES_PATH="${DRUPAL_PUBLIC_FILES_PATH:-/home/site/files}"

insert_before_location_root() {
  local insert_file="$1"
  local tmp_file
  tmp_file="$(mktemp)"

  awk -v insert_file="$insert_file" '
    /^[[:space:]]*location \/ \{/ && !inserted {
      while ((getline line < insert_file) > 0) {
        print line
      }
      close(insert_file)
      inserted = 1
    }
    { print }
    END {
      if (!inserted) {
        exit 1
      }
    }
  ' "$NGINX_CONF" > "$tmp_file"

  mv "$tmp_file" "$NGINX_CONF"
}

mkdir -p "$PUBLIC_FILES_PATH/css" "$PUBLIC_FILES_PATH/js"

# Point Nginx at Drupal's public docroot inside the deployed package.
sed -i 's|root /home/site/wwwroot;|root /home/site/wwwroot/web;|g' "$NGINX_CONF"

# Keep Drupal clean URLs working if the App Service template does not already
# include the front-controller fallback.
if ! grep -Fq 'try_files $uri $uri/ /index.php?$query_string;' "$NGINX_CONF"; then
  sed -i '/^[[:space:]]*location \/ {/a\        try_files $uri $uri/ /index.php?$query_string;' "$NGINX_CONF"
fi

# Serve public files from an external writable mount. This keeps
# /sites/default/files URLs stable while allowing wwwroot to be a read-only
# run-from-package mount. Missing image derivatives fall back to Drupal.
if ! grep -Fq 'location ^~ /sites/default/files/' "$NGINX_CONF"; then
  public_files_snippet="$(mktemp)"
  cat > "$public_files_snippet" <<EOF

    location ^~ /sites/default/files/ {
        if (\$uri ~* "\\.php$") {
            return 403;
        }
        alias ${PUBLIC_FILES_PATH%/}/;
        try_files \$uri /index.php?\$query_string;
    }
EOF
  insert_before_location_root "$public_files_snippet"
  rm -f "$public_files_snippet"
fi

# Preserve Drupal-rendered 403/404 pages when fastcgi_intercept_errors is
# enabled by the App Service Nginx template.
if ! grep -Fq 'error_page 403 /index.php;' "$NGINX_CONF"; then
  error_pages_snippet="$(mktemp)"
  cat > "$error_pages_snippet" <<'EOF'

    error_page 403 /index.php;
    error_page 404 /index.php;
EOF
  insert_before_location_root "$error_pages_snippet"
  rm -f "$error_pages_snippet"
fi

nginx -t
service nginx reload

echo "nginx configured and reloaded successfully"

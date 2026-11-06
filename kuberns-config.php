<?php
// Runtime configuration for container deployments.
// Values come from environment variables injected by the platform. This file replaces the
// config.php that the web installer normally generates (the installer's purchase-code check
// requires an external service, which is not available in a container). The Dockerfile copies
// this file to config.php at build time.

// MySQL
$sql_db_host = getenv('DB_HOST') ?: 'localhost';
$sql_db_user = getenv('DB_USER') ?: 'root';
$sql_db_pass = getenv('DB_PASSWORD') ?: '';
$sql_db_name = getenv('DB_NAME') ?: 'playtube';
if (getenv('DB_PORT')) {
    ini_set('mysqli.default_port', getenv('DB_PORT'));
}

// Public site URL. Prefer an explicit SITE_URL, otherwise build it from the platform hostname.
$site_url = getenv('SITE_URL') ?: '';
if ($site_url === '' && getenv('DEPLOYMENT_HOSTNAME')) {
    $site_url = 'https://' . getenv('DEPLOYMENT_HOSTNAME');
}
if ($site_url === '') {
    $site_url = 'http://localhost:8000';
}
$site_url = rtrim($site_url, '/');

// Kept for compatibility with code that reads it; not needed at runtime.
$purchase_code = getenv('PURCHASE_CODE') ?: '';

$auto_redirect = true;

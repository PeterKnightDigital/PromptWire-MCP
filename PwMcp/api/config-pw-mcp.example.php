<?php
/**
 * PW-MCP API Configuration
 *
 * SETUP
 * -----
 * 1. Copy this file to your ProcessWire site's config directory:
 *      cp config-pw-mcp.example.php /path/to/remote-site/site/config-pw-mcp.php
 *
 * 2. Set a strong random API key (generate one with: openssl rand -hex 32)
 *
 * 3. Optionally restrict access to your Mac's IP address
 *    (find it with: curl ifconfig.me)
 *
 * This file is loaded by pw-mcp-api.php at runtime.
 * Keep it outside your webroot if possible, or ensure your web server
 * denies direct access to site/ directory (ProcessWire's default .htaccess does this).
 */

// Required: Set a strong, unique secret key (minimum 32 characters recommended)
// This must match PW_REMOTE_KEY in your local mcp.json env config.
define('PW_MCP_API_KEY', 'REPLACE_WITH_A_STRONG_RANDOM_KEY');

// Optional: Restrict API access to specific IP addresses (your Mac's public IP)
// Find your IP: curl ifconfig.me
// Leave commented out to allow from any IP (key auth still required)
// define('PW_MCP_ALLOWED_IPS', '1.2.3.4');

// Multiple IPs: comma-separated
// define('PW_MCP_ALLOWED_IPS', '1.2.3.4,5.6.7.8');

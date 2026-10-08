<?php
// WP-CLI process only: isolated fixture targets, without editing wp-config.php.
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('CLI fixture preload only.'); }
if (!defined('AG_SYNC_BRIDGE_V4MPG_ALLOWED_TARGETS')) {
    define('AG_SYNC_BRIDGE_V4MPG_ALLOWED_TARGETS', '910001:e2e-a,910002:e2e-b');
}

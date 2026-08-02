<?php

// Loaded by WP-CLI before wp-config.php so isolated E2E-only target IDs are
// available without editing a local WordPress installation.
if ( ! defined( 'AG_SYNC_BRIDGE_V4MPG_ALLOWED_TARGETS' ) ) {
	define( 'AG_SYNC_BRIDGE_V4MPG_ALLOWED_TARGETS', '2:pro-2,5:pro-5,6:italia-6,9:italia-9,910001:e2e,910001:e2e-a,910002:e2e-b' );
}

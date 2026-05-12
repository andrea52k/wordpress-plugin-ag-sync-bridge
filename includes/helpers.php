<?php
namespace AGSyncBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function array_get( array $values, $key, $default = null ) {
	return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
}

function normalize_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );
	return preg_replace( '#/+#', '/', $path );
}

function path_is_absolute( $path ) {
	$path       = (string) $path;
	$normalized = normalize_path( $path );

	if ( preg_match( '#^[a-zA-Z]:/#', $normalized ) ) {
		return 'Windows' === PHP_OS_FAMILY;
	}

	if ( 0 === strpos( str_replace( '\\', '/', $path ), '//' ) ) {
		return 'Windows' === PHP_OS_FAMILY;
	}

	if ( 0 === strpos( $normalized, '/' ) ) {
		return 'Windows' !== PHP_OS_FAMILY;
	}

	return false;
}

function ensure_directory( $path ) {
	return is_dir( $path ) || wp_mkdir_p( $path );
}

function sanitize_line_list( $value ) {
	$value = (string) $value;
	$lines = preg_split( '/\r\n|\r|\n/', $value );
	$lines = is_array( $lines ) ? $lines : array();
	$clean = array();

	foreach ( $lines as $line ) {
		$line = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $line ) );
		if ( '' !== $line ) {
			$clean[] = $line;
		}
	}

	return $clean;
}

function format_bytes( $bytes ) {
	$bytes = (float) $bytes;
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

	foreach ( $units as $unit ) {
		if ( $bytes < 1024 || 'TB' === $unit ) {
			return number_format_i18n( $bytes, $bytes < 10 && 'B' !== $unit ? 1 : 0 ) . ' ' . $unit;
		}

		$bytes /= 1024;
	}

	return number_format_i18n( $bytes, 1 ) . ' TB';
}

function plugin() {
	return Plugin::instance();
}

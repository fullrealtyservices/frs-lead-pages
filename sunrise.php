<?php
/**
 * FRS Lead Pages — sunrise drop-in.
 *
 * Included very early (before WordPress loads) from wp-content/sunrise.php. When
 * the request arrives on the configured public landing-page domain, it serves
 * the blog that hosts the lead pages, rooted on that domain — so a URL like
 * https://go.21stcenturylending.com/p/{slug} resolves to the lead page while the
 * rest of the network is untouched.
 *
 * It does nothing at all unless:
 *   - the generated map file exists and is enabled, and
 *   - the current request host exactly matches the configured domain.
 *
 * Reads a generated map file (no database access this early). The managed block
 * in wp-content/sunrise.php and the map file are written by Core\DomainMapping.
 *
 * @package FRSLeadPages
 */

defined( 'ABSPATH' ) || exit;

( static function () {
	if ( empty( $_SERVER['HTTP_HOST'] ) ) {
		return;
	}

	$map_file = WP_CONTENT_DIR . '/frs-lead-pages-domain-map.php';
	if ( ! is_readable( $map_file ) ) {
		return;
	}

	$map = include $map_file;
	if ( ! is_array( $map ) || empty( $map['enabled'] ) || empty( $map['host'] ) || empty( $map['blog_id'] ) ) {
		return;
	}

	$host = strtolower( $_SERVER['HTTP_HOST'] );
	// Tolerate an optional :port suffix on the Host header.
	$host = preg_replace( '/:\d+$/', '', $host );
	if ( $host !== strtolower( (string) $map['host'] ) ) {
		return;
	}

	global $wpdb, $current_blog, $current_site, $blog_id, $site_id;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return;
	}

	$blog = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->blogs} WHERE blog_id = %d LIMIT 1", (int) $map['blog_id'] ) );
	if ( ! $blog ) {
		return;
	}

	$network = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->site} WHERE id = %d LIMIT 1", (int) $blog->site_id ) );
	if ( ! $network ) {
		return;
	}

	// Serve this blog rooted on the public domain.
	$blog->domain = $host;
	$blog->path   = '/';

	$current_blog          = $blog;
	$current_site          = $network;
	$current_site->blog_id = (string) $blog->blog_id;

	$blog_id = (int) $blog->blog_id;
	$site_id = (int) $blog->site_id;
} )();

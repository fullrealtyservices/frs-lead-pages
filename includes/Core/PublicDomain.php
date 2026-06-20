<?php
/**
 * Public Landing-Page Domain
 *
 * Publishes lead pages on the public landing-page domain
 * (go.21stcenturylending.com, an alias of the hub) so they live outside the
 * authenticated Entra/SSO hub and are viewable by the public.
 *
 * - Rewrites every lead-page permalink onto the public domain so all current
 *   and future pages "publish" there (links, QR codes, shares, og:url).
 * - Blocks WordPress' canonical redirect from bouncing a lead page served on
 *   the public domain back to the hub domain.
 *
 * The matching SSO exemption (so the public domain is never gated by the login
 * redirect) lives in the `login-redirect` snippet.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\Core;

defined( 'ABSPATH' ) || exit;

class PublicDomain {

	/** Option holding the configured public domain. */
	const OPTION = 'frs_lead_pages_public_domain';

	/** Default public landing-page domain. */
	const DEFAULT_DOMAIN = 'https://go.21stcenturylending.com';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'post_type_link', [ __CLASS__, 'filter_permalink' ], 10, 2 );
		add_filter( 'preview_post_link', [ __CLASS__, 'filter_preview_link' ], 10, 2 );
		// Don't let core bounce public-domain requests back to the hub domain.
		add_filter( 'redirect_canonical', [ __CLASS__, 'maybe_block_canonical' ], 10, 2 );
	}

	/**
	 * Configured public domain, normalised to scheme + host with no trailing slash.
	 * Returns '' when disabled (empty option) so callers fall back to the hub URL.
	 *
	 * @return string
	 */
	public static function get_domain(): string {
		$domain = (string) get_option( self::OPTION, self::DEFAULT_DOMAIN );

		/** Allow overriding the public landing-page domain. */
		$domain = (string) apply_filters( 'frs_lead_pages_public_domain', $domain );
		$domain = trim( $domain );

		if ( $domain === '' ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $domain ) ) {
			$domain = 'https://' . $domain;
		}

		return untrailingslashit( $domain );
	}

	/**
	 * Rewrite lead-page permalinks onto the public domain.
	 *
	 * @param string   $permalink The post's permalink.
	 * @param \WP_Post $post      The post object.
	 * @return string
	 */
	public static function filter_permalink( $permalink, $post ) {
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'frs_lead_page' ) {
			return $permalink;
		}

		return self::swap_host( (string) $permalink );
	}

	/**
	 * Rewrite lead-page preview links onto the public domain.
	 *
	 * @param string   $link The preview link.
	 * @param \WP_Post $post The post object.
	 * @return string
	 */
	public static function filter_preview_link( $link, $post ) {
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'frs_lead_page' ) {
			return $link;
		}

		return self::swap_host( (string) $link );
	}

	/**
	 * Replace scheme + host of a hub URL with the public domain, keeping the
	 * path, query and fragment intact.
	 *
	 * @param string $url URL on the hub domain.
	 * @return string
	 */
	private static function swap_host( string $url ): string {
		$domain = self::get_domain();
		if ( $domain === '' ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			return $url;
		}

		$out = $domain . $parts['path'];
		if ( ! empty( $parts['query'] ) ) {
			$out .= '?' . $parts['query'];
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$out .= '#' . $parts['fragment'];
		}

		return $out;
	}

	/**
	 * Prevent WordPress' canonical redirect from bouncing a lead page served on
	 * the public domain back to the hub domain.
	 *
	 * @param string $redirect_url  The proposed redirect URL.
	 * @param string $requested_url The requested URL.
	 * @return string|false
	 */
	public static function maybe_block_canonical( $redirect_url, $requested_url ) {
		if ( is_singular( 'frs_lead_page' ) ) {
			return false;
		}

		return $redirect_url;
	}
}

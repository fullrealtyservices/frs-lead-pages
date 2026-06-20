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
		// When a request is served on the public domain, root the (sub)site there
		// so lead-page paths like /p/{slug} resolve at the domain root. The domain
		// -> subsite mapping itself lives in sunrise.php.
		add_filter( 'option_home', [ __CLASS__, 'filter_served_host_url' ] );
		add_filter( 'option_siteurl', [ __CLASS__, 'filter_served_host_url' ] );
		// Only lead pages are exposed on the public domain; bounce anything else.
		add_action( 'template_redirect', [ __CLASS__, 'restrict_public_domain' ], 0 );
		// Keep the BetterDocs "Instant Answer" knowledge-base widget off the public domain.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'suppress_kb_widget' ], 100 );
		add_action( 'wp_head', [ __CLASS__, 'suppress_kb_widget_css' ], 99 );
	}

	/**
	 * Is the current request being served on the public landing-page domain?
	 *
	 * @return bool
	 */
	public static function is_public_domain_request(): bool {
		$domain = self::get_domain();
		if ( $domain === '' ) {
			return false;
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		return $host !== '' && $host === wp_parse_url( $domain, PHP_URL_HOST );
	}

	/**
	 * Dequeue the BetterDocs Instant Answer (knowledge base) widget assets on the
	 * public domain so it never loads on the landing pages.
	 *
	 * @return void
	 */
	public static function suppress_kb_widget(): void {
		if ( ! self::is_public_domain_request() ) {
			return;
		}
		foreach ( [ 'betterdocs-instant-answer', 'betterdocs-instant-answer-cd', 'betterdocs-categorygrid' ] as $handle ) {
			wp_dequeue_script( $handle );
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Hard-hide the Instant Answer widget container on the public domain, in case
	 * its markup is still printed by a footer hook.
	 *
	 * @return void
	 */
	public static function suppress_kb_widget_css(): void {
		if ( self::is_public_domain_request() ) {
			echo '<style id="frs-hide-kb-widget">#betterdocs-ia{display:none!important;}</style>' . "\n";
		}
	}

	/**
	 * On the public domain, serve only lead pages. Any other front-end request
	 * is sent to the network's main site, so the public domain never exposes the
	 * rest of the (sub)site.
	 *
	 * @return void
	 */
	public static function restrict_public_domain(): void {
		$domain = self::get_domain();
		if ( $domain === '' ) {
			return;
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		if ( $host === '' || $host !== wp_parse_url( $domain, PHP_URL_HOST ) ) {
			return; // Not the public domain — leave the request alone.
		}

		if ( is_singular( 'frs_lead_page' ) ) {
			return; // The one thing allowed here.
		}

		// Don't interfere with REST, AJAX, or cron served on this host.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		wp_redirect( network_home_url( '/' ), 302 );
		exit;
	}

	/**
	 * When the current request is being served on the public domain, return that
	 * domain (rooted) for home/siteurl so WordPress parses paths at the domain
	 * root instead of under the subsite path (e.g. /lending). Only affects
	 * requests whose host IS the public domain; the hub is untouched.
	 *
	 * @param mixed $value Stored option value.
	 * @return mixed
	 */
	public static function filter_served_host_url( $value ) {
		$domain = self::get_domain();
		if ( $domain === '' ) {
			return $value;
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
		if ( $host !== '' && $host === wp_parse_url( $domain, PHP_URL_HOST ) ) {
			return $domain;
		}

		return $value;
	}

	/**
	 * Configured public domain, normalised to scheme + host with no trailing slash.
	 * Returns '' when disabled (empty option) so callers fall back to the hub URL.
	 *
	 * @return string
	 */
	public static function get_domain(): string {
		// Feature is opt-in: no domain unless the mapping is enabled and set.
		if ( ! DomainMapping::is_enabled() ) {
			return '';
		}

		$host = DomainMapping::get_host();
		if ( $host === '' ) {
			return '';
		}

		/** Allow overriding the public landing-page domain. */
		$domain = (string) apply_filters( 'frs_lead_pages_public_domain', 'https://' . $host );
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

		$path = $parts['path'];

		// On multisite, drop the subsite path prefix (e.g. /lending) so links are
		// rooted on the public domain: go.21stcenturylending.com/p/{slug}.
		if ( is_multisite() ) {
			$details = get_blog_details( get_current_blog_id() );
			if ( $details && ! empty( $details->path ) && $details->path !== '/' ) {
				$site_path = untrailingslashit( $details->path );
				if ( $site_path !== '' && strpos( $path, $site_path . '/' ) === 0 ) {
					$path = substr( $path, strlen( $site_path ) );
				}
			}
		}

		$out = $domain . $path;
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

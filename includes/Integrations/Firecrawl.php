<?php
/**
 * Firecrawl API Integration
 *
 * Fetches property data and images from real estate listings.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\Integrations;

class Firecrawl {

    /**
     * API endpoint
     */
    const API_URL = 'https://api.firecrawl.dev/v1';

    /**
     * Get API key from settings
     */
    public static function get_api_key(): string {
        // Try frs-lead-pages setting first, fallback to psb setting
        $key = get_option( 'frs_lead_pages_firecrawl_api_key', '' );
        if ( empty( $key ) ) {
            $key = get_option( 'psb_firecrawl_api_key', '' );
        }
        return $key;
    }

    /**
     * Check if API is configured
     */
    public static function is_configured(): bool {
        return ! empty( self::get_api_key() );
    }

    /**
     * Scrape a listing URL for property data
     *
     * @param string $url Listing URL (Zillow, Redfin, Realtor.com, etc.)
     * @return array|WP_Error Property data or error
     */
    public static function scrape_listing( string $url ): array|\WP_Error {
        $api_key = self::get_api_key();

        if ( empty( $api_key ) ) {
            return new \WP_Error( 'no_api_key', 'Firecrawl API key not configured' );
        }

        $response = wp_remote_post( self::API_URL . '/scrape', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'url'     => $url,
                'formats' => [ 'markdown', 'extract' ],
                'extract' => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'address'    => [ 'type' => 'string' ],
                            'price'      => [ 'type' => 'number' ],
                            'bedrooms'   => [ 'type' => 'number' ],
                            'bathrooms'  => [ 'type' => 'number' ],
                            'sqft'       => [ 'type' => 'number' ],
                            'year_built' => [ 'type' => 'number' ],
                            'lot_size'   => [ 'type' => 'string' ],
                            'mls_number' => [ 'type' => 'string' ],
                            'images'     => [
                                'type'  => 'array',
                                'items' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ]),
            'timeout' => 60,
        ]);

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return new \WP_Error(
                'api_error',
                $body['error'] ?? 'Failed to fetch property data',
                [ 'status' => $code ]
            );
        }

        // Extract property data
        $extract = $body['data']['extract'] ?? [];
        $images  = $body['data']['images'] ?? [];

        // Merge extracted images with page images
        $all_images = array_merge(
            $extract['images'] ?? [],
            $images
        );

        // Filter to only property images (exclude icons, logos, etc.)
        $property_images = array_filter( $all_images, function( $img ) {
            // Skip small images and common non-property patterns
            if ( stripos( $img, 'logo' ) !== false ) return false;
            if ( stripos( $img, 'icon' ) !== false ) return false;
            if ( stripos( $img, 'avatar' ) !== false ) return false;
            if ( stripos( $img, 'profile' ) !== false ) return false;
            if ( stripos( $img, 'badge' ) !== false ) return false;
            if ( stripos( $img, 'watermark' ) !== false ) return false;
            if ( stripos( $img, 'pixel' ) !== false ) return false;
            if ( stripos( $img, 'tracking' ) !== false ) return false;
            return true;
        });

        // Validate and filter images - ensure they're accessible
        $validated_images = self::validate_images( array_values( $property_images ) );

        // Extract credit usage from response
        $credits_used = $body['creditsUsed'] ?? null;
        $credits_remaining = $body['creditsRemaining'] ?? null;

        return [
            'address'           => $extract['address'] ?? '',
            'price'             => (int) ( $extract['price'] ?? 0 ),
            'bedrooms'          => (int) ( $extract['bedrooms'] ?? 0 ),
            'bathrooms'         => (float) ( $extract['bathrooms'] ?? 0 ),
            'sqft'              => (int) ( $extract['sqft'] ?? 0 ),
            'year_built'        => (int) ( $extract['year_built'] ?? 0 ),
            'lot_size'          => $extract['lot_size'] ?? '',
            'mls_number'        => $extract['mls_number'] ?? '',
            'images'            => array_slice( $validated_images, 0, 3 ),
            'source_url'        => $url,
            'credits_used'      => $credits_used,
            'credits_remaining' => $credits_remaining,
        ];
    }

    /**
     * Search for property by address
     *
     * @param string $address Property address
     * @return array|WP_Error Property data or error
     */
    public static function search_property( string $address ): array|\WP_Error {
        $api_key = self::get_api_key();

        if ( empty( $api_key ) ) {
            return new \WP_Error( 'no_api_key', 'Firecrawl API key not configured' );
        }

        // Search for the property listing
        $response = wp_remote_post( self::API_URL . '/search', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'query'  => $address . ' real estate listing',
                'limit'  => 5,
                'scrapeOptions' => [
                    'formats' => [ 'markdown' ],
                ],
            ]),
            'timeout' => 60,
        ]);

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return new \WP_Error(
                'api_error',
                $body['error'] ?? 'Failed to search for property',
                [ 'status' => $code ]
            );
        }

        $results = $body['data'] ?? [];

        if ( empty( $results ) ) {
            return new \WP_Error( 'not_found', 'No listing found for this address' );
        }

        // Find the best match (prefer Zillow, Redfin, Realtor.com)
        $preferred_domains = [ 'zillow.com', 'redfin.com', 'realtor.com' ];
        $best_result = $results[0];

        foreach ( $results as $result ) {
            foreach ( $preferred_domains as $domain ) {
                if ( isset( $result['url'] ) && stripos( $result['url'], $domain ) !== false ) {
                    $best_result = $result;
                    break 2;
                }
            }
        }

        // If we found a URL, scrape it for full details
        if ( ! empty( $best_result['url'] ) ) {
            return self::scrape_listing( $best_result['url'] );
        }

        return new \WP_Error( 'not_found', 'Could not find property listing' );
    }

    /**
     * Validate image URLs are accessible and are actual images
     *
     * @param array $urls Image URLs to validate
     * @return array Validated URLs only
     */
    private static function validate_images( array $urls ): array {
        $validated = [];
        $real_estate_cdns = [
            'zillowstatic.com',
            'zillow.com',
            'redfin.com',
            'realtor.com',
            'trulia.com',
            'imgix.net',
            'cloudinary.com',
        ];

        foreach ( $urls as $url ) {
            // Basic URL validation
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                continue;
            }

            // Check if URL is from known real estate CDNs (security check)
            $is_known_source = false;
            foreach ( $real_estate_cdns as $cdn ) {
                if ( stripos( $url, $cdn ) !== false ) {
                    $is_known_source = true;
                    break;
                }
            }
            if ( ! $is_known_source ) {
                continue;  // Skip URLs from unknown sources
            }

            // Skip obvious non-image file types
            $path_lower = strtolower( parse_url( $url, PHP_URL_PATH ) );
            if ( preg_match( '/\.(gif|svg|webp)$/i', $path_lower ) ) {
                continue;  // GIFs often tracking pixels, SVG often logos, WebP less reliable
            }

            // Verify image is accessible with HEAD request
            $response = wp_remote_head( $url, [
                'timeout'   => 5,
                'sslverify' => true,
                'redirection' => 2,
            ] );

            if ( is_wp_error( $response ) ) {
                continue;  // URL not accessible
            }

            $status = wp_remote_retrieve_response_code( $response );
            if ( $status !== 200 ) {
                continue;  // Not found or redirected
            }

            // Check MIME type from headers
            $content_type = wp_remote_retrieve_header( $response, 'content-type' );
            if ( ! $content_type || ! in_array( $content_type, [
                'image/jpeg',
                'image/png',
                'image/jpg',
            ], true ) ) {
                continue;  // Not a valid image type
            }

            // Check Content-Length to skip tracking pixels (typically < 100 bytes)
            $content_length = wp_remote_retrieve_header( $response, 'content-length' );
            if ( $content_length && absint( $content_length ) < 100 ) {
                continue;  // Likely a tracking pixel
            }

            // If all checks pass, add to validated list
            $validated[] = $url;
        }

        return $validated;
    }
}

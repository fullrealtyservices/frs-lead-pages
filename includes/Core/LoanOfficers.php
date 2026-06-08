<?php
/**
 * Loan Officers API Integration
 *
 * Fetches loan officers from frs-wp-users API or falls back to local WordPress users.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\Core;

class LoanOfficers {

    /**
     * Transient key for cached loan officers
     */
    const CACHE_KEY = 'frs_lead_pages_loan_officers';

    /**
     * Get loan officers (cached)
     *
     * @param bool $force_refresh Force refresh from API
     * @return array Array of loan officer data
     */
    public static function get_loan_officers( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        // Try external API first
        $api_url = get_option( 'frs_lead_pages_users_api_url', '' );

        if ( ! empty( $api_url ) ) {
            $los = self::fetch_from_api( $api_url );
            if ( ! empty( $los ) ) {
                self::cache_loan_officers( $los );
                return $los;
            }
        }

        // Fallback to local WordPress users
        $los = self::fetch_local_users();
        self::cache_loan_officers( $los );

        return $los;
    }

    /**
     * Fetch loan officers from frs-wp-users API
     *
     * @param string $api_url Base API URL
     * @return array
     */
    private static function fetch_from_api( string $api_url ): array {
        $api_key = get_option( 'frs_lead_pages_users_api_key', '' );

        $headers = [
            'Accept' => 'application/json',
        ];

        if ( ! empty( $api_key ) ) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $response = wp_remote_get( trailingslashit( $api_url ) . 'wp-json/frs/v1/loan-officers', [
            'headers' => $headers,
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'FRS Lead Pages - API fetch failed: ' . $response->get_error_message() );
            return [];
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) {
            error_log( 'FRS Lead Pages - API returned status ' . $status );
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            return [];
        }

        // Normalize data structure
        return array_map( function( $lo ) {
            $nmls = $lo['nmls'] ?? $lo['nmls_id'] ?? '';
            $arrive = $lo['arrive'] ?? '';

            // Generate arrive link from NMLS if not provided
            if ( empty( $arrive ) && ! empty( $nmls ) ) {
                $arrive = 'https://apply.21stcenturylending.com/register/' . $nmls;
            }

            return [
                'id'        => $lo['id'] ?? 0,
                'name'      => $lo['name'] ?? $lo['display_name'] ?? '',
                'email'     => $lo['email'] ?? '',
                'phone'     => $lo['phone'] ?? '',
                'nmls'      => $nmls,
                'title'     => $lo['title'] ?? $lo['job_title'] ?? 'Loan Officer',
                'photo_url' => $lo['photo_url'] ?? $lo['avatar'] ?? '',
                'arrive'    => $arrive,
                'active'    => $lo['active'] ?? true,
            ];
        }, $body );
    }

    /**
     * Fetch loan officers from local WordPress users
     *
     * @return array
     */
    private static function fetch_local_users(): array {
        $users = get_users( [
            'role'    => 'loan_officer',
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'number'  => 100,
        ] );

        return array_map( function( $user ) {
            $nmls = \FRSLeadPages\frs_get_user_nmls( $user->ID );
            $arrive = self::get_user_arrive_link( $user->ID, $nmls );

            return [
                'id'        => $user->ID,
                'name'      => $user->display_name,
                'email'     => $user->user_email,
                'phone'     => get_user_meta( $user->ID, 'phone', true ) ?: get_user_meta( $user->ID, 'billing_phone', true ),
                'nmls'      => $nmls,
                'title'     => get_user_meta( $user->ID, 'title', true ) ?: get_user_meta( $user->ID, 'job_title', true ) ?: 'Loan Officer',
                'photo_url' => \FRSLeadPages\frs_normalize_upload_url( get_avatar_url( $user->ID, [ 'size' => 200 ] ) ),
                'arrive'    => $arrive,
                'active'    => true,
            ];
        }, $users );
    }

    /**
     * Get user's arrive link from profile or generate from NMLS
     *
     * @param int    $user_id User ID
     * @param string $nmls NMLS number
     * @return string Arrive link URL
     */
    public static function get_user_arrive_link( int $user_id, string $nmls ): string {
        // Try to get from frs_profiles table via frs-wp-users
        global $wpdb;
        $table = $wpdb->prefix . 'frs_profiles';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
            $arrive = $wpdb->get_var( $wpdb->prepare(
                "SELECT arrive FROM {$table} WHERE user_id = %d",
                $user_id
            ) );

            if ( ! empty( $arrive ) ) {
                return $arrive;
            }
        }

        // Fallback: generate from NMLS
        if ( ! empty( $nmls ) ) {
            return 'https://apply.21stcenturylending.com/register/' . $nmls;
        }

        return '';
    }

    /**
     * Cache loan officers
     *
     * @param array $los Loan officers data
     */
    private static function cache_loan_officers( array $los ): void {
        $duration = get_option( 'frs_lead_pages_lo_cache_duration', 3600 );
        set_transient( self::CACHE_KEY, $los, $duration );

        // Also store as fallback
        update_option( 'frs_lead_pages_lo_fallback', $los );
    }

    /**
     * Get loan officers formatted for Fluent Forms dropdown
     *
     * @return array Options array for dropdown field
     */
    public static function get_dropdown_options(): array {
        $los = self::get_loan_officers();

        return array_map( function( $lo ) {
            $label = $lo['name'];
            if ( ! empty( $lo['nmls'] ) ) {
                $label .= ' | NMLS# ' . $lo['nmls'];
            }

            return [
                'label' => $label,
                'value' => (string) $lo['id'],
                'image' => $lo['photo_url'] ?? '',
            ];
        }, array_filter( $los, fn( $lo ) => $lo['active'] ?? true ) );
    }

    /**
     * Get single loan officer by ID
     *
     * @param int $id Loan officer ID
     * @return array|null
     */
    public static function get_loan_officer( int $id ): ?array {
        $los = self::get_loan_officers();

        foreach ( $los as $lo ) {
            if ( (int) $lo['id'] === $id ) {
                return $lo;
            }
        }

        // Not in cache, try direct lookup
        $user = get_user_by( 'ID', $id );
        if ( ! $user ) {
            return null;
        }

        $nmls = \FRSLeadPages\frs_get_user_nmls( $id );
        $arrive = self::get_user_arrive_link( $id, $nmls );

        return [
            'id'        => $user->ID,
            'name'      => $user->display_name,
            'email'     => $user->user_email,
            'phone'     => get_user_meta( $id, 'phone', true ) ?: get_user_meta( $id, 'billing_phone', true ),
            'nmls'      => $nmls,
            'title'     => get_user_meta( $id, 'title', true ) ?: get_user_meta( $id, 'job_title', true ) ?: 'Loan Officer',
            'photo_url' => \FRSLeadPages\frs_normalize_upload_url( get_avatar_url( $id, [ 'size' => 200 ] ) ),
            'arrive'    => $arrive,
            'active'    => true,
        ];
    }

    /**
     * Force refresh loan officers cache
     *
     * @return array Fresh loan officers data
     */
    public static function refresh(): array {
        delete_transient( self::CACHE_KEY );
        return self::get_loan_officers( true );
    }
}

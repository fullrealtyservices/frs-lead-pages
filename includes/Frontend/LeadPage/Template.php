<?php
/**
 * Lead Page Template Handler
 *
 * Routes to the correct template based on page type and handles shared logic.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\Frontend\LeadPage;

class Template {

    /**
     * Render the lead page template
     *
     * @param int $page_id Post ID
     * @return void
     */
    public static function render( int $page_id ): void {
        // Prevent caching
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Load page data
        $data = self::get_page_data( $page_id );

        // Track page view
        $views = (int) get_post_meta( $page_id, '_frs_page_views', true );
        update_post_meta( $page_id, '_frs_page_views', $views + 1 );

        // Enqueue the correct CSS based on page type
        self::enqueue_assets( $data['page_type'] );

        // Route to correct template
        if ( $data['page_type'] === 'mortgage_calculator' ) {
            include __DIR__ . '/template-calculator.php';
        } else {
            include __DIR__ . '/template-standard.php';
        }
    }

    /**
     * Enqueue CSS/JS assets
     *
     * @param string $page_type Page type
     * @return void
     */
    private static function enqueue_assets( string $page_type ): void {
        $base_url = plugins_url( 'includes/Frontend/LeadPage/', FRS_LEAD_PAGES_PLUGIN_FILE );
        $version = FRS_LEAD_PAGES_VERSION;

        if ( $page_type === 'mortgage_calculator' ) {
            wp_enqueue_style( 'frs-lead-page-calculator', $base_url . 'style-calculator.css', [], $version );
        } else {
            wp_enqueue_style( 'frs-lead-page-standard', $base_url . 'style-standard.css', [], $version );
        }

        // Output CSS variables from admin settings
        $primary       = get_option( 'frs_lead_pages_primary_color', '#1e3a5f' );
        $primary_hover = get_option( 'frs_lead_pages_primary_hover', '#152a45' );
        $primary_light = self::hex_to_rgba( $primary, 0.15 );

        $css_vars = ":root {
            --frs-primary: {$primary};
            --frs-primary-hover: {$primary_hover};
            --frs-primary-light: {$primary_light};
        }";

        wp_add_inline_style(
            $page_type === 'mortgage_calculator' ? 'frs-lead-page-calculator' : 'frs-lead-page-standard',
            $css_vars
        );

        wp_enqueue_script( 'frs-lead-page', $base_url . 'script.js', [], $version, true );

        // Enqueue form handler
        $forms_url = plugins_url( 'forms/', FRS_LEAD_PAGES_PLUGIN_FILE );
        wp_enqueue_script( 'frs-lead-form-handler', $forms_url . 'form-handler.js', [], $version, true );

        // Keep form submissions same-origin with the host that served the page
        // (e.g. the public landing domain) so public pages don't hit CORS.
        $api_base = rest_url( 'frs-lead-pages/v1' );
        $host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        if ( $host ) {
            $scheme   = is_ssl() ? 'https' : 'http';
            $api_base = preg_replace( '#^https?://[^/]+#', $scheme . '://' . $host, $api_base );
        }

        wp_localize_script( 'frs-lead-form-handler', 'frsLeadPages', [
            'apiBase' => esc_url_raw( $api_base ),
        ] );
    }

    /**
     * Convert hex color to rgba
     *
     * @param string $hex   Hex color
     * @param float  $alpha Alpha value
     * @return string
     */
    private static function hex_to_rgba( string $hex, float $alpha ): string {
        $hex = ltrim( $hex, '#' );

        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );

        return "rgba({$r}, {$g}, {$b}, {$alpha})";
    }

    /**
     * Get all page data
     *
     * @param int $page_id Post ID
     * @return array
     */
    public static function get_page_data( int $page_id ): array {
        $page_type      = get_post_meta( $page_id, '_frs_page_type', true );
        $headline       = get_post_meta( $page_id, '_frs_headline', true );
        $subheadline    = get_post_meta( $page_id, '_frs_subheadline', true );
        $consent_text   = get_post_meta( $page_id, '_frs_consent_text', true );
        $hero_image_url = get_post_meta( $page_id, '_frs_hero_image_url', true );
        $hero_image_id  = get_post_meta( $page_id, '_frs_hero_image_id', true );

        // Get hero image from attachment if ID is set
        if ( $hero_image_id ) {
            $hero_image_url = \FRSLeadPages\frs_get_attachment_image_url( (int) $hero_image_id, 'full' );
        } elseif ( $hero_image_url ) {
            $hero_image_url = \FRSLeadPages\frs_normalize_upload_url( $hero_image_url );
        }

        // Property details (for open house)
        $property_address = get_post_meta( $page_id, '_frs_property_address', true );
        $property_price   = get_post_meta( $page_id, '_frs_property_price', true );
        $property_beds    = get_post_meta( $page_id, '_frs_property_beds', true );
        $property_baths   = get_post_meta( $page_id, '_frs_property_baths', true );

        // Event details
        $event_name        = get_post_meta( $page_id, '_frs_event_name', true );
        $event_date        = get_post_meta( $page_id, '_frs_event_date', true );
        $event_time        = get_post_meta( $page_id, '_frs_event_time', true );
        $event_end_time    = get_post_meta( $page_id, '_frs_event_end_time', true );
        $event_venue       = get_post_meta( $page_id, '_frs_event_venue', true );
        $event_description = get_post_meta( $page_id, '_frs_event_description', true );

        // Value propositions
        $value_props = get_post_meta( $page_id, '_frs_value_props', true );

        // LO and Realtor IDs
        $lo_id      = get_post_meta( $page_id, '_frs_loan_officer_id', true );
        $realtor_id = get_post_meta( $page_id, '_frs_realtor_id', true );

        // Get LO data
        $lo_data = self::get_lo_data( $lo_id, $page_id );

        // Get Realtor data
        $realtor_data = self::get_realtor_data( $realtor_id, $page_id );

        // Accent colors by page type
        $accent_colors = [
            'open_house'          => '#0ea5e9',
            'customer_spotlight'  => '#10b981',
            'special_event'       => '#f59e0b',
            'mortgage_calculator' => '#8b5cf6',
        ];
        $accent_color = $accent_colors[ $page_type ] ?? '#0ea5e9';

        // Badge labels
        $badge_labels = [
            'open_house'          => 'Open House',
            'customer_spotlight'  => 'Customer Spotlight',
            'special_event'       => 'Special Event',
            'mortgage_calculator' => 'Mortgage Calculator',
        ];

        // Gradient colors for calculator
        $gradient_start = get_post_meta( $page_id, '_frs_gradient_start', true ) ?: '#252526';
        $gradient_end   = get_post_meta( $page_id, '_frs_gradient_end', true ) ?: '#1f1f1f';

        // Brokerage logo
        $brokerage_logo = get_post_meta( $page_id, '_frs_brokerage_logo', true );
        if ( $brokerage_logo ) {
            $brokerage_logo = \FRSLeadPages\frs_normalize_upload_url( $brokerage_logo );
        }

        return [
            'page_id'            => $page_id,
            'page_type'          => $page_type,
            'headline'           => $headline,
            'subheadline'        => $subheadline,
            'consent_text'       => $consent_text,
            'hero_image_url'     => $hero_image_url,
            'form_id'            => $form_id,
            'property_address'   => $property_address,
            'property_price'     => $property_price,
            'property_beds'      => $property_beds,
            'property_baths'     => $property_baths,
            'event_name'         => $event_name,
            'event_date'         => $event_date,
            'event_time'         => $event_time,
            'event_end_time'     => $event_end_time,
            'event_venue'        => $event_venue,
            'event_description'  => $event_description,
            'value_props'        => $value_props,
            'lo_id'              => $lo_id,
            'lo_data'            => $lo_data,
            'realtor_id'         => $realtor_id,
            'realtor_data'       => $realtor_data,
            'accent_color'       => $accent_color,
            'badge_labels'       => $badge_labels,
            'gradient_start'     => $gradient_start,
            'gradient_end'       => $gradient_end,
            'brokerage_logo'     => $brokerage_logo,
        ];
    }

    /**
     * Get LO data from user ID
     *
     * @param int|string $lo_id   User ID
     * @param int        $page_id Page ID for page-specific photo override
     * @return array
     */
    private static function get_lo_data( $lo_id, int $page_id = 0 ): array {
        if ( ! $lo_id ) {
            return [];
        }

        $lo_user = get_user_by( 'ID', $lo_id );
        if ( ! $lo_user ) {
            return [];
        }

        // Check for page-specific LO photo first (from wizard upload in BUG #7 fix)
        $photo = '';
        if ( $page_id ) {
            $page_photo = get_post_meta( $page_id, '_frs_lo_photo', true );
            if ( $page_photo ) {
                $photo = \FRSLeadPages\frs_normalize_upload_url( $page_photo );
            }
        }
        
        // Fall back to user's default photo if no page-specific override
        if ( empty( $photo ) ) {
            $photo = self::get_user_photo( $lo_id );
        }

        // Prefer the team info the LO entered in the wizard (saved per-page)
        // over their profile data, so their "Your Team Info" edits actually
        // populate the published page. Profile values are the fallback.
        $page_name  = $page_id ? get_post_meta( $page_id, '_frs_lo_name', true )  : '';
        $page_email = $page_id ? get_post_meta( $page_id, '_frs_lo_email', true ) : '';
        $page_phone = $page_id ? get_post_meta( $page_id, '_frs_lo_phone', true ) : '';
        $page_nmls  = $page_id ? get_post_meta( $page_id, '_frs_lo_nmls', true )  : '';

        return [
            'id'         => $lo_id,
            'name'       => $page_name ?: $lo_user->display_name,
            'first_name' => $lo_user->first_name,
            'last_name'  => $lo_user->last_name,
            'email'      => $page_email ?: $lo_user->user_email,
            'phone'      => $page_phone ?: ( get_user_meta( $lo_id, 'phone', true ) ?: get_user_meta( $lo_id, 'phone_number', true ) ?: get_user_meta( $lo_id, 'mobile_phone', true ) ),
            'nmls'       => $page_nmls ?: \FRSLeadPages\frs_get_user_nmls( $lo_id ),
            'title'      => get_user_meta( $lo_id, 'job_title', true ) ?: 'Loan Officer',
            'company'    => '21st Century Lending',
            'photo'      => $photo,
        ];
    }

    /**
     * Get Realtor data from user ID or manual entry
     *
     * @param int|string $realtor_id User ID
     * @param int        $page_id    Page ID for manual fallback
     * @return array
     */
    private static function get_realtor_data( $realtor_id, int $page_id ): array {
        if ( $realtor_id ) {
            $realtor_user = get_user_by( 'ID', $realtor_id );
            if ( $realtor_user ) {
                // Check for page-specific realtor photo first (from wizard upload in BUG #7 fix)
                $photo = '';
                $page_photo = get_post_meta( $page_id, '_frs_realtor_photo', true );
                if ( $page_photo ) {
                    $photo = \FRSLeadPages\frs_normalize_upload_url( $page_photo );
                } else {
                    $photo = self::get_user_photo( $realtor_id );
                }
                
                return [
                    'id'         => $realtor_id,
                    'name'       => $realtor_user->display_name,
                    'first_name' => $realtor_user->first_name,
                    'last_name'  => $realtor_user->last_name,
                    'email'      => $realtor_user->user_email,
                    'phone'      => get_user_meta( $realtor_id, 'phone', true ) ?: get_user_meta( $realtor_id, 'phone_number', true ) ?: get_user_meta( $realtor_id, 'mobile_phone', true ),
                    'title'      => get_user_meta( $realtor_id, 'job_title', true ) ?: 'Sales Associate',
                    'license'    => get_user_meta( $realtor_id, 'license_number', true ) ?: get_user_meta( $realtor_id, 'dre_license', true ),
                    'company'    => get_user_meta( $realtor_id, 'company', true ) ?: get_user_meta( $realtor_id, 'brokerage', true ) ?: '',
                    'photo'      => $photo,
                ];
            }
        }

        // Fallback to manual entry
        $realtor_name = get_post_meta( $page_id, '_frs_realtor_name', true );
        if ( $realtor_name ) {
            return [
                'id'         => 0,
                'name'       => $realtor_name,
                'first_name' => '',
                'last_name'  => '',
                'email'      => get_post_meta( $page_id, '_frs_realtor_email', true ),
                'phone'      => get_post_meta( $page_id, '_frs_realtor_phone', true ),
                'title'      => 'Sales Associate',
                'license'    => get_post_meta( $page_id, '_frs_realtor_license', true ),
                'company'    => get_post_meta( $page_id, '_frs_realtor_company', true ),
                'photo'      => ( $p = get_post_meta( $page_id, '_frs_realtor_photo', true ) ) ? \FRSLeadPages\frs_normalize_upload_url( $p ) : '',
            ];
        }

        return [];
    }

    /**
     * Get user photo from multiple sources
     *
     * @param int $user_id User ID
     * @return string Photo URL
     */
    public static function get_user_photo( int $user_id ): string {
        if ( $user_id <= 0 ) {
            return '';
        }

        // Single source of truth for FRS user headshots across the whole
        // network: \FRSUsers\Core\Avatar::get_url() (CDN -> main-site
        // attachment, always full size, no size rewrites). Every context —
        // directory, profiles, wizards, published pages — must resolve the
        // same photo, so go through it first. Fully guarded: a missing
        // class/method or any runtime error can never fatal a public page.
        try {
            if ( is_callable( [ '\FRSUsers\Core\Avatar', 'get_url' ] ) ) {
                $url = \FRSUsers\Core\Avatar::get_url( $user_id );
                if ( is_string( $url ) && $url !== '' ) {
                    return $url; // already a complete, correct URL — do not rewrite.
                }
            }
        } catch ( \Throwable $e ) {
            // ignore and try fallbacks
        }

        // Optional legacy meta sources — any of these may be absent; never
        // assume a key exists.
        try {
            foreach ( [ 'user_profile_photo', 'custom_avatar_url', 'profile_photo' ] as $meta_key ) {
                $value = get_user_meta( $user_id, $meta_key, true );
                if ( is_string( $value ) && $value !== '' ) {
                    return \FRSLeadPages\frs_normalize_upload_url( $value );
                }
            }
            $simple = get_user_meta( $user_id, 'simple_local_avatar', true );
            if ( is_array( $simple ) && ! empty( $simple['full'] ) && is_string( $simple['full'] ) ) {
                return \FRSLeadPages\frs_normalize_upload_url( $simple['full'] );
            }
        } catch ( \Throwable $e ) {
            // ignore and try the final fallback
        }

        // Last resort: the WordPress avatar (no forced pixel size that might
        // not exist), but never the gravatar mystery-person placeholder.
        try {
            $avatar = get_avatar_url( $user_id );
            if ( is_string( $avatar ) && $avatar !== '' && strpos( $avatar, 'gravatar.com/avatar' ) === false ) {
                return \FRSLeadPages\frs_normalize_upload_url( $avatar );
            }
        } catch ( \Throwable $e ) {
            // ignore
        }

        return '';
    }

    /**
     * Get form headers based on page type
     *
     * @param string $page_type Page type
     * @return array
     */
    public static function get_form_headers( string $page_type ): array {
        $headers = [
            'open_house' => [
                'title'    => 'Sign In to View This Property',
                'subtitle' => 'Fill out the form below and we\'ll send you more details',
            ],
            'customer_spotlight' => [
                'title'    => 'Get Your Free Assessment',
                'subtitle' => 'Answer a few quick questions and we\'ll reach out with your personalized evaluation',
            ],
            'special_event' => [
                'title'    => 'Register for This Event',
                'subtitle' => 'Secure your spot and we\'ll send you all the details',
            ],
            'mortgage_calculator' => [
                'title'    => 'Get Your Personalized Results',
                'subtitle' => 'Complete the form to receive your custom mortgage analysis',
            ],
        ];

        return $headers[ $page_type ] ?? $headers['customer_spotlight'];
    }

    /**
     * Build calendar URLs for events
     *
     * @param array $data Page data
     * @return array
     */
    public static function get_calendar_urls( array $data ): array {
        if ( empty( $data['event_name'] ) || empty( $data['event_date'] ) ) {
            return [];
        }

        $event_start = $data['event_date'];
        $event_end   = $data['event_date'];

        // Parse time if available
        if ( $data['event_time'] ) {
            $start_datetime = strtotime( $data['event_date'] . ' ' . $data['event_time'] );
            $event_start    = date( 'Ymd\THis', $start_datetime );

            // End time: use provided end time or default to 2 hours after start
            if ( $data['event_end_time'] ) {
                $end_datetime = strtotime( $data['event_date'] . ' ' . $data['event_end_time'] );
            } else {
                $end_datetime = $start_datetime + ( 2 * 60 * 60 ); // 2 hours
            }
            $event_end = date( 'Ymd\THis', $end_datetime );
        } else {
            // All-day event
            $event_start = date( 'Ymd', strtotime( $data['event_date'] ) );
            $event_end   = date( 'Ymd', strtotime( $data['event_date'] . ' +1 day' ) );
        }

        $cal_title       = $data['event_name'];
        $cal_description = $data['event_description'] ?: $data['subheadline'] ?: '';
        $cal_location    = $data['event_venue'] ?: '';

        // Google Calendar URL
        $google_cal_url = add_query_arg( [
            'action'   => 'TEMPLATE',
            'text'     => $cal_title,
            'dates'    => $event_start . '/' . $event_end,
            'details'  => $cal_description,
            'location' => $cal_location,
        ], 'https://calendar.google.com/calendar/render' );

        // Outlook Web URL
        $outlook_url = add_query_arg( [
            'path'     => '/calendar/action/compose',
            'rru'      => 'addevent',
            'subject'  => $cal_title,
            'startdt'  => $event_start,
            'enddt'    => $event_end,
            'body'     => $cal_description,
            'location' => $cal_location,
        ], 'https://outlook.live.com/calendar/0/deeplink/compose' );

        // ICS file URL
        $ics_url = add_query_arg( [
            'frs_calendar_event' => $data['page_id'],
            'format'             => 'ics',
        ], get_permalink( $data['page_id'] ) );

        return [
            'google'  => $google_cal_url,
            'outlook' => $outlook_url,
            'ics'     => $ics_url,
        ];
    }
}

<?php
/**
 * Plugin Name: FRS Lead Pages
 * Plugin URI: https://myhub21.com
 * Description: Lead generation landing page builder with multi-step wizard. Create Open House, Customer Spotlight, and Event pages with LO/Realtor co-branding.
 * Version: 1.5.15
 * Author: Derin Tolu / FRS Brand Experience Teams
 * Author URI: https://myhub21.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: frs-lead-pages
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Network: true
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'FRS_LEAD_PAGES_VERSION', '1.5.15' );
define( 'FRS_LEAD_PAGES_PLUGIN_FILE', __FILE__ );
define( 'FRS_LEAD_PAGES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRS_LEAD_PAGES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FRS_LEAD_PAGES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Get user NMLS from the most accurate source
 *
 * Priority:
 * 1. FRS Profiles table (frs-wp-users plugin)
 * 2. Linked person post meta
 * 3. User meta fallback
 *
 * @param int $user_id WordPress user ID.
 * @return string NMLS number or empty string.
 */
function frs_get_user_nmls( int $user_id ): string {
    if ( ! $user_id ) {
        return '';
    }

    // 1. Check FRS Profiles table first (most accurate source)
    if ( class_exists( 'FRSUsers\Models\Profile' ) ) {
        $profile = \FRSUsers\Models\Profile::get_by_user_id( $user_id );
        if ( $profile ) {
            $nmls = $profile->nmls ?: $profile->nmls_number;
            if ( ! empty( $nmls ) ) {
                return (string) $nmls;
            }
        }
    }

    // 2. Check linked person post meta
    $profile_id = get_user_meta( $user_id, 'profile', true );
    if ( $profile_id ) {
        $nmls = get_post_meta( $profile_id, 'nmls', true ) ?: get_post_meta( $profile_id, 'nmls_number', true );
        if ( ! empty( $nmls ) ) {
            return (string) $nmls;
        }
    }

    // 3. Fallback to user meta
    $nmls = get_user_meta( $user_id, 'nmls_id', true ) ?: get_user_meta( $user_id, 'nmls', true );
    return (string) $nmls;
}

// Autoloader
spl_autoload_register( function ( $class ) {
    $prefix = 'FRSLeadPages\\';
    $base_dir = FRS_LEAD_PAGES_PLUGIN_DIR . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
});

/**
 * Initialize the plugin
 */
function init() {
    // Initialize capabilities (map_meta_cap filter)
    Core\Capabilities::init();

    // Check if capabilities need updating (plugin update scenario)
    if ( Core\Capabilities::needs_update() ) {
        Core\Capabilities::register();
    }

    // Load core classes
    Core\PostTypes::init();
    Core\PublicDomain::init();
    Core\DomainMapping::init();
    Core\Shortcodes::init();
    Core\Analytics::init();

    // Initialize blocks
    Blocks\LeadStats::init();
    Blocks\LeadStatsTable::init();

    // Initialize integrations
    Core\Submissions::init();
    Integrations\FollowUpBoss::init();

    // Auto-create/update tables on version change (covers deploys without reactivation)
    $db_version = get_option( 'frs_lead_pages_db_version', '0' );
    if ( version_compare( $db_version, FRS_LEAD_PAGES_VERSION, '<' ) ) {
        Core\Submissions::create_table();
        Core\Analytics::create_table();
        update_option( 'frs_lead_pages_db_version', FRS_LEAD_PAGES_VERSION );
    }

    // WP-CLI commands
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        Core\CLI::init();
    }

    // Initialize Partner Portal (multisite support)
    Core\PartnerPortal::init();

    // Initialize Sync Service (cross-site sync)
    Core\SyncService::init();

    // Register cron hook for async sync
    add_action( 'frs_sync_page_to_lender', function( $page_id ) {
        Core\SyncService::push_to_lender( $page_id );
    } );

    // Initialize wizards
    OpenHouse\Wizard::init();
    CustomerSpotlight\Wizard::init();
    SpecialEvent\Wizard::init();
    MortgageCalculator\Wizard::init();
    RateQuote\Wizard::init();
    ApplyNow\Wizard::init();

    // Initialize admin
    if ( is_admin() ) {
        Admin\Settings::init();
        Admin\Dashboard::init();
        Admin\Submissions::init();
        Admin\PortalSettings::init();
    }

    // Load REST API routes
    add_action( 'rest_api_init', function() {
        Routes\Api::register_routes();
    });

    // AJAX handlers for deleting from frontend
    add_action( 'wp_ajax_frs_delete_lead', __NAMESPACE__ . '\\ajax_delete_lead' );
    add_action( 'wp_ajax_frs_delete_lead_page', __NAMESPACE__ . '\\ajax_delete_lead_page' );
    add_action( 'wp_ajax_frs_get_analytics', __NAMESPACE__ . '\\ajax_get_analytics' );

    // Shared photo upload endpoint used by the wizards (LO headshot + partner photo).
    add_action( 'wp_ajax_frs_lp_upload_photo', __NAMESPACE__ . '\\ajax_upload_photo' );

    // Generate QR code on page publish (Open House only)
    add_action( 'save_post_frs_lead_page', __NAMESPACE__ . '\\maybe_generate_qr', 10, 2 );
}

/**
 * AJAX handler to delete a lead
 */
function ajax_delete_lead() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'frs_delete_lead' ) ) {
        wp_send_json_error( 'Security check failed' );
    }

    // Check if user is logged in
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'You must be logged in' );
    }

    $lead_id = isset( $_POST['lead_id'] ) ? sanitize_text_field( $_POST['lead_id'] ) : '';

    if ( empty( $lead_id ) ) {
        wp_send_json_error( 'Invalid lead ID' );
    }

    global $wpdb;

    // Handle different lead sources (lrg_ prefix = wp_lead_submissions, frs_ prefix = frs_lead_submissions)
    if ( strpos( $lead_id, 'lrg_' ) === 0 ) {
        // Delete from wp_lead_submissions table
        $real_id = absint( str_replace( 'lrg_', '', $lead_id ) );
        $table = $wpdb->prefix . 'lead_submissions';
        $deleted = $wpdb->delete( $table, [ 'id' => $real_id ], [ '%d' ] );
    } elseif ( strpos( $lead_id, 'frs_' ) === 0 ) {
        // Delete from frs_lead_submissions table
        $real_id = absint( str_replace( 'frs_', '', $lead_id ) );
        $deleted = Core\Submissions::delete( $real_id );
    } else {
        // Try as a bare numeric ID (frs_lead_submissions)
        $real_id = absint( $lead_id );
        if ( $real_id ) {
            $deleted = Core\Submissions::delete( $real_id );
        } else {
            wp_send_json_error( 'Unknown lead type' );
        }
    }

    if ( $deleted ) {
        wp_send_json_success( 'Lead deleted' );
    } else {
        wp_send_json_error( 'Failed to delete lead' );
    }
}

/**
 * AJAX handler to delete a lead page
 */
function ajax_delete_lead_page() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'frs_delete_lead_page' ) ) {
        wp_send_json_error( 'Security check failed' );
    }

    // Check if user is logged in
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'You must be logged in' );
    }

    $page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;

    if ( ! $page_id ) {
        wp_send_json_error( 'Invalid page ID' );
    }

    // Check if it's a lead page
    $post = get_post( $page_id );
    if ( ! $post || $post->post_type !== 'frs_lead_page' ) {
        wp_send_json_error( 'Invalid lead page' );
    }

    // Check if user owns this page (is the LO or realtor assigned)
    $current_user_id = get_current_user_id();
    $lo_id = get_post_meta( $page_id, '_frs_loan_officer_id', true );
    $realtor_id = get_post_meta( $page_id, '_frs_realtor_id', true );

    if ( $post->post_author != $current_user_id && $lo_id != $current_user_id && $realtor_id != $current_user_id && ! current_user_can( 'delete_others_posts' ) ) {
        wp_send_json_error( 'You do not have permission to delete this page' );
    }

    // Delete the page
    $deleted = wp_delete_post( $page_id, true );

    if ( $deleted ) {
        wp_send_json_success( 'Lead page deleted' );
    } else {
        wp_send_json_error( 'Failed to delete lead page' );
    }
}

/**
 * AJAX handler for analytics period switching
 */
function ajax_get_analytics() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'frs_analytics' ) ) {
        wp_send_json_error( 'Security check failed' );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not logged in' );
    }

    $period  = sanitize_text_field( $_POST['period'] ?? '30days' );
    $allowed = [ '30days', 'week', 'all' ];
    if ( ! in_array( $period, $allowed, true ) ) {
        $period = '30days';
    }

    $user_id = get_current_user_id();
    $summary = Core\Analytics::get_user_stats( $user_id, $period );
    $pages   = Core\Analytics::get_user_pages_stats( $user_id, $period );

    $type_labels = [
        'open_house'          => 'Open House',
        'customer_spotlight'  => 'Spotlight',
        'special_event'       => 'Event',
        'mortgage_calculator' => 'Calculator',
        'rate_quote'          => 'Rate Quote',
        'apply_now'           => 'Apply Now',
    ];

    foreach ( $pages as &$page ) {
        $page['type_label'] = $type_labels[ $page['page_type'] ] ?? 'Page';
        $page['views_fmt']           = number_format( $page['views'] );
        $page['qr_scans_fmt']        = number_format( $page['qr_scans'] );
        $page['submissions_fmt']     = number_format( $page['submissions'] );
        $page['conversion_rate_fmt'] = $page['conversion_rate'] . '%';
    }
    unset( $page );

    wp_send_json_success( [
        'summary' => [
            'views'           => number_format( $summary['views'] ),
            'qr_scans'        => number_format( $summary['qr_scans'] ),
            'submissions'     => number_format( $summary['submissions'] ),
            'conversion_rate' => $summary['conversion_rate'] . '%',
        ],
        'pages' => $pages,
    ] );
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Generate QR code when Open House page is published
 */
function maybe_generate_qr( $post_id, $post ) {
    if ( $post->post_status !== 'publish' ) {
        return;
    }

    $page_type = get_post_meta( $post_id, '_frs_page_type', true );

    if ( $page_type === 'open_house' ) {
        $existing = Core\QRCode::get( $post_id );
        if ( ! $existing ) {
            Core\QRCode::generate( $post_id );
        }
    }
}

/**
 * Activation hook
 */
function activate() {
    // Register capabilities for roles
    Core\Capabilities::register();

    // Create submissions table
    Core\Submissions::create_table();

    // Create analytics table
    Core\Analytics::create_table();

    // Migrate existing view counts to new tracking system
    Core\Analytics::migrate_existing_counts();

    // Flush rewrite rules for custom post types
    Core\PostTypes::register();
    flush_rewrite_rules();

    // (Re)build the public-domain mapping artifacts if the feature is enabled.
    Core\DomainMapping::sync();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation hook
 */
function deactivate() {
    // Note: We don't unregister capabilities on deactivation
    // to preserve user access if plugin is temporarily disabled.
    // Capabilities are only removed on uninstall.

    // Remove the sunrise mapping + map file so a disabled plugin never leaves a
    // dangling domain map behind.
    Core\DomainMapping::teardown();

    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Uninstall hook - registered separately in uninstall.php
 * To fully remove capabilities, create uninstall.php with:
 * Core\Capabilities::unregister();
 */

/**
 * Handle ICS calendar file download for events
 */
function handle_ics_download() {
    if ( empty( $_GET['frs_calendar_event'] ) || empty( $_GET['format'] ) || $_GET['format'] !== 'ics' ) {
        return;
    }

    $post_id = absint( $_GET['frs_calendar_event'] );
    $post = get_post( $post_id );

    if ( ! $post || $post->post_type !== 'frs_lead_page' ) {
        return;
    }

    $page_type = get_post_meta( $post_id, '_frs_page_type', true );
    if ( $page_type !== 'special_event' ) {
        return;
    }

    // Get event data
    $event_name = get_post_meta( $post_id, '_frs_event_name', true );
    $event_date = get_post_meta( $post_id, '_frs_event_date', true );
    $event_time = get_post_meta( $post_id, '_frs_event_time', true );
    $event_end_time = get_post_meta( $post_id, '_frs_event_end_time', true );
    $event_venue = get_post_meta( $post_id, '_frs_event_venue', true );
    $event_description = get_post_meta( $post_id, '_frs_event_description', true );
    $subheadline = get_post_meta( $post_id, '_frs_subheadline', true );

    if ( ! $event_name || ! $event_date ) {
        return;
    }

    // Build date/time strings
    if ( $event_time ) {
        $start_datetime = strtotime( $event_date . ' ' . $event_time );
        $dtstart = gmdate( 'Ymd\THis\Z', $start_datetime );

        if ( $event_end_time ) {
            $end_datetime = strtotime( $event_date . ' ' . $event_end_time );
        } else {
            $end_datetime = $start_datetime + ( 2 * 60 * 60 ); // 2 hours default
        }
        $dtend = gmdate( 'Ymd\THis\Z', $end_datetime );
    } else {
        // All-day event
        $dtstart = date( 'Ymd', strtotime( $event_date ) );
        $dtend = date( 'Ymd', strtotime( $event_date . ' +1 day' ) );
    }

    $description = $event_description ?: $subheadline ?: '';
    $location = $event_venue ?: '';
    $url = get_permalink( $post_id );
    $uid = 'frs-event-' . $post_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );

    // Generate ICS content
    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//FRS Lead Pages//Event Calendar//EN\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:" . esc_ics( $uid ) . "\r\n";
    $ics .= "DTSTAMP:" . gmdate( 'Ymd\THis\Z' ) . "\r\n";

    if ( $event_time ) {
        $ics .= "DTSTART:" . $dtstart . "\r\n";
        $ics .= "DTEND:" . $dtend . "\r\n";
    } else {
        $ics .= "DTSTART;VALUE=DATE:" . $dtstart . "\r\n";
        $ics .= "DTEND;VALUE=DATE:" . $dtend . "\r\n";
    }

    $ics .= "SUMMARY:" . esc_ics( $event_name ) . "\r\n";

    if ( $description ) {
        $ics .= "DESCRIPTION:" . esc_ics( $description ) . "\r\n";
    }

    if ( $location ) {
        $ics .= "LOCATION:" . esc_ics( $location ) . "\r\n";
    }

    $ics .= "URL:" . esc_ics( $url ) . "\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";

    // Send headers and output
    $filename = sanitize_title( $event_name ) . '.ics';

    header( 'Content-Type: text/calendar; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $ics ) );
    header( 'Cache-Control: no-cache, must-revalidate' );
    header( 'Expires: 0' );

    echo $ics;
    exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\handle_ics_download', 1 );

/**
 * Escape string for ICS format
 */
function esc_ics( $string ) {
    $string = str_replace( [ '\\', ';', ',', "\n", "\r" ], [ '\\\\', '\\;', '\\,', '\\n', '' ], $string );
    return $string;
}

/**
 * Handle vCard file download for LO and Realtor contacts
 */
function handle_vcard_download() {
    if ( empty( $_GET['frs_vcard'] ) || empty( $_GET['type'] ) ) {
        return;
    }

    $user_id = sanitize_text_field( $_GET['frs_vcard'] );
    $type = sanitize_text_field( $_GET['type'] );
    $page_id = isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;

    // Get user data based on type
    if ( $user_id === 'manual' && $page_id ) {
        // Manual realtor entry from page meta
        $contact = [
            'first_name' => '',
            'last_name'  => get_post_meta( $page_id, '_frs_realtor_name', true ),
            'name'       => get_post_meta( $page_id, '_frs_realtor_name', true ),
            'email'      => get_post_meta( $page_id, '_frs_realtor_email', true ),
            'phone'      => get_post_meta( $page_id, '_frs_realtor_phone', true ),
            'title'      => 'Sales Associate',
            'company'    => get_post_meta( $page_id, '_frs_realtor_company', true ),
            'license'    => get_post_meta( $page_id, '_frs_realtor_license', true ),
            'photo'      => get_post_meta( $page_id, '_frs_realtor_photo', true ),
        ];
    } else {
        $user_id = absint( $user_id );
        $user = get_user_by( 'ID', $user_id );

        if ( ! $user ) {
            return;
        }

        $contact = [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'name'       => $user->display_name,
            'email'      => $user->user_email,
            'phone'      => get_user_meta( $user_id, 'phone', true ) ?: get_user_meta( $user_id, 'phone_number', true ) ?: get_user_meta( $user_id, 'mobile_phone', true ),
            'title'      => get_user_meta( $user_id, 'job_title', true ),
            'photo'      => '',
        ];

        if ( $type === 'lo' ) {
            $contact['company'] = '21st Century Lending';
            $contact['title'] = $contact['title'] ?: 'Loan Officer';
            $contact['nmls'] = frs_get_user_nmls( (int) $user_id );
        } else {
            $contact['company'] = get_user_meta( $user_id, 'company', true ) ?: get_user_meta( $user_id, 'brokerage', true );
            $contact['title'] = $contact['title'] ?: 'Sales Associate';
            $contact['license'] = get_user_meta( $user_id, 'license_number', true ) ?: get_user_meta( $user_id, 'dre_license', true );
        }

        // Get photo URL
        $contact['photo'] = get_user_photo( $user_id );
    }

    if ( empty( $contact['name'] ) ) {
        return;
    }

    // Generate vCard content
    $vcard = "BEGIN:VCARD\r\n";
    $vcard .= "VERSION:3.0\r\n";

    // Name
    $fn = $contact['first_name'] && $contact['last_name']
        ? $contact['last_name'] . ';' . $contact['first_name'] . ';;;'
        : ';' . $contact['name'] . ';;;';
    $vcard .= "N:" . esc_vcard( $fn ) . "\r\n";
    $vcard .= "FN:" . esc_vcard( $contact['name'] ) . "\r\n";

    // Organization and Title
    if ( ! empty( $contact['company'] ) ) {
        $vcard .= "ORG:" . esc_vcard( $contact['company'] ) . "\r\n";
    }
    if ( ! empty( $contact['title'] ) ) {
        $title = $contact['title'];
        if ( ! empty( $contact['nmls'] ) ) {
            $title .= ' | NMLS# ' . $contact['nmls'];
        } elseif ( ! empty( $contact['license'] ) ) {
            $title .= ' | DRE# ' . $contact['license'];
        }
        $vcard .= "TITLE:" . esc_vcard( $title ) . "\r\n";
    }

    // Email
    if ( ! empty( $contact['email'] ) ) {
        $vcard .= "EMAIL;TYPE=WORK:" . esc_vcard( $contact['email'] ) . "\r\n";
    }

    // Phone
    if ( ! empty( $contact['phone'] ) ) {
        $vcard .= "TEL;TYPE=CELL:" . esc_vcard( $contact['phone'] ) . "\r\n";
    }

    // Photo (base64 encoded if available)
    // SECURITY: Only allow local WordPress uploads to prevent SSRF
    if ( ! empty( $contact['photo'] ) && filter_var( $contact['photo'], FILTER_VALIDATE_URL ) ) {
        $upload_dir = wp_upload_dir();
        $is_local = ( strpos( $contact['photo'], $upload_dir['baseurl'] ) === 0 );
        
        // Only process local WordPress uploads or known trusted attachment URLs
        if ( $is_local || strpos( $contact['photo'], home_url() ) === 0 ) {
            // Use wp_safe_remote_get with timeout and size limits
            $response = wp_safe_remote_get( $contact['photo'], [
                'timeout'    => 5,
                'sslverify'  => true,
                'stream'     => true,
                'filename'   => wp_tempnam(),
            ] );
            
            if ( ! is_wp_error( $response ) ) {
                $status = wp_remote_retrieve_response_code( $response );
                $content_type = wp_remote_retrieve_header( $response, 'content-type' );
                
                // Validate response
                if ( $status === 200 && $content_type ) {
                    // Only allow image MIME types
                    $allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
                    $is_image = false;
                    
                    foreach ( $allowed_types as $type ) {
                        if ( strpos( $content_type, $type ) === 0 ) {
                            $is_image = true;
                            break;
                        }
                    }
                    
                    if ( $is_image ) {
                        $photo_data = wp_remote_retrieve_body( $response );
                        $photo_size = strlen( $photo_data );
                        
                        // Max 5MB for photos
                        if ( $photo_size > 0 && $photo_size < 5242880 ) {
                            $photo_base64 = base64_encode( $photo_data );
                            
                            // Use actual MIME type from headers, not extension
                            $photo_type = 'JPEG';
                            if ( strpos( $content_type, 'png' ) !== false ) {
                                $photo_type = 'PNG';
                            } elseif ( strpos( $content_type, 'gif' ) !== false ) {
                                $photo_type = 'GIF';
                            } elseif ( strpos( $content_type, 'webp' ) !== false ) {
                                $photo_type = 'WEBP';
                            }
                            
                            $vcard .= "PHOTO;ENCODING=b;TYPE=" . $photo_type . ":" . $photo_base64 . "\r\n";
                        }
                    }
                }
            }
        }
    }

    // Note with license/NMLS info
    $note_parts = [];
    if ( ! empty( $contact['nmls'] ) ) {
        $note_parts[] = 'NMLS# ' . $contact['nmls'];
    }
    if ( ! empty( $contact['license'] ) ) {
        $note_parts[] = 'DRE# ' . $contact['license'];
    }
    if ( ! empty( $note_parts ) ) {
        $vcard .= "NOTE:" . esc_vcard( implode( ' | ', $note_parts ) ) . "\r\n";
    }

    $vcard .= "END:VCARD\r\n";

    // Send headers and output
    $filename = sanitize_title( $contact['name'] ) . '.vcf';

    header( 'Content-Type: text/vcard; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $vcard ) );
    header( 'Cache-Control: no-cache, must-revalidate' );
    header( 'Expires: 0' );

    echo $vcard;
    exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\handle_vcard_download', 1 );

/**
 * Escape string for vCard format
 */
function esc_vcard( $string ) {
    $string = str_replace( [ '\\', ';', ',', "\n", "\r" ], [ '\\\\', '\\;', '\\,', '\\n', '' ], $string );
    return $string;
}

/**
 * Multisite-aware wp_get_attachment_url.
 *
 * On multisite with centralized media, WordPress may insert /sites/N/ in
 * upload URLs even though all files live in a shared /uploads/ directory.
 * This strips that segment so URLs resolve to the actual file location.
 *
 * @param int $attachment_id Attachment post ID.
 * @return string URL or empty string.
 */
function frs_get_attachment_url( int $attachment_id ): string {
    if ( ! $attachment_id ) {
        return '';
    }

    $url = wp_get_attachment_url( $attachment_id );

    if ( ! $url && is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
        switch_to_blog( get_main_site_id() );
        $url = wp_get_attachment_url( $attachment_id );
        restore_current_blog();
    }

    return $url ? frs_normalize_upload_url( $url ) : '';
}

/**
 * Multisite-aware wp_get_attachment_image_url.
 *
 * @param int    $attachment_id Attachment post ID.
 * @param string $size          Image size. Default 'full'.
 * @return string URL or empty string.
 */
function frs_get_attachment_image_url( int $attachment_id, string $size = 'full' ): string {
    if ( ! $attachment_id ) {
        return '';
    }

    $url = wp_get_attachment_image_url( $attachment_id, $size );

    if ( ! $url && is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
        switch_to_blog( get_main_site_id() );
        $url = wp_get_attachment_image_url( $attachment_id, $size );
        restore_current_blog();
    }

    return $url ? frs_normalize_upload_url( $url ) : '';
}

/**
 * Strip /sites/N/ from multisite upload URLs.
 *
 * Centralized media libraries store all files in /uploads/ without
 * per-site subdirectories, but WordPress still generates URLs with
 * /sites/N/. This normalizes them.
 *
 * @param string $url The URL to normalize.
 * @return string Normalized URL.
 */
function frs_normalize_upload_url( string $url ): string {
    // Leave non-uploads values (e.g. data: URIs, empty) untouched.
    if ( $url === '' || strpos( $url, '/wp-content/uploads/' ) === false ) {
        return $url;
    }

    // NOTE: Do NOT strip /uploads/sites/N/. On this multisite the files actually
    // live under /uploads/sites/2/ (the stripped /uploads/ path 404s), so the URL
    // WordPress generates is already correct — stripping it breaks headshots/logos.

    // Rewrite the host to the current serving site. Upload URLs are sometimes
    // stored with a stale host (e.g. a .local dev host carried over by a content
    // migration); the files exist on this server, so re-point them at this site
    // so they actually load. Fixes headshots/logos that 404'd on the wrong host.
    if ( preg_match( '#(/wp-content/uploads/.+)$#', $url, $m ) ) {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $host ) {
            $url = ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $m[1];
        }
    }

    return $url;
}

/**
 * Get 21st Century Lending logo URL
 * Can be overridden via admin settings or defaults to plugin asset
 *
 * @return string Logo URL
 */
function get_21c_logo_url(): string {
    // Check if admin has set a custom logo via settings
    $logo_id = get_option( 'frs_lead_pages_21c_logo', 0 );
    if ( $logo_id ) {
        $url = wp_get_attachment_image_url( absint( $logo_id ), 'full' );
        if ( $url ) {
            return $url;
        }
    }
    
    // Default: use uploads path
    return content_url( '/uploads/2025/09/21C-Wordmark-White.svg' );
}

/**
 * Get the realtor/partner logo URL
 *
 * @return string Logo URL or empty string
 */
function get_realtor_logo_url(): string {
    $logo_id = get_option( 'frs_lead_pages_realtor_logo', 0 );
    if ( $logo_id ) {
        $url = wp_get_attachment_image_url( absint( $logo_id ), 'full' );
        if ( $url ) {
            return $url;
        }
    }
    return '';
}

/**
 * Get user photo from multiple sources
 */
function get_user_photo( $user_id ) {
    if ( ! $user_id ) {
        return '';
    }

    // Check FRS Profiles table (headshot_id)
    if ( class_exists( 'FRSUsers\Models\Profile' ) ) {
        $profile = \FRSUsers\Models\Profile::get_by_user_id( $user_id );
        if ( $profile && ! empty( $profile->headshot_id ) ) {
            $url = frs_get_attachment_url( (int) $profile->headshot_id );
            if ( $url ) {
                return $url;
            }
        }
    }

    // Check user_profile_photo meta (SureDash)
    $suredash_photo = get_user_meta( $user_id, 'user_profile_photo', true );
    if ( ! empty( $suredash_photo ) ) {
        return frs_normalize_upload_url( $suredash_photo );
    }

    // Check Simple Local Avatars
    $simple_avatar = get_user_meta( $user_id, 'simple_local_avatar', true );
    if ( ! empty( $simple_avatar ) && ! empty( $simple_avatar['full'] ) ) {
        return frs_normalize_upload_url( $simple_avatar['full'] );
    }

    // Check custom_avatar_url meta
    $custom_avatar = get_user_meta( $user_id, 'custom_avatar_url', true );
    if ( ! empty( $custom_avatar ) ) {
        return frs_normalize_upload_url( $custom_avatar );
    }

    // Check profile_photo meta
    $profile_photo = get_user_meta( $user_id, 'profile_photo', true );
    if ( ! empty( $profile_photo ) ) {
        return frs_normalize_upload_url( $profile_photo );
    }

    return '';
}

/**
 * Shared AJAX handler: upload an image to the media library and return its URL.
 *
 * Used by the lead-page wizards so loan officers can upload a custom headshot
 * and add a custom partner photo. Returns a real, persistent media-library URL
 * (replaces the previous fragile base64 data-URI approach).
 *
 * Expects multipart/form-data with `file`, `nonce` (action: frs_lead_pages).
 */
function ajax_upload_photo(): void {
    check_ajax_referer( 'frs_lead_pages', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'You must be logged in to upload.' ] );
    }

    if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
        wp_send_json_error( [ 'message' => 'No file received.' ] );
    }

    $file = $_FILES['file'];

    // Validate MIME type.
    $allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
    if ( empty( $file['type'] ) || ! in_array( $file['type'], $allowed_types, true ) ) {
        wp_send_json_error( [ 'message' => 'Please upload an image (JPEG, PNG, GIF, or WebP).' ] );
    }

    // Validate size (5 MB).
    if ( ! empty( $file['size'] ) && (int) $file['size'] > 5 * 1024 * 1024 ) {
        wp_send_json_error( [ 'message' => 'Image must be under 5MB.' ] );
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $overrides = [
        'test_form' => false,
        'mimes'     => [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ],
    ];

    $attachment_id = media_handle_upload( 'file', 0, [], $overrides );

    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
    }

    $url = wp_get_attachment_image_url( $attachment_id, 'full' );

    if ( ! $url ) {
        wp_send_json_error( [ 'message' => 'Upload succeeded but no URL was generated.' ] );
    }

    wp_send_json_success( [
        'id'  => (int) $attachment_id,
        'url' => frs_normalize_upload_url( $url ),
    ] );
}

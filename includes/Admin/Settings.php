<?php
/**
 * Admin Settings Page for FRS Lead Pages
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\Admin;

use FRSLeadPages\Integrations\Firecrawl;

class Settings {

    /**
     * Option group name
     */
    const OPTION_GROUP = 'frs_lead_pages_settings';

    /**
     * Initialize admin settings
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'wp_ajax_frs_lead_pages_refresh_los', [ __CLASS__, 'ajax_refresh_los' ] );
        add_action( 'wp_ajax_frs_lead_pages_retry_webhooks', [ __CLASS__, 'ajax_retry_webhooks' ] );
        add_action( 'wp_ajax_frs_lead_pages_test_firecrawl', [ __CLASS__, 'ajax_test_firecrawl' ] );
        add_action( 'admin_notices', [ __CLASS__, 'firecrawl_admin_notices' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_media_uploader' ] );
    }

    /**
     * Enqueue media uploader on settings page
     */
    public static function enqueue_media_uploader( $hook ) {
        if ( $hook !== 'frs_lead_page_page_frs-lead-pages-settings' ) {
            return;
        }
        wp_enqueue_media();
        // WordPress Design System component styles (cards, toggles, etc.).
        wp_enqueue_style( 'wp-components' );
    }

    /**
     * Sanitize a checkbox value to a real boolean.
     *
     * @param mixed $value Raw value.
     * @return bool
     */
    public static function sanitize_checkbox( $value ): bool {
        return ! empty( $value ) && '0' !== $value;
    }

    /**
     * Sanitize a domain down to a bare host (no scheme, path, or trailing slash).
     *
     * @param mixed $value Raw value.
     * @return string
     */
    public static function sanitize_domain( $value ): string {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return '';
        }
        if ( ! preg_match( '#^https?://#i', $value ) ) {
            $value = 'https://' . $value;
        }
        $host = wp_parse_url( $value, PHP_URL_HOST );
        return $host ? strtolower( $host ) : '';
    }

    /**
     * Add admin menu page
     */
    public static function add_menu_page() {
        add_submenu_page(
            'edit.php?post_type=frs_lead_page',
            __( 'Lead Pages Settings', 'frs-lead-pages' ),
            __( 'Settings', 'frs-lead-pages' ),
            'manage_options',
            'frs-lead-pages-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    /**
     * Register settings
     */
    public static function register_settings() {
        // Branding Settings
        register_setting( self::OPTION_GROUP, 'frs_lead_pages_primary_color', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#1e3a5f',
        ] );

        register_setting( self::OPTION_GROUP, 'frs_lead_pages_primary_hover', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#152a45',
        ] );

        // Webhook Settings
        register_setting( self::OPTION_GROUP, 'frs_lead_pages_webhook_url', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ] );

        register_setting( self::OPTION_GROUP, 'frs_lead_pages_webhook_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        // frs-wp-users API Settings
        register_setting( self::OPTION_GROUP, 'frs_lead_pages_users_api_url', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ] );

        register_setting( self::OPTION_GROUP, 'frs_lead_pages_users_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( self::OPTION_GROUP, 'frs_lead_pages_lo_cache_duration', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 3600,
        ] );

        // Firecrawl API Settings
        register_setting( self::OPTION_GROUP, 'frs_lead_pages_firecrawl_api_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        // Logo Settings
        register_setting( self::OPTION_GROUP, 'frs_lead_pages_21c_logo', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );

        register_setting( self::OPTION_GROUP, 'frs_lead_pages_realtor_logo', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );

        // Public Landing-Page Domain
        register_setting( self::OPTION_GROUP, 'frs_lead_pages_public_domain_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default'           => false,
        ] );

        register_setting( self::OPTION_GROUP, 'frs_lead_pages_public_domain', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_domain' ],
            'default'           => '',
        ] );

        add_settings_section(
            'frs_lead_pages_public_domain_section',
            __( 'Public Landing-Page Domain', 'frs-lead-pages' ),
            [ __CLASS__, 'render_public_domain_section' ],
            'frs-lead-pages-settings'
        );

        // Branding Section
        add_settings_section(
            'frs_lead_pages_branding_section',
            __( 'Branding', 'frs-lead-pages' ),
            [ __CLASS__, 'render_branding_section' ],
            'frs-lead-pages-settings'
        );

        add_settings_field(
            'frs_lead_pages_primary_color',
            __( 'Primary Color', 'frs-lead-pages' ),
            [ __CLASS__, 'render_primary_color_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_branding_section'
        );

        add_settings_field(
            'frs_lead_pages_primary_hover',
            __( 'Primary Hover Color', 'frs-lead-pages' ),
            [ __CLASS__, 'render_primary_hover_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_branding_section'
        );

        add_settings_field(
            'frs_lead_pages_21c_logo',
            __( '21st Century Logo', 'frs-lead-pages' ),
            [ __CLASS__, 'render_21c_logo_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_branding_section'
        );

        add_settings_field(
            'frs_lead_pages_realtor_logo',
            __( 'Realtor Partner Logo', 'frs-lead-pages' ),
            [ __CLASS__, 'render_realtor_logo_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_branding_section'
        );

        // Webhook Section
        add_settings_section(
            'frs_lead_pages_webhook_section',
            __( 'n8n Webhook Settings', 'frs-lead-pages' ),
            [ __CLASS__, 'render_webhook_section' ],
            'frs-lead-pages-settings'
        );

        add_settings_field(
            'frs_lead_pages_webhook_url',
            __( 'Webhook URL', 'frs-lead-pages' ),
            [ __CLASS__, 'render_webhook_url_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_webhook_section'
        );

        add_settings_field(
            'frs_lead_pages_webhook_secret',
            __( 'Webhook Secret', 'frs-lead-pages' ),
            [ __CLASS__, 'render_webhook_secret_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_webhook_section'
        );

        // API Section
        add_settings_section(
            'frs_lead_pages_api_section',
            __( 'Loan Officer API Settings', 'frs-lead-pages' ),
            [ __CLASS__, 'render_api_section' ],
            'frs-lead-pages-settings'
        );

        add_settings_field(
            'frs_lead_pages_users_api_url',
            __( 'frs-wp-users API URL', 'frs-lead-pages' ),
            [ __CLASS__, 'render_api_url_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_api_section'
        );

        add_settings_field(
            'frs_lead_pages_users_api_key',
            __( 'API Key', 'frs-lead-pages' ),
            [ __CLASS__, 'render_api_key_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_api_section'
        );

        add_settings_field(
            'frs_lead_pages_lo_cache_duration',
            __( 'Cache Duration', 'frs-lead-pages' ),
            [ __CLASS__, 'render_cache_duration_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_api_section'
        );

        // Firecrawl Section
        add_settings_section(
            'frs_lead_pages_firecrawl_section',
            __( 'Firecrawl Property Lookup', 'frs-lead-pages' ),
            [ __CLASS__, 'render_firecrawl_section' ],
            'frs-lead-pages-settings'
        );

        add_settings_field(
            'frs_lead_pages_firecrawl_api_key',
            __( 'API Key', 'frs-lead-pages' ),
            [ __CLASS__, 'render_firecrawl_api_key_field' ],
            'frs-lead-pages-settings',
            'frs_lead_pages_firecrawl_section'
        );
    }

    /**
     * Render settings page
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $failed_webhooks = get_option( 'frs_lead_pages_failed_webhooks', [] );
        $failed_count = count( $failed_webhooks );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php settings_errors( 'frs_lead_pages_messages' ); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( 'frs-lead-pages-settings' );
                submit_button( __( 'Save Settings', 'frs-lead-pages' ) );
                ?>
            </form>

            <hr>

            <!-- Failed Webhooks Section -->
            <h2><?php _e( 'Failed Webhooks', 'frs-lead-pages' ); ?></h2>
            <?php if ( $failed_count > 0 ) : ?>
                <p class="description" style="color: #d63638;">
                    <?php printf( __( '%d webhook(s) failed to send.', 'frs-lead-pages' ), $failed_count ); ?>
                </p>
                <button type="button" class="button" id="frs-retry-webhooks">
                    <?php _e( 'Retry Failed Webhooks', 'frs-lead-pages' ); ?>
                </button>
                <span id="frs-retry-result" style="margin-left: 10px;"></span>
            <?php else : ?>
                <p class="description" style="color: #00a32a;">
                    <?php _e( 'No failed webhooks.', 'frs-lead-pages' ); ?>
                </p>
            <?php endif; ?>

            <hr>

            <!-- Firecrawl Status Section -->
            <h2><?php _e( 'Firecrawl API Status', 'frs-lead-pages' ); ?></h2>
            <?php
            $firecrawl_status = get_transient( 'frs_firecrawl_api_status' );
            $is_configured = Firecrawl::is_configured();
            ?>
            <?php if ( ! $is_configured ) : ?>
                <p class="description" style="color: #d63638;">
                    <span class="dashicons dashicons-warning"></span>
                    <?php _e( 'Firecrawl API key not configured. Property lookup will not work.', 'frs-lead-pages' ); ?>
                </p>
            <?php elseif ( $firecrawl_status === 'error' ) : ?>
                <p class="description" style="color: #d63638;">
                    <span class="dashicons dashicons-no"></span>
                    <?php
                    $error = get_transient( 'frs_firecrawl_last_error' );
                    printf( __( 'Last API call failed: %s', 'frs-lead-pages' ), esc_html( $error ) );
                    ?>
                </p>
            <?php elseif ( $firecrawl_status === 'ok' ) : ?>
                <p class="description" style="color: #00a32a;">
                    <span class="dashicons dashicons-yes"></span>
                    <?php _e( 'API connection verified.', 'frs-lead-pages' ); ?>
                </p>
            <?php else : ?>
                <p class="description">
                    <?php _e( 'API status unknown. Click Test to verify connection.', 'frs-lead-pages' ); ?>
                </p>
            <?php endif; ?>
            <p>
                <button type="button" class="button" id="frs-test-firecrawl" <?php disabled( ! $is_configured ); ?>>
                    <?php _e( 'Test API Connection', 'frs-lead-pages' ); ?>
                </button>
                <span id="frs-firecrawl-result" style="margin-left: 10px;"></span>
            </p>

            <hr>

            <!-- LO Cache Section -->
            <h2><?php _e( 'Loan Officer Cache', 'frs-lead-pages' ); ?></h2>
            <?php
            $cached_los = get_transient( 'frs_lead_pages_loan_officers' );
            $lo_count = is_array( $cached_los ) ? count( $cached_los ) : 0;
            ?>
            <p class="description">
                <?php printf( __( 'Currently cached: %d loan officers', 'frs-lead-pages' ), $lo_count ); ?>
            </p>
            <button type="button" class="button" id="frs-refresh-los">
                <?php _e( 'Refresh Loan Officers', 'frs-lead-pages' ); ?>
            </button>
            <span id="frs-refresh-result" style="margin-left: 10px;"></span>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#frs-retry-webhooks').on('click', function() {
                var $btn = $(this);
                var $result = $('#frs-retry-result');
                $btn.prop('disabled', true);
                $result.text('Retrying...');

                $.post(ajaxurl, {
                    action: 'frs_lead_pages_retry_webhooks',
                    _wpnonce: '<?php echo wp_create_nonce( 'frs_lead_pages_retry' ); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.text('Retried ' + response.data.retried + ', succeeded ' + response.data.success);
                        if (response.data.remaining === 0) {
                            location.reload();
                        }
                    } else {
                        $result.text('Error: ' + response.data);
                    }
                });
            });

            $('#frs-refresh-los').on('click', function() {
                var $btn = $(this);
                var $result = $('#frs-refresh-result');
                $btn.prop('disabled', true);
                $result.text('Refreshing...');

                $.post(ajaxurl, {
                    action: 'frs_lead_pages_refresh_los',
                    _wpnonce: '<?php echo wp_create_nonce( 'frs_lead_pages_refresh' ); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.text('Loaded ' + response.data.count + ' loan officers');
                    } else {
                        $result.text('Error: ' + response.data);
                    }
                });
            });

            $('#frs-test-firecrawl').on('click', function() {
                var $btn = $(this);
                var $result = $('#frs-firecrawl-result');
                $btn.prop('disabled', true);
                $result.text('Testing...').css('color', '');

                $.post(ajaxurl, {
                    action: 'frs_lead_pages_test_firecrawl',
                    _wpnonce: '<?php echo wp_create_nonce( 'frs_lead_pages_firecrawl' ); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.text('Connection successful! Credits remaining: ' + response.data.credits).css('color', '#00a32a');
                    } else {
                        $result.text('Error: ' + response.data).css('color', '#d63638');
                    }
                });
            });

            // Logo upload handlers
            $('.frs-upload-logo').on('click', function(e) {
                e.preventDefault();
                var $container = $(this).closest('.frs-logo-upload');
                var $input = $container.find('input[type="hidden"]');
                var $preview = $container.find('.frs-logo-preview');
                var $removeBtn = $container.find('.frs-remove-logo');

                var frame = wp.media({
                    title: '<?php _e( 'Select Logo', 'frs-lead-pages' ); ?>',
                    button: { text: '<?php _e( 'Use This Logo', 'frs-lead-pages' ); ?>' },
                    multiple: false,
                    library: { type: 'image' }
                });

                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $input.val(attachment.id);
                    $preview.find('img').attr('src', attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url);
                    $preview.show();
                    $removeBtn.show();
                });

                frame.open();
            });

            $('.frs-remove-logo').on('click', function(e) {
                e.preventDefault();
                var $container = $(this).closest('.frs-logo-upload');
                $container.find('input[type="hidden"]').val('');
                $container.find('.frs-logo-preview').hide();
                $(this).hide();
            });
        });
        </script>
        <?php
    }

    /**
     * Section callbacks
     */
    public static function render_webhook_section() {
        echo '<p>' . __( 'Configure the n8n webhook for lead routing and automation.', 'frs-lead-pages' ) . '</p>';
    }

    public static function render_api_section() {
        echo '<p>' . __( 'Configure the connection to frs-wp-users for loan officer data.', 'frs-lead-pages' ) . '</p>';
    }

    public static function render_firecrawl_section() {
        echo '<p>' . __( 'Configure Firecrawl API for property address lookup and data enrichment.', 'frs-lead-pages' ) . '</p>';
    }

    public static function render_branding_section() {
        echo '<p>' . __( 'Customize the colors used on landing pages.', 'frs-lead-pages' ) . '</p>';
    }

    /**
     * Public landing-page domain section — modern (WPDS-styled) UI.
     *
     * Lets an admin publish lead pages on a separate public domain (e.g.
     * go.21stcenturylending.com) regardless of the domain the site runs on.
     */
    public static function render_public_domain_section() {
        $enabled = (bool) get_option( 'frs_lead_pages_public_domain_enabled', false );
        $domain  = (string) get_option( 'frs_lead_pages_public_domain', '' );
        $status  = \FRSLeadPages\Core\DomainMapping::status();

        $rows = [
            [ __( 'Multisite network', 'frs-lead-pages' ), is_multisite() ],
        ];
        if ( is_multisite() ) {
            $rows[] = [ __( 'SUNRISE enabled in wp-config.php', 'frs-lead-pages' ), $status['sunrise_constant'] ];
            $rows[] = [ __( 'sunrise.php mapping installed', 'frs-lead-pages' ), $status['sunrise_block'] ];
            $rows[] = [ __( 'Domain map file written', 'frs-lead-pages' ), $status['map_file'] ];
        }
        ?>
        <style>
            .frs-pd-card{max-width:760px;margin:12px 0 4px;border:1px solid #dcdcde;border-radius:8px;background:#fff;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .frs-pd-card__body{padding:20px 24px}
            .frs-pd-row{display:flex;align-items:flex-start;gap:14px;padding:14px 0;border-top:1px solid #f0f0f1}
            .frs-pd-row:first-child{border-top:0;padding-top:0}
            .frs-pd-row__main{flex:1}
            .frs-pd-label{font-weight:600;margin:0 0 2px}
            .frs-pd-help{color:#646970;margin:0;font-size:12.5px}
            .frs-pd-toggle{position:relative;display:inline-block;width:40px;height:22px;flex:0 0 auto;margin-top:2px}
            .frs-pd-toggle input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer;z-index:2}
            .frs-pd-toggle .frs-pd-track{position:absolute;inset:0;background:#a7aaad;border-radius:22px;transition:background .15s ease}
            .frs-pd-toggle .frs-pd-thumb{position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .15s ease;box-shadow:0 1px 2px rgba(0,0,0,.25)}
            .frs-pd-toggle input:checked + .frs-pd-track{background:var(--wp-admin-theme-color,#3858e9)}
            .frs-pd-toggle input:checked + .frs-pd-track + .frs-pd-thumb{transform:translateX(18px)}
            .frs-pd-toggle input:focus-visible + .frs-pd-track{box-shadow:0 0 0 2px #fff,0 0 0 4px var(--wp-admin-theme-color,#3858e9)}
            .frs-pd-domain{display:flex;align-items:stretch;max-width:460px;margin-top:4px}
            .frs-pd-domain .frs-pd-scheme{display:flex;align-items:center;padding:0 10px;background:#f0f0f1;border:1px solid #8c8f94;border-right:0;border-radius:4px 0 0 4px;color:#646970;font-family:Menlo,Consolas,monospace;font-size:13px}
            .frs-pd-domain input[type=text]{flex:1;border-radius:0 4px 4px 0;font-family:Menlo,Consolas,monospace}
            .frs-pd-preview{margin-top:12px;padding:10px 12px;background:#f6f7f7;border-radius:6px;font-size:13px}
            .frs-pd-preview code{background:transparent;color:#1d2327}
            .frs-pd-status{margin-top:6px;border:1px solid #f0f0f1;border-radius:8px;padding:6px 14px;background:#fbfbfc}
            .frs-pd-status .frs-pd-srow{display:flex;align-items:center;gap:8px;padding:7px 0;border-top:1px solid #f0f0f1;font-size:13px}
            .frs-pd-status .frs-pd-srow:first-child{border-top:0}
            .frs-pd-pill{display:inline-flex;align-items:center;gap:5px;font-weight:600;font-size:12px;padding:2px 9px;border-radius:11px}
            .frs-pd-pill.is-on{background:#edfaef;color:#00690c}
            .frs-pd-pill.is-off{background:#fcf0f1;color:#8a2424}
            .frs-pd-disabled{opacity:.5;pointer-events:none}
            .frs-pd-note{margin:8px 0 0;color:#646970;font-size:12.5px}
        </style>
        <div class="frs-pd-card">
            <div class="frs-pd-card__body">

                <div class="frs-pd-row">
                    <label class="frs-pd-toggle">
                        <input type="checkbox" id="frs-pd-enabled" name="frs_lead_pages_public_domain_enabled" value="1" <?php checked( $enabled ); ?> />
                        <span class="frs-pd-track"></span>
                        <span class="frs-pd-thumb"></span>
                    </label>
                    <div class="frs-pd-row__main">
                        <p class="frs-pd-label"><?php esc_html_e( 'Publish lead pages on a separate public domain', 'frs-lead-pages' ); ?></p>
                        <p class="frs-pd-help"><?php esc_html_e( 'Serves only the lead pages on the domain below — outside the SSO-gated site — while the rest of the site stays where it is. Works regardless of the domain this site runs on.', 'frs-lead-pages' ); ?></p>
                    </div>
                </div>

                <div class="frs-pd-row frs-pd-fields <?php echo $enabled ? '' : 'frs-pd-disabled'; ?>" id="frs-pd-fields">
                    <div class="frs-pd-row__main">
                        <p class="frs-pd-label"><label for="frs-pd-domain"><?php esc_html_e( 'Public domain', 'frs-lead-pages' ); ?></label></p>
                        <div class="frs-pd-domain">
                            <span class="frs-pd-scheme">https://</span>
                            <input type="text" id="frs-pd-domain" name="frs_lead_pages_public_domain" value="<?php echo esc_attr( $domain ); ?>" class="regular-text" placeholder="go.21stcenturylending.com" autocapitalize="off" autocorrect="off" spellcheck="false" />
                        </div>
                        <div class="frs-pd-preview">
                            <?php esc_html_e( 'Lead pages will publish to:', 'frs-lead-pages' ); ?>
                            <code id="frs-pd-preview-url">https://<?php echo esc_html( $domain ?: 'your-domain.com' ); ?>/p/your-page/</code>
                        </div>
                        <p class="frs-pd-help" style="margin-top:10px;">
                            <?php esc_html_e( 'Point this domain at this server (DNS / hosting alias) first. On multisite it is mapped to the lead pages via sunrise.php automatically when enabled.', 'frs-lead-pages' ); ?>
                        </p>
                    </div>
                </div>

                <?php if ( is_multisite() ) : ?>
                <div class="frs-pd-row">
                    <div class="frs-pd-row__main">
                        <p class="frs-pd-label" style="display:flex;align-items:center;gap:10px;">
                            <?php esc_html_e( 'Mapping status', 'frs-lead-pages' ); ?>
                            <span class="frs-pd-pill <?php echo $status['ready'] ? 'is-on' : 'is-off'; ?>">
                                <span class="dashicons dashicons-<?php echo $status['ready'] ? 'yes-alt' : 'warning'; ?>" style="font-size:14px;width:14px;height:14px;"></span>
                                <?php echo $status['ready'] ? esc_html__( 'Active', 'frs-lead-pages' ) : esc_html__( 'Not active', 'frs-lead-pages' ); ?>
                            </span>
                        </p>
                        <div class="frs-pd-status">
                            <?php foreach ( $rows as $row ) : ?>
                                <div class="frs-pd-srow">
                                    <span class="dashicons dashicons-<?php echo $row[1] ? 'yes' : 'minus'; ?>" style="color:<?php echo $row[1] ? '#00690c' : '#8a2424'; ?>"></span>
                                    <span><?php echo esc_html( $row[0] ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ( (array) $status['notes'] as $note ) : ?>
                            <p class="frs-pd-note"><span class="dashicons dashicons-info-outline" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span> <?php echo esc_html( $note ); ?></p>
                        <?php endforeach; ?>
                        <p class="frs-pd-note"><?php esc_html_e( 'Status refreshes after you save.', 'frs-lead-pages' ); ?></p>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <script>
        ( function() {
            var toggle = document.getElementById( 'frs-pd-enabled' );
            var fields = document.getElementById( 'frs-pd-fields' );
            var domain = document.getElementById( 'frs-pd-domain' );
            var preview = document.getElementById( 'frs-pd-preview-url' );
            if ( ! toggle ) { return; }
            function sync() {
                if ( fields ) { fields.classList.toggle( 'frs-pd-disabled', ! toggle.checked ); }
            }
            function updatePreview() {
                var host = ( domain.value || 'your-domain.com' ).trim().replace( /^https?:\/\//i, '' ).replace( /\/.*$/, '' );
                preview.textContent = 'https://' + host + '/p/your-page/';
            }
            toggle.addEventListener( 'change', sync );
            if ( domain ) { domain.addEventListener( 'input', updatePreview ); }
            sync();
        } )();
        </script>
        <?php
    }

    public static function render_primary_color_field() {
        $value = get_option( 'frs_lead_pages_primary_color', '#1e3a5f' );
        ?>
        <input type="color" name="frs_lead_pages_primary_color" value="<?php echo esc_attr( $value ); ?>" />
        <input type="text" value="<?php echo esc_attr( $value ); ?>" class="small-text" readonly style="margin-left: 8px;" />
        <p class="description"><?php _e( 'Used for buttons, links, and form focus states.', 'frs-lead-pages' ); ?></p>
        <?php
    }

    public static function render_primary_hover_field() {
        $value = get_option( 'frs_lead_pages_primary_hover', '#152a45' );
        ?>
        <input type="color" name="frs_lead_pages_primary_hover" value="<?php echo esc_attr( $value ); ?>" />
        <input type="text" value="<?php echo esc_attr( $value ); ?>" class="small-text" readonly style="margin-left: 8px;" />
        <p class="description"><?php _e( 'Hover state for buttons and interactive elements.', 'frs-lead-pages' ); ?></p>
        <?php
    }

    public static function render_21c_logo_field() {
        $logo_id = get_option( 'frs_lead_pages_21c_logo', 0 );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        ?>
        <div class="frs-logo-upload" data-field="frs_lead_pages_21c_logo">
            <input type="hidden" name="frs_lead_pages_21c_logo" id="frs_lead_pages_21c_logo" value="<?php echo esc_attr( $logo_id ); ?>" />
            <div class="frs-logo-preview" style="margin-bottom: 10px; <?php echo $logo_url ? '' : 'display:none;'; ?>">
                <img src="<?php echo esc_url( $logo_url ); ?>" style="max-width: 200px; max-height: 80px; background: #333; padding: 10px; border-radius: 4px;" />
            </div>
            <button type="button" class="button frs-upload-logo"><?php _e( 'Select Logo', 'frs-lead-pages' ); ?></button>
            <button type="button" class="button frs-remove-logo" style="<?php echo $logo_url ? '' : 'display:none;'; ?>"><?php _e( 'Remove', 'frs-lead-pages' ); ?></button>
            <p class="description"><?php _e( 'Logo displayed on all lead pages. Recommended: white/light logo on transparent background (SVG or PNG).', 'frs-lead-pages' ); ?></p>
        </div>
        <?php
    }

    public static function render_realtor_logo_field() {
        $logo_id = get_option( 'frs_lead_pages_realtor_logo', 0 );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        ?>
        <div class="frs-logo-upload" data-field="frs_lead_pages_realtor_logo">
            <input type="hidden" name="frs_lead_pages_realtor_logo" id="frs_lead_pages_realtor_logo" value="<?php echo esc_attr( $logo_id ); ?>" />
            <div class="frs-logo-preview" style="margin-bottom: 10px; <?php echo $logo_url ? '' : 'display:none;'; ?>">
                <img src="<?php echo esc_url( $logo_url ); ?>" style="max-width: 200px; max-height: 80px; background: #333; padding: 10px; border-radius: 4px;" />
            </div>
            <button type="button" class="button frs-upload-logo"><?php _e( 'Select Logo', 'frs-lead-pages' ); ?></button>
            <button type="button" class="button frs-remove-logo" style="<?php echo $logo_url ? '' : 'display:none;'; ?>"><?php _e( 'Remove', 'frs-lead-pages' ); ?></button>
            <p class="description"><?php _e( 'Default realtor/partner logo for co-branded pages. Can be overridden per page.', 'frs-lead-pages' ); ?></p>
        </div>
        <?php
    }

    /**
     * Field callbacks
     */
    public static function render_webhook_url_field() {
        $value = get_option( 'frs_lead_pages_webhook_url', '' );
        ?>
        <input type="url" name="frs_lead_pages_webhook_url" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="https://n8n.example.com/webhook/..." />
        <p class="description"><?php _e( 'The n8n webhook URL for lead notifications.', 'frs-lead-pages' ); ?></p>
        <?php
    }

    public static function render_webhook_secret_field() {
        $value = get_option( 'frs_lead_pages_webhook_secret', '' );
        ?>
        <input type="password" name="frs_lead_pages_webhook_secret" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
        <p class="description"><?php _e( 'Optional secret for webhook verification (sent as X-Webhook-Secret header).', 'frs-lead-pages' ); ?></p>
        <?php
    }

    public static function render_api_url_field() {
        $value = get_option( 'frs_lead_pages_users_api_url', '' );
        ?>
        <input type="url" name="frs_lead_pages_users_api_url" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="https://example.com" />
        <p class="description"><?php _e( 'Base URL for frs-wp-users API (e.g., https://hub21.local). Leave empty to use local users.', 'frs-lead-pages' ); ?></p>
        <?php
    }

    public static function render_api_key_field() {
        $value = get_option( 'frs_lead_pages_users_api_key', '' );
        ?>
        <input type="password" name="frs_lead_pages_users_api_key" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
        <p class="description"><?php _e( 'API key for frs-wp-users authentication (if required).', 'frs-lead-pages' ); ?></p>
        <?php
    }

    public static function render_cache_duration_field() {
        $value = get_option( 'frs_lead_pages_lo_cache_duration', 3600 );
        ?>
        <select name="frs_lead_pages_lo_cache_duration">
            <option value="1800" <?php selected( $value, 1800 ); ?>><?php _e( '30 minutes', 'frs-lead-pages' ); ?></option>
            <option value="3600" <?php selected( $value, 3600 ); ?>><?php _e( '1 hour', 'frs-lead-pages' ); ?></option>
            <option value="7200" <?php selected( $value, 7200 ); ?>><?php _e( '2 hours', 'frs-lead-pages' ); ?></option>
            <option value="86400" <?php selected( $value, 86400 ); ?>><?php _e( '24 hours', 'frs-lead-pages' ); ?></option>
        </select>
        <p class="description"><?php _e( 'How long to cache loan officer data.', 'frs-lead-pages' ); ?></p>
        <?php
    }

    public static function render_firecrawl_api_key_field() {
        // Check both possible option names for backward compatibility
        $value = get_option( 'frs_lead_pages_firecrawl_api_key', '' );
        if ( empty( $value ) ) {
            $value = get_option( 'psb_firecrawl_api_key', '' );
        }
        ?>
        <input type="password" name="frs_lead_pages_firecrawl_api_key" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
        <p class="description">
            <?php _e( 'Your Firecrawl API key for property lookups.', 'frs-lead-pages' ); ?>
            <a href="https://firecrawl.dev" target="_blank"><?php _e( 'Get an API key', 'frs-lead-pages' ); ?></a>
        </p>
        <?php
    }

    /**
     * AJAX: Refresh loan officers
     */
    public static function ajax_refresh_los() {
        check_ajax_referer( 'frs_lead_pages_refresh', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        // Clear cache and refresh
        delete_transient( 'frs_lead_pages_loan_officers' );
        $los = \FRSLeadPages\Core\LoanOfficers::get_loan_officers( true );

        wp_send_json_success( [ 'count' => count( $los ) ] );
    }

    /**
     * AJAX: Retry failed webhooks
     */
    public static function ajax_retry_webhooks() {
        check_ajax_referer( 'frs_lead_pages_retry', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $result = \FRSLeadPages\Core\Submissions::retry_failed_webhooks();
        $remaining = count( get_option( 'frs_lead_pages_failed_webhooks', [] ) );

        wp_send_json_success( array_merge( $result, [ 'remaining' => $remaining ] ) );
    }

    /**
     * AJAX: Test Firecrawl API connection
     */
    public static function ajax_test_firecrawl() {
        check_ajax_referer( 'frs_lead_pages_firecrawl', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        if ( ! Firecrawl::is_configured() ) {
            wp_send_json_error( 'API key not configured' );
        }

        // Test with a simple scrape request to check API health
        $api_key = Firecrawl::get_api_key();
        $response = wp_remote_post( 'https://api.firecrawl.dev/v1/scrape', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'url'     => 'https://example.com',
                'formats' => [ 'markdown' ],
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            set_transient( 'frs_firecrawl_api_status', 'error', HOUR_IN_SECONDS );
            set_transient( 'frs_firecrawl_last_error', $response->get_error_message(), HOUR_IN_SECONDS );
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code === 401 || $code === 403 ) {
            set_transient( 'frs_firecrawl_api_status', 'error', HOUR_IN_SECONDS );
            set_transient( 'frs_firecrawl_last_error', 'Invalid API key', HOUR_IN_SECONDS );
            wp_send_json_error( 'Invalid API key' );
        }

        if ( $code === 402 ) {
            set_transient( 'frs_firecrawl_api_status', 'error', HOUR_IN_SECONDS );
            set_transient( 'frs_firecrawl_last_error', 'No credits remaining', HOUR_IN_SECONDS );
            wp_send_json_error( 'No credits remaining. Please add more credits to your Firecrawl account.' );
        }

        if ( $code >= 200 && $code < 300 ) {
            set_transient( 'frs_firecrawl_api_status', 'ok', DAY_IN_SECONDS );
            delete_transient( 'frs_firecrawl_last_error' );

            // Try to get credits info if available
            $credits = isset( $data['creditsUsed'] ) ? 'used ' . $data['creditsUsed'] : 'available';
            wp_send_json_success( [ 'credits' => $credits, 'status' => 'ok' ] );
        }

        // Unknown error
        $error_message = isset( $data['error'] ) ? $data['error'] : 'HTTP ' . $code;
        set_transient( 'frs_firecrawl_api_status', 'error', HOUR_IN_SECONDS );
        set_transient( 'frs_firecrawl_last_error', $error_message, HOUR_IN_SECONDS );
        wp_send_json_error( $error_message );
    }

    /**
     * Display admin notices for Firecrawl API issues
     */
    public static function firecrawl_admin_notices() {
        // Only show on relevant pages
        $screen = get_current_screen();
        if ( ! $screen || ( $screen->post_type !== 'frs_lead_page' && $screen->id !== 'frs_lead_page_page_frs-lead-pages-settings' ) ) {
            return;
        }

        // Check if configured
        if ( ! Firecrawl::is_configured() ) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php _e( 'Lead Pages:', 'frs-lead-pages' ); ?></strong>
                    <?php _e( 'Firecrawl API key is not configured. Property address lookup will not work.', 'frs-lead-pages' ); ?>
                    <a href="<?php echo admin_url( 'edit.php?post_type=frs_lead_page&page=frs-lead-pages-settings' ); ?>">
                        <?php _e( 'Configure now', 'frs-lead-pages' ); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        // Check API status
        $status = get_transient( 'frs_firecrawl_api_status' );
        if ( $status === 'error' ) {
            $error = get_transient( 'frs_firecrawl_last_error' );
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php _e( 'Lead Pages:', 'frs-lead-pages' ); ?></strong>
                    <?php printf( __( 'Firecrawl API error: %s', 'frs-lead-pages' ), esc_html( $error ) ); ?>
                    <a href="<?php echo admin_url( 'edit.php?post_type=frs_lead_page&page=frs-lead-pages-settings' ); ?>">
                        <?php _e( 'Check settings', 'frs-lead-pages' ); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }
}

<?php
/**
 * Open House Wizard
 *
 * Multi-step wizard for creating Open House landing pages.
 * Clean, professional design with Firecrawl property lookup.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\OpenHouse;

use FRSLeadPages\Integrations\Firecrawl;
use FRSLeadPages\Integrations\InstantImages;
use FRSLeadPages\Core\LoanOfficers;
use FRSLeadPages\Core\Realtors;
use FRSLeadPages\Core\UserMode;

class Wizard {

    /**
     * Trigger class for opening modal
     */
    const TRIGGER_CLASS = 'oh-wizard-trigger';

    /**
     * Hash for URL triggering
     */
    const TRIGGER_HASH = 'open-house-wizard';

    /**
     * Initialize
     */
    public static function init() {
        add_shortcode( 'open_house_wizard', [ __CLASS__, 'render' ] );
        add_shortcode( 'open_house_wizard_button', [ __CLASS__, 'render_button' ] );
        add_action( 'wp_ajax_frs_property_lookup', [ __CLASS__, 'ajax_property_lookup' ] );
        add_action( 'wp_ajax_frs_create_open_house', [ __CLASS__, 'ajax_create_open_house' ] );
        add_action( 'wp_ajax_frs_oh_wizard_frame', [ __CLASS__, 'render_iframe_content' ] );

        // Add modal to footer on frontend
    }

    /**
     * Render iframe content (standalone page for the form)
     */
    public static function render_iframe_content(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Not authorized' );
        }

        $user = wp_get_current_user();
        $allowed_roles = [ 'administrator', 'editor', 'author', 'contributor', 'loan_officer', 'realtor_partner' ];

        if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
            wp_die( 'Not authorized' );
        }

        // Output standalone HTML page with just the form
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow-y: auto; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #fff; }
    </style>
    <?php echo self::render_iframe_styles(); ?>
</head>
<body>
    <?php echo self::render_form_content(); ?>
    <script>var ajaxurl = '<?php echo admin_url( 'admin-ajax.php' ); ?>';</script>
    <?php echo self::render_scripts(); ?>
</body>
</html>
        <?php
        exit;
    }

    /**
     * Render trigger button shortcode
     *
     * Usage: [open_house_wizard_button text="Create Open House" class="my-class"]
     */
    public static function render_button( array $atts = [] ): string {
        $atts = shortcode_atts([
            'text'  => 'Create Open House',
            'class' => '',
        ], $atts, 'open_house_wizard_button' );

        $classes = self::TRIGGER_CLASS;
        if ( ! empty( $atts['class'] ) ) {
            $classes .= ' ' . esc_attr( $atts['class'] );
        }

        return sprintf(
            '<button type="button" class="%s">%s</button>',
            esc_attr( $classes ),
            esc_html( $atts['text'] )
        );
    }

    /**
     * Render modal container in footer
     */
    public static function render_modal_container(): void {
        // Only render if user can access
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user = wp_get_current_user();
        $allowed_roles = [ 'administrator', 'editor', 'author', 'contributor', 'loan_officer', 'realtor_partner' ];

        if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
            return;
        }

        // Scripts are enqueued in Dashboard::enqueue_wizard_scripts()
        echo self::render_modal();
    }

    /**
     * Render the wizard (inline version)
     */
    public static function render( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return self::render_login_required();
        }

        $user = wp_get_current_user();
        $allowed_roles = [ 'administrator', 'editor', 'author', 'contributor', 'loan_officer', 'realtor_partner' ];

        if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
            return self::render_access_denied();
        }

        return self::render_wizard_content();
    }

    /**
     * Render modal version
     */
    private static function render_modal(): string {
        ob_start();
        ?>
        <div id="oh-wizard-modal" class="oh-modal">
            <div class="oh-modal__backdrop"></div>
            <div class="oh-modal__container">
                <button type="button" class="oh-modal__close" aria-label="Close">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <?php echo self::render_wizard_content( true ); ?>
            </div>
        </div>
        <?php echo self::render_modal_styles(); ?>
        <?php echo self::render_modal_scripts(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render wizard content
     */
    private static function render_wizard_content( bool $is_modal = false ): string {
        // Determine user mode (Loan Officer or Realtor)
        $user_mode = UserMode::get_mode();
        $is_loan_officer = UserMode::is_loan_officer();
        $partner_config = UserMode::get_partner_step_config();

        // Get current user data for pre-fill
        $user = wp_get_current_user();
        $user_data = UserMode::get_current_user_data();
        $user_data['mode'] = $user_mode;

        // Get partners based on user mode
        // LOs select Realtors, Realtors select LOs
        $partners = $partner_config['partners'];

        ob_start();
        ?>
        <div id="oh-wizard" class="oh-wizard" data-user='<?php echo esc_attr( wp_json_encode( $user_data ) ); ?>'>
            <div class="oh-wizard__hero">
                <div class="oh-wizard__hero-content">
                    <h1>Create Your<br>Open House Page</h1>
                    <p>Build a professional sign-in page for your property tour in just a few steps.</p>
                </div>
            </div>

            <div class="oh-wizard__form">
                <div class="oh-wizard__progress">
                    <div class="oh-wizard__progress-bar" style="width: 12.5%"></div>
                </div>

                <div class="oh-wizard__header">
                    <p class="oh-wizard__title">Open House Wizard</p>
                    <p class="oh-wizard__subtitle">Step <span id="oh-step-num">1</span> of 9</p>
                </div>

                <div class="oh-wizard__nav-top">
                    <button type="button" id="oh-back-top" class="oh-btn oh-btn--ghost oh-btn--sm" style="display:none;">Back</button>
                    <button type="button" id="oh-next-top" class="oh-btn oh-btn--primary oh-btn--sm">Continue</button>
                </div>

                <div class="oh-wizard__content">
                <!-- Step 0: Page Type Selection -->
                <div class="oh-step" data-step="0">
                    <div class="oh-step__header">
                        <h2><?php echo $is_loan_officer ? 'What type of page?' : esc_html( $partner_config['title'] ); ?></h2>
                        <p><?php echo $is_loan_officer ? 'Choose how you want to brand this page' : esc_html( $partner_config['subtitle'] ); ?></p>
                    </div>
                    <div class="oh-step__body">
                        <?php if ( $is_loan_officer ) : ?>
                            <!-- LO Mode: Choose Solo or Co-branded -->
                            <input type="hidden" id="oh-page-type" name="page_type" value="">
                            <input type="hidden" id="oh-partner" name="partner" value="">

                            <div class="oh-page-type-cards">
                                <div class="oh-page-type-card" data-type="solo">
                                    <div class="oh-page-type-card__icon">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                    </div>
                                    <h3>Solo Page</h3>
                                    <p>Just your branding</p>
                                </div>
                                <div class="oh-page-type-card" data-type="cobranded">
                                    <div class="oh-page-type-card__icon">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="9" cy="8" r="3.5"/>
                                            <circle cx="15" cy="8" r="3.5"/>
                                            <path d="M3 21v-2a4 4 0 0 1 4-4h2"/>
                                            <path d="M15 15h2a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                    </div>
                                    <h3>Co-branded</h3>
                                    <p>With a partner</p>
                                </div>
                            </div>

                            <!-- Partner info (shown when co-branded is selected) -->
                            <div id="oh-partner-selection" class="oh-partner-selection" style="display: none;">
                                <p class="oh-section-label" style="margin-top:24px;">Partner Real Estate Agent</p>
                                <div class="oh-row">
                                    <div class="oh-field oh-field--half">
                                        <label class="oh-label">Partner Name</label>
                                        <input type="text" id="oh-partner-name-input" class="oh-input" placeholder="Jane Smith">
                                    </div>
                                    <div class="oh-field oh-field--half">
                                        <label class="oh-label">Phone</label>
                                        <input type="tel" id="oh-partner-phone-input" class="oh-input" placeholder="(555) 123-4567">
                                    </div>
                                </div>
                                <div class="oh-field">
                                    <label class="oh-label">Email</label>
                                    <input type="email" id="oh-partner-email-input" class="oh-input" placeholder="jane@realestate.com">
                                </div>

                                <!-- Partner Headshot Upload -->
                                <div class="oh-field" style="margin-top: 24px;">
                                    <label class="oh-label">Partner Headshot (optional)</label>
                                    <div class="oh-photo-upload" id="oh-partner-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="oh-partner-photo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="oh-partner-photo-preview" style="margin-top: 12px; display: none;">
                                        <img id="oh-partner-photo-preview-img" src="" alt="Headshot preview" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                                        <button type="button" id="oh-partner-photo-remove" class="oh-btn oh-btn--ghost oh-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="oh-partner-photo-url" value="">
                                </div>

                                <!-- Company Logo Upload -->
                                <div class="oh-field" style="margin-top: 16px;">
                                    <label class="oh-label">Company Logo (optional)</label>
                                    <div class="oh-photo-upload" id="oh-partner-logo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="oh-partner-logo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="oh-partner-logo-preview" style="margin-top: 12px; display: none;">
                                        <img id="oh-partner-logo-preview-img" src="" alt="Logo preview" style="width: 120px; height: 120px; border-radius: 8px; object-fit: contain; background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0;">
                                        <button type="button" id="oh-partner-logo-remove" class="oh-btn oh-btn--ghost oh-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="oh-partner-logo-url" value="">
                                </div>
                                <p class="oh-helper">Both images are optional — add whichever you want on the landing page</p>
                            </div>
                        <?php else : ?>
                            <!-- Partner Mode: Select LO (required) -->
                            <label class="oh-label"><?php echo esc_html( $partner_config['label'] ); ?></label>
                            <div class="oh-dropdown" id="oh-partner-dropdown"
                                 data-mode="<?php echo esc_attr( $user_mode ); ?>"
                                 data-required="true"
                                 data-preferred="<?php echo esc_attr( $partner_config['preferred_id'] ?? 0 ); ?>"
                                 data-auto-selected="<?php echo ( $partner_config['auto_selected'] ?? false ) ? 'true' : 'false'; ?>">
                                <input type="hidden" id="oh-partner" name="partner" value="">
                                <button type="button" class="oh-dropdown__trigger">
                                    <span class="oh-dropdown__value"><?php echo esc_html( $partner_config['placeholder'] ); ?></span>
                                    <svg class="oh-dropdown__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <div class="oh-dropdown__menu">
                                    <?php foreach ( $partners as $partner ) : ?>
                                        <?php
                                        $partner_id = $partner['user_id'] ?? $partner['id'];
                                        $partner_name = $partner['name'];
                                        $partner_photo = $partner['photo_url'] ?? '';
                                        $partner_email = $partner['email'] ?? '';
                                        $partner_phone = $partner['phone'] ?? '';
                                        $partner_nmls = $partner['nmls'] ?? '';
                                        $is_preferred = ( (int) $partner_id === (int) ( $partner_config['preferred_id'] ?? 0 ) );
                                        ?>
                                        <div class="oh-dropdown__item<?php echo $is_preferred ? ' oh-dropdown__item--preferred' : ''; ?>"
                                             data-value="<?php echo esc_attr( $partner_id ); ?>"
                                             data-name="<?php echo esc_attr( $partner_name ); ?>"
                                             data-nmls="<?php echo esc_attr( $partner_nmls ); ?>"
                                             data-photo="<?php echo esc_attr( $partner_photo ); ?>"
                                             data-email="<?php echo esc_attr( $partner_email ); ?>"
                                             data-phone="<?php echo esc_attr( $partner_phone ); ?>">
                                            <img src="<?php echo esc_url( $partner_photo ); ?>" alt="" class="oh-dropdown__photo">
                                            <div class="oh-dropdown__info">
                                                <span class="oh-dropdown__name"><?php echo esc_html( $partner_name ); ?></span>
                                                <?php if ( $partner_nmls ) : ?>
                                                    <span class="oh-dropdown__nmls">NMLS# <?php echo esc_html( $partner_nmls ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( $is_preferred ) : ?>
                                                <span class="oh-dropdown__preferred-badge">★ Preferred</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="oh-helper"><?php echo esc_html( $partner_config['helper'] ); ?></p>

                            <?php if ( $partner_config['show_remember'] ?? false ) : ?>
                                <label class="oh-checkbox" style="margin-top: 12px;">
                                    <input type="checkbox" id="oh-remember-partner" name="remember_partner" value="1">
                                    <span class="oh-checkbox__label">Remember my choice for next time</span>
                                </label>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 1: Property Lookup -->
                <div class="oh-step" data-step="1" style="display:none;">
                    <div class="oh-step__header">
                        <h2>Find the Property</h2>
                        <p>Enter the listing URL or address</p>
                    </div>
                    <div class="oh-step__body">
                        <div class="oh-tabs">
                            <button type="button" class="oh-tab oh-tab--active" data-tab="url">Listing URL</button>
                            <button type="button" class="oh-tab" data-tab="address">Address</button>
                        </div>

                        <div class="oh-tab-content" data-tab="url">
                            <label class="oh-label">Listing URL</label>
                            <input type="url" id="oh-listing-url" class="oh-input" placeholder="https://zillow.com/homedetails/...">
                            <p class="oh-helper">Paste a link from Zillow, Redfin, or Realtor.com</p>
                        </div>

                        <div class="oh-tab-content" data-tab="address" style="display:none;">
                            <label class="oh-label">Property Address</label>
                            <input type="text" id="oh-address-search" class="oh-input" placeholder="123 Main St, City, CA 94000">
                        </div>

                        <button type="button" id="oh-lookup-btn" class="oh-btn oh-btn--secondary">
                            <span class="oh-btn__text">Find Property</span>
                            <span class="oh-btn__loading" style="display:none;">Searching...</span>
                        </button>

                        <div id="oh-lookup-error" class="oh-error" style="display:none;"></div>
                    </div>
                </div>

                <!-- Step 2: Property Details -->
                <div class="oh-step" data-step="2" style="display:none;">
                    <div class="oh-step__header">
                        <h2>Confirm Property Details</h2>
                        <p>Review and edit the property information</p>
                    </div>
                    <div class="oh-step__body">
                        <div class="oh-field">
                            <label class="oh-label">Address</label>
                            <input type="text" id="oh-address" class="oh-input" required>
                        </div>
                        <div class="oh-row">
                            <div class="oh-field oh-field--half">
                                <label class="oh-label">Price</label>
                                <input type="text" id="oh-price" class="oh-input" placeholder="$500,000">
                            </div>
                            <div class="oh-field oh-field--half">
                                <label class="oh-label">Square Feet</label>
                                <input type="number" id="oh-sqft" class="oh-input" placeholder="2,000">
                            </div>
                        </div>
                        <div class="oh-row">
                            <div class="oh-field oh-field--half">
                                <label class="oh-label">Bedrooms</label>
                                <input type="number" id="oh-beds" class="oh-input" placeholder="3">
                            </div>
                            <div class="oh-field oh-field--half">
                                <label class="oh-label">Bathrooms</label>
                                <input type="number" id="oh-baths" class="oh-input" step="0.5" placeholder="2">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Hero Image -->
                <div class="oh-step" data-step="3" style="display:none;">
                    <div class="oh-step__header">
                        <h2>Choose Your Photo</h2>
                        <p>Select the main image for your page</p>
                    </div>
                    <div class="oh-step__body">
                        <div id="oh-images-grid" class="oh-images-grid">
                            <!-- Populated by JS -->
                        </div>
                        <div class="oh-stock-search" style="margin-top: 16px; text-align: center;">
                            <?php echo InstantImages::render_search_button( 'oh', '#1e293b' ); ?>
                        </div>
                        <div id="oh-no-images" class="oh-upload-fallback" style="display:none;">
                            <p>No listing photos found. Search stock photos or upload your own:</p>
                            <?php echo InstantImages::render_search_button( 'oh', '#1e293b' ); ?>
                            <p style="margin: 16px 0 12px; color: #94a3b8;">or</p>
                            <input type="file" id="oh-image-upload" accept="image/*" class="oh-file-input">
                            <label for="oh-image-upload" class="oh-btn oh-btn--secondary">Upload Image</label>
                        </div>
                        <input type="hidden" id="oh-hero-image" value="">
                        <?php echo InstantImages::render_search_modal( 'oh', 'oh-hero-image' ); ?>
                    </div>
                </div>

                <!-- Step 4: Customize Page -->
                <div class="oh-step" data-step="4" style="display:none;">
                    <div class="oh-step__header">
                        <h2>Customize Your Page</h2>
                        <p>Choose your headline and messaging</p>
                    </div>
                    <div class="oh-step__body">
                        <div class="oh-field">
                            <label class="oh-label">Headline</label>
                            <div class="oh-radio-group" id="oh-headline-group">
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-headline" value="Welcome!" checked>
                                    <span class="oh-radio-btn__label">Welcome!</span>
                                </label>
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-headline" value="You're Invited">
                                    <span class="oh-radio-btn__label">You're Invited</span>
                                </label>
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-headline" value="Come On In">
                                    <span class="oh-radio-btn__label">Come On In</span>
                                </label>
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-headline" value="Thanks for Visiting">
                                    <span class="oh-radio-btn__label">Thanks for Visiting</span>
                                </label>
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-headline" value="custom">
                                    <span class="oh-radio-btn__label">Custom...</span>
                                </label>
                            </div>
                            <input type="text" id="oh-headline-custom" class="oh-input" placeholder="Enter custom headline" style="display:none; margin-top:12px;">
                        </div>
                        <div class="oh-field">
                            <label class="oh-label">Subheadline</label>
                            <div class="oh-radio-group oh-radio-group--vertical" id="oh-subheadline-group">
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-subheadline" value="Please sign in to tour this property" checked>
                                    <span class="oh-radio-btn__label">Please sign in to tour this property</span>
                                </label>
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-subheadline" value="Sign in to get more info on this home">
                                    <span class="oh-radio-btn__label">Sign in to get more info on this home</span>
                                </label>
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-subheadline" value="We'd love to know who's visiting">
                                    <span class="oh-radio-btn__label">We'd love to know who's visiting</span>
                                </label>
                                <label class="oh-radio-btn">
                                    <input type="radio" name="oh-subheadline" value="custom">
                                    <span class="oh-radio-btn__label">Custom...</span>
                                </label>
                            </div>
                            <input type="text" id="oh-subheadline-custom" class="oh-input" placeholder="Enter custom subheadline" style="display:none; margin-top:12px;">
                        </div>
                        <div class="oh-field">
                            <label class="oh-label">Button Text</label>
                            <input type="text" id="oh-button-text" class="oh-input" value="Sign In">
                        </div>
                    </div>
                </div>

                <!-- Step 5: Contact Fields -->
                <div class="oh-step" data-step="5" style="display:none;">
                    <div class="oh-step__header">
                        <h2>Contact Fields</h2>
                        <p>Required info from visitors</p>
                    </div>
                    <div class="oh-step__body">
                        <div class="oh-toggle-list">
                            <label class="oh-toggle">
                                <input type="checkbox" checked disabled> Full Name <span class="oh-toggle__required">Required</span>
                            </label>
                            <label class="oh-toggle">
                                <input type="checkbox" checked disabled> Email <span class="oh-toggle__required">Required</span>
                            </label>
                            <label class="oh-toggle">
                                <input type="checkbox" checked disabled> Phone <span class="oh-toggle__required">Required</span>
                            </label>
                            <label class="oh-toggle">
                                <input type="checkbox" name="oh-q-comments" checked> Comments
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Step 6: Qualifying Questions -->
                <div class="oh-step" data-step="6" style="display:none;">
                    <div class="oh-step__header">
                        <h2>Qualifying Questions</h2>
                        <p>Optional questions to qualify leads</p>
                    </div>
                    <div class="oh-step__body">
                        <div class="oh-toggle-list">
                            <label class="oh-toggle">
                                <input type="checkbox" name="oh-q-agent" checked> Are you working with an agent?
                            </label>
                            <label class="oh-toggle">
                                <input type="checkbox" name="oh-q-preapproved" checked> Are you pre-approved?
                            </label>
                            <label class="oh-toggle">
                                <input type="checkbox" name="oh-q-interested" checked> Interested in pre-approval?
                            </label>
                            <label class="oh-toggle">
                                <input type="checkbox" name="oh-q-timeline"> Buying timeline
                            </label>
                            <label class="oh-toggle">
                                <input type="checkbox" name="oh-q-firsthome"> First home purchase?
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Step 7: Branding (bi-directional) -->
                <div class="oh-step" data-step="7" style="display:none;">
                    <div class="oh-step__header">
                        <h2>Your Team Info</h2>
                        <p>Confirm your contact details</p>
                    </div>
                    <div class="oh-step__body">
                        <p class="oh-section-label">Your Information</p>
                        <?php if ( $is_loan_officer ) : ?>
                            <!-- LO Mode: Show LO fields -->
                            <div class="oh-row">
                                <div class="oh-field oh-field--half">
                                    <label class="oh-label">Your Name</label>
                                    <input type="text" id="oh-lo-name" class="oh-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                                </div>
                                <div class="oh-field oh-field--half">
                                    <label class="oh-label">NMLS #</label>
                                    <input type="text" id="oh-lo-nmls" class="oh-input" value="<?php echo esc_attr( $user_data['nmls'] ?? '' ); ?>">
                                </div>
                            </div>
                            <div class="oh-row">
                                <div class="oh-field oh-field--half">
                                    <label class="oh-label">Phone</label>
                                    <input type="tel" id="oh-lo-phone" class="oh-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                                </div>
                                <div class="oh-field oh-field--half">
                                    <label class="oh-label">Email</label>
                                    <input type="email" id="oh-lo-email" class="oh-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                                </div>
                            </div>

                            <!-- Headshot: show their actual profile photo + option to upload a different one -->
                            <div class="oh-field" style="margin-top: 24px;">
                                <label class="oh-label">Your Headshot</label>
                                <div style="display:flex; align-items:center; gap:16px;">
                                    <img id="oh-lo-photo-img" src="<?php echo esc_url( $user_data['photo'] ); ?>" alt="Your headshot" style="width:84px; height:84px; border-radius:50%; object-fit:cover; border:2px solid #e2e8f0; background:#f1f5f9; flex-shrink:0;">
                                    <div>
                                        <button type="button" id="oh-lo-photo-btn" class="oh-btn oh-btn--secondary" style="padding:8px 16px;">Upload a different photo</button>
                                        <input type="file" id="oh-lo-photo-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                                        <p id="oh-lo-photo-status" style="margin:8px 0 0; font-size:13px; color:#64748b;">Using your profile headshot</p>
                                    </div>
                                </div>
                                <input type="hidden" id="oh-lo-photo-url" value="">
                            </div>

                            <p class="oh-section-label" style="margin-top:24px;">Realtor Partner (from Step 1)</p>
                            <div id="oh-partner-preview" class="oh-lo-preview">
                                <p style="color:#94a3b8;font-size:14px;margin:0;" id="oh-no-partner-msg">No realtor partner selected (solo page)</p>
                            </div>
                        <?php else : ?>
                            <!-- Realtor Mode: Show Realtor fields -->
                            <div class="oh-row">
                                <div class="oh-field oh-field--half">
                                    <label class="oh-label">Your Name</label>
                                    <input type="text" id="oh-realtor-name" class="oh-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                                </div>
                                <div class="oh-field oh-field--half">
                                    <label class="oh-label">License #</label>
                                    <input type="text" id="oh-realtor-license" class="oh-input" value="<?php echo esc_attr( $user_data['license'] ?? '' ); ?>">
                                </div>
                            </div>
                            <div class="oh-row">
                                <div class="oh-field oh-field--half">
                                    <label class="oh-label">Phone</label>
                                    <input type="tel" id="oh-realtor-phone" class="oh-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                                </div>
                                <div class="oh-field oh-field--half">
                                    <label class="oh-label">Email</label>
                                    <input type="email" id="oh-realtor-email" class="oh-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                                </div>
                            </div>

                            <!-- Photo Upload -->
                            <div class="oh-field" style="margin-top: 24px;">
                                <label class="oh-label">Your Photo (Optional)</label>
                                <div class="oh-photo-upload" id="oh-realtor-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                    <input type="file" id="oh-realtor-photo-file" accept="image/*" style="display: none;">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                    <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                </div>
                                <div id="oh-realtor-photo-preview" style="margin-top: 12px; display: none;">
                                    <img id="oh-realtor-photo-preview-img" src="" alt="Preview" style="width: 100px; height: 100px; border-radius: 8px; object-fit: cover;">
                                    <button type="button" id="oh-realtor-photo-remove" class="oh-btn oh-btn--ghost oh-btn--sm" style="margin-left: 12px;">Remove</button>
                                </div>
                                <input type="hidden" id="oh-realtor-photo-url" value="">
                            </div>

                            <p class="oh-section-label" style="margin-top:24px;">Loan Officer (from Step 1)</p>
                            <div id="oh-partner-preview" class="oh-lo-preview">
                                <!-- Populated by JS -->
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 8: Preview & Publish -->
                <div class="oh-step" data-step="8" style="display:none;">
                    <div class="oh-step__header">
                        <h2>Review & Publish</h2>
                        <p>Everything looks good? Let's make it live.</p>
                    </div>
                    <div class="oh-step__body">
                        <div id="oh-summary" class="oh-summary">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                </div>

                <!-- Success State -->
                <div class="oh-step oh-step--success" data-step="success" style="display:none;">
                    <div class="oh-success">
                        <div class="oh-success__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <h2>Your Page is Live</h2>
                        <p id="oh-success-address"></p>
                        <div class="oh-success__actions">
                            <a id="oh-success-link" href="#" class="oh-btn oh-btn--primary" target="_blank">View Page</a>
                            <button type="button" id="oh-copy-link" class="oh-btn oh-btn--secondary">Copy Link</button>
                            <a id="oh-qr-link" href="#" class="oh-btn oh-btn--secondary" download>Download QR</a>
                        </div>
                        <a href="<?php echo esc_url( remove_query_arg( 'created' ) ); ?>" class="oh-link">Create Another</a>
                    </div>
                </div>

                </div><!-- .oh-wizard__content -->

                <div class="oh-wizard__footer">
                    <button type="button" id="oh-back" class="oh-btn oh-btn--ghost" style="display:none;">Back</button>
                    <button type="button" id="oh-next" class="oh-btn oh-btn--primary">Continue</button>
                    <button type="button" id="oh-publish" class="oh-btn oh-btn--primary" style="display:none;">
                        <span class="oh-btn__text">Publish Page</span>
                        <span class="oh-btn__loading" style="display:none;">Creating...</span>
                    </button>
                </div>
            </div><!-- .oh-wizard__form -->
        </div><!-- .oh-wizard -->

        <?php echo self::render_styles(); ?>
        <?php echo self::render_scripts(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render styles
     */
    private static function render_styles(): string {
        return '
        <style>
            .oh-wizard {
                display: block;
                position: relative;
                min-height: 100dvh;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .oh-wizard__hero {
                width: 60%;
                height: 100dvh;
                background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding: clamp(2rem, 5vw, 4rem);
                position: fixed;
                left: 0;
                top: 0;
                overflow: hidden;
            }
            .oh-wizard__hero::before {
                content: "";
                position: absolute;
                top: -50%;
                right: -50%;
                width: 100%;
                height: 100%;
                background: radial-gradient(circle, rgba(14,165,233,0.15) 0%, transparent 70%);
                pointer-events: none;
            }
            .oh-wizard__hero-content {
                position: relative;
                z-index: 1;
            }
            .oh-wizard__hero h1 {
                font-size: 48px;
                font-weight: 700;
                color: #fff;
                margin: 0 0 16px;
                line-height: 1.1;
            }
            .oh-wizard__hero p {
                font-size: 18px;
                color: rgba(255,255,255,0.7);
                margin: 0;
                max-width: 400px;
            }
            .oh-wizard__form {
                position: absolute;
                left: 60%;
                right: 0;
                top: 0;
                min-height: 100dvh;
                background: #fff;
                padding: 24px 32px 96px;
                box-sizing: border-box;
            }
            .oh-wizard__progress {
                height: 3px;
                background: #e5e7eb;
                margin-bottom: 16px;
            }
            .oh-wizard__progress-bar {
                height: 100%;
                background: #1e293b;
                transition: width 0.3s ease;
            }
            .oh-wizard__header {
                margin-bottom: 8px;
            }
            .oh-wizard__title {
                font-size: 12px;
                font-weight: 600;
                color: #1e293b;
                margin: 0 0 4px;
                text-transform: uppercase;
                letter-spacing: 0.1em;
            }
            .oh-wizard__subtitle {
                font-size: 13px;
                color: #94a3b8;
                margin: 0;
            }
            .oh-wizard__nav-top {
                display: flex;
                gap: 12px;
                justify-content: flex-end;
                margin-bottom: 16px;
            }
            .oh-btn--sm {
                padding: 8px 16px;
                font-size: 13px;
            }
            .oh-wizard__content {
            }
            .oh-step {
                display: flex;
                flex-direction: column;
            }
            .oh-step__body {
                padding-right: 8px;
            }
            .oh-step__header h2 {
                font-size: 22px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 4px;
            }
            .oh-step__header p {
                font-size: 13px;
                color: #64748b;
                margin: 0 0 16px;
            }
            .oh-label {
                display: block !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                color: #374151 !important;
                margin-bottom: 6px !important;
            }
            #oh-wizard .oh-input,
            #oh-wizard input[type="text"],
            #oh-wizard input[type="email"],
            #oh-wizard input[type="tel"],
            #oh-wizard input[type="number"],
            #oh-wizard textarea {
                width: 100%;
                height: 40px;
                padding: 0 12px;
                font-size: 14px;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                background-color: #fff;
                box-sizing: border-box;
            }
            .oh-dropdown {
                position: relative;
                width: 100%;
            }
            .oh-dropdown__trigger {
                width: 100%;
                height: 60px;
                padding: 0 20px;
                font-size: 16px;
                border: 2px solid #e5e7eb;
                border-radius: 10px;
                background-color: #fff;
                box-sizing: border-box;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: space-between;
                text-align: left;
                color: #374151;
            }
            .oh-dropdown__trigger:hover {
                border-color: #9ca3af;
            }
            .oh-dropdown.open .oh-dropdown__trigger {
                border-color: #1e293b;
                box-shadow: 0 0 0 4px rgba(14,165,233,0.1);
            }
            .oh-dropdown__value {
                flex: 1;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .oh-dropdown__arrow {
                flex-shrink: 0;
                transition: transform 0.2s;
                color: #9ca3af;
            }
            .oh-dropdown.open .oh-dropdown__arrow {
                transform: rotate(180deg);
            }
            .oh-dropdown__menu {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                margin-top: 4px;
                background: #fff;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.15);
                max-height: 300px;
                overflow-y: auto;
                z-index: 100;
                display: none;
            }
            .oh-dropdown.open .oh-dropdown__menu {
                display: block;
            }
            .oh-dropdown__item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                cursor: pointer;
                transition: background 0.15s;
            }
            .oh-dropdown__item:hover {
                background: #f3f4f6;
            }
            .oh-dropdown__item.selected {
                background: #eff6ff;
            }
            .oh-dropdown__photo {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                object-fit: cover;
                flex-shrink: 0;
            }
            .oh-dropdown__info {
                display: flex;
                flex-direction: column;
                gap: 2px;
            }
            .oh-dropdown__name {
                font-size: 15px;
                font-weight: 600;
                color: #1f2937;
            }
            .oh-dropdown__nmls {
                font-size: 13px;
                color: #6b7280;
            }
            .oh-dropdown__item--preferred {
                background: #cffafe;
                border-left: 3px solid #2DD4DA;
            }
            .oh-dropdown__item--preferred:hover {
                background: #a5f3fc;
            }
            .oh-dropdown__preferred-badge {
                margin-left: auto;
                font-size: 11px;
                font-weight: 600;
                color: #0891b2;
                background: #cffafe;
                padding: 2px 8px;
                border-radius: 10px;
            }
            /* Page Type Cards (Step 0 for LOs) */
            .oh-page-type-cards {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
                margin-bottom: 8px;
            }
            .oh-page-type-card {
                border: 2px solid #e2e8f0;
                border-radius: 12px;
                padding: 24px 16px;
                text-align: center;
                cursor: pointer;
                transition: all 0.2s ease;
                background: #fff;
            }
            .oh-page-type-card:hover {
                border-color: #1e293b;
                background: #f8fafc;
            }
            .oh-page-type-card.selected {
                border-color: #1e293b;
                background: #f8fafc;
                box-shadow: 0 0 0 4px rgba(14,165,233,0.15);
            }
            .oh-page-type-card__icon {
                margin-bottom: 12px;
                color: #64748b;
            }
            .oh-page-type-card.selected .oh-page-type-card__icon {
                color: #1e293b;
            }
            .oh-page-type-card h3 {
                font-size: 16px;
                font-weight: 600;
                color: #1e293b;
                margin: 0 0 4px 0;
            }
            .oh-page-type-card p {
                font-size: 13px;
                color: #64748b;
                margin: 0;
            }
            .oh-partner-selection {
                animation: fadeIn 0.3s ease;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .oh-checkbox {
                display: flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
            }
            .oh-checkbox input[type="checkbox"] {
                width: 18px;
                height: 18px;
                accent-color: #1e293b;
                cursor: pointer;
            }
            .oh-checkbox__label {
                font-size: 14px;
                color: #6b7280;
            }
            .oh-input:focus, .oh-select:focus,
            #oh-wizard input:focus,
            #oh-wizard select:focus,
            #oh-wizard textarea:focus {
                outline: none;
                border: 1px solid #1e293b !important;
                border-bottom: 1px solid #1e293b !important;
                box-shadow: 0 0 0 4px rgba(14,165,233,0.1);
            }
            .oh-input::placeholder {
                color: #94a3b8;
            }
            .oh-field {
                margin-bottom: 28px !important;
            }
            .oh-row {
                display: flex;
                gap: 20px;
            }
            .oh-field--half {
                flex: 1;
            }
            .oh-helper {
                font-size: 13px;
                color: #94a3b8;
                margin: 10px 0 0;
            }
            .oh-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 18px 36px;
                font-size: 17px;
                font-weight: 600;
                border-radius: 12px;
                border: none;
                cursor: pointer;
                transition: all 0.2s;
            }
            .oh-btn--primary {
                background: #1e293b;
                color: #fff;
            }
            .oh-btn--primary:hover {
                background: #0f172a;
            }
            .oh-btn--secondary {
                background: #f1f5f9;
                color: #0f172a;
            }
            .oh-btn--secondary:hover {
                background: #e2e8f0;
            }
            .oh-btn--ghost {
                background: transparent;
                color: #64748b;
            }
            .oh-btn--ghost:hover {
                color: #0f172a;
            }
            .oh-wizard__footer {
                display: flex;
                justify-content: space-between;
                padding: 24px 0;
                margin-top: auto;
                border-top: 0;
                flex-shrink: 0;
                background: #fff;
            }
            .oh-tabs {
                display: flex;
                gap: 8px;
                margin-bottom: 24px;
            }
            .oh-tab {
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 600;
                background: transparent;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                cursor: pointer;
                color: #64748b;
                transition: all 0.2s;
            }
            .oh-tab:hover {
                border-color: #cbd5e1;
            }
            .oh-tab--active {
                background: #1e293b;
                border-color: #1e293b;
                color: #fff;
            }
            .oh-error {
                padding: 14px 18px;
                background: #fef2f2;
                border: 1px solid #fecaca;
                border-radius: 10px;
                color: #dc2626;
                font-size: 14px;
                margin-top: 16px;
            }
            #oh-lookup-btn {
                margin-top: 24px;
            }
            .oh-tab-content {
                margin-bottom: 8px;
            }
            .oh-radio-group {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .oh-radio-group--vertical {
                flex-direction: column;
            }
            .oh-radio-btn {
                position: relative;
                cursor: pointer;
            }
            .oh-radio-btn input {
                position: absolute;
                opacity: 0;
                width: 0;
                height: 0;
            }
            .oh-radio-btn__label {
                display: inline-block;
                padding: 14px 20px;
                font-size: 15px;
                font-weight: 500;
                color: #374151;
                background: #fff;
                border: 2px solid #e5e7eb;
                border-radius: 10px;
                transition: all 0.15s ease;
                cursor: pointer;
            }
            .oh-radio-btn:hover .oh-radio-btn__label {
                border-color: #cbd5e1;
                background: #f8fafc;
            }
            .oh-radio-btn input:checked + .oh-radio-btn__label {
                background: #1e293b;
                border-color: #1e293b;
                color: #fff;
                box-shadow: 0 2px 8px rgba(14,165,233,0.3);
            }
            .oh-radio-btn input:focus + .oh-radio-btn__label {
                box-shadow: 0 0 0 4px rgba(14,165,233,0.2);
            }
            .oh-images-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 16px;
            }
            .oh-image-option {
                aspect-ratio: 4/3;
                border-radius: 12px;
                overflow: hidden;
                cursor: pointer;
                border: 3px solid transparent;
                transition: all 0.2s;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .oh-image-option:hover {
                transform: scale(1.02);
            }
            .oh-image-option img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .oh-image-option--selected {
                border-color: #1e293b;
                box-shadow: 0 0 0 4px rgba(14,165,233,0.2);
            }
            .oh-toggle-list {
                display: flex;
                flex-direction: column;
                gap: 16px;
            }
            .oh-toggle {
                display: flex;
                align-items: center;
                gap: 14px;
                font-size: 15px;
                color: #374151;
                cursor: pointer;
                padding: 12px 16px;
                background: #f8fafc;
                border-radius: 10px;
                transition: background 0.2s;
            }
            .oh-toggle:hover {
                background: #f1f5f9;
            }
            .oh-toggle input {
                width: 20px;
                height: 20px;
                accent-color: #1e293b;
            }
            .oh-toggle__required {
                font-size: 11px;
                font-weight: 600;
                color: #94a3b8;
                margin-left: auto;
                text-transform: uppercase;
            }
            .oh-section-label {
                font-size: 11px;
                font-weight: 700;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.1em;
                margin: 0 0 16px;
            }
            .oh-lo-preview {
                display: flex;
                align-items: center;
                gap: 16px;
                padding: 20px;
                background: #f8fafc;
                border-radius: 12px;
            }
            .oh-lo-preview img {
                width: 64px;
                height: 64px;
                border-radius: 50%;
                object-fit: cover;
                border: 3px solid #fff;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .oh-lo-preview__info h4 {
                font-size: 17px;
                font-weight: 600;
                color: #0f172a;
                margin: 0 0 4px;
            }
            .oh-lo-preview__info p {
                font-size: 14px;
                color: #64748b;
                margin: 0;
            }
            .oh-summary {
                background: #f8fafc;
                border-radius: 16px;
                padding: 28px;
            }
            .oh-summary__row {
                display: flex;
                justify-content: space-between;
                padding: 14px 0;
                border-bottom: 1px solid #e5e7eb;
            }
            .oh-summary__row:last-child {
                border-bottom: none;
            }
            .oh-summary__label {
                font-size: 14px;
                color: #64748b;
            }
            .oh-summary__value {
                font-size: 14px;
                font-weight: 600;
                color: #0f172a;
            }
            .oh-success {
                text-align: center;
                padding: 48px 24px;
            }
            .oh-success__icon {
                width: 88px;
                height: 88px;
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #fff;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 28px;
                box-shadow: 0 8px 24px rgba(16,185,129,0.3);
            }
            .oh-success h2 {
                font-size: 28px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 8px;
            }
            .oh-success p {
                font-size: 16px;
                color: #64748b;
                margin: 0 0 28px;
            }
            .oh-success__actions {
                display: flex;
                gap: 12px;
                justify-content: center;
                margin-bottom: 24px;
            }
            .oh-link {
                font-size: 14px;
                color: #64748b;
                text-decoration: none;
            }
            .oh-link:hover {
                color: #1e293b;
            }
            .oh-upload-fallback {
                text-align: center;
                padding: 40px;
                background: #f8fafc;
                border-radius: 12px;
                border: 2px dashed #cbd5e1;
            }
            .oh-file-input {
                display: none;
            }
            @media (max-width: 1024px) {
                .oh-wizard {
                    flex-direction: column;
                    height: auto;
                    min-height: 100dvh;
                }
                .oh-wizard__hero {
                    position: relative;
                    width: 100%;
                    height: auto;
                    flex: none;
                    padding: 48px 32px;
                    min-height: auto;
                }
                .oh-wizard__hero h1 {
                    font-size: 32px;
                }
                .oh-wizard__form {
                    position: relative;
                    left: 0;
                    right: 0;
                    top: auto;
                    bottom: auto;
                    width: 100%;
                    height: auto;
                    flex: 1;
                }
                .oh-wizard__footer {
                    padding: 20px 24px;
                }
            }
            @media (max-width: 640px) {
                .oh-row {
                    flex-direction: column;
                    gap: 0;
                }
                .oh-images-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>' . InstantImages::render_search_styles( 'oh', '#1e293b' );
    }

    /**
     * Render scripts
     */
    private static function render_scripts(): string {
        return '
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            console.log("OH Wizard: Initializing...");

            const wizard = document.getElementById("oh-wizard");
            if (!wizard) {
                console.error("OH Wizard: #oh-wizard element not found");
                return;
            }
            console.log("OH Wizard: Found wizard element");

            const steps = wizard.querySelectorAll(".oh-step[data-step]");
            const progressBar = wizard.querySelector(".oh-wizard__progress-bar");
            const stepNum = document.getElementById("oh-step-num");
            const backBtn = document.getElementById("oh-back");
            const nextBtn = document.getElementById("oh-next");
            const publishBtn = document.getElementById("oh-publish");
            const backBtnTop = document.getElementById("oh-back-top");
            const nextBtnTop = document.getElementById("oh-next-top");

            console.log("OH Wizard: Steps found:", steps.length);
            console.log("OH Wizard: Next button:", nextBtn);
            console.log("OH Wizard: Back button:", backBtn);

            if (!nextBtn) {
                console.error("OH Wizard: #oh-next button not found");
                return;
            }

            let currentStep = 0;
            // Get user mode from wizard data attribute
            const userData = JSON.parse(wizard.dataset.user || "{}");
            const userMode = userData.mode || "realtor";
            const isLoanOfficer = userMode === "loan_officer";

            let data = {
                userMode: userMode,
                partner: {}, // Selected partner (LO selects Realtor, Realtor selects LO)
                loanOfficer: {}, // For backwards compatibility
                property: {},
                images: [],
                customize: {},
                questions: {},
                branding: {}
            };

            // Custom dropdown handling with fixed positioning
            document.querySelectorAll(".oh-dropdown").forEach(dropdown => {
                const trigger = dropdown.querySelector(".oh-dropdown__trigger");
                const menu = dropdown.querySelector(".oh-dropdown__menu");
                const items = dropdown.querySelectorAll(".oh-dropdown__item");
                const hiddenInput = dropdown.querySelector("input[type=hidden]");
                const valueDisplay = dropdown.querySelector(".oh-dropdown__value");

                function positionMenu() {
                    const rect = trigger.getBoundingClientRect();
                    menu.style.top = (rect.bottom + 4) + "px";
                    menu.style.left = rect.left + "px";
                    menu.style.width = rect.width + "px";
                }

                trigger.addEventListener("click", (e) => {
                    e.stopPropagation();
                    document.querySelectorAll(".oh-dropdown.open").forEach(d => {
                        if (d !== dropdown) d.classList.remove("open");
                    });
                    dropdown.classList.toggle("open");
                    if (dropdown.classList.contains("open")) {
                        positionMenu();
                    }
                });

                items.forEach(item => {
                    item.addEventListener("click", () => {
                        items.forEach(i => i.classList.remove("selected"));
                        item.classList.add("selected");
                        hiddenInput.value = item.dataset.value;

                        // Get display text - check for name span or option-text span
                        const nameEl = item.querySelector(".oh-dropdown__name");
                        const optionEl = item.querySelector(".oh-dropdown__option-text");
                        valueDisplay.textContent = nameEl ? nameEl.textContent : (optionEl ? optionEl.textContent : item.dataset.value);
                        dropdown.classList.remove("open");

                        // Store all data attributes for partner selection
                        if (item.dataset.name) hiddenInput.dataset.name = item.dataset.name;
                        if (item.dataset.nmls) hiddenInput.dataset.nmls = item.dataset.nmls;
                        if (item.dataset.license) hiddenInput.dataset.license = item.dataset.license;
                        if (item.dataset.company) hiddenInput.dataset.company = item.dataset.company;
                        if (item.dataset.photo) hiddenInput.dataset.photo = item.dataset.photo;
                        if (item.dataset.email) hiddenInput.dataset.email = item.dataset.email;
                        if (item.dataset.phone) hiddenInput.dataset.phone = item.dataset.phone;
                    });
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener("click", () => {
                document.querySelectorAll(".oh-dropdown.open").forEach(d => d.classList.remove("open"));
            });

            // Page type card selection (LO mode only)
            const pageTypeCards = document.querySelectorAll(".oh-page-type-card");
            const pageTypeInput = document.getElementById("oh-page-type");
            const partnerSelection = document.getElementById("oh-partner-selection");

            if (pageTypeCards.length > 0) {
                pageTypeCards.forEach(card => {
                    card.addEventListener("click", () => {
                        // Remove selection from all cards
                        pageTypeCards.forEach(c => c.classList.remove("selected"));
                        // Select this card
                        card.classList.add("selected");
                        // Update hidden input
                        const pageType = card.dataset.type;
                        if (pageTypeInput) pageTypeInput.value = pageType;

                        // Show/hide partner selection based on type
                        if (partnerSelection) {
                            if (pageType === "cobranded") {
                                partnerSelection.style.display = "block";
                            } else {
                                partnerSelection.style.display = "none";
                                // Clear partner selection if switching to solo
                                const partnerInput = document.getElementById("oh-partner");
                                if (partnerInput) {
                                    partnerInput.value = "";
                                    partnerInput.dataset.name = "";
                                }
                            }
                        }

                        console.log("OH Wizard: Page type selected:", pageType);
                    });
                });
            }

            // Auto-select preferred partner if set
            const partnerDropdown = document.getElementById("oh-partner-dropdown");
            if (partnerDropdown) {
                const preferredId = partnerDropdown.dataset.preferred;
                if (preferredId && preferredId !== "0") {
                    const preferredItem = partnerDropdown.querySelector(`.oh-dropdown__item[data-value="${preferredId}"]`);
                    if (preferredItem) {
                        // Simulate click to select the preferred partner
                        preferredItem.click();
                        console.log("OH Wizard: Auto-selected preferred partner ID:", preferredId);
                    }
                }
            }

            // Radio button custom option handling
            document.querySelectorAll("input[name=\"oh-headline\"]").forEach(radio => {
                radio.addEventListener("change", function() {
                    const customInput = document.getElementById("oh-headline-custom");
                    if (customInput) {
                        customInput.style.display = this.value === "custom" ? "block" : "none";
                        if (this.value === "custom") customInput.focus();
                    }
                });
            });
            document.querySelectorAll("input[name=\"oh-subheadline\"]").forEach(radio => {
                radio.addEventListener("change", function() {
                    const customInput = document.getElementById("oh-subheadline-custom");
                    if (customInput) {
                        customInput.style.display = this.value === "custom" ? "block" : "none";
                        if (this.value === "custom") customInput.focus();
                    }
                });
            });

            function showStep(step) {
                steps.forEach(s => s.style.display = "none");
                const target = wizard.querySelector(`[data-step="${step}"]`);
                if (target) target.style.display = "block";

                const progress = ((step + 1) / 9) * 100;
                progressBar.style.width = progress + "%";
                stepNum.textContent = step + 1;

                backBtn.style.display = step > 0 ? "inline-flex" : "none";
                nextBtn.style.display = step < 8 ? "inline-flex" : "none";
                publishBtn.style.display = step === 8 ? "inline-flex" : "none";

                // Update top buttons too
                if (backBtnTop) backBtnTop.style.display = step > 0 ? "inline-flex" : "none";
                if (nextBtnTop) nextBtnTop.style.display = step < 8 ? "inline-flex" : "none";

                if (step === 7) updateLoPreview();
                if (step === 8) updateSummary();
            }

            function validateStep(step) {
                console.log("OH Wizard: Validating step:", step, "userMode:", userMode);
                if (step === 0) {
                    if (isLoanOfficer) {
                        // LO Mode: Check page type selection
                        const pageType = document.getElementById("oh-page-type")?.value;

                        if (!pageType) {
                            alert("Please select a page type (Solo or Co-branded)");
                            return false;
                        }

                        data.pageType = pageType;

                        // If co-branded, collect partner info from inputs
                        if (pageType === "cobranded") {
                            const partnerName     = document.getElementById("oh-partner-name-input")?.value.trim() || "";
                            const partnerEmail    = document.getElementById("oh-partner-email-input")?.value.trim() || "";
                            const partnerPhone    = document.getElementById("oh-partner-phone-input")?.value.trim() || "";
                            const partnerHeadshot = document.getElementById("oh-partner-photo-url")?.value || "";
                            const partnerLogo     = document.getElementById("oh-partner-logo-url")?.value || "";

                            if (!partnerName) {
                                alert("Please enter the partner\'s name");
                                return false;
                            }
                            if (!partnerEmail) {
                                alert("Please enter the partner\'s email");
                                return false;
                            }
                            if (!partnerPhone) {
                                alert("Please enter the partner\'s phone number");
                                return false;
                            }

                            data.partner = {
                                name:    partnerName,
                                email:   partnerEmail,
                                phone:   partnerPhone,
                                photo:   partnerHeadshot,
                                logo:    partnerLogo,
                                company: "",
                                license: ""
                            };
                        } else {
                            // Solo page - no partner
                            data.partner = {};
                        }

                        console.log("OH Wizard: LO mode - pageType:", pageType, "partner:", data.partner);
                    } else {
                        // Partner Mode: LO selection required
                        const partner = document.getElementById("oh-partner");

                        if (!partner || !partner.value) {
                            alert("Please select a loan officer");
                            return false;
                        }

                        data.partner = {
                            id: partner.value,
                            name: partner.dataset.name || "",
                            nmls: partner.dataset.nmls || "",
                            photo: partner.dataset.photo || "",
                            email: partner.dataset.email || "",
                            phone: partner.dataset.phone || ""
                        };

                        // For backwards compatibility
                        data.loanOfficer = data.partner;

                        // Save preference if "Remember my choice" is checked
                        const rememberCheckbox = document.getElementById("oh-remember-partner");
                        if (rememberCheckbox && rememberCheckbox.checked) {
                            fetch(ajaxurl, {
                                method: "POST",
                                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                                body: new URLSearchParams({
                                    action: "frs_set_preferred_lo",
                                    nonce: "' . wp_create_nonce( 'frs_lead_pages' ) . '",
                                    lo_id: partner.value,
                                    remember: "true"
                                })
                            }).then(r => r.json()).then(res => {
                                console.log("OH Wizard: Saved preferred LO:", res);
                            }).catch(err => {
                                console.error("OH Wizard: Failed to save preference:", err);
                            });
                        }

                        console.log("OH Wizard: Partner mode - LO selected:", data.partner);
                    }
                }
                if (step === 2) {
                    const addr = document.getElementById("oh-address").value;
                    if (!addr) {
                        alert("Please enter the property address");
                        return false;
                    }
                    data.property = {
                        address: addr,
                        price: document.getElementById("oh-price").value,
                        sqft: document.getElementById("oh-sqft").value,
                        beds: document.getElementById("oh-beds").value,
                        baths: document.getElementById("oh-baths").value
                    };
                }
                if (step === 3) {
                    const heroImg = document.getElementById("oh-hero-image").value;
                    if (!heroImg) {
                        alert("Please select a hero image");
                        return false;
                    }
                    data.property.heroImage = heroImg;
                }
                if (step === 4) {
                    const headlineRadio = wizard.querySelector("input[name=\"oh-headline\"]:checked");
                    const subheadlineRadio = wizard.querySelector("input[name=\"oh-subheadline\"]:checked");
                    const headlineVal = headlineRadio ? headlineRadio.value : "Welcome!";
                    const subheadlineVal = subheadlineRadio ? subheadlineRadio.value : "Please sign in to tour this property";
                    data.customize = {
                        headline: headlineVal === "custom" ? document.getElementById("oh-headline-custom").value : headlineVal,
                        subheadline: subheadlineVal === "custom" ? document.getElementById("oh-subheadline-custom").value : subheadlineVal,
                        buttonText: document.getElementById("oh-button-text").value || "Sign In"
                    };
                    console.log("OH Wizard: Customize data:", data.customize);
                }
                if (step === 5) {
                    data.questions = {
                        comments: wizard.querySelector("[name=oh-q-comments]").checked,
                        agent: wizard.querySelector("[name=oh-q-agent]").checked,
                        preapproved: wizard.querySelector("[name=oh-q-preapproved]").checked,
                        interested: wizard.querySelector("[name=oh-q-interested]").checked,
                        timeline: wizard.querySelector("[name=oh-q-timeline]").checked,
                        firsthome: wizard.querySelector("[name=oh-q-firsthome]").checked
                    };
                }
                if (step === 6) {
                    if (isLoanOfficer) {
                        // LO mode: capture LO info
                        data.branding = {
                            loName: document.getElementById("oh-lo-name")?.value || "",
                            loNmls: document.getElementById("oh-lo-nmls")?.value || "",
                            loPhone: document.getElementById("oh-lo-phone")?.value || "",
                            loEmail: document.getElementById("oh-lo-email")?.value || "",
                            loPhoto: document.getElementById("oh-lo-photo-url")?.value || ""
                        };
                    } else {
                        // Realtor mode: capture realtor info
                        data.branding = {
                            realtorName: document.getElementById("oh-realtor-name")?.value || "",
                            realtorLicense: document.getElementById("oh-realtor-license")?.value || "",
                            realtorPhone: document.getElementById("oh-realtor-phone")?.value || "",
                            realtorEmail: document.getElementById("oh-realtor-email")?.value || "",
                            realtorPhoto: document.getElementById("oh-realtor-photo-url")?.value || ""
                        };
                    }
                    console.log("OH Wizard: Branding data:", data.branding);
                }
                return true;
            }

            function updateLoPreview() {
                const preview = document.getElementById("oh-partner-preview");
                const noPartnerMsg = document.getElementById("oh-no-partner-msg");

                if (data.partner && data.partner.name) {
                    // Has a partner selected
                    if (isLoanOfficer) {
                        // LO mode - showing realtor partner
                        preview.innerHTML = `
                            <img src="${data.partner.photo || ""}" alt="">
                            <div class="oh-lo-preview__info">
                                <h4>${data.partner.name}</h4>
                                <p>${data.partner.company || "Sales Associate"}</p>
                            </div>
                        `;
                    } else {
                        // Realtor mode - showing LO partner
                        preview.innerHTML = `
                            <img src="${data.partner.photo || ""}" alt="">
                            <div class="oh-lo-preview__info">
                                <h4>${data.partner.name}</h4>
                                <p>NMLS# ${data.partner.nmls || ""}</p>
                            </div>
                        `;
                    }
                    if (noPartnerMsg) noPartnerMsg.style.display = "none";
                } else if (noPartnerMsg) {
                    // No partner - show solo message (only for LO mode)
                    noPartnerMsg.style.display = "block";
                }
            }

            function updateSummary() {
                const summary = document.getElementById("oh-summary");

                let partnerRow = "";
                if (data.partner && data.partner.name) {
                    const partnerLabel = isLoanOfficer ? "Realtor Partner" : "Loan Officer";
                    partnerRow = `
                        <div class="oh-summary__row">
                            <span class="oh-summary__label">${partnerLabel}</span>
                            <span class="oh-summary__value">${data.partner.name}</span>
                        </div>
                    `;
                } else if (isLoanOfficer) {
                    partnerRow = `
                        <div class="oh-summary__row">
                            <span class="oh-summary__label">Realtor Partner</span>
                            <span class="oh-summary__value">None (solo page)</span>
                        </div>
                    `;
                }

                summary.innerHTML = `
                    <div class="oh-summary__row">
                        <span class="oh-summary__label">Property</span>
                        <span class="oh-summary__value">${data.property.address}</span>
                    </div>
                    <div class="oh-summary__row">
                        <span class="oh-summary__label">Price</span>
                        <span class="oh-summary__value">${data.property.price || "Not set"}</span>
                    </div>
                    ${partnerRow}
                    <div class="oh-summary__row">
                        <span class="oh-summary__label">Headline</span>
                        <span class="oh-summary__value">${data.customize.headline}</span>
                    </div>
                `;
            }

            // Navigation
            nextBtn.addEventListener("click", function() {
                console.log("OH Wizard: Continue clicked, current step:", currentStep);
                if (validateStep(currentStep)) {
                    currentStep++;
                    console.log("OH Wizard: Moving to step:", currentStep);
                    showStep(currentStep);
                } else {
                    console.log("OH Wizard: Validation failed for step:", currentStep);
                }
            });

            backBtn.addEventListener("click", function() {
                console.log("OH Wizard: Back clicked");
                currentStep--;
                showStep(currentStep);
            });

            // Top button listeners
            if (nextBtnTop) {
                nextBtnTop.addEventListener("click", function() {
                    if (validateStep(currentStep)) {
                        currentStep++;
                        showStep(currentStep);
                    }
                });
            }
            if (backBtnTop) {
                backBtnTop.addEventListener("click", function() {
                    currentStep--;
                    showStep(currentStep);
                });
            }

            console.log("OH Wizard: Navigation handlers attached");

            // Tab switching
            const tabs = wizard.querySelectorAll(".oh-tab");
            console.log("OH Wizard: Found tabs:", tabs.length);
            tabs.forEach(tab => {
                tab.addEventListener("click", function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log("OH Wizard: Tab clicked:", this.dataset.tab);
                    wizard.querySelectorAll(".oh-tab").forEach(t => t.classList.remove("oh-tab--active"));
                    this.classList.add("oh-tab--active");
                    wizard.querySelectorAll(".oh-tab-content").forEach(c => c.style.display = "none");
                    const targetContent = wizard.querySelector(".oh-tab-content[data-tab=\"" + this.dataset.tab + "\"]");
                    console.log("OH Wizard: Target content:", targetContent);
                    if (targetContent) targetContent.style.display = "block";
                });
            });

            // Property lookup
            const lookupBtn = document.getElementById("oh-lookup-btn");
            if (lookupBtn) lookupBtn.addEventListener("click", async () => {
                const btn = document.getElementById("oh-lookup-btn");
                const url = document.getElementById("oh-listing-url").value;
                const address = document.getElementById("oh-address-search").value;
                const errorEl = document.getElementById("oh-lookup-error");

                btn.querySelector(".oh-btn__text").style.display = "none";
                btn.querySelector(".oh-btn__loading").style.display = "inline";
                errorEl.style.display = "none";

                try {
                    const response = await fetch("' . admin_url( 'admin-ajax.php' ) . '", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            action: "frs_property_lookup",
                            url: url,
                            address: address,
                            nonce: "' . wp_create_nonce( 'frs_property_lookup' ) . '"
                        })
                    });
                    const result = await response.json();

                    if (result.success) {
                        // Populate property fields
                        document.getElementById("oh-address").value = result.data.address || "";
                        document.getElementById("oh-price").value = result.data.price ? "$" + Number(result.data.price).toLocaleString() : "";
                        document.getElementById("oh-sqft").value = result.data.sqft || "";
                        document.getElementById("oh-beds").value = result.data.bedrooms || "";
                        document.getElementById("oh-baths").value = result.data.bathrooms || "";

                        // Store images
                        data.images = result.data.images || [];
                        renderImages();

                        // Move to next step
                        currentStep = 2;
                        showStep(currentStep);
                    } else {
                        errorEl.textContent = result.data || "Could not find property. Please try again or enter details manually.";
                        errorEl.style.display = "block";
                    }
                } catch (e) {
                    errorEl.textContent = "An error occurred. Please try again.";
                    errorEl.style.display = "block";
                }

                btn.querySelector(".oh-btn__text").style.display = "inline";
                btn.querySelector(".oh-btn__loading").style.display = "none";
            });

            function renderImages() {
                const grid = document.getElementById("oh-images-grid");
                const noImages = document.getElementById("oh-no-images");

                if (data.images.length === 0) {
                    grid.style.display = "none";
                    noImages.style.display = "block";
                    return;
                }

                grid.innerHTML = data.images.map((img, i) => `
                    <div class="oh-image-option ${i === 0 ? "oh-image-option--selected" : ""}" data-url="${img}">
                        <img src="${img}" alt="Property image">
                    </div>
                `).join("");

                document.getElementById("oh-hero-image").value = data.images[0];

                grid.querySelectorAll(".oh-image-option").forEach(opt => {
                    opt.addEventListener("click", () => {
                        grid.querySelectorAll(".oh-image-option").forEach(o => o.classList.remove("oh-image-option--selected"));
                        opt.classList.add("oh-image-option--selected");
                        document.getElementById("oh-hero-image").value = opt.dataset.url;
                    });
                });
            }


            // Publish
            publishBtn.addEventListener("click", async () => {
                publishBtn.querySelector(".oh-btn__text").style.display = "none";
                publishBtn.querySelector(".oh-btn__loading").style.display = "inline";

                try {
                    const response = await fetch("' . admin_url( 'admin-ajax.php' ) . '", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            action: "frs_create_open_house",
                            nonce: "' . wp_create_nonce( 'frs_create_open_house' ) . '",
                            data: JSON.stringify(data)
                        })
                    });
                    const result = await response.json();

                    if (result.success) {
                        document.getElementById("oh-success-address").textContent = data.property.address;
                        document.getElementById("oh-success-link").href = result.data.url;
                        document.getElementById("oh-qr-link").href = result.data.qr || "#";
                        document.getElementById("oh-copy-link").onclick = () => {
                            navigator.clipboard.writeText(result.data.url);
                            alert("Link copied!");
                        };
                        showStep("success");
                        wizard.querySelector(".oh-wizard__footer").style.display = "none";
                    } else {
                        alert(result.data || "Failed to create page");
                    }
                } catch (e) {
                    alert("An error occurred");
                }

                publishBtn.querySelector(".oh-btn__text").style.display = "inline";
                publishBtn.querySelector(".oh-btn__loading").style.display = "none";
            });

            // Image upload fallback
            const imageUpload = document.getElementById("oh-image-upload");
            if (imageUpload) imageUpload.addEventListener("change", (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (ev) => {
                        data.images = [ev.target.result];
                        renderImages();
                    };
                    reader.readAsDataURL(file);
                }
            });

            // Photo upload handlers
            function setupPhotoUpload(photoType) {
                // photoType: "lo" or "realtor"
                const uploadDiv = document.getElementById("oh-" + photoType + "-photo-upload");
                const fileInput = document.getElementById("oh-" + photoType + "-photo-file");
                const preview = document.getElementById("oh-" + photoType + "-photo-preview");
                const previewImg = document.getElementById("oh-" + photoType + "-photo-preview-img");
                const removeBtn = document.getElementById("oh-" + photoType + "-photo-remove");
                const photoUrlInput = document.getElementById("oh-" + photoType + "-photo-url");

                if (!uploadDiv || !fileInput) return;

                // Click to upload
                uploadDiv.addEventListener("click", () => fileInput.click());

                // Drag and drop
                uploadDiv.addEventListener("dragover", (e) => {
                    e.preventDefault();
                    uploadDiv.style.borderColor = "#0ea5e9";
                    uploadDiv.style.backgroundColor = "rgba(14, 165, 233, 0.05)";
                });

                uploadDiv.addEventListener("dragleave", () => {
                    uploadDiv.style.borderColor = "#cbd5e1";
                    uploadDiv.style.backgroundColor = "transparent";
                });

                uploadDiv.addEventListener("drop", (e) => {
                    e.preventDefault();
                    uploadDiv.style.borderColor = "#cbd5e1";
                    uploadDiv.style.backgroundColor = "transparent";
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        fileInput.files = files;
                        fileInput.dispatchEvent(new Event("change", { bubbles: true }));
                    }
                });

                // File selection
                fileInput.addEventListener("change", (e) => {
                    const file = e.target.files[0];
                    if (!file) return;

                    // Validate file type
                    if (!file.type.match(/image\/(jpeg|png|gif|webp)/)) {
                        alert("Please upload an image file (PNG, JPG, GIF, or WebP)");
                        return;
                    }

                    // Validate file size (5MB max)
                    if (file.size > 5242880) {
                        alert("File size must be less than 5MB");
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = (ev) => {
                        previewImg.src = ev.target.result;
                        preview.style.display = "flex";
                        preview.style.alignItems = "center";
                        uploadDiv.style.display = "none";
                    };
                    reader.readAsDataURL(file);
                    window.frsLpUploadPhoto(file, (url) => {
                        photoUrlInput.value = url;
                        previewImg.src = url;
                    }, (msg) => {
                        alert(msg || "Upload failed. Please try again.");
                        photoUrlInput.value = "";
                        fileInput.value = "";
                        preview.style.display = "none";
                        uploadDiv.style.display = "block";
                    });
                });

                // Remove photo
                if (removeBtn) {
                    removeBtn.addEventListener("click", () => {
                        fileInput.value = "";
                        photoUrlInput.value = "";
                        preview.style.display = "none";
                        uploadDiv.style.display = "block";
                    });
                }
            }

            setupPhotoUpload("realtor");
            setupPhotoUpload("partner");

            // LO headshot: show their current photo, let them upload a different one,
            // and confirm visibly once the new photo is uploaded.
            (function setupLoHeadshot() {
                const img = document.getElementById("oh-lo-photo-img");
                const btn = document.getElementById("oh-lo-photo-btn");
                const fileInput = document.getElementById("oh-lo-photo-file");
                const statusEl = document.getElementById("oh-lo-photo-status");
                const urlInput = document.getElementById("oh-lo-photo-url");
                if (!img || !fileInput) return;
                if (btn) btn.addEventListener("click", () => fileInput.click());
                fileInput.addEventListener("change", (e) => {
                    const file = e.target.files[0];
                    if (!file) return;
                    if (!file.type.match(/image\/(jpeg|png|gif|webp)/)) { alert("Please upload an image (PNG, JPG, GIF, or WebP)"); return; }
                    if (file.size > 5242880) { alert("File size must be less than 5MB"); return; }
                    if (statusEl) { statusEl.style.color = "#64748b"; statusEl.textContent = "Uploading…"; }
                    const reader = new FileReader();
                    reader.onload = (ev) => { img.src = ev.target.result; };
                    reader.readAsDataURL(file);
                    window.frsLpUploadPhoto(file, (url) => {
                        urlInput.value = url;
                        img.src = url;
                        if (statusEl) { statusEl.style.color = "#16a34a"; statusEl.textContent = "✓ New photo uploaded"; }
                    }, (msg) => {
                        alert(msg || "Upload failed. Please try again.");
                        if (statusEl) { statusEl.style.color = "#64748b"; statusEl.textContent = "Using your profile headshot"; }
                    });
                });
            })();

            // Partner company logo upload (separate from headshot)
            (function setupPartnerLogoUpload() {
                const uploadDiv  = document.getElementById("oh-partner-logo-upload");
                const fileInput  = document.getElementById("oh-partner-logo-file");
                const preview    = document.getElementById("oh-partner-logo-preview");
                const previewImg = document.getElementById("oh-partner-logo-preview-img");
                const removeBtn  = document.getElementById("oh-partner-logo-remove");
                const urlInput   = document.getElementById("oh-partner-logo-url");
                if (!uploadDiv || !fileInput) return;

                uploadDiv.addEventListener("click", () => fileInput.click());
                uploadDiv.addEventListener("dragover", (e) => { e.preventDefault(); uploadDiv.style.borderColor = "#0ea5e9"; });
                uploadDiv.addEventListener("dragleave", () => { uploadDiv.style.borderColor = "#cbd5e1"; });
                uploadDiv.addEventListener("drop", (e) => {
                    e.preventDefault();
                    uploadDiv.style.borderColor = "#cbd5e1";
                    if (e.dataTransfer.files.length) {
                        fileInput.files = e.dataTransfer.files;
                        fileInput.dispatchEvent(new Event("change", { bubbles: true }));
                    }
                });
                fileInput.addEventListener("change", (e) => {
                    const file = e.target.files[0];
                    if (!file) return;
                    if (!file.type.match(/image\/(jpeg|png|gif|webp)/)) { alert("Please upload an image (PNG, JPG, GIF, or WebP)"); return; }
                    if (file.size > 5242880) { alert("File size must be less than 5MB"); return; }
                    const reader = new FileReader();
                    reader.onload = (ev) => {
                        previewImg.src = ev.target.result;
                        preview.style.display = "flex";
                        preview.style.alignItems = "center";
                        uploadDiv.style.display = "none";
                    };
                    reader.readAsDataURL(file);
                    window.frsLpUploadPhoto(file, (url) => {
                        urlInput.value = url;
                        previewImg.src = url;
                    }, (msg) => {
                        alert(msg || "Upload failed. Please try again.");
                        urlInput.value = "";
                        fileInput.value = "";
                        preview.style.display = "none";
                        uploadDiv.style.display = "block";
                    });
                });
                if (removeBtn) removeBtn.addEventListener("click", () => {
                    fileInput.value = "";
                    urlInput.value = "";
                    preview.style.display = "none";
                    uploadDiv.style.display = "block";
                });
            })();

            showStep(0);
            console.log("OH Wizard: Initialization complete");
        });
        </script>' . InstantImages::render_search_scripts( 'oh', 'oh-hero-image', 'oh-images-grid' );
    }

    /**
     * AJAX: Property lookup
     */
    public static function ajax_property_lookup() {
        check_ajax_referer( 'frs_property_lookup', 'nonce' );

        $url     = sanitize_text_field( $_POST['url'] ?? '' );
        $address = sanitize_text_field( $_POST['address'] ?? '' );

        if ( ! empty( $url ) ) {
            $result = Firecrawl::scrape_listing( $url );
        } elseif ( ! empty( $address ) ) {
            $result = Firecrawl::search_property( $address );
        } else {
            wp_send_json_error( 'Please enter a URL or address' );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Create open house page
     */
    public static function ajax_create_open_house() {
        check_ajax_referer( 'frs_create_open_house', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $data = json_decode( stripslashes( $_POST['data'] ?? '{}' ), true );

        if ( empty( $data['property']['address'] ) ) {
            wp_send_json_error( 'Missing property address' );
        }

        // Create the landing page
        $page_id = wp_insert_post([
            'post_type'   => 'frs_lead_page',
            'post_title'  => 'Open House: ' . $data['property']['address'],
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ]);

        if ( is_wp_error( $page_id ) ) {
            wp_send_json_error( $page_id->get_error_message() );
        }

        // Save meta
        update_post_meta( $page_id, '_frs_page_type', 'open_house' );
        update_post_meta( $page_id, '_frs_property_address', $data['property']['address'] );
        update_post_meta( $page_id, '_frs_property_price', preg_replace( '/[^0-9]/', '', $data['property']['price'] ?? '' ) );
        update_post_meta( $page_id, '_frs_property_sqft', $data['property']['sqft'] ?? '' );
        update_post_meta( $page_id, '_frs_property_beds', $data['property']['beds'] ?? '' );
        update_post_meta( $page_id, '_frs_property_baths', $data['property']['baths'] ?? '' );
        update_post_meta( $page_id, '_frs_hero_image_url', $data['property']['heroImage'] ?? '' );
        update_post_meta( $page_id, '_frs_headline', $data['customize']['headline'] ?? 'Welcome!' );
        update_post_meta( $page_id, '_frs_subheadline', $data['customize']['subheadline'] ?? '' );
        update_post_meta( $page_id, '_frs_button_text', $data['customize']['buttonText'] ?? 'Sign In' );
        update_post_meta( $page_id, '_frs_enabled_questions', $data['questions'] ?? [] );
        update_post_meta( $page_id, '_frs_page_views', 0 );

        // Save creator info and partner info based on user mode
        $user_mode = $data['userMode'] ?? 'realtor';
        update_post_meta( $page_id, '_frs_creator_mode', $user_mode );

        if ( $user_mode === 'loan_officer' ) {
            // LO Mode: Current user is the LO, partner is optional Realtor
            update_post_meta( $page_id, '_frs_loan_officer_id', get_current_user_id() );
            update_post_meta( $page_id, '_frs_lo_name', $data['branding']['loName'] ?? '' );
            update_post_meta( $page_id, '_frs_lo_phone', $data['branding']['loPhone'] ?? '' );
            update_post_meta( $page_id, '_frs_lo_email', $data['branding']['loEmail'] ?? '' );
            update_post_meta( $page_id, '_frs_lo_nmls', $data['branding']['loNmls'] ?? '' );
            
            // LO Photo (new feature - custom photo upload)
            if ( ! empty( $data['branding']['loPhoto'] ) ) {
                update_post_meta( $page_id, '_frs_lo_photo', $data['branding']['loPhoto'] );
            }

            // Optional Realtor partner (manual entry from Co-branded step)
            if ( ! empty( $data['partner']['name'] ) ) {
                update_post_meta( $page_id, '_frs_realtor_id', 0 );
                update_post_meta( $page_id, '_frs_realtor_name', $data['partner']['name'] );
                update_post_meta( $page_id, '_frs_realtor_phone', $data['partner']['phone'] ?? '' );
                update_post_meta( $page_id, '_frs_realtor_email', $data['partner']['email'] ?? '' );
                update_post_meta( $page_id, '_frs_realtor_license', $data['partner']['license'] ?? '' );
                update_post_meta( $page_id, '_frs_realtor_company', $data['partner']['company'] ?? '' );

                // Partner headshot (optional, starts empty)
                if ( ! empty( $data['partner']['photo'] ) ) {
                    update_post_meta( $page_id, '_frs_realtor_photo', $data['partner']['photo'] );
                }

                // Partner company logo (optional, starts empty)
                if ( ! empty( $data['partner']['logo'] ) ) {
                    update_post_meta( $page_id, '_frs_brokerage_logo', $data['partner']['logo'] );
                }
            }
        } else {
            // Realtor Mode: Current user is the Realtor, partner is required LO
            update_post_meta( $page_id, '_frs_realtor_id', get_current_user_id() );
            update_post_meta( $page_id, '_frs_realtor_name', $data['branding']['realtorName'] ?? '' );
            update_post_meta( $page_id, '_frs_realtor_phone', $data['branding']['realtorPhone'] ?? '' );
            update_post_meta( $page_id, '_frs_realtor_email', $data['branding']['realtorEmail'] ?? '' );
            update_post_meta( $page_id, '_frs_realtor_license', $data['branding']['realtorLicense'] ?? '' );
            
            // Realtor Photo (new feature - custom photo upload)
            if ( ! empty( $data['branding']['realtorPhoto'] ) ) {
                update_post_meta( $page_id, '_frs_realtor_photo', $data['branding']['realtorPhoto'] );
            }

            // LO partner (required for realtor mode, but might use legacy data structure)
            $lo_id = $data['partner']['id'] ?? $data['loanOfficer']['id'] ?? '';
            if ( ! empty( $lo_id ) ) {
                update_post_meta( $page_id, '_frs_loan_officer_id', $lo_id );
                
                // LO Photo (new feature - custom photo upload for partner)
                if ( ! empty( $data['branding']['loPhoto'] ) ) {
                    update_post_meta( $page_id, '_frs_lo_photo', $data['branding']['loPhoto'] );
                }
            }
        }

        // Generate QR code
        $qr_url = '';
        if ( class_exists( '\FRSLeadPages\Core\QRCode' ) ) {
            $qr_url = \FRSLeadPages\Core\QRCode::generate( $page_id );
            update_post_meta( $page_id, '_frs_qr_code', $qr_url );
        }

        wp_send_json_success([
            'id'  => $page_id,
            'url' => get_permalink( $page_id ),
            'qr'  => $qr_url,
        ]);
    }

    /**
     * Render login required
     */
    private static function render_login_required(): string {
        return '<div class="oh-wizard" style="text-align:center;padding:48px;">
            <h2>Login Required</h2>
            <p>Please log in to create an Open House page.</p>
            <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="oh-btn oh-btn--primary">Log In</a>
        </div>';
    }

    /**
     * Render access denied
     */
    private static function render_access_denied(): string {
        return '<div class="oh-wizard" style="text-align:center;padding:48px;">
            <h2>Access Denied</h2>
            <p>You do not have permission to create Open House pages.</p>
        </div>';
    }

    /**
     * Enqueue wizard assets
     */
    private static function enqueue_assets(): void {
        $base_url = plugins_url( 'includes/OpenHouse/', FRS_LEAD_PAGES_PLUGIN_FILE );
        $version  = FRS_LEAD_PAGES_VERSION;

        wp_enqueue_style(
            'frs-open-house-wizard',
            $base_url . 'style.css',
            [],
            $version
        );

        wp_enqueue_script(
            'frs-open-house-wizard',
            $base_url . 'script.js',
            [],
            $version,
            true
        );

        wp_localize_script( 'frs-open-house-wizard', 'frsOpenHouseWizard', [
            'triggerClass' => self::TRIGGER_CLASS,
            'triggerHash'  => self::TRIGGER_HASH,
        ] );
    }

    /**
     * Render modal styles (now uses external CSS)
     */
    private static function render_modal_styles(): string {
        self::enqueue_assets();
        return '';
    }

    /**
     * Render modal scripts (now uses external JS)
     */
    private static function render_modal_scripts(): string {
        return '';
    }
}

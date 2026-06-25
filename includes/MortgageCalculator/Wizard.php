<?php
/**
 * Mortgage Calculator Wizard
 *
 * Multi-step wizard for creating Mortgage Calculator landing pages.
 * Provides interactive calculators with lead capture.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\MortgageCalculator;

use FRSLeadPages\Core\LoanOfficers;
use FRSLeadPages\Core\Realtors;
use FRSLeadPages\Core\UserMode;

class Wizard {

    /**
     * Trigger class for opening modal
     */
    const TRIGGER_CLASS = 'mc-wizard-trigger';

    /**
     * Hash for URL triggering
     */
    const TRIGGER_HASH = 'mortgage-calculator-wizard';

    /**
     * Initialize
     */
    public static function init() {
        add_shortcode( 'mortgage_calculator_wizard', [ __CLASS__, 'render' ] );
        add_shortcode( 'mortgage_calculator_wizard_button', [ __CLASS__, 'render_button' ] );
        add_action( 'wp_ajax_frs_create_calculator', [ __CLASS__, 'ajax_create_calculator' ] );

        // Add modal to footer on frontend
    }

    /**
     * Render trigger button shortcode
     */
    public static function render_button( array $atts = [] ): string {
        $atts = shortcode_atts([
            'text'  => 'Create Calculator Page',
            'class' => '',
        ], $atts, 'mortgage_calculator_wizard_button' );

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
        <div id="mc-wizard-modal" class="mc-modal">
            <div class="mc-modal__backdrop"></div>
            <div class="mc-modal__container">
                <button type="button" class="mc-modal__close" aria-label="Close">
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
        $user_data['photo'] = \FRSLeadPages\frs_normalize_upload_url( get_avatar_url( $user->ID, [ 'size' => 200 ] ) );

        // Get partners based on user mode
        $partners = $partner_config['partners'];

        ob_start();
        ?>
        <div id="mc-wizard" class="mc-wizard" data-user='<?php echo esc_attr( wp_json_encode( $user_data ) ); ?>'>
            <div class="mc-wizard__hero">
                <div class="mc-wizard__hero-content">
                    <h1>Create Your<br>Calculator Page</h1>
                    <p>Build an interactive mortgage calculator landing page with built-in lead capture.</p>
                </div>
            </div>

            <div class="mc-wizard__form">
                <div class="mc-wizard__progress">
                    <div class="mc-wizard__progress-bar" style="width: 14.3%"></div>
                </div>

                <div class="mc-wizard__header">
                    <p class="mc-wizard__title">Calculator Wizard</p>
                    <p class="mc-wizard__subtitle">Step <span id="mc-step-num">1</span> of 3</p>
                </div>

                <div class="mc-wizard__nav-top">
                    <button type="button" id="mc-back-top" class="mc-btn mc-btn--ghost mc-btn--sm" style="display:none;">Back</button>
                    <button type="button" id="mc-next-top" class="mc-btn mc-btn--primary mc-btn--sm">Continue</button>
                </div>

                <div class="mc-wizard__content">
                <!-- Step 0: Page Type Selection -->
                <div class="mc-step" data-step="0">
                    <div class="mc-step__header">
                        <h2><?php echo $is_loan_officer ? 'What type of page?' : esc_html( $partner_config['title'] ); ?></h2>
                        <p><?php echo $is_loan_officer ? 'Choose how you want to brand this page' : esc_html( $partner_config['subtitle'] ); ?></p>
                    </div>
                    <div class="mc-step__body">
                        <?php if ( $is_loan_officer ) : ?>
                            <input type="hidden" id="mc-page-type" name="page_type" value="">
                            <input type="hidden" id="mc-partner" name="partner" value="">

                            <div class="mc-page-type-cards">
                                <div class="mc-page-type-card" data-type="solo">
                                    <div class="mc-page-type-card__icon">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                    </div>
                                    <h3>Solo Page</h3>
                                    <p>Just your branding</p>
                                </div>
                                <div class="mc-page-type-card" data-type="cobranded">
                                    <div class="mc-page-type-card__icon">
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

                            <div id="mc-partner-selection" class="mc-partner-selection" style="display: none;">
                                <p class="mc-section-label" style="margin-top:24px;">Partner Real Estate Agent</p>
                                <div class="mc-row">
                                    <div class="mc-field mc-field--half">
                                        <label class="mc-label">Partner Name</label>
                                        <input type="text" id="mc-partner-name-input" class="mc-input" placeholder="Jane Smith">
                                    </div>
                                    <div class="mc-field mc-field--half">
                                        <label class="mc-label">Phone</label>
                                        <input type="tel" id="mc-partner-phone-input" class="mc-input" placeholder="(555) 123-4567">
                                    </div>
                                </div>
                                <div class="mc-field">
                                    <label class="mc-label">Email</label>
                                    <input type="email" id="mc-partner-email-input" class="mc-input" placeholder="jane@realestate.com">
                                </div>

                                <!-- Partner Headshot Upload -->
                                <div class="mc-field" style="margin-top: 24px;">
                                    <label class="mc-label">Partner Headshot (optional)</label>
                                    <div class="mc-photo-upload" id="mc-partner-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="mc-partner-photo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="mc-partner-photo-preview" style="margin-top: 12px; display: none;">
                                        <img id="mc-partner-photo-preview-img" src="" alt="Headshot preview" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                                        <button type="button" id="mc-partner-photo-remove" class="mc-btn mc-btn--ghost mc-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="mc-partner-photo-url" value="">
                                </div>

                                <!-- Company Logo Upload -->
                                <div class="mc-field" style="margin-top: 16px;">
                                    <label class="mc-label">Company Logo (optional)</label>
                                    <div class="mc-photo-upload" id="mc-partner-logo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="mc-partner-logo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="mc-partner-logo-preview" style="margin-top: 12px; display: none;">
                                        <img id="mc-partner-logo-preview-img" src="" alt="Logo preview" style="width: 120px; height: 120px; border-radius: 8px; object-fit: contain; background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0;">
                                        <button type="button" id="mc-partner-logo-remove" class="mc-btn mc-btn--ghost mc-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="mc-partner-logo-url" value="">
                                </div>
                                <p class="mc-helper">Both images are optional — add whichever you want on the landing page</p>
                            </div>
                        <?php else : ?>
                            <label class="mc-label"><?php echo esc_html( $partner_config['label'] ); ?></label>
                            <div class="mc-dropdown" id="mc-partner-dropdown" data-mode="<?php echo esc_attr( $user_mode ); ?>" data-required="true" data-preferred="<?php echo esc_attr( $partner_config['preferred_id'] ?? 0 ); ?>">
                                <input type="hidden" id="mc-partner" name="partner" value="">
                                <button type="button" class="mc-dropdown__trigger">
                                    <span class="mc-dropdown__value"><?php echo esc_html( $partner_config['placeholder'] ); ?></span>
                                    <svg class="mc-dropdown__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <div class="mc-dropdown__menu">
                                    <?php foreach ( $partners as $partner ) : ?>
                                        <?php
                                        $partner_id = $partner['user_id'] ?? $partner['id'];
                                        $partner_name = $partner['name'];
                                        $partner_photo = $partner['photo_url'] ?? '';
                                        $partner_nmls = $partner['nmls'] ?? '';
                                        $is_preferred = ( (int) $partner_id === (int) ( $partner_config['preferred_id'] ?? 0 ) );
                                        ?>
                                        <div class="mc-dropdown__item<?php echo $is_preferred ? ' mc-dropdown__item--preferred' : ''; ?>"
                                             data-value="<?php echo esc_attr( $partner_id ); ?>"
                                             data-name="<?php echo esc_attr( $partner_name ); ?>"
                                             data-nmls="<?php echo esc_attr( $partner_nmls ); ?>"
                                             data-photo="<?php echo esc_attr( $partner_photo ); ?>">
                                            <img src="<?php echo esc_url( $partner_photo ); ?>" alt="" class="mc-dropdown__photo">
                                            <div class="mc-dropdown__info">
                                                <span class="mc-dropdown__name"><?php echo esc_html( $partner_name ); ?></span>
                                                <?php if ( $partner_nmls ) : ?>
                                                    <span class="mc-dropdown__nmls">NMLS# <?php echo esc_html( $partner_nmls ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( $is_preferred ) : ?>
                                                <span class="mc-dropdown__preferred-badge">★ Preferred</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="mc-helper"><?php echo esc_html( $partner_config['helper'] ); ?></p>

                            <?php if ( $partner_config['show_remember'] ?? false ) : ?>
                                <label class="mc-checkbox" style="margin-top: 12px;">
                                    <input type="checkbox" id="mc-remember-partner" name="remember_partner" value="1">
                                    <span class="mc-checkbox__label">Remember my choice for next time</span>
                                </label>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="mc-color-section" style="margin-top: 24px;">
                            <label class="mc-label">Calculator Colors</label>
                            <p class="mc-helper" style="margin-bottom: 16px;">Choose the gradient colors for your calculator branding</p>
                            <div class="mc-color-pickers">
                                <div class="mc-color-picker">
                                    <label class="mc-color-picker__label">Gradient Start</label>
                                    <div class="mc-color-picker__input-wrap">
                                        <input type="color" id="mc-color-start" value="#252526" class="mc-color-input">
                                        <input type="text" id="mc-color-start-hex" value="#252526" class="mc-input mc-input--small" maxlength="7">
                                    </div>
                                </div>
                                <div class="mc-color-picker">
                                    <label class="mc-color-picker__label">Gradient End</label>
                                    <div class="mc-color-picker__input-wrap">
                                        <input type="color" id="mc-color-end" value="#1f1f1f" class="mc-color-input">
                                        <input type="text" id="mc-color-end-hex" value="#1f1f1f" class="mc-input mc-input--small" maxlength="7">
                                    </div>
                                </div>
                            </div>
                            <div class="mc-color-preview">
                                <div class="mc-color-preview__gradient" id="mc-gradient-preview" style="background: linear-gradient(135deg, #252526 0%, #1f1f1f 100%);"></div>
                                <span class="mc-color-preview__label">Preview</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 1: Your Branding (bi-directional) -->
                <div class="mc-step" data-step="1" style="display:none;">
                    <div class="mc-step__header">
                        <h2>Your Branding</h2>
                        <p>Review and customize your profile info</p>
                    </div>
                    <div class="mc-step__body">
                        <div class="mc-branding-preview">
                            <div class="mc-branding-photo">
                                <img id="mc-preview-photo" src="<?php echo esc_url( $user_data['photo'] ); ?>" alt="">
                            </div>
                            <div class="mc-branding-info">
                                <p class="mc-branding-name" id="mc-preview-name"><?php echo esc_html( $user_data['name'] ); ?></p>
                                <p class="mc-branding-detail" id="mc-preview-license"><?php echo esc_html( $is_loan_officer ? ( $user_data['nmls'] ?? '' ) : ( $user_data['license'] ?? '' ) ); ?></p>
                            </div>
                        </div>

                        <?php if ( $is_loan_officer ) : ?>
                            <!-- LO Mode: Show LO fields -->

                            <!-- Headshot: use profile photo or upload a new one -->
                            <div class="mc-field">
                                <label class="mc-label">Your Headshot</label>
                                <div class="mc-headshot-choice" role="radiogroup" style="display:flex; gap:12px; flex-wrap:wrap;">
                                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px;">
                                        <input type="radio" name="mc-headshot-source" value="profile" checked> <span>Use my profile headshot</span>
                                    </label>
                                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px;">
                                        <input type="radio" name="mc-headshot-source" value="upload"> <span>Upload a new one</span>
                                    </label>
                                </div>
                                <div id="mc-lo-photo-upload-wrap" style="display:none; margin-top:12px;">
                                    <div class="mc-photo-upload" id="mc-lo-photo-upload" style="border:2px dashed #cbd5e1; padding:20px; border-radius:8px; text-align:center; cursor:pointer;">
                                        <input type="file" id="mc-lo-photo-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                                        <p style="margin:0; font-weight:500;">Click to upload or drag and drop</p>
                                        <p style="margin:4px 0 0; font-size:12px; color:#94a3b8;">PNG, JPG, GIF or WebP (max 5MB)</p>
                                    </div>
                                    <p id="mc-lo-photo-status" style="display:none; font-size:13px; margin-top:8px;"></p>
                                </div>
                                <input type="hidden" id="mc-lo-photo-url" value="" data-profile-photo="<?php echo esc_attr( $user_data['photo'] ); ?>">
                            </div>

                            <div class="mc-field">
                                <label class="mc-label">Display Name</label>
                                <input type="text" id="mc-lo-name" class="mc-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                            </div>
                            <div class="mc-field">
                                <label class="mc-label">NMLS #</label>
                                <input type="text" id="mc-lo-nmls" class="mc-input" value="<?php echo esc_attr( $user_data['nmls'] ?? '' ); ?>">
                            </div>
                            <div class="mc-field">
                                <label class="mc-label">Contact Phone</label>
                                <input type="tel" id="mc-lo-phone" class="mc-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                            </div>
                            <div class="mc-field">
                                <label class="mc-label">Contact Email</label>
                                <input type="email" id="mc-lo-email" class="mc-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                            </div>
                        <?php else : ?>
                            <!-- Realtor Mode: Show Realtor fields -->
                            <div class="mc-field">
                                <label class="mc-label">Display Name</label>
                                <input type="text" id="mc-realtor-name" class="mc-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                            </div>
                            <div class="mc-field">
                                <label class="mc-label">License Number</label>
                                <input type="text" id="mc-realtor-license" class="mc-input" value="<?php echo esc_attr( $user_data['license'] ?? '' ); ?>">
                            </div>
                            <div class="mc-field">
                                <label class="mc-label">Contact Phone</label>
                                <input type="tel" id="mc-realtor-phone" class="mc-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                            </div>
                            <div class="mc-field">
                                <label class="mc-label">Contact Email</label>
                                <input type="email" id="mc-realtor-email" class="mc-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 2: Choose Output -->
                <div class="mc-step" data-step="2" style="display:none;">
                    <div class="mc-step__header">
                        <h2>How Would You Like to Use This?</h2>
                        <p>Choose how you want to share your branded calculator</p>
                    </div>
                    <div class="mc-step__body">
                        <div class="mc-output-options">
                            <label class="mc-output-card">
                                <input type="radio" name="mc-output-type" value="landing_page" checked>
                                <div class="mc-output-card__content">
                                    <div class="mc-output-card__icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3" y="3" width="18" height="18" rx="2"/>
                                            <path d="M3 9h18"/>
                                            <path d="M9 21V9"/>
                                        </svg>
                                    </div>
                                    <div class="mc-output-card__text">
                                        <strong>Create Landing Page</strong>
                                        <span>We'll host a branded calculator page for you</span>
                                        <ul class="mc-output-card__features">
                                            <li>Ready-to-share URL</li>
                                            <li>Team info displayed</li>
                                            <li>Lead tracking built-in</li>
                                        </ul>
                                    </div>
                                </div>
                            </label>

                            <label class="mc-output-card">
                                <input type="radio" name="mc-output-type" value="embed_code">
                                <div class="mc-output-card__content">
                                    <div class="mc-output-card__icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <polyline points="16 18 22 12 16 6"/>
                                            <polyline points="8 6 2 12 8 18"/>
                                        </svg>
                                    </div>
                                    <div class="mc-output-card__text">
                                        <strong>Get Embed Code</strong>
                                        <span>Add the calculator to your own website</span>
                                        <ul class="mc-output-card__features">
                                            <li>Copy &amp; paste code snippet</li>
                                            <li>Works on any website</li>
                                            <li>Your branding included</li>
                                        </ul>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <!-- Embed Code Output (shown when embed option selected) -->
                        <div id="mc-embed-output" class="mc-embed-output" style="display:none;">
                            <p class="mc-embed-output__label">Copy this code and paste it into your website:</p>
                            <div class="mc-embed-output__code-wrap">
                                <pre id="mc-embed-code" class="mc-embed-output__code"></pre>
                                <button type="button" id="mc-copy-embed" class="mc-btn mc-btn--secondary mc-btn--small">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    Copy Code
                                </button>
                            </div>
                            <p class="mc-embed-output__help">This will embed a fully-functional mortgage calculator branded with your loan officer's information.</p>
                        </div>
                    </div>
                </div>

                <!-- Success State -->
                <div class="mc-step mc-step--success" data-step="success" style="display:none;">
                    <div class="mc-success">
                        <div class="mc-success__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                        <h2 id="mc-success-title">Your Calculator Page is Live!</h2>
                        <p id="mc-success-subtitle">Share this link with your clients</p>
                        <div class="mc-success__url-box">
                            <input type="text" id="mc-success-url" readonly>
                            <button type="button" id="mc-copy-url" class="mc-btn mc-btn--primary">Copy Link</button>
                        </div>
                        <div class="mc-success__actions">
                            <a id="mc-view-page" href="#" class="mc-btn mc-btn--secondary" target="_blank">View Page</a>
                            <button type="button" id="mc-create-another" class="mc-btn mc-btn--ghost">Create Another</button>
                        </div>
                    </div>
                </div>

                </div>

                <div class="mc-wizard__footer">
                    <button type="button" class="mc-btn mc-btn--secondary" id="mc-prev-btn" style="display:none;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Back
                    </button>
                    <button type="button" class="mc-btn mc-btn--primary" id="mc-next-btn">
                        Continue
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render login required message
     */
    private static function render_login_required(): string {
        return '<div class="mc-message mc-message--warning">Please log in to create a calculator page.</div>';
    }

    /**
     * Render access denied message
     */
    private static function render_access_denied(): string {
        return '<div class="mc-message mc-message--error">You do not have permission to create calculator pages.</div>';
    }

    /**
     * Enqueue modal assets
     */
    private static function enqueue_assets(): void {
        $base_url = plugins_url( 'includes/MortgageCalculator/', FRS_LEAD_PAGES_PLUGIN_FILE );
        $version  = FRS_LEAD_PAGES_VERSION;

        wp_enqueue_style( 'frs-mortgage-calculator-wizard', $base_url . 'style.css', [], $version );
        wp_enqueue_script( 'frs-mortgage-calculator-wizard', $base_url . 'script.js', [], $version, true );

        wp_localize_script( 'frs-mortgage-calculator-wizard', 'frsMortgageCalculatorWizard', [
            'triggerClass' => self::TRIGGER_CLASS,
            'triggerHash'  => self::TRIGGER_HASH,
            'siteUrl'      => home_url(),
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'frs_lead_pages' ),
        ] );
    }

    /**
     * Render modal styles
     */
    private static function render_modal_styles(): string {
        self::enqueue_assets();
        return '';
        // Styles now loaded from external style.css file
        $unused = <<<'UNUSED'
        <style>
        /* Mortgage Calculator Wizard - 21st Century Lending Brand */
        :root {
            --mc-primary: #2563eb;
            --mc-primary-dark: #1d4ed8;
            --mc-primary-light: #3b82f6;
            --mc-primary-bg: #eff6ff;
            --mc-text: #1e293b;
            --mc-text-light: #64748b;
            --mc-border: #e5e7eb;
            --mc-white: #ffffff;
            --mc-success: #10b981;
            --mc-error: #ef4444;
        }

        /* Modal Overlay */
        .mc-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            overflow-y: auto;
        }
        .mc-modal.is-open {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .mc-modal__backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1;
        }
        .mc-modal__container {
            position: relative;
            z-index: 2;
            width: 100vw;
            height: 100vh;
            overflow-y: auto;
        }
        .mc-modal__close {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 10;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.95);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.2s;
        }
        .mc-modal__close:hover {
            background: var(--mc-white);
            transform: scale(1.1);
        }
        .mc-modal__close svg {
            color: var(--mc-text);
        }

        /* Wizard Layout */
        .mc-wizard {
            display: flex;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* Hero Section */
        .mc-wizard__hero {
            width: 50%;
            height: 100vh;
            background: linear-gradient(135deg, var(--mc-primary) 0%, var(--mc-primary-dark) 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 64px;
            position: fixed;
            left: 0;
            top: 0;
            overflow: hidden;
            color: var(--mc-white);
        }
        .mc-wizard__hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        }
        .mc-wizard__hero h1 {
            font-size: 42px;
            font-weight: 700;
            line-height: 1.1;
            margin: 0 0 16px 0;
            color: var(--mc-white);
            position: relative;
        }
        .mc-wizard__hero p {
            font-size: 18px;
            opacity: 0.9;
            margin: 0;
            line-height: 1.6;
            max-width: 400px;
            position: relative;
        }

        /* Form Section */
        .mc-wizard__form {
            width: 50%;
            margin-left: 50%;
            min-height: 100vh;
            background: var(--mc-white);
            padding: 48px 56px;
            box-sizing: border-box;
        }

        /* Progress Bar */
        .mc-wizard__progress {
            height: 4px;
            margin-bottom: 32px;
            background: var(--mc-border);
        }
        .mc-wizard__progress-bar {
            height: 100%;
            background: var(--mc-primary);
            transition: width 0.3s ease;
        }

        /* Header */
        .mc-wizard__header {
            padding: 20px 32px;
            border-bottom: 1px solid var(--mc-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .mc-wizard__title {
            font-size: 14px;
            font-weight: 600;
            color: var(--mc-primary);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .mc-wizard__nav-top {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-bottom: 16px;
        }
        .mc-btn--sm {
            padding: 8px 16px;
            font-size: 13px;
        }
        .mc-wizard__subtitle {
            font-size: 14px;
            color: var(--mc-text-light);
            margin: 0;
        }

        /* Content Area */
        .mc-wizard__content {
        }

        /* Step Sections */
        .mc-step__header {
            margin-bottom: 24px;
        }
        .mc-step__header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--mc-text);
            margin: 0 0 8px 0;
        }
        .mc-step__header p {
            font-size: 15px;
            color: var(--mc-text-light);
            margin: 0;
        }

        /* Form Elements */
        .mc-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--mc-text);
            margin-bottom: 8px;
        }
        .mc-label-hint {
            font-weight: 400;
            color: var(--mc-text-light);
        }
        .mc-input,
        .mc-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--mc-border);
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        .mc-input:focus,
        .mc-textarea:focus {
            outline: none;
            border-color: var(--mc-primary);
            box-shadow: 0 0 0 3px var(--mc-primary-bg);
        }
        .mc-textarea {
            resize: vertical;
            min-height: 100px;
        }
        .mc-helper {
            font-size: 13px;
            color: var(--mc-text-light);
            margin-top: 8px;
        }
        .mc-field {
            margin-bottom: 20px;
        }

        /* Dropdown */
        .mc-dropdown {
            position: relative;
        }
        .mc-dropdown__trigger {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--mc-border);
            border-radius: 10px;
            background: var(--mc-white);
            font-size: 15px;
            text-align: left;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s;
        }
        .mc-dropdown__trigger:hover {
            border-color: var(--mc-primary-light);
        }
        .mc-dropdown.is-open .mc-dropdown__trigger {
            border-color: var(--mc-primary);
            box-shadow: 0 0 0 3px var(--mc-primary-bg);
        }
        .mc-dropdown__arrow {
            transition: transform 0.2s;
        }
        .mc-dropdown.is-open .mc-dropdown__arrow {
            transform: rotate(180deg);
        }
        .mc-dropdown__menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            margin-top: 4px;
            background: var(--mc-white);
            border: 2px solid var(--mc-border);
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            z-index: 100;
            max-height: 280px;
            overflow-y: auto;
        }
        .mc-dropdown.is-open .mc-dropdown__menu {
            display: block;
        }
        .mc-dropdown__item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .mc-dropdown__item:hover {
            background: var(--mc-primary-bg);
        }
        .mc-dropdown__item.is-selected {
            background: var(--mc-primary-bg);
        }
        .mc-dropdown__photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .mc-dropdown__info {
            display: flex;
            flex-direction: column;
        }
        .mc-dropdown__name {
            font-weight: 600;
            color: var(--mc-text);
        }
        .mc-dropdown__nmls {
            font-size: 13px;
            color: var(--mc-text-light);
        }
        .mc-dropdown__item--preferred {
            background: #cffafe;
            border-left: 3px solid #2DD4DA;
        }
        .mc-dropdown__item--preferred:hover {
            background: #a5f3fc;
        }
        .mc-dropdown__preferred-badge {
            margin-left: auto;
            font-size: 11px;
            font-weight: 600;
            color: #0891b2;
            background: #cffafe;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .mc-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .mc-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #8b5cf6;
            cursor: pointer;
        }
        .mc-checkbox__label {
            font-size: 14px;
            color: var(--mc-text-light);
        }

        /* Type Selection Grid */
        .mc-type-grid {
            display: grid;
            gap: 12px;
        }
        .mc-type-card {
            cursor: pointer;
        }
        .mc-type-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .mc-type-card__content {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            border: 2px solid var(--mc-border);
            border-radius: 12px;
            transition: all 0.2s;
        }
        .mc-type-card:hover .mc-type-card__content {
            border-color: var(--mc-primary-light);
        }
        .mc-type-card input:checked + .mc-type-card__content {
            border-color: var(--mc-primary);
            background: var(--mc-primary-bg);
        }
        .mc-type-card__icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--mc-primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .mc-type-card__icon svg {
            width: 24px;
            height: 24px;
            stroke: var(--mc-primary);
        }
        .mc-type-card input:checked + .mc-type-card__content .mc-type-card__icon {
            background: var(--mc-primary);
        }
        .mc-type-card input:checked + .mc-type-card__content .mc-type-card__icon svg {
            stroke: var(--mc-white);
        }
        .mc-type-card__text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .mc-type-card__text strong {
            font-size: 15px;
            color: var(--mc-text);
        }
        .mc-type-card__text span {
            font-size: 13px;
            color: var(--mc-text-light);
        }

        /* Radio Group */
        .mc-radio-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .mc-radio-group label {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border: 2px solid var(--mc-border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .mc-radio-group label:hover {
            border-color: var(--mc-primary-light);
        }
        .mc-radio-group input:checked + span {
            color: var(--mc-primary);
        }
        .mc-radio-group label:has(input:checked) {
            border-color: var(--mc-primary);
            background: var(--mc-primary-bg);
        }

        /* Toggle List */
        .mc-section-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--mc-text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .mc-toggle-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .mc-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 8px;
            cursor: pointer;
        }
        .mc-toggle input {
            width: 18px;
            height: 18px;
            accent-color: var(--mc-primary);
        }
        .mc-toggle__label {
            flex: 1;
            font-size: 14px;
            color: var(--mc-text);
        }
        .mc-toggle__required {
            font-size: 12px;
            color: var(--mc-text-light);
            background: var(--mc-border);
            padding: 2px 8px;
            border-radius: 4px;
        }

        /* Image Grid */
        .mc-images-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .mc-images-grid label {
            position: relative;
            cursor: pointer;
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 4/3;
        }
        .mc-images-grid input {
            position: absolute;
            opacity: 0;
        }
        .mc-images-grid img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s;
        }
        .mc-images-grid label:hover img {
            transform: scale(1.05);
        }
        .mc-images-grid input:checked + img {
            outline: 3px solid var(--mc-primary);
            outline-offset: -3px;
        }

        /* Upload Section */
        .mc-upload-section {
            text-align: center;
            padding: 20px;
            border: 2px dashed var(--mc-border);
            border-radius: 8px;
        }
        .mc-upload-section p {
            margin: 0 0 12px;
            color: var(--mc-text-light);
        }
        .mc-file-input {
            display: none;
        }

        /* Branding Preview */
        .mc-branding-preview {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            background: var(--mc-primary-bg);
            border-radius: 12px;
            margin-bottom: 24px;
        }
        .mc-branding-photo img {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--mc-white);
        }
        .mc-branding-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--mc-text);
            margin: 0 0 4px;
        }
        .mc-branding-detail {
            font-size: 14px;
            color: var(--mc-text-light);
            margin: 0;
        }

        /* Preview Card */
        .mc-preview-card {
            border: 2px solid var(--mc-border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .mc-preview-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--mc-primary) 0%, var(--mc-primary-dark) 100%);
            color: var(--mc-white);
        }
        .mc-preview-badge {
            display: inline-block;
            padding: 4px 12px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .mc-preview-header h3 {
            font-size: 20px;
            margin: 0 0 8px;
            color: var(--mc-white);
        }
        .mc-preview-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .mc-preview-image {
            height: 120px;
            background: #f1f5f9;
        }
        .mc-preview-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .mc-preview-features {
            padding: 16px 20px;
            border-bottom: 1px solid var(--mc-border);
        }
        .mc-preview-features ul {
            margin: 0;
            padding: 0 0 0 20px;
            font-size: 14px;
            color: var(--mc-text);
        }
        .mc-preview-features li {
            margin-bottom: 4px;
        }
        .mc-preview-footer {
            padding: 16px 20px;
            background: #f8fafc;
        }
        .mc-preview-agents {
            display: flex;
            gap: 20px;
        }
        .mc-preview-agent {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mc-preview-agent img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }
        .mc-preview-agent span {
            font-size: 13px;
            font-weight: 500;
            color: var(--mc-text);
        }

        /* Output Options */
        .mc-output-options {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .mc-output-card {
            cursor: pointer;
        }
        .mc-output-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .mc-output-card__content {
            display: flex;
            gap: 20px;
            padding: 24px;
            border: 2px solid var(--mc-border);
            border-radius: 12px;
            transition: all 0.2s;
        }
        .mc-output-card:hover .mc-output-card__content {
            border-color: var(--mc-primary-light);
        }
        .mc-output-card input:checked + .mc-output-card__content {
            border-color: var(--mc-primary);
            background: var(--mc-primary-bg);
        }
        .mc-output-card__icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: var(--mc-primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .mc-output-card__icon svg {
            width: 28px;
            height: 28px;
            stroke: var(--mc-primary);
        }
        .mc-output-card input:checked + .mc-output-card__content .mc-output-card__icon {
            background: var(--mc-primary);
        }
        .mc-output-card input:checked + .mc-output-card__content .mc-output-card__icon svg {
            stroke: var(--mc-white);
        }
        .mc-output-card__text {
            flex: 1;
        }
        .mc-output-card__text strong {
            display: block;
            font-size: 17px;
            font-weight: 600;
            color: var(--mc-text);
            margin-bottom: 4px;
        }
        .mc-output-card__text > span {
            display: block;
            font-size: 14px;
            color: var(--mc-text-light);
            margin-bottom: 12px;
        }
        .mc-output-card__features {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .mc-output-card__features li {
            font-size: 13px;
            color: var(--mc-text-light);
            padding-left: 20px;
            position: relative;
            margin-bottom: 4px;
        }
        .mc-output-card__features li::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: var(--mc-success);
            font-weight: bold;
        }

        /* Embed Output */
        .mc-embed-output {
            margin-top: 24px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
        }
        .mc-embed-output__label {
            font-size: 14px;
            font-weight: 600;
            color: var(--mc-text);
            margin: 0 0 12px;
        }
        .mc-embed-output__code-wrap {
            position: relative;
        }
        .mc-embed-output__code {
            background: #1e293b;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.5;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            margin: 0 0 12px;
        }
        .mc-embed-output__code-wrap .mc-btn {
            position: absolute;
            top: 8px;
            right: 8px;
        }
        .mc-btn--small {
            padding: 8px 12px;
            font-size: 13px;
        }
        .mc-embed-output__help {
            font-size: 13px;
            color: var(--mc-text-light);
            margin: 0;
        }

        /* Success State */
        .mc-success {
            text-align: center;
            padding: 40px 20px;
        }
        .mc-success__icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--mc-success) 0%, #059669 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .mc-success__icon svg {
            stroke: var(--mc-white);
        }
        .mc-success h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--mc-text);
            margin: 0 0 8px;
        }
        .mc-success p {
            font-size: 15px;
            color: var(--mc-text-light);
            margin: 0 0 24px;
        }
        .mc-success__url-box {
            display: flex;
            gap: 8px;
            max-width: 500px;
            margin: 0 auto 24px;
        }
        .mc-success__url-box input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid var(--mc-border);
            border-radius: 8px;
            font-size: 14px;
            background: #f8fafc;
        }
        .mc-success__actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .mc-btn--ghost {
            background: transparent;
            color: var(--mc-text-light);
            border: none;
        }
        .mc-btn--ghost:hover {
            color: var(--mc-primary);
        }

        /* Footer */
        .mc-wizard__footer {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--mc-border);
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        /* Buttons */
        .mc-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        .mc-btn--primary {
            background: var(--mc-primary);
            color: var(--mc-white);
        }
        .mc-btn--primary:hover {
            background: var(--mc-primary-dark);
        }
        .mc-btn--primary:disabled {
            background: var(--mc-border);
            cursor: not-allowed;
        }
        .mc-btn--secondary {
            background: var(--mc-white);
            color: var(--mc-text);
            border: 2px solid var(--mc-border);
        }
        .mc-btn--secondary:hover {
            border-color: var(--mc-primary);
            color: var(--mc-primary);
        }

        /* Loading State */
        .mc-btn.is-loading {
            pointer-events: none;
            opacity: 0.7;
        }
        .mc-btn.is-loading::after {
            content: '';
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: mc-spin 0.8s linear infinite;
            margin-left: 8px;
        }
        @keyframes mc-spin {
            to { transform: rotate(360deg); }
        }

        /* Messages */
        .mc-message {
            padding: 16px 20px;
            border-radius: 8px;
            font-size: 14px;
        }
        .mc-message--warning {
            background: #cffafe;
            color: #92400e;
        }
        .mc-message--error {
            background: #fee2e2;
            color: #991b1b;
        }
        .mc-message--success {
            background: #d1fae5;
            color: #065f46;
        }

        /* Color Pickers */
        .mc-color-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--mc-border);
        }
        .mc-color-pickers {
            display: flex;
            gap: 24px;
            margin-bottom: 16px;
        }
        .mc-color-picker {
            flex: 1;
        }
        .mc-color-picker__label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--mc-text-light);
            margin-bottom: 8px;
        }
        .mc-color-picker__input-wrap {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .mc-color-input {
            width: 48px;
            height: 48px;
            border: 2px solid var(--mc-border);
            border-radius: 8px;
            cursor: pointer;
            padding: 2px;
        }
        .mc-color-input::-webkit-color-swatch-wrapper {
            padding: 0;
        }
        .mc-color-input::-webkit-color-swatch {
            border: none;
            border-radius: 4px;
        }
        .mc-input--small {
            flex: 1;
            font-family: monospace;
            text-transform: uppercase;
        }
        .mc-color-preview {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .mc-color-preview__gradient {
            width: 100%;
            height: 48px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .mc-color-preview__label {
            font-size: 13px;
            color: var(--mc-text-light);
            white-space: nowrap;
        }

        /* Page Type Cards (LO mode) */
        .mc-page-type-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 8px; }
        .mc-page-type-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 24px 16px; text-align: center; cursor: pointer; transition: all 0.2s ease; background: #fff; }
        .mc-page-type-card:hover { border-color: var(--mc-primary-light); background: var(--mc-primary-bg); }
        .mc-page-type-card.selected { border-color: var(--mc-primary); background: var(--mc-primary-bg); box-shadow: 0 0 0 4px rgba(37,99,235,0.15); }
        .mc-page-type-card__icon { width: 64px; height: 64px; margin: 0 auto 12px; display: flex; align-items: center; justify-content: center; background: var(--mc-primary-bg); border-radius: 50%; }
        .mc-page-type-card__icon svg { stroke: var(--mc-primary); }
        .mc-page-type-card.selected .mc-page-type-card__icon { background: var(--mc-primary); }
        .mc-page-type-card.selected .mc-page-type-card__icon svg { stroke: #fff; }
        .mc-page-type-card h3 { font-size: 16px; font-weight: 600; color: var(--mc-text); margin: 0 0 4px; }
        .mc-page-type-card p { font-size: 13px; color: var(--mc-text-light); margin: 0; }
        .mc-partner-selection { margin-top: 16px; }

        /* Responsive */
        @media (max-width: 1024px) {
            .mc-wizard {
                flex-direction: column;
                height: auto;
                min-height: 100vh;
            }
            .mc-wizard__hero {
                width: 100%;
                padding: 48px 32px;
            }
            .mc-wizard__hero h1 {
                font-size: 32px;
            }
            .mc-wizard__form {
                width: 100%;
                height: auto;
                padding: 32px;
            }
        }
        @media (max-width: 640px) {
            .mc-wizard__hero {
                padding: 32px 24px;
            }
            .mc-wizard__hero h1 {
                font-size: 28px;
            }
            .mc-wizard__form {
                padding: 24px;
            }
        }
        </style>
UNUSED;
    }

    /**
     * Render modal scripts
     */
    private static function render_modal_scripts(): string {
        return '';
        // Scripts now loaded from external script.js file
        $unused = <<<'UNUSED'
        <script>
        (function() {
            const modal = document.getElementById('mc-wizard-modal');
            const wizard = document.getElementById('mc-wizard');
            if (!modal || !wizard) return;

            let currentStep = 0;
            const totalSteps = 3;
            let selectedPartner = null;
            let selectedOutputType = 'landing_page';

            // Get user data and mode
            const userData = JSON.parse(wizard.dataset.user || "{}");
            const userMode = userData.mode || "realtor";
            const isLoanOfficer = userMode === "loan_officer";

            // Page type card selection (LO mode)
            const pageTypeCards = wizard.querySelectorAll('.mc-page-type-card');
            const pageTypeInput = document.getElementById('mc-page-type');
            const partnerSelectionDiv = document.getElementById('mc-partner-selection');
            const partnerInput = document.getElementById('mc-partner');

            if (pageTypeCards.length > 0 && isLoanOfficer) {
                pageTypeCards.forEach(card => {
                    card.addEventListener('click', () => {
                        // Deselect all cards
                        pageTypeCards.forEach(c => c.classList.remove('selected'));
                        // Select clicked card
                        card.classList.add('selected');
                        const pageType = card.dataset.type;
                        if (pageTypeInput) pageTypeInput.value = pageType;

                        // Show/hide partner selection
                        if (partnerSelectionDiv) {
                            if (pageType === 'cobranded') {
                                partnerSelectionDiv.style.display = 'block';
                            } else {
                                partnerSelectionDiv.style.display = 'none';
                                // Clear partner selection for solo pages
                                if (partnerInput) partnerInput.value = '';
                                selectedPartner = null;
                                const dropdownValue = wizard.querySelector('#mc-partner-dropdown .mc-dropdown__value');
                                if (dropdownValue) dropdownValue.textContent = 'Choose a partner...';
                                wizard.querySelectorAll('#mc-partner-dropdown .mc-dropdown__item').forEach(i => i.classList.remove('is-selected'));
                            }
                        }
                    });
                });
            }

            // Open modal
            document.querySelectorAll('.<?php echo self::TRIGGER_CLASS; ?>').forEach(btn => {
                btn.addEventListener('click', () => {
                    modal.classList.add('is-open');
                    document.body.style.overflow = 'hidden';
                });
            });

            // Check URL hash
            if (window.location.hash === '#<?php echo self::TRIGGER_HASH; ?>') {
                modal.classList.add('is-open');
                document.body.style.overflow = 'hidden';
            }

            // Close modal
            modal.querySelector('.mc-modal__backdrop').addEventListener('click', closeModal);
            modal.querySelector('.mc-modal__close').addEventListener('click', closeModal);

            function closeModal() {
                modal.classList.remove('is-open');
                document.body.style.overflow = '';
            }

            // Skip partner button (LO mode only)
            const skipPartnerBtn = document.getElementById('mc-skip-partner');
            if (skipPartnerBtn) {
                skipPartnerBtn.addEventListener('click', () => {
                    selectedPartner = null; // Clear partner
                    goToStep(1);
                });
            }

            // Dropdown functionality
            const dropdown = document.getElementById('mc-partner-dropdown');
            if (dropdown) {
                const trigger = dropdown.querySelector('.mc-dropdown__trigger');
                const menu = dropdown.querySelector('.mc-dropdown__menu');
                const items = dropdown.querySelectorAll('.mc-dropdown__item');
                const input = document.getElementById('mc-partner');
                const valueDisplay = dropdown.querySelector('.mc-dropdown__value');
                const isRequired = dropdown.dataset.required === 'true';

                trigger.addEventListener('click', () => {
                    dropdown.classList.toggle('is-open');
                });

                items.forEach(item => {
                    item.addEventListener('click', () => {
                        items.forEach(i => i.classList.remove('is-selected'));
                        item.classList.add('is-selected');
                        input.value = item.dataset.value;
                        selectedPartner = {
                            id: item.dataset.value,
                            name: item.dataset.name,
                            nmls: item.dataset.nmls || '',
                            license: item.dataset.license || '',
                            company: item.dataset.company || '',
                            photo: item.dataset.photo || '',
                            email: item.dataset.email || '',
                            phone: item.dataset.phone || ''
                        };
                        valueDisplay.innerHTML = `
                            <img src="${item.dataset.photo || ''}" style="width:24px;height:24px;border-radius:50%;margin-right:8px;">
                            ${item.dataset.name}
                        `;
                        dropdown.classList.remove('is-open');
                    });
                });

                document.addEventListener('click', (e) => {
                    if (!dropdown.contains(e.target)) {
                        dropdown.classList.remove('is-open');
                    }
                });

                // Auto-select preferred partner if set
                const preferredId = dropdown.dataset.preferred;
                if (preferredId && preferredId !== '0') {
                    const preferredItem = dropdown.querySelector(`.mc-dropdown__item[data-value="${preferredId}"]`);
                    if (preferredItem) {
                        preferredItem.click();
                        console.log('MC Wizard: Auto-selected preferred partner ID:', preferredId);
                    }
                }
            }

            // Color picker functionality
            const colorStartPicker = document.getElementById('mc-color-start');
            const colorStartHex = document.getElementById('mc-color-start-hex');
            const colorEndPicker = document.getElementById('mc-color-end');
            const colorEndHex = document.getElementById('mc-color-end-hex');
            const gradientPreview = document.getElementById('mc-gradient-preview');

            function updateGradientPreview() {
                const start = colorStartPicker.value;
                const end = colorEndPicker.value;
                gradientPreview.style.background = `linear-gradient(135deg, ${start} 0%, ${end} 100%)`;
            }

            // Sync color picker with hex input
            colorStartPicker.addEventListener('input', () => {
                colorStartHex.value = colorStartPicker.value;
                updateGradientPreview();
            });
            colorStartHex.addEventListener('input', () => {
                if (/^#[0-9A-Fa-f]{6}$/.test(colorStartHex.value)) {
                    colorStartPicker.value = colorStartHex.value;
                    updateGradientPreview();
                }
            });
            colorEndPicker.addEventListener('input', () => {
                colorEndHex.value = colorEndPicker.value;
                updateGradientPreview();
            });
            colorEndHex.addEventListener('input', () => {
                if (/^#[0-9A-Fa-f]{6}$/.test(colorEndHex.value)) {
                    colorEndPicker.value = colorEndHex.value;
                    updateGradientPreview();
                }
            });

            // Output type selection (landing page vs embed code)
            document.querySelectorAll('input[name="mc-output-type"]').forEach(input => {
                input.addEventListener('change', (e) => {
                    selectedOutputType = e.target.value;
                    const embedOutput = document.getElementById('mc-embed-output');

                    if (selectedOutputType === 'embed_code') {
                        // Generate and show embed code
                        generateEmbedCode();
                        embedOutput.style.display = 'block';
                    } else {
                        embedOutput.style.display = 'none';
                    }

                    // Update button text when output type changes
                    if (currentStep === totalSteps - 1) {
                        updateNextButtonText();
                    }
                });
            });

            // Generate embed code
            function generateEmbedCode() {
                const siteUrl = '<?php echo esc_url( home_url() ); ?>';
                const gradientStart = document.getElementById('mc-color-start').value;
                const gradientEnd = document.getElementById('mc-color-end').value;

                let embedCode;
                if (isLoanOfficer) {
                    // LO mode: Get LO data from current user, partner is optional realtor
                    const loName = document.getElementById('mc-lo-name')?.value || userData.name;
                    const loNmls = document.getElementById('mc-lo-nmls')?.value || userData.nmls || '';
                    const loPhone = document.getElementById('mc-lo-phone')?.value || userData.phone;
                    const loEmail = document.getElementById('mc-lo-email')?.value || userData.email;

                    embedCode = `<!-- Mortgage Calculator Widget - Powered by 21st Century Lending -->
<div id="frs-mortgage-calculator"
     data-lo-id="${userData.id}"
     data-lo-name="${loName}"
     data-lo-nmls="${loNmls}"
     data-lo-photo="${userData.photo || ''}"
     data-lo-email="${loEmail}"
     data-lo-phone="${loPhone}"
     ${selectedPartner ? `data-realtor-id="${selectedPartner.id}"` : ''}
     ${selectedPartner ? `data-realtor-name="${selectedPartner.name}"` : ''}
     data-gradient-start="${gradientStart}"
     data-gradient-end="${gradientEnd}">
</div>
<script src="${siteUrl}/wp-content/plugins/frs-mortgage-calculator/assets/dist/assets/widget.js"><\/script>`;
                } else {
                    // Realtor mode: Partner is the LO
                    if (!selectedPartner) return;

                    const realtorName = document.getElementById('mc-realtor-name')?.value || userData.name;

                    embedCode = `<!-- Mortgage Calculator Widget - Powered by 21st Century Lending -->
<div id="frs-mortgage-calculator"
     data-lo-id="${selectedPartner.id}"
     data-lo-name="${selectedPartner.name}"
     data-lo-nmls="${selectedPartner.nmls}"
     data-lo-photo="${selectedPartner.photo}"
     data-lo-email="${selectedPartner.email}"
     data-lo-phone="${selectedPartner.phone}"
     data-realtor-id="${userData.id}"
     data-realtor-name="${realtorName}"
     data-gradient-start="${gradientStart}"
     data-gradient-end="${gradientEnd}">
</div>
<script src="${siteUrl}/wp-content/plugins/frs-mortgage-calculator/assets/dist/assets/widget.js"><\/script>`;
                }

                const codeEl = document.getElementById('mc-embed-code');
                if (codeEl) {
                    codeEl.textContent = embedCode;
                }
            }

            // Copy embed code button
            const copyEmbedBtn = document.getElementById('mc-copy-embed');
            if (copyEmbedBtn) {
                copyEmbedBtn.addEventListener('click', () => {
                    const codeEl = document.getElementById('mc-embed-code');
                    if (codeEl) {
                        navigator.clipboard.writeText(codeEl.textContent).then(() => {
                            const originalText = copyEmbedBtn.innerHTML;
                            copyEmbedBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Copied!';
                            setTimeout(() => {
                                copyEmbedBtn.innerHTML = originalText;
                            }, 2000);
                        });
                    }
                });
            }

            // Copy URL button (success state)
            const copyUrlBtn = document.getElementById('mc-copy-url');
            if (copyUrlBtn) {
                copyUrlBtn.addEventListener('click', () => {
                    const urlInput = document.getElementById('mc-success-url');
                    if (urlInput) {
                        navigator.clipboard.writeText(urlInput.value).then(() => {
                            const originalText = copyUrlBtn.textContent;
                            copyUrlBtn.textContent = 'Copied!';
                            setTimeout(() => {
                                copyUrlBtn.textContent = originalText;
                            }, 2000);
                        });
                    }
                });
            }

            // Create another button
            const createAnotherBtn = document.getElementById('mc-create-another');
            if (createAnotherBtn) {
                createAnotherBtn.addEventListener('click', () => {
                    // Reset wizard state
                    currentStep = 0;
                    selectedPartner = null;
                    selectedOutputType = 'landing_page';

                    // Reset form
                    const partnerInput = document.getElementById('mc-partner');
                    if (partnerInput) partnerInput.value = '';
                    const dropdownValue = document.querySelector('#mc-partner-dropdown .mc-dropdown__value');
                    if (dropdownValue) dropdownValue.textContent = isLoanOfficer ? 'Select a realtor partner...' : 'Select a loan officer...';
                    document.querySelectorAll('.mc-dropdown__item').forEach(i => i.classList.remove('is-selected'));
                    document.querySelectorAll('input[name="mc-output-type"]').forEach(i => {
                        i.checked = i.value === 'landing_page';
                    });
                    document.getElementById('mc-embed-output').style.display = 'none';

                    // Go back to step 0
                    goToStep(0);

                    // Show footer again
                    document.querySelector('.mc-wizard__footer').style.display = 'flex';
                });
            }

            // Navigation
            const prevBtn = document.getElementById('mc-prev-btn');
            const nextBtn = document.getElementById('mc-next-btn');
            const backBtnTop = document.getElementById('mc-back-top');
            const nextBtnTop = document.getElementById('mc-next-top');

            prevBtn.addEventListener('click', () => {
                if (currentStep > 0) {
                    goToStep(currentStep - 1);
                }
            });

            nextBtn.addEventListener('click', () => {
                if (validateStep()) {
                    if (currentStep < totalSteps - 1) {
                        goToStep(currentStep + 1);
                    } else {
                        submitWizard();
                    }
                }
            });

            // Top button listeners
            if (nextBtnTop) {
                nextBtnTop.addEventListener('click', () => {
                    if (validateStep()) {
                        if (currentStep < totalSteps - 1) {
                            goToStep(currentStep + 1);
                        } else {
                            submitWizard();
                        }
                    }
                });
            }
            if (backBtnTop) {
                backBtnTop.addEventListener('click', () => {
                    if (currentStep > 0) {
                        goToStep(currentStep - 1);
                    }
                });
            }

            function goToStep(step) {
                document.querySelectorAll('.mc-step').forEach(el => el.style.display = 'none');
                const stepEl = document.querySelector(`.mc-step[data-step="${step}"]`);
                if (stepEl) stepEl.style.display = 'block';
                currentStep = step;

                // Update progress
                const progress = ((step + 1) / totalSteps) * 100;
                document.querySelector('.mc-wizard__progress-bar').style.width = progress + '%';
                document.getElementById('mc-step-num').textContent = step + 1;

                // Update buttons based on step and output type
                prevBtn.style.display = step === 0 ? 'none' : 'flex';
                if (backBtnTop) backBtnTop.style.display = step === 0 ? 'none' : 'inline-flex';
                if (nextBtnTop) nextBtnTop.style.display = step < totalSteps - 1 ? 'inline-flex' : 'none';

                if (step === totalSteps - 1) {
                    // On last step, button text depends on output type
                    updateNextButtonText();
                } else {
                    nextBtn.innerHTML = 'Continue <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
                }
            }

            function updateNextButtonText() {
                if (selectedOutputType === 'embed_code') {
                    nextBtn.innerHTML = 'Done <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
                } else {
                    nextBtn.innerHTML = 'Create Page <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
                }
            }

            function validateStep() {
                if (currentStep === 0) {
                    if (isLoanOfficer) {
                        // LO Mode: Check page type is selected
                        const pageType = document.getElementById('mc-page-type')?.value;
                        if (!pageType) {
                            alert('Please select Solo Page or Co-branded');
                            return false;
                        }

                        // If co-branded, collect partner info from inputs
                        if (pageType === 'cobranded') {
                            const partnerName     = document.getElementById('mc-partner-name-input')?.value.trim() || '';
                            const partnerEmail    = document.getElementById('mc-partner-email-input')?.value.trim() || '';
                            const partnerPhone    = document.getElementById('mc-partner-phone-input')?.value.trim() || '';
                            const partnerHeadshot = document.getElementById('mc-partner-photo-url')?.value || '';
                            const partnerLogo     = document.getElementById('mc-partner-logo-url')?.value || '';

                            if (!partnerName)  { alert('Please enter the partner\'s name');  return false; }
                            if (!partnerEmail) { alert('Please enter the partner\'s email'); return false; }
                            if (!partnerPhone) { alert('Please enter the partner\'s phone number'); return false; }

                            selectedPartner = {
                                id: 0,
                                name: partnerName,
                                email: partnerEmail,
                                phone: partnerPhone,
                                photo: partnerHeadshot,
                                logo: partnerLogo,
                                company: '',
                                license: '',
                                nmls: ''
                            };
                        } else {
                            selectedPartner = null;
                        }
                    } else {
                        // Partner Mode: Require LO selection
                        const partnerDropdown = document.getElementById('mc-partner-dropdown');
                        const isRequired = partnerDropdown?.dataset.required === 'true';

                        if (isRequired && !selectedPartner) {
                            alert('Please select a loan officer');
                            return false;
                        }

                        // Save preference if "Remember my choice" is checked
                        if (selectedPartner) {
                            const rememberCheckbox = document.getElementById('mc-remember-partner');
                            if (rememberCheckbox && rememberCheckbox.checked) {
                                fetch(ajaxurl, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({
                                        action: 'frs_set_preferred_lo',
                                        nonce: '<?php echo wp_create_nonce( 'frs_lead_pages' ); ?>',
                                        lo_id: selectedPartner.id,
                                        remember: 'true'
                                    })
                                }).then(r => r.json()).then(res => {
                                    console.log('MC Wizard: Saved preferred LO:', res);
                                }).catch(err => {
                                    console.error('MC Wizard: Failed to save preference:', err);
                                });
                            }
                        }
                    }
                }
                return true;
            }

            function submitWizard() {
                // If embed code is selected, just close the modal (they already have the code)
                if (selectedOutputType === 'embed_code') {
                    closeModal();
                    return;
                }

                // Otherwise, create landing page
                nextBtn.classList.add('is-loading');
                nextBtn.disabled = true;

                const data = {
                    action: 'frs_create_calculator',
                    nonce: '<?php echo wp_create_nonce( 'frs_lead_pages' ); ?>',
                    user_mode: userMode,
                    gradient_start: document.getElementById('mc-color-start').value,
                    gradient_end: document.getElementById('mc-color-end').value
                };

                // Add data based on user mode
                if (isLoanOfficer) {
                    // LO mode: Current user is LO, optional realtor partner
                    data.lo_name = document.getElementById('mc-lo-name')?.value || userData.name;
                    data.lo_nmls = document.getElementById('mc-lo-nmls')?.value || '';
                    data.lo_phone = document.getElementById('mc-lo-phone')?.value || '';
                    data.lo_email = document.getElementById('mc-lo-email')?.value || '';

                    if (selectedPartner) {
                        data.partner_id = selectedPartner.id;
                        data.partner_name = selectedPartner.name;
                        data.partner_license = selectedPartner.license;
                        data.partner_company = selectedPartner.company;
                        data.partner_phone = selectedPartner.phone;
                        data.partner_email = selectedPartner.email;
                        data.partner_photo = selectedPartner.photo || '';
                        data.partner_logo = selectedPartner.logo || '';
                    }
                } else {
                    // Realtor mode: Partner is LO
                    data.realtor_name = document.getElementById('mc-realtor-name')?.value || userData.name;
                    data.realtor_license = document.getElementById('mc-realtor-license')?.value || '';
                    data.realtor_phone = document.getElementById('mc-realtor-phone')?.value || '';
                    data.realtor_email = document.getElementById('mc-realtor-email')?.value || '';
                    data.loan_officer_id = selectedPartner?.id || '';
                }

                fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(data)
                })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        // Show success state instead of redirecting
                        showSuccessState(response.data.url);
                    } else {
                        alert(response.data.message || 'Error creating page');
                        nextBtn.classList.remove('is-loading');
                        nextBtn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Error creating page');
                    nextBtn.classList.remove('is-loading');
                    nextBtn.disabled = false;
                });
            }

            function showSuccessState(pageUrl) {
                // Hide all steps
                document.querySelectorAll('.mc-step').forEach(el => el.style.display = 'none');

                // Show success state
                const successStep = document.querySelector('.mc-step[data-step="success"]');
                if (successStep) {
                    successStep.style.display = 'block';
                }

                // Set the URL
                document.getElementById('mc-success-url').value = pageUrl;
                document.getElementById('mc-view-page').href = pageUrl;

                // Hide footer buttons
                document.querySelector('.mc-wizard__footer').style.display = 'none';

                // Reset next button state
                nextBtn.classList.remove('is-loading');
                nextBtn.disabled = false;
            }

            // Image upload handling
            const imageUpload = document.getElementById('mc-image-upload');
            if (imageUpload) {
                imageUpload.addEventListener('change', (e) => {
                    const file = e.target.files[0];
                    if (!file) return;

                    const reader = new FileReader();
                    reader.onload = (e) => {
                        document.getElementById('mc-hero-image').value = e.target.result;
                        // Deselect stock images
                        document.querySelectorAll('#mc-images-grid input').forEach(i => i.checked = false);
                    };
                    reader.readAsDataURL(file);
                });
            }

            // Update branding preview in real-time
            ['mc-realtor-name', 'mc-realtor-license'].forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('input', () => {
                        if (id === 'mc-realtor-name') {
                            document.getElementById('mc-preview-name').textContent = input.value;
                        } else {
                            document.getElementById('mc-preview-license').textContent = input.value;
                        }
                    });
                }
            });

            // Partner headshot + company logo uploads (separate fields)
            function setupMcPartnerUpload(suffix) {
                const uploadDiv  = document.getElementById('mc-partner-' + suffix + '-upload');
                const fileInput  = document.getElementById('mc-partner-' + suffix + '-file');
                const preview    = document.getElementById('mc-partner-' + suffix + '-preview');
                const previewImg = document.getElementById('mc-partner-' + suffix + '-preview-img');
                const removeBtn  = document.getElementById('mc-partner-' + suffix + '-remove');
                const urlInput   = document.getElementById('mc-partner-' + suffix + '-url');
                if (!uploadDiv || !fileInput) return;

                uploadDiv.addEventListener('click', () => fileInput.click());
                uploadDiv.addEventListener('dragover', (e) => { e.preventDefault(); uploadDiv.style.borderColor = '#2563eb'; });
                uploadDiv.addEventListener('dragleave', () => { uploadDiv.style.borderColor = '#cbd5e1'; });
                uploadDiv.addEventListener('drop', (e) => {
                    e.preventDefault();
                    uploadDiv.style.borderColor = '#cbd5e1';
                    if (e.dataTransfer.files.length) {
                        fileInput.files = e.dataTransfer.files;
                        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                fileInput.addEventListener('change', (e) => {
                    const file = e.target.files[0];
                    if (!file) return;
                    if (!file.type.match(/image\/(jpeg|png|gif|webp)/)) { alert('Please upload an image (PNG, JPG, GIF, or WebP)'); return; }
                    if (file.size > 5242880) { alert('File size must be less than 5MB'); return; }
                    const reader = new FileReader();
                    reader.onload = (ev) => {
                        previewImg.src = ev.target.result;
                        preview.style.display = 'flex';
                        preview.style.alignItems = 'center';
                        uploadDiv.style.display = 'none';
                    };
                    reader.readAsDataURL(file);
                    window.frsLpUploadPhoto(file, (url) => {
                        urlInput.value = url;
                        previewImg.src = url;
                    }, (msg) => {
                        alert(msg || 'Upload failed. Please try again.');
                        urlInput.value = '';
                        fileInput.value = '';
                        preview.style.display = 'none';
                        uploadDiv.style.display = 'block';
                    });
                });
                if (removeBtn) removeBtn.addEventListener('click', () => {
                    fileInput.value = '';
                    urlInput.value = '';
                    preview.style.display = 'none';
                    uploadDiv.style.display = 'block';
                });
            }
            setupMcPartnerUpload('photo');
            setupMcPartnerUpload('logo');
        })();
        </script>
UNUSED;
    }

    /**
     * AJAX handler for creating calculator page
     */
    public static function ajax_create_calculator(): void {
        check_ajax_referer( 'frs_lead_pages', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not authorized' ] );
        }

        $user = wp_get_current_user();
        $allowed_roles = [ 'administrator', 'editor', 'author', 'contributor', 'loan_officer', 'realtor_partner' ];

        if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
            wp_send_json_error( [ 'message' => 'Not authorized' ] );
        }

        $calculator_type = sanitize_text_field( $_POST['calculator_type'] ?? '' );
        $headline = sanitize_text_field( $_POST['headline'] ?? '' );
        $subheadline = sanitize_text_field( $_POST['subheadline'] ?? '' );
        $value_props = sanitize_textarea_field( $_POST['value_props'] ?? '' );
        $hero_image = esc_url_raw( $_POST['hero_image'] ?? '' );
        $loan_officer_id = absint( $_POST['loan_officer_id'] ?? 0 );
        $fields = json_decode( stripslashes( $_POST['fields'] ?? '{}' ), true );
        $publish_status = sanitize_text_field( $_POST['publish_status'] ?? 'publish' );

        $calculator_labels = [
            'purchase' => 'Purchase Calculator',
            'refinance' => 'Refinance Calculator',
            'affordability' => 'Affordability Calculator',
            'va_loan' => 'VA Loan Calculator',
            'fha_loan' => 'FHA Loan Calculator',
        ];

        // Create post
        $post_data = [
            'post_title'   => $headline ?: $calculator_labels[ $calculator_type ] ?? 'Mortgage Calculator',
            'post_status'  => $publish_status === 'draft' ? 'draft' : 'publish',
            'post_type'    => 'frs_lead_page',
            'post_author'  => $user->ID,
        ];

        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        // Save common meta
        update_post_meta( $post_id, '_frs_page_type', 'mortgage_calculator' );
        update_post_meta( $post_id, '_frs_calculator_type', $calculator_type );
        update_post_meta( $post_id, '_frs_headline', $headline );
        update_post_meta( $post_id, '_frs_subheadline', $subheadline );
        update_post_meta( $post_id, '_frs_value_props', $value_props );
        update_post_meta( $post_id, '_frs_hero_image', $hero_image );
        update_post_meta( $post_id, '_frs_fields', $fields );
        update_post_meta( $post_id, '_frs_gradient_start', sanitize_hex_color( $_POST['gradient_start'] ?? '#2563eb' ) );
        update_post_meta( $post_id, '_frs_gradient_end', sanitize_hex_color( $_POST['gradient_end'] ?? '#2dd4da' ) );

        // Save creator info and partner info based on user mode
        $user_mode = sanitize_text_field( $_POST['user_mode'] ?? 'realtor' );
        update_post_meta( $post_id, '_frs_creator_mode', $user_mode );

        if ( $user_mode === 'loan_officer' ) {
            // LO Mode: Current user is the LO, partner is optional Realtor
            update_post_meta( $post_id, '_frs_loan_officer_id', $user->ID );
            update_post_meta( $post_id, '_frs_lo_name', sanitize_text_field( $_POST['lo_name'] ?? '' ) );
            update_post_meta( $post_id, '_frs_lo_phone', sanitize_text_field( $_POST['lo_phone'] ?? '' ) );
            update_post_meta( $post_id, '_frs_lo_email', sanitize_email( $_POST['lo_email'] ?? '' ) );
            update_post_meta( $post_id, '_frs_lo_nmls', sanitize_text_field( $_POST['lo_nmls'] ?? '' ) );

            // Optional Realtor partner (manual entry from Co-branded step)
            $partner_name = sanitize_text_field( $_POST['partner_name'] ?? '' );
            if ( ! empty( $partner_name ) ) {
                update_post_meta( $post_id, '_frs_realtor_id', 0 );
                update_post_meta( $post_id, '_frs_realtor_name', $partner_name );
                update_post_meta( $post_id, '_frs_realtor_phone', sanitize_text_field( $_POST['partner_phone'] ?? '' ) );
                update_post_meta( $post_id, '_frs_realtor_email', sanitize_email( $_POST['partner_email'] ?? '' ) );
                update_post_meta( $post_id, '_frs_realtor_license', sanitize_text_field( $_POST['partner_license'] ?? '' ) );
                update_post_meta( $post_id, '_frs_realtor_company', sanitize_text_field( $_POST['partner_company'] ?? '' ) );

                // Partner headshot (optional) — a media-library URL from the upload endpoint.
                $partner_photo = esc_url_raw( $_POST['partner_photo'] ?? '' );
                if ( ! empty( $partner_photo ) ) {
                    update_post_meta( $post_id, '_frs_realtor_photo', $partner_photo );
                }

                // Partner company logo (optional) — a media-library URL from the upload endpoint.
                $partner_logo = esc_url_raw( $_POST['partner_logo'] ?? '' );
                if ( ! empty( $partner_logo ) ) {
                    update_post_meta( $post_id, '_frs_brokerage_logo', $partner_logo );
                }
            }
        } else {
            // Realtor Mode: Current user is the Realtor, partner is required LO
            update_post_meta( $post_id, '_frs_realtor_id', $user->ID );
            update_post_meta( $post_id, '_frs_realtor_name', sanitize_text_field( $_POST['realtor_name'] ?? '' ) );
            update_post_meta( $post_id, '_frs_realtor_phone', sanitize_text_field( $_POST['realtor_phone'] ?? '' ) );
            update_post_meta( $post_id, '_frs_realtor_email', sanitize_email( $_POST['realtor_email'] ?? '' ) );
            update_post_meta( $post_id, '_frs_realtor_license', sanitize_text_field( $_POST['realtor_license'] ?? '' ) );

            // LO partner
            update_post_meta( $post_id, '_frs_loan_officer_id', absint( $_POST['loan_officer_id'] ?? 0 ) );
        }

        wp_send_json_success([
            'post_id' => $post_id,
            'url'     => get_permalink( $post_id ),
        ]);
    }
}

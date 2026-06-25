<?php
/**
 * Rate Quote Wizard
 *
 * Multi-step wizard for creating Rate Quote landing pages.
 * Integrates Fluent Booking for scheduling.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\RateQuote;

use FRSLeadPages\Core\LoanOfficers;
use FRSLeadPages\Core\Realtors;
use FRSLeadPages\Core\UserMode;

class Wizard {

    /**
     * Trigger class for opening modal
     */
    const TRIGGER_CLASS = 'rq-wizard-trigger';

    /**
     * Hash for URL triggering
     */
    const TRIGGER_HASH = 'rate-quote-wizard';

    /**
     * Initialize
     */
    public static function init() {
        add_shortcode( 'rate_quote_wizard', [ __CLASS__, 'render' ] );
        add_shortcode( 'rate_quote_wizard_button', [ __CLASS__, 'render_button' ] );
        add_action( 'wp_ajax_frs_create_rate_quote', [ __CLASS__, 'ajax_create_rate_quote' ] );

        // Add modal to footer on frontend
    }

    /**
     * Render trigger button shortcode
     */
    public static function render_button( array $atts = [] ): string {
        $atts = shortcode_atts([
            'text'  => 'Create Rate Quote Page',
            'class' => '',
        ], $atts, 'rate_quote_wizard_button' );

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
        <div id="rq-wizard-modal" class="rq-modal">
            <div class="rq-modal__backdrop"></div>
            <div class="rq-modal__container">
                <button type="button" class="rq-modal__close" aria-label="Close">
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

        // Get Fluent Booking calendars for current user
        $calendars = self::get_user_calendars();

        ob_start();
        ?>
        <div id="rq-wizard" class="rq-wizard" data-user='<?php echo esc_attr( wp_json_encode( $user_data ) ); ?>'>
            <div class="rq-wizard__hero">
                <div class="rq-wizard__hero-content">
                    <h1>Create Your<br>Rate Quote Page</h1>
                    <p>Build a rate quote landing page with scheduling integration.</p>
                </div>
            </div>

            <div class="rq-wizard__form">
                <div class="rq-wizard__progress">
                    <div class="rq-wizard__progress-bar" style="width: 25%"></div>
                </div>

                <div class="rq-wizard__header">
                    <p class="rq-wizard__title">Rate Quote Wizard</p>
                    <p class="rq-wizard__subtitle">Step <span id="rq-step-num">1</span> of 4</p>
                </div>

                <div class="rq-wizard__nav-top">
                    <button type="button" id="rq-back-top" class="rq-btn rq-btn--ghost rq-btn--sm" style="display:none;">Back</button>
                    <button type="button" id="rq-next-top" class="rq-btn rq-btn--primary rq-btn--sm">Continue</button>
                </div>

                <div class="rq-wizard__content">
                <!-- Step 0: Page Type Selection -->
                <div class="rq-step" data-step="0">
                    <div class="rq-step__header">
                        <h2><?php echo $is_loan_officer ? 'What type of page?' : esc_html( $partner_config['title'] ); ?></h2>
                        <p><?php echo $is_loan_officer ? 'Choose how you want to brand this page' : esc_html( $partner_config['subtitle'] ); ?></p>
                    </div>
                    <div class="rq-step__body">
                        <?php if ( $is_loan_officer ) : ?>
                            <input type="hidden" id="rq-page-type" name="page_type" value="">
                            <input type="hidden" id="rq-partner" name="partner" value="">

                            <div class="rq-page-type-cards">
                                <div class="rq-page-type-card" data-type="solo">
                                    <div class="rq-page-type-card__icon">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                    </div>
                                    <h3>Solo Page</h3>
                                    <p>Just your branding</p>
                                </div>
                                <div class="rq-page-type-card" data-type="cobranded">
                                    <div class="rq-page-type-card__icon">
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

                            <div id="rq-partner-selection" class="rq-partner-selection" style="display: none;">
                                <p class="rq-section-label" style="margin-top:24px;">Partner Real Estate Agent</p>
                                <div class="rq-row">
                                    <div class="rq-field rq-field--half">
                                        <label class="rq-label">Partner Name</label>
                                        <input type="text" id="rq-partner-name-input" class="rq-input" placeholder="Jane Smith">
                                    </div>
                                    <div class="rq-field rq-field--half">
                                        <label class="rq-label">Phone</label>
                                        <input type="tel" id="rq-partner-phone-input" class="rq-input" placeholder="(555) 123-4567">
                                    </div>
                                </div>
                                <div class="rq-field">
                                    <label class="rq-label">Email</label>
                                    <input type="email" id="rq-partner-email-input" class="rq-input" placeholder="jane@realestate.com">
                                </div>

                                <!-- Partner Headshot Upload -->
                                <div class="rq-field" style="margin-top: 24px;">
                                    <label class="rq-label">Partner Headshot (optional)</label>
                                    <div class="rq-photo-upload" id="rq-partner-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="rq-partner-photo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="rq-partner-photo-preview" style="margin-top: 12px; display: none;">
                                        <img id="rq-partner-photo-preview-img" src="" alt="Headshot preview" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                                        <button type="button" id="rq-partner-photo-remove" class="rq-btn rq-btn--ghost rq-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="rq-partner-photo-url" value="">
                                </div>

                                <!-- Company Logo Upload -->
                                <div class="rq-field" style="margin-top: 16px;">
                                    <label class="rq-label">Company Logo (optional)</label>
                                    <div class="rq-photo-upload" id="rq-partner-logo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="rq-partner-logo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="rq-partner-logo-preview" style="margin-top: 12px; display: none;">
                                        <img id="rq-partner-logo-preview-img" src="" alt="Logo preview" style="width: 120px; height: 120px; border-radius: 8px; object-fit: contain; background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0;">
                                        <button type="button" id="rq-partner-logo-remove" class="rq-btn rq-btn--ghost rq-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="rq-partner-logo-url" value="">
                                </div>
                                <p class="rq-helper">Both images are optional — add whichever you want on the landing page</p>
                            </div>
                        <?php else : ?>
                            <label class="rq-label"><?php echo esc_html( $partner_config['label'] ); ?></label>
                            <div class="rq-dropdown" id="rq-partner-dropdown" data-mode="<?php echo esc_attr( $user_mode ); ?>" data-required="true" data-preferred="<?php echo esc_attr( $partner_config['preferred_id'] ?? 0 ); ?>">
                                <input type="hidden" id="rq-partner" name="partner" value="">
                                <button type="button" class="rq-dropdown__trigger">
                                    <span class="rq-dropdown__value"><?php echo esc_html( $partner_config['placeholder'] ); ?></span>
                                    <svg class="rq-dropdown__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <div class="rq-dropdown__menu">
                                    <?php foreach ( $partners as $partner ) : ?>
                                        <?php
                                        $partner_id = $partner['user_id'] ?? $partner['id'];
                                        $partner_name = $partner['name'];
                                        $partner_photo = $partner['photo_url'] ?? '';
                                        $partner_nmls = $partner['nmls'] ?? '';
                                        $is_preferred = ( (int) $partner_id === (int) ( $partner_config['preferred_id'] ?? 0 ) );
                                        ?>
                                        <div class="rq-dropdown__item<?php echo $is_preferred ? ' rq-dropdown__item--preferred' : ''; ?>"
                                             data-value="<?php echo esc_attr( $partner_id ); ?>"
                                             data-name="<?php echo esc_attr( $partner_name ); ?>"
                                             data-nmls="<?php echo esc_attr( $partner_nmls ); ?>"
                                             data-photo="<?php echo esc_attr( $partner_photo ); ?>">
                                            <img src="<?php echo esc_url( $partner_photo ); ?>" alt="" class="rq-dropdown__photo">
                                            <div class="rq-dropdown__info">
                                                <span class="rq-dropdown__name"><?php echo esc_html( $partner_name ); ?></span>
                                                <?php if ( $partner_nmls ) : ?>
                                                    <span class="rq-dropdown__nmls">NMLS# <?php echo esc_html( $partner_nmls ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( $is_preferred ) : ?>
                                                <span class="rq-dropdown__preferred-badge">★ Preferred</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="rq-helper"><?php echo esc_html( $partner_config['helper'] ); ?></p>

                            <?php if ( $partner_config['show_remember'] ?? false ) : ?>
                                <label class="rq-checkbox" style="margin-top: 12px;">
                                    <input type="checkbox" id="rq-remember-partner" name="remember_partner" value="1">
                                    <span class="rq-checkbox__label">Remember my choice for next time</span>
                                </label>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 1: Page Content -->
                <div class="rq-step" data-step="1" style="display:none;">
                    <div class="rq-step__header">
                        <h2>Page Content</h2>
                        <p>Set your headline and description</p>
                    </div>
                    <div class="rq-step__body">
                        <div class="rq-field">
                            <label class="rq-label">Headline</label>
                            <input type="text" id="rq-headline" class="rq-input" value="Get Your Personalized Rate Quote" placeholder="Enter headline">
                        </div>
                        <div class="rq-field">
                            <label class="rq-label">Subheadline <span class="rq-label-hint">(optional)</span></label>
                            <input type="text" id="rq-subheadline" class="rq-input" value="Quick, personalized quotes with no obligation" placeholder="Enter subheadline">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Scheduling Options -->
                <div class="rq-step" data-step="2" style="display:none;">
                    <div class="rq-step__header">
                        <h2>Scheduling Integration</h2>
                        <p>Choose how visitors can schedule with you</p>
                    </div>
                    <div class="rq-step__body">
                        <input type="hidden" id="rq-schedule-type" name="schedule_type" value="form">

                        <div class="rq-schedule-options">
                            <div class="rq-schedule-card" data-type="form">
                                <div class="rq-schedule-card__icon">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                        <line x1="16" y1="13" x2="8" y2="13"/>
                                        <line x1="16" y1="17" x2="8" y2="17"/>
                                        <polyline points="10 9 9 9 8 9"/>
                                    </svg>
                                </div>
                                <h3>Lead Capture Form</h3>
                                <p>Collect info via Fluent Form</p>
                            </div>
                            <?php if ( ! empty( $calendars ) ) : ?>
                            <div class="rq-schedule-card" data-type="booking">
                                <div class="rq-schedule-card__icon">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                        <circle cx="12" cy="15" r="2"/>
                                    </svg>
                                </div>
                                <h3>Booking Calendar</h3>
                                <p>Let visitors book a time</p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Calendar Selection (shown when booking is selected) -->
                        <div id="rq-calendar-selection" class="rq-calendar-selection" style="display: none;">
                            <label class="rq-label" style="margin-top: 24px;">Select Calendar</label>
                            <select id="rq-calendar-id" class="rq-select">
                                <option value="">-- Select a calendar --</option>
                                <?php foreach ( $calendars as $calendar ) : ?>
                                    <option value="<?php echo esc_attr( $calendar['id'] ); ?>"><?php echo esc_html( $calendar['title'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="rq-helper">Choose your Fluent Booking calendar</p>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Your Branding -->
                <div class="rq-step" data-step="3" style="display:none;">
                    <div class="rq-step__header">
                        <h2>Your Branding</h2>
                        <p>Review and customize your profile info</p>
                    </div>
                    <div class="rq-step__body">
                        <div class="rq-branding-preview">
                            <div class="rq-branding-photo">
                                <img id="rq-preview-photo" src="<?php echo esc_url( $user_data['photo'] ); ?>" alt="">
                            </div>
                            <div class="rq-branding-info">
                                <p class="rq-branding-name" id="rq-preview-name"><?php echo esc_html( $user_data['name'] ); ?></p>
                                <p class="rq-branding-detail" id="rq-preview-license"><?php echo esc_html( $is_loan_officer ? ( $user_data['nmls'] ?? '' ) : ( $user_data['license'] ?? '' ) ); ?></p>
                            </div>
                        </div>

                        <?php if ( $is_loan_officer ) : ?>
                            <!-- LO Mode: Show LO fields -->

                            <!-- Headshot: use profile photo or upload a new one -->
                            <div class="rq-field">
                                <label class="rq-label">Your Headshot</label>
                                <div class="rq-headshot-choice" role="radiogroup" style="display:flex; gap:12px; flex-wrap:wrap;">
                                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px;">
                                        <input type="radio" name="rq-headshot-source" value="profile" checked> <span>Use my profile headshot</span>
                                    </label>
                                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px;">
                                        <input type="radio" name="rq-headshot-source" value="upload"> <span>Upload a new one</span>
                                    </label>
                                </div>
                                <div id="rq-lo-photo-upload-wrap" style="display:none; margin-top:12px;">
                                    <div class="rq-photo-upload" id="rq-lo-photo-upload" style="border:2px dashed #cbd5e1; padding:20px; border-radius:8px; text-align:center; cursor:pointer;">
                                        <input type="file" id="rq-lo-photo-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                                        <p style="margin:0; font-weight:500;">Click to upload or drag and drop</p>
                                        <p style="margin:4px 0 0; font-size:12px; color:#94a3b8;">PNG, JPG, GIF or WebP (max 5MB)</p>
                                    </div>
                                    <p id="rq-lo-photo-status" style="display:none; font-size:13px; margin-top:8px;"></p>
                                </div>
                                <input type="hidden" id="rq-lo-photo-url" value="" data-profile-photo="<?php echo esc_attr( $user_data['photo'] ); ?>">
                            </div>

                            <div class="rq-field">
                                <label class="rq-label">Display Name</label>
                                <input type="text" id="rq-lo-name" class="rq-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                            </div>
                            <div class="rq-field">
                                <label class="rq-label">NMLS #</label>
                                <input type="text" id="rq-lo-nmls" class="rq-input" value="<?php echo esc_attr( $user_data['nmls'] ?? '' ); ?>">
                            </div>
                            <div class="rq-field">
                                <label class="rq-label">Contact Phone</label>
                                <input type="tel" id="rq-lo-phone" class="rq-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                            </div>
                            <div class="rq-field">
                                <label class="rq-label">Contact Email</label>
                                <input type="email" id="rq-lo-email" class="rq-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                            </div>
                        <?php else : ?>
                            <!-- Realtor Mode: Show Realtor fields -->
                            <div class="rq-field">
                                <label class="rq-label">Display Name</label>
                                <input type="text" id="rq-realtor-name" class="rq-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                            </div>
                            <div class="rq-field">
                                <label class="rq-label">License Number</label>
                                <input type="text" id="rq-realtor-license" class="rq-input" value="<?php echo esc_attr( $user_data['license'] ?? '' ); ?>">
                            </div>
                            <div class="rq-field">
                                <label class="rq-label">Contact Phone</label>
                                <input type="tel" id="rq-realtor-phone" class="rq-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                            </div>
                            <div class="rq-field">
                                <label class="rq-label">Contact Email</label>
                                <input type="email" id="rq-realtor-email" class="rq-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Success State -->
                <div class="rq-step rq-step--success" data-step="success" style="display:none;">
                    <div class="rq-success">
                        <div class="rq-success__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                        <h2 id="rq-success-title">Your Rate Quote Page is Live!</h2>
                        <p id="rq-success-subtitle">Share this link with your clients</p>
                        <div class="rq-success__url-box">
                            <input type="text" id="rq-success-url" readonly>
                            <button type="button" id="rq-copy-url" class="rq-btn rq-btn--primary">Copy Link</button>
                        </div>
                        <div class="rq-success__actions">
                            <a id="rq-view-page" href="#" class="rq-btn rq-btn--secondary" target="_blank">View Page</a>
                            <button type="button" id="rq-create-another" class="rq-btn rq-btn--ghost">Create Another</button>
                        </div>
                    </div>
                </div>

                </div>

                <div class="rq-wizard__footer">
                    <button type="button" class="rq-btn rq-btn--secondary" id="rq-prev-btn" style="display:none;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Back
                    </button>
                    <button type="button" class="rq-btn rq-btn--primary" id="rq-next-btn">
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
     * Get user's Fluent Booking calendars
     */
    private static function get_user_calendars(): array {
        $calendars = [];

        if ( ! defined( 'FLUENT_BOOKING_VERSION' ) || ! class_exists( '\FluentBooking\App\Models\Calendar' ) ) {
            return $calendars;
        }

        $user_id = get_current_user_id();
        $user_calendars = \FluentBooking\App\Models\Calendar::where( 'user_id', $user_id )->get();

        foreach ( $user_calendars as $calendar ) {
            $calendars[] = [
                'id'    => $calendar->id,
                'title' => $calendar->title ?? 'Calendar #' . $calendar->id,
            ];
        }

        return $calendars;
    }

    /**
     * Render login required message
     */
    private static function render_login_required(): string {
        return '<div class="rq-message rq-message--warning">Please log in to create a rate quote page.</div>';
    }

    /**
     * Render access denied message
     */
    private static function render_access_denied(): string {
        return '<div class="rq-message rq-message--error">You do not have permission to create rate quote pages.</div>';
    }

    /**
     * Enqueue modal assets
     */
    private static function enqueue_assets(): void {
        $base_url = plugins_url( 'includes/RateQuote/', FRS_LEAD_PAGES_PLUGIN_FILE );
        $version  = FRS_LEAD_PAGES_VERSION;

        wp_enqueue_style( 'frs-rate-quote-wizard', $base_url . 'style.css', [], $version );
        wp_enqueue_script( 'frs-rate-quote-wizard', $base_url . 'script.js', [], $version, true );

        wp_localize_script( 'frs-rate-quote-wizard', 'frsRateQuoteWizard', [
            'triggerClass' => self::TRIGGER_CLASS,
            'triggerHash'  => self::TRIGGER_HASH,
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
        /* Rate Quote Wizard - Emerald Green Theme */
        :root {
            --rq-primary: #10b981;
            --rq-primary-dark: #059669;
            --rq-primary-light: #34d399;
            --rq-primary-bg: #ecfdf5;
            --rq-text: #1e293b;
            --rq-text-light: #64748b;
            --rq-border: #e5e7eb;
            --rq-white: #ffffff;
            --rq-success: #10b981;
            --rq-error: #ef4444;
        }

        /* Modal Overlay */
        .rq-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            overflow-y: auto;
        }
        .rq-modal.is-open {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .rq-modal__backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1;
        }
        .rq-modal__container {
            position: relative;
            z-index: 2;
            width: 100vw;
            height: 100vh;
            overflow-y: auto;
        }
        .rq-modal__close {
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
        .rq-modal__close:hover {
            background: var(--rq-white);
            transform: scale(1.1);
        }
        .rq-modal__close svg {
            color: var(--rq-text);
        }

        /* Wizard Layout */
        .rq-wizard {
            display: flex;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* Hero Section */
        .rq-wizard__hero {
            width: 50%;
            height: 100vh;
            background: linear-gradient(135deg, var(--rq-primary) 0%, var(--rq-primary-dark) 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 64px;
            position: fixed;
            left: 0;
            top: 0;
            overflow: hidden;
            color: var(--rq-white);
        }
        .rq-wizard__hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        }
        .rq-wizard__hero h1 {
            font-size: 42px;
            font-weight: 700;
            line-height: 1.1;
            margin: 0 0 16px 0;
            color: var(--rq-white);
            position: relative;
        }
        .rq-wizard__hero p {
            font-size: 18px;
            opacity: 0.9;
            margin: 0;
            line-height: 1.6;
            max-width: 400px;
            position: relative;
        }

        /* Form Section */
        .rq-wizard__form {
            width: 50%;
            margin-left: 50%;
            min-height: 100vh;
            background: var(--rq-white);
            padding: 48px 56px;
            box-sizing: border-box;
        }

        /* Progress Bar */
        .rq-wizard__progress {
            height: 4px;
            margin-bottom: 32px;
            background: var(--rq-border);
        }
        .rq-wizard__progress-bar {
            height: 100%;
            background: var(--rq-primary);
            transition: width 0.3s ease;
        }

        /* Header */
        .rq-wizard__header {
            padding: 20px 0;
            border-bottom: 1px solid var(--rq-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .rq-wizard__title {
            font-size: 14px;
            font-weight: 600;
            color: var(--rq-primary);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .rq-wizard__nav-top {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-bottom: 16px;
        }
        .rq-btn--sm {
            padding: 8px 16px;
            font-size: 13px;
        }
        .rq-wizard__subtitle {
            font-size: 14px;
            color: var(--rq-text-light);
            margin: 0;
        }

        /* Step Sections */
        .rq-step__header {
            margin-bottom: 24px;
        }
        .rq-step__header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--rq-text);
            margin: 0 0 8px 0;
        }
        .rq-step__header p {
            font-size: 15px;
            color: var(--rq-text-light);
            margin: 0;
        }

        /* Form Elements */
        .rq-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--rq-text);
            margin-bottom: 8px;
        }
        .rq-label-hint {
            font-weight: 400;
            color: var(--rq-text-light);
        }
        .rq-input,
        .rq-select,
        .rq-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--rq-border);
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        .rq-input:focus,
        .rq-select:focus,
        .rq-textarea:focus {
            outline: none;
            border-color: var(--rq-primary);
            box-shadow: 0 0 0 3px var(--rq-primary-bg);
        }
        .rq-helper {
            font-size: 13px;
            color: var(--rq-text-light);
            margin-top: 8px;
        }
        .rq-field {
            margin-bottom: 20px;
        }

        /* Dropdown */
        .rq-dropdown {
            position: relative;
        }
        .rq-dropdown__trigger {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--rq-border);
            border-radius: 10px;
            background: var(--rq-white);
            font-size: 15px;
            text-align: left;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s;
        }
        .rq-dropdown__trigger:hover {
            border-color: var(--rq-primary-light);
        }
        .rq-dropdown.is-open .rq-dropdown__trigger {
            border-color: var(--rq-primary);
            box-shadow: 0 0 0 3px var(--rq-primary-bg);
        }
        .rq-dropdown__arrow {
            transition: transform 0.2s;
        }
        .rq-dropdown.is-open .rq-dropdown__arrow {
            transform: rotate(180deg);
        }
        .rq-dropdown__menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            margin-top: 4px;
            background: var(--rq-white);
            border: 2px solid var(--rq-border);
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            z-index: 100;
            max-height: 280px;
            overflow-y: auto;
        }
        .rq-dropdown.is-open .rq-dropdown__menu {
            display: block;
        }
        .rq-dropdown__item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .rq-dropdown__item:hover {
            background: var(--rq-primary-bg);
        }
        .rq-dropdown__item.is-selected {
            background: var(--rq-primary-bg);
        }
        .rq-dropdown__photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .rq-dropdown__info {
            display: flex;
            flex-direction: column;
        }
        .rq-dropdown__name {
            font-weight: 600;
            color: var(--rq-text);
        }
        .rq-dropdown__nmls {
            font-size: 13px;
            color: var(--rq-text-light);
        }
        .rq-dropdown__item--preferred {
            background: #cffafe;
            border-left: 3px solid #2DD4DA;
        }
        .rq-dropdown__item--preferred:hover {
            background: #a5f3fc;
        }
        .rq-dropdown__preferred-badge {
            margin-left: auto;
            font-size: 11px;
            font-weight: 600;
            color: #0891b2;
            background: #cffafe;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .rq-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .rq-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--rq-primary);
            cursor: pointer;
        }
        .rq-checkbox__label {
            font-size: 14px;
            color: var(--rq-text-light);
        }

        /* Page Type Cards (LO mode) */
        .rq-page-type-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 8px; }
        .rq-page-type-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 24px 16px; text-align: center; cursor: pointer; transition: all 0.2s ease; background: #fff; }
        .rq-page-type-card:hover { border-color: var(--rq-primary-light); background: var(--rq-primary-bg); }
        .rq-page-type-card.selected { border-color: var(--rq-primary); background: var(--rq-primary-bg); box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }
        .rq-page-type-card__icon { width: 64px; height: 64px; margin: 0 auto 12px; display: flex; align-items: center; justify-content: center; background: var(--rq-primary-bg); border-radius: 50%; }
        .rq-page-type-card__icon svg { stroke: var(--rq-primary); }
        .rq-page-type-card.selected .rq-page-type-card__icon { background: var(--rq-primary); }
        .rq-page-type-card.selected .rq-page-type-card__icon svg { stroke: #fff; }
        .rq-page-type-card h3 { font-size: 16px; font-weight: 600; color: var(--rq-text); margin: 0 0 4px; }
        .rq-page-type-card p { font-size: 13px; color: var(--rq-text-light); margin: 0; }
        .rq-partner-selection { margin-top: 16px; }

        /* Schedule Options */
        .rq-schedule-options { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 8px; }
        .rq-schedule-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 24px 16px; text-align: center; cursor: pointer; transition: all 0.2s ease; background: #fff; }
        .rq-schedule-card:hover { border-color: var(--rq-primary-light); background: var(--rq-primary-bg); }
        .rq-schedule-card.selected { border-color: var(--rq-primary); background: var(--rq-primary-bg); box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }
        .rq-schedule-card__icon { width: 64px; height: 64px; margin: 0 auto 12px; display: flex; align-items: center; justify-content: center; background: var(--rq-primary-bg); border-radius: 50%; }
        .rq-schedule-card__icon svg { stroke: var(--rq-primary); }
        .rq-schedule-card.selected .rq-schedule-card__icon { background: var(--rq-primary); }
        .rq-schedule-card.selected .rq-schedule-card__icon svg { stroke: #fff; }
        .rq-schedule-card h3 { font-size: 16px; font-weight: 600; color: var(--rq-text); margin: 0 0 4px; }
        .rq-schedule-card p { font-size: 13px; color: var(--rq-text-light); margin: 0; }

        /* Branding Preview */
        .rq-branding-preview {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            background: var(--rq-primary-bg);
            border-radius: 12px;
            margin-bottom: 24px;
        }
        .rq-branding-photo img {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--rq-white);
        }
        .rq-branding-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--rq-text);
            margin: 0 0 4px;
        }
        .rq-branding-detail {
            font-size: 14px;
            color: var(--rq-text-light);
            margin: 0;
        }

        /* Success State */
        .rq-success {
            text-align: center;
            padding: 40px 20px;
        }
        .rq-success__icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--rq-success) 0%, #059669 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .rq-success__icon svg {
            stroke: var(--rq-white);
        }
        .rq-success h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--rq-text);
            margin: 0 0 8px;
        }
        .rq-success p {
            font-size: 15px;
            color: var(--rq-text-light);
            margin: 0 0 24px;
        }
        .rq-success__url-box {
            display: flex;
            gap: 8px;
            max-width: 500px;
            margin: 0 auto 24px;
        }
        .rq-success__url-box input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid var(--rq-border);
            border-radius: 8px;
            font-size: 14px;
            background: #f8fafc;
        }
        .rq-success__actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        /* Footer */
        .rq-wizard__footer {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--rq-border);
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        /* Buttons */
        .rq-btn {
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
        .rq-btn--primary {
            background: var(--rq-primary);
            color: var(--rq-white);
        }
        .rq-btn--primary:hover {
            background: var(--rq-primary-dark);
        }
        .rq-btn--primary:disabled {
            background: var(--rq-border);
            cursor: not-allowed;
        }
        .rq-btn--secondary {
            background: var(--rq-white);
            color: var(--rq-text);
            border: 2px solid var(--rq-border);
        }
        .rq-btn--secondary:hover {
            border-color: var(--rq-primary);
            color: var(--rq-primary);
        }
        .rq-btn--ghost {
            background: transparent;
            color: var(--rq-text-light);
            border: none;
        }
        .rq-btn--ghost:hover {
            color: var(--rq-primary);
        }

        /* Loading State */
        .rq-btn.is-loading {
            pointer-events: none;
            opacity: 0.7;
        }
        .rq-btn.is-loading::after {
            content: '';
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: rq-spin 0.8s linear infinite;
            margin-left: 8px;
        }
        @keyframes rq-spin {
            to { transform: rotate(360deg); }
        }

        /* Messages */
        .rq-message {
            padding: 16px 20px;
            border-radius: 8px;
            font-size: 14px;
        }
        .rq-message--warning {
            background: #cffafe;
            color: #92400e;
        }
        .rq-message--error {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .rq-wizard {
                flex-direction: column;
                height: auto;
                min-height: 100vh;
            }
            .rq-wizard__hero {
                width: 100%;
                height: auto;
                position: relative;
                padding: 48px 32px;
            }
            .rq-wizard__hero h1 {
                font-size: 32px;
            }
            .rq-wizard__form {
                width: 100%;
                margin-left: 0;
                padding: 32px;
            }
        }
        @media (max-width: 640px) {
            .rq-wizard__hero {
                padding: 32px 24px;
            }
            .rq-wizard__hero h1 {
                font-size: 28px;
            }
            .rq-wizard__form {
                padding: 24px;
            }
            .rq-page-type-cards,
            .rq-schedule-options {
                grid-template-columns: 1fr;
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
            const modal = document.getElementById('rq-wizard-modal');
            const wizard = document.getElementById('rq-wizard');
            if (!modal || !wizard) return;

            let currentStep = 0;
            const totalSteps = 4;
            let selectedPartner = null;
            let selectedScheduleType = 'form';

            // Get user data and mode
            const userData = JSON.parse(wizard.dataset.user || "{}");
            const userMode = userData.mode || "realtor";
            const isLoanOfficer = userMode === "loan_officer";

            // Page type card selection (LO mode)
            const pageTypeCards = wizard.querySelectorAll('.rq-page-type-card');
            const pageTypeInput = document.getElementById('rq-page-type');
            const partnerSelectionDiv = document.getElementById('rq-partner-selection');
            const partnerInput = document.getElementById('rq-partner');

            if (pageTypeCards.length > 0 && isLoanOfficer) {
                pageTypeCards.forEach(card => {
                    card.addEventListener('click', () => {
                        pageTypeCards.forEach(c => c.classList.remove('selected'));
                        card.classList.add('selected');
                        const pageType = card.dataset.type;
                        if (pageTypeInput) pageTypeInput.value = pageType;

                        if (partnerSelectionDiv) {
                            if (pageType === 'cobranded') {
                                partnerSelectionDiv.style.display = 'block';
                            } else {
                                partnerSelectionDiv.style.display = 'none';
                                if (partnerInput) partnerInput.value = '';
                                selectedPartner = null;
                                const dropdownValue = wizard.querySelector('#rq-partner-dropdown .rq-dropdown__value');
                                if (dropdownValue) dropdownValue.textContent = 'Choose a partner...';
                                wizard.querySelectorAll('#rq-partner-dropdown .rq-dropdown__item').forEach(i => i.classList.remove('is-selected'));
                            }
                        }
                    });
                });
            }

            // Schedule type card selection
            const scheduleCards = wizard.querySelectorAll('.rq-schedule-card');
            const scheduleTypeInput = document.getElementById('rq-schedule-type');
            const formSelection = document.getElementById('rq-form-selection');
            const calendarSelection = document.getElementById('rq-calendar-selection');

            if (scheduleCards.length > 0) {
                // Default select first card
                scheduleCards[0].classList.add('selected');

                scheduleCards.forEach(card => {
                    card.addEventListener('click', () => {
                        scheduleCards.forEach(c => c.classList.remove('selected'));
                        card.classList.add('selected');
                        selectedScheduleType = card.dataset.type;
                        if (scheduleTypeInput) scheduleTypeInput.value = selectedScheduleType;

                        // Toggle form/calendar selection
                        if (selectedScheduleType === 'form') {
                            formSelection.style.display = 'block';
                            calendarSelection.style.display = 'none';
                        } else {
                            formSelection.style.display = 'none';
                            calendarSelection.style.display = 'block';
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
            modal.querySelector('.rq-modal__backdrop').addEventListener('click', closeModal);
            modal.querySelector('.rq-modal__close').addEventListener('click', closeModal);

            function closeModal() {
                modal.classList.remove('is-open');
                document.body.style.overflow = '';
            }

            // Dropdown functionality
            const dropdown = document.getElementById('rq-partner-dropdown');
            if (dropdown) {
                const trigger = dropdown.querySelector('.rq-dropdown__trigger');
                const menu = dropdown.querySelector('.rq-dropdown__menu');
                const items = dropdown.querySelectorAll('.rq-dropdown__item');
                const input = document.getElementById('rq-partner');
                const valueDisplay = dropdown.querySelector('.rq-dropdown__value');

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
                    const preferredItem = dropdown.querySelector(`.rq-dropdown__item[data-value="${preferredId}"]`);
                    if (preferredItem) {
                        preferredItem.click();
                    }
                }
            }

            // Copy URL button
            const copyUrlBtn = document.getElementById('rq-copy-url');
            if (copyUrlBtn) {
                copyUrlBtn.addEventListener('click', () => {
                    const urlInput = document.getElementById('rq-success-url');
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
            const createAnotherBtn = document.getElementById('rq-create-another');
            if (createAnotherBtn) {
                createAnotherBtn.addEventListener('click', () => {
                    currentStep = 0;
                    selectedPartner = null;
                    selectedScheduleType = 'form';
                    goToStep(0);
                    document.querySelector('.rq-wizard__footer').style.display = 'flex';
                });
            }

            // Navigation
            const prevBtn = document.getElementById('rq-prev-btn');
            const nextBtn = document.getElementById('rq-next-btn');
            const backBtnTop = document.getElementById('rq-back-top');
            const nextBtnTop = document.getElementById('rq-next-top');

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
                document.querySelectorAll('.rq-step').forEach(el => el.style.display = 'none');
                const stepEl = document.querySelector(`.rq-step[data-step="${step}"]`);
                if (stepEl) stepEl.style.display = 'block';
                currentStep = step;

                // Update progress
                const progress = ((step + 1) / totalSteps) * 100;
                document.querySelector('.rq-wizard__progress-bar').style.width = progress + '%';
                document.getElementById('rq-step-num').textContent = step + 1;

                // Update buttons
                prevBtn.style.display = step === 0 ? 'none' : 'flex';
                if (backBtnTop) backBtnTop.style.display = step === 0 ? 'none' : 'inline-flex';
                if (nextBtnTop) nextBtnTop.style.display = step < totalSteps - 1 ? 'inline-flex' : 'none';

                if (step === totalSteps - 1) {
                    nextBtn.innerHTML = 'Create Page <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
                } else {
                    nextBtn.innerHTML = 'Continue <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
                }
            }

            function validateStep() {
                if (currentStep === 0) {
                    if (isLoanOfficer) {
                        const pageType = document.getElementById('rq-page-type')?.value;
                        if (!pageType) {
                            alert('Please select Solo Page or Co-branded');
                            return false;
                        }
                        if (pageType === 'cobranded') {
                            const partnerName     = document.getElementById('rq-partner-name-input')?.value.trim() || '';
                            const partnerEmail    = document.getElementById('rq-partner-email-input')?.value.trim() || '';
                            const partnerPhone    = document.getElementById('rq-partner-phone-input')?.value.trim() || '';
                            const partnerHeadshot = document.getElementById('rq-partner-photo-url')?.value || '';
                            const partnerLogo     = document.getElementById('rq-partner-logo-url')?.value || '';

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
                        const partnerDropdown = document.getElementById('rq-partner-dropdown');
                        const isRequired = partnerDropdown?.dataset.required === 'true';
                        if (isRequired && !selectedPartner) {
                            alert('Please select a loan officer');
                            return false;
                        }

                        // Save preference if checked
                        if (selectedPartner) {
                            const rememberCheckbox = document.getElementById('rq-remember-partner');
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
                                });
                            }
                        }
                    }
                } else if (currentStep === 1) {
                    const headline = document.getElementById('rq-headline')?.value?.trim();
                    if (!headline) {
                        alert('Please enter a headline');
                        return false;
                    }
                } else if (currentStep === 2) {
                    if (selectedScheduleType === 'form') {
                        const formId = document.getElementById('rq-form-id')?.value;
                        if (!formId) {
                            alert('Please select a form');
                            return false;
                        }
                    } else if (selectedScheduleType === 'booking') {
                        const calendarId = document.getElementById('rq-calendar-id')?.value;
                        if (!calendarId) {
                            alert('Please select a calendar');
                            return false;
                        }
                    }
                }
                return true;
            }

            function submitWizard() {
                nextBtn.classList.add('is-loading');
                nextBtn.disabled = true;

                const data = {
                    action: 'frs_create_rate_quote',
                    nonce: '<?php echo wp_create_nonce( 'frs_lead_pages' ); ?>',
                    user_mode: userMode,
                    headline: document.getElementById('rq-headline')?.value || '',
                    subheadline: document.getElementById('rq-subheadline')?.value || '',
                    schedule_type: selectedScheduleType,
                    calendar_id: document.getElementById('rq-calendar-id')?.value || ''
                };

                if (isLoanOfficer) {
                    data.lo_name = document.getElementById('rq-lo-name')?.value || userData.name;
                    data.lo_nmls = document.getElementById('rq-lo-nmls')?.value || '';
                    data.lo_phone = document.getElementById('rq-lo-phone')?.value || '';
                    data.lo_email = document.getElementById('rq-lo-email')?.value || '';

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
                    data.realtor_name = document.getElementById('rq-realtor-name')?.value || userData.name;
                    data.realtor_license = document.getElementById('rq-realtor-license')?.value || '';
                    data.realtor_phone = document.getElementById('rq-realtor-phone')?.value || '';
                    data.realtor_email = document.getElementById('rq-realtor-email')?.value || '';
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
                document.querySelectorAll('.rq-step').forEach(el => el.style.display = 'none');
                const successStep = document.querySelector('.rq-step[data-step="success"]');
                if (successStep) successStep.style.display = 'block';

                document.getElementById('rq-success-url').value = pageUrl;
                document.getElementById('rq-view-page').href = pageUrl;
                document.querySelector('.rq-wizard__footer').style.display = 'none';

                nextBtn.classList.remove('is-loading');
                nextBtn.disabled = false;
            }

            // Partner headshot + company logo uploads (separate fields)
            function setupRqPartnerUpload(suffix) {
                const uploadDiv  = document.getElementById('rq-partner-' + suffix + '-upload');
                const fileInput  = document.getElementById('rq-partner-' + suffix + '-file');
                const preview    = document.getElementById('rq-partner-' + suffix + '-preview');
                const previewImg = document.getElementById('rq-partner-' + suffix + '-preview-img');
                const removeBtn  = document.getElementById('rq-partner-' + suffix + '-remove');
                const urlInput   = document.getElementById('rq-partner-' + suffix + '-url');
                if (!uploadDiv || !fileInput) return;

                uploadDiv.addEventListener('click', () => fileInput.click());
                uploadDiv.addEventListener('dragover', (e) => { e.preventDefault(); uploadDiv.style.borderColor = '#10b981'; });
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
            setupRqPartnerUpload('photo');
            setupRqPartnerUpload('logo');
        })();
        </script>
UNUSED;
    }

    /**
     * AJAX handler for creating rate quote page
     */
    public static function ajax_create_rate_quote(): void {
        check_ajax_referer( 'frs_lead_pages', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not authorized' ] );
        }

        $user = wp_get_current_user();
        $allowed_roles = [ 'administrator', 'editor', 'author', 'contributor', 'loan_officer', 'realtor_partner' ];

        if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
            wp_send_json_error( [ 'message' => 'Not authorized' ] );
        }

        $headline = sanitize_text_field( $_POST['headline'] ?? 'Get Your Personalized Rate Quote' );
        $subheadline = sanitize_text_field( $_POST['subheadline'] ?? '' );
        $schedule_type = sanitize_text_field( $_POST['schedule_type'] ?? 'form' );
        $calendar_id = absint( $_POST['calendar_id'] ?? 0 );

        // Create post
        $post_data = [
            'post_title'   => $headline,
            'post_status'  => 'publish',
            'post_type'    => 'frs_lead_page',
            'post_author'  => $user->ID,
        ];

        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        // Save common meta
        update_post_meta( $post_id, '_frs_page_type', 'rate_quote' );
        update_post_meta( $post_id, '_frs_headline', $headline );
        update_post_meta( $post_id, '_frs_subheadline', $subheadline );
        update_post_meta( $post_id, '_frs_schedule_type', $schedule_type );
        update_post_meta( $post_id, '_frs_calendar_id', $calendar_id );

        // Save creator info and partner info based on user mode
        $user_mode = sanitize_text_field( $_POST['user_mode'] ?? 'realtor' );
        update_post_meta( $post_id, '_frs_creator_mode', $user_mode );

        if ( $user_mode === 'loan_officer' ) {
            update_post_meta( $post_id, '_frs_loan_officer_id', $user->ID );
            update_post_meta( $post_id, '_frs_lo_name', sanitize_text_field( $_POST['lo_name'] ?? '' ) );
            update_post_meta( $post_id, '_frs_lo_phone', sanitize_text_field( $_POST['lo_phone'] ?? '' ) );
            update_post_meta( $post_id, '_frs_lo_email', sanitize_email( $_POST['lo_email'] ?? '' ) );
            update_post_meta( $post_id, '_frs_lo_nmls', sanitize_text_field( $_POST['lo_nmls'] ?? '' ) );

            // Optional custom headshot chosen in the Branding step. Empty means
            // "use my profile headshot" — the template falls back to get_user_photo().
            $lo_photo = esc_url_raw( $_POST['lo_photo'] ?? '' );
            if ( ! empty( $lo_photo ) ) {
                update_post_meta( $post_id, '_frs_lo_photo', $lo_photo );
            }

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
            update_post_meta( $post_id, '_frs_realtor_id', $user->ID );
            update_post_meta( $post_id, '_frs_realtor_name', sanitize_text_field( $_POST['realtor_name'] ?? '' ) );
            update_post_meta( $post_id, '_frs_realtor_phone', sanitize_text_field( $_POST['realtor_phone'] ?? '' ) );
            update_post_meta( $post_id, '_frs_realtor_email', sanitize_email( $_POST['realtor_email'] ?? '' ) );
            update_post_meta( $post_id, '_frs_realtor_license', sanitize_text_field( $_POST['realtor_license'] ?? '' ) );
            update_post_meta( $post_id, '_frs_loan_officer_id', absint( $_POST['loan_officer_id'] ?? 0 ) );
        }

        wp_send_json_success([
            'post_id' => $post_id,
            'url'     => get_permalink( $post_id ),
        ]);
    }
}

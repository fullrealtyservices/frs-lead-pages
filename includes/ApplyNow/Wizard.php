<?php
/**
 * Apply Now Wizard
 *
 * Multi-step wizard for creating Apply Now landing pages.
 * Integrates Fluent Booking for scheduling.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\ApplyNow;

use FRSLeadPages\Core\LoanOfficers;
use FRSLeadPages\Core\Realtors;
use FRSLeadPages\Core\UserMode;
use FRSLeadPages\Integrations\InstantImages;

class Wizard {

    /**
     * Trigger class for opening modal
     */
    const TRIGGER_CLASS = 'an-wizard-trigger';

    /**
     * Hash for URL triggering
     */
    const TRIGGER_HASH = 'apply-now-wizard';

    /**
     * Initialize
     */
    public static function init() {
        add_shortcode( 'apply_now_wizard', [ __CLASS__, 'render' ] );
        add_shortcode( 'apply_now_wizard_button', [ __CLASS__, 'render_button' ] );
        add_action( 'wp_ajax_frs_create_apply_now', [ __CLASS__, 'ajax_create_apply_now' ] );

        // Add modal to footer on frontend
    }

    /**
     * Render trigger button shortcode
     */
    public static function render_button( array $atts = [] ): string {
        $atts = shortcode_atts([
            'text'  => 'Create Apply Now Page',
            'class' => '',
        ], $atts, 'apply_now_wizard_button' );

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
        <div id="an-wizard-modal" class="an-modal">
            <div class="an-modal__backdrop"></div>
            <div class="an-modal__container">
                <button type="button" class="an-modal__close" aria-label="Close">
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
     * Get stock copy options for Apply Now pages
     *
     * @return array
     */
    private static function get_stock_copy_options(): array {
        return [
            'headlines' => [
                'Ready to Own Your Dream Home?',
                'Start Your Home Loan Journey Today',
                'Apply Now – Fast, Easy, Secure',
                'Your Path to Homeownership Starts Here',
                'Get Pre-Approved in Minutes',
            ],
            'descriptions' => [
                'Take the first step toward owning your dream home. Our streamlined application process makes it easy to get started.',
                'Whether you\'re a first-time buyer or looking to refinance, we\'re here to guide you every step of the way.',
                'Get personalized loan options with competitive rates. Apply now and let\'s make your homeownership goals a reality.',
                'Ready to take the next step? Our team is here to help you find the perfect loan for your situation.',
            ],
        ];
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
        $user_data['photo'] = \FRSLeadPages\get_user_photo( $user->ID ) ?: \FRSLeadPages\frs_normalize_upload_url( get_avatar_url( $user->ID, [ 'size' => 200 ] ) );

        // Get partners based on user mode
        $partners = $partner_config['partners'];

        // Get available calendars
        $calendars = self::get_user_calendars();

        // Get stock copy options
        $stock_copy = self::get_stock_copy_options();

        // Stock hero images for Apply Now
        $stock_images = [
            'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=600&h=400&fit=crop',
            'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=600&h=400&fit=crop',
            'https://images.unsplash.com/photo-1560184897-ae75f418493e?w=600&h=400&fit=crop',
        ];

        $total_steps = 5; // Partner, Content, Hero Image, Scheduling, Branding

        ob_start();
        ?>
        <div id="an-wizard" class="an-wizard" data-user='<?php echo esc_attr( wp_json_encode( $user_data ) ); ?>'>
            <div class="an-wizard__hero">
                <div class="an-wizard__hero-content">
                    <h1>Create Your<br>Apply Now Page</h1>
                    <p>Build a professional loan application landing page that converts.</p>
                </div>
            </div>

            <div class="an-wizard__form">
                <div class="an-wizard__progress">
                    <div class="an-wizard__progress-bar" style="width: 20%"></div>
                </div>

                <div class="an-wizard__header">
                    <p class="an-wizard__title">Apply Now Wizard</p>
                    <p class="an-wizard__subtitle">Step <span id="an-step-num">1</span> of <?php echo $total_steps; ?></p>
                </div>

                <div class="an-wizard__nav-top">
                    <button type="button" id="an-back-top" class="an-btn an-btn--ghost an-btn--sm" style="display:none;">Back</button>
                    <button type="button" id="an-next-top" class="an-btn an-btn--primary an-btn--sm">Continue</button>
                </div>

                <div class="an-wizard__content">
                <!-- Step 0: Page Type Selection -->
                <div class="an-step" data-step="0">
                    <div class="an-step__header">
                        <h2><?php echo $is_loan_officer ? 'What type of page?' : esc_html( $partner_config['title'] ); ?></h2>
                        <p><?php echo $is_loan_officer ? 'Choose how you want to brand this page' : esc_html( $partner_config['subtitle'] ); ?></p>
                    </div>
                    <div class="an-step__body">
                        <?php if ( $is_loan_officer ) : ?>
                            <input type="hidden" id="an-page-type" name="page_type" value="">
                            <input type="hidden" id="an-partner" name="partner" value="">

                            <div class="an-page-type-cards">
                                <div class="an-page-type-card" data-type="solo">
                                    <div class="an-page-type-card__icon">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                    </div>
                                    <h3>Solo Page</h3>
                                    <p>Just your branding</p>
                                </div>
                                <div class="an-page-type-card" data-type="cobranded">
                                    <div class="an-page-type-card__icon">
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

                            <div id="an-partner-selection" class="an-partner-selection" style="display: none;">
                                <p class="an-section-label" style="margin-top:24px;">Partner Real Estate Agent</p>
                                <div class="an-row">
                                    <div class="an-field an-field--half">
                                        <label class="an-label">Partner Name</label>
                                        <input type="text" id="an-partner-name-input" class="an-input" placeholder="Jane Smith">
                                    </div>
                                    <div class="an-field an-field--half">
                                        <label class="an-label">Phone</label>
                                        <input type="tel" id="an-partner-phone-input" class="an-input" placeholder="(555) 123-4567">
                                    </div>
                                </div>
                                <div class="an-field">
                                    <label class="an-label">Email</label>
                                    <input type="email" id="an-partner-email-input" class="an-input" placeholder="jane@realestate.com">
                                </div>

                                <!-- Partner Headshot Upload -->
                                <div class="an-field" style="margin-top: 24px;">
                                    <label class="an-label">Partner Headshot (optional)</label>
                                    <div class="an-photo-upload" id="an-partner-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="an-partner-photo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="an-partner-photo-preview" style="margin-top: 12px; display: none;">
                                        <img id="an-partner-photo-preview-img" src="" alt="Headshot preview" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                                        <button type="button" id="an-partner-photo-remove" class="an-btn an-btn--ghost an-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="an-partner-photo-url" value="">
                                </div>

                                <!-- Company Logo Upload -->
                                <div class="an-field" style="margin-top: 16px;">
                                    <label class="an-label">Company Logo (optional)</label>
                                    <div class="an-photo-upload" id="an-partner-logo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="an-partner-logo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="an-partner-logo-preview" style="margin-top: 12px; display: none;">
                                        <img id="an-partner-logo-preview-img" src="" alt="Logo preview" style="width: 120px; height: 120px; border-radius: 8px; object-fit: contain; background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0;">
                                        <button type="button" id="an-partner-logo-remove" class="an-btn an-btn--ghost an-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="an-partner-logo-url" value="">
                                </div>
                                <p class="an-helper">Both images are optional — add whichever you want on the landing page</p>
                            </div>
                        <?php else : ?>
                            <label class="an-label"><?php echo esc_html( $partner_config['label'] ); ?></label>
                            <div class="an-dropdown" id="an-partner-dropdown" data-mode="<?php echo esc_attr( $user_mode ); ?>" data-required="true" data-preferred="<?php echo esc_attr( $partner_config['preferred_id'] ?? 0 ); ?>">
                                <input type="hidden" id="an-partner" name="partner" value="">
                                <button type="button" class="an-dropdown__trigger">
                                    <span class="an-dropdown__value"><?php echo esc_html( $partner_config['placeholder'] ); ?></span>
                                    <svg class="an-dropdown__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <div class="an-dropdown__menu">
                                    <?php foreach ( $partners as $partner ) : ?>
                                        <?php
                                        $partner_id = $partner['user_id'] ?? $partner['id'];
                                        $partner_name = $partner['name'];
                                        $partner_photo = $partner['photo_url'] ?? '';
                                        $partner_nmls = $partner['nmls'] ?? '';
                                        $partner_arrive = $partner['arrive'] ?? '';
                                        $is_preferred = ( (int) $partner_id === (int) ( $partner_config['preferred_id'] ?? 0 ) );
                                        ?>
                                        <div class="an-dropdown__item<?php echo $is_preferred ? ' an-dropdown__item--preferred' : ''; ?>"
                                             data-value="<?php echo esc_attr( $partner_id ); ?>"
                                             data-name="<?php echo esc_attr( $partner_name ); ?>"
                                             data-nmls="<?php echo esc_attr( $partner_nmls ); ?>"
                                             data-arrive="<?php echo esc_attr( $partner_arrive ); ?>"
                                             data-photo="<?php echo esc_attr( $partner_photo ); ?>">
                                            <img src="<?php echo esc_url( $partner_photo ); ?>" alt="" class="an-dropdown__photo">
                                            <div class="an-dropdown__info">
                                                <span class="an-dropdown__name"><?php echo esc_html( $partner_name ); ?></span>
                                                <?php if ( $partner_nmls ) : ?>
                                                    <span class="an-dropdown__nmls">NMLS# <?php echo esc_html( $partner_nmls ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( $is_preferred ) : ?>
                                                <span class="an-dropdown__preferred-badge">★ Preferred</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="an-helper"><?php echo esc_html( $partner_config['helper'] ); ?></p>

                            <?php if ( $partner_config['show_remember'] ?? false ) : ?>
                                <label class="an-checkbox" style="margin-top: 12px;">
                                    <input type="checkbox" id="an-remember-partner" name="remember_partner" value="1">
                                    <span class="an-checkbox__label">Remember my choice for next time</span>
                                </label>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 1: Page Content with Stock Copy -->
                <div class="an-step" data-step="1" style="display:none;">
                    <div class="an-step__header">
                        <h2>Craft Your Message</h2>
                        <p>Choose compelling copy that converts</p>
                    </div>
                    <div class="an-step__body">
                        <div class="an-field">
                            <label class="an-label">Headline</label>
                            <div id="an-headline-options" class="an-radio-group">
                                <?php foreach ( $stock_copy['headlines'] as $i => $headline ) : ?>
                                    <label class="an-radio-btn">
                                        <input type="radio" name="an-headline-choice" value="<?php echo esc_attr( $headline ); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                                        <span class="an-radio-btn__label"><?php echo esc_html( $headline ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <label class="an-radio-btn">
                                    <input type="radio" name="an-headline-choice" value="custom">
                                    <span class="an-radio-btn__label">Write my own...</span>
                                </label>
                            </div>
                            <input type="text" id="an-headline-custom" class="an-input" placeholder="Enter your custom headline" style="display:none; margin-top:12px;">
                            <input type="hidden" id="an-headline" value="<?php echo esc_attr( $stock_copy['headlines'][0] ); ?>">
                        </div>
                        <div class="an-field">
                            <label class="an-label">Description</label>
                            <div id="an-description-options" class="an-radio-group an-radio-group--vertical">
                                <?php foreach ( $stock_copy['descriptions'] as $i => $desc ) : ?>
                                    <label class="an-radio-btn an-radio-btn--block">
                                        <input type="radio" name="an-description-choice" value="<?php echo esc_attr( $desc ); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                                        <span class="an-radio-btn__label"><?php echo esc_html( $desc ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <label class="an-radio-btn an-radio-btn--block">
                                    <input type="radio" name="an-description-choice" value="custom">
                                    <span class="an-radio-btn__label">Write my own...</span>
                                </label>
                            </div>
                            <textarea id="an-description-custom" class="an-textarea" placeholder="Enter your custom description" rows="3" style="display:none; margin-top:12px;"></textarea>
                            <input type="hidden" id="an-subheadline" value="<?php echo esc_attr( $stock_copy['descriptions'][0] ); ?>">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Hero Image -->
                <div class="an-step" data-step="2" style="display:none;">
                    <div class="an-step__header">
                        <h2>Choose Your Hero Image</h2>
                        <p>Select an eye-catching background for your page</p>
                    </div>
                    <div class="an-step__body">
                        <div id="an-images-grid" class="an-images-grid">
                            <?php foreach ( $stock_images as $i => $img_url ) : ?>
                                <div class="an-image-option<?php echo $i === 0 ? ' an-image-option--selected' : ''; ?>" data-url="<?php echo esc_attr( $img_url ); ?>">
                                    <img src="<?php echo esc_url( $img_url ); ?>" alt="Stock image <?php echo $i + 1; ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="an-upload-section">
                            <p>Or find the perfect stock photo:</p>
                            <?php echo InstantImages::render_search_button( 'an', '#2563EB' ); ?>
                            <p style="margin-top: 16px;">Or upload your own:</p>
                            <input type="file" id="an-image-upload" accept="image/*" class="an-file-input">
                            <label for="an-image-upload" class="an-btn an-btn--secondary">Upload Image</label>
                        </div>
                        <input type="hidden" id="an-hero-image" value="<?php echo esc_attr( $stock_images[0] ); ?>">
                        <?php echo InstantImages::render_search_modal( 'an', 'an-hero-image' ); ?>
                    </div>
                </div>

                <!-- Step 3: Scheduling Options with Custom Dropdowns -->
                <div class="an-step" data-step="3" style="display:none;">
                    <div class="an-step__header">
                        <h2>Scheduling Integration</h2>
                        <p>Choose how visitors can connect with you</p>
                    </div>
                    <div class="an-step__body">
                        <input type="hidden" id="an-schedule-type" name="schedule_type" value="form">

                        <div class="an-schedule-options">
                            <div class="an-schedule-card selected" data-type="form">
                                <div class="an-schedule-card__icon">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                        <line x1="16" y1="13" x2="8" y2="13"/>
                                        <line x1="16" y1="17" x2="8" y2="17"/>
                                        <polyline points="10 9 9 9 8 9"/>
                                    </svg>
                                </div>
                                <h3>Application Form</h3>
                                <p>Collect info via Fluent Form</p>
                            </div>
                            <?php if ( ! empty( $calendars ) ) : ?>
                            <div class="an-schedule-card" data-type="booking">
                                <div class="an-schedule-card__icon">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                        <circle cx="12" cy="15" r="2"/>
                                    </svg>
                                </div>
                                <h3>Booking Calendar</h3>
                                <p>Let visitors book a consultation</p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Calendar Selection with Custom Dropdown -->
                        <div id="an-calendar-selection" class="an-calendar-selection" style="display: none;">
                            <label class="an-label" style="margin-top: 24px;">Select Calendar</label>
                            <input type="hidden" id="an-calendar-id" value="">
                            <div class="an-dropdown" id="an-calendar-dropdown">
                                <button type="button" class="an-dropdown__trigger">
                                    <span class="an-dropdown__value">Choose a calendar...</span>
                                    <svg class="an-dropdown__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <div class="an-dropdown__menu">
                                    <?php foreach ( $calendars as $calendar ) : ?>
                                        <div class="an-dropdown__item an-dropdown__item--simple" data-value="<?php echo esc_attr( $calendar['id'] ); ?>">
                                            <span class="an-dropdown__name"><?php echo esc_html( $calendar['title'] ); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="an-helper">Choose your Fluent Booking calendar</p>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Your Branding -->
                <div class="an-step" data-step="4" style="display:none;">
                    <div class="an-step__header">
                        <h2>Your Branding</h2>
                        <p>Review and customize your profile info</p>
                    </div>
                    <div class="an-step__body">
                        <div class="an-branding-preview">
                            <div class="an-branding-photo">
                                <img id="an-preview-photo" src="<?php echo esc_url( $user_data['photo'] ); ?>" alt="">
                            </div>
                            <div class="an-branding-info">
                                <p class="an-branding-name" id="an-preview-name"><?php echo esc_html( $user_data['name'] ); ?></p>
                                <p class="an-branding-detail" id="an-preview-license"><?php echo esc_html( $is_loan_officer ? ( $user_data['nmls'] ? 'NMLS# ' . $user_data['nmls'] : '' ) : ( $user_data['license'] ?? '' ) ); ?></p>
                            </div>
                        </div>

                        <?php if ( $is_loan_officer ) : ?>
                            <!-- LO Mode: Show LO fields -->

                            <!-- Headshot: use profile photo or upload a new one -->
                            <div class="an-field" style="margin-bottom:20px;">
                                <label class="an-label">Your Headshot</label>
                                <div class="an-headshot-choice" role="radiogroup" style="display:flex; gap:12px; flex-wrap:wrap;">
                                    <label class="an-headshot-opt" style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px;">
                                        <input type="radio" name="an-headshot-source" value="profile" checked>
                                        <span>Use my profile headshot</span>
                                    </label>
                                    <label class="an-headshot-opt" style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px;">
                                        <input type="radio" name="an-headshot-source" value="upload">
                                        <span>Upload a new one</span>
                                    </label>
                                </div>
                                <div id="an-lo-photo-upload-wrap" style="display:none; margin-top:12px;">
                                    <div class="an-photo-upload" id="an-lo-photo-upload" style="border:2px dashed #cbd5e1; padding:20px; border-radius:8px; text-align:center; cursor:pointer;">
                                        <input type="file" id="an-lo-photo-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin:0 auto 8px; opacity:0.5;">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                        <p style="margin:0; font-weight:500;">Click to upload or drag and drop</p>
                                        <p style="margin:4px 0 0; font-size:12px; color:#94a3b8;">PNG, JPG, GIF or WebP (max 5MB)</p>
                                    </div>
                                    <p id="an-lo-photo-status" style="display:none; font-size:13px; margin-top:8px;"></p>
                                </div>
                                <input type="hidden" id="an-lo-photo-url" value="" data-profile-photo="<?php echo esc_attr( $user_data['photo'] ); ?>">
                            </div>

                            <div class="an-row">
                                <div class="an-field an-field--half">
                                    <label class="an-label">Display Name</label>
                                    <input type="text" id="an-lo-name" class="an-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                                </div>
                                <div class="an-field an-field--half">
                                    <label class="an-label">NMLS #</label>
                                    <input type="text" id="an-lo-nmls" class="an-input" value="<?php echo esc_attr( $user_data['nmls'] ?? '' ); ?>">
                                </div>
                            </div>
                            <div class="an-row">
                                <div class="an-field an-field--half">
                                    <label class="an-label">Contact Phone</label>
                                    <input type="tel" id="an-lo-phone" class="an-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                                </div>
                                <div class="an-field an-field--half">
                                    <label class="an-label">Contact Email</label>
                                    <input type="email" id="an-lo-email" class="an-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                                </div>
                            </div>

                            <?php if ( ! empty( $user_data['arrive'] ) ) : ?>
                                <div class="an-arrive-preview">
                                    <label class="an-label">Apply Now Link (CTA)</label>
                                    <div class="an-arrive-link">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                            <polyline points="15 3 21 3 21 9"/>
                                            <line x1="10" y1="14" x2="21" y2="3"/>
                                        </svg>
                                        <span><?php echo esc_html( $user_data['arrive'] ); ?></span>
                                    </div>
                                    <p class="an-helper">This link will be used as the Apply Now button on your page</p>
                                </div>
                                <input type="hidden" id="an-arrive-link" value="<?php echo esc_attr( $user_data['arrive'] ); ?>">
                            <?php endif; ?>
                        <?php else : ?>
                            <!-- Realtor Mode: Show Realtor fields -->
                            <div class="an-row">
                                <div class="an-field an-field--half">
                                    <label class="an-label">Display Name</label>
                                    <input type="text" id="an-realtor-name" class="an-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                                </div>
                                <div class="an-field an-field--half">
                                    <label class="an-label">License Number</label>
                                    <input type="text" id="an-realtor-license" class="an-input" value="<?php echo esc_attr( $user_data['license'] ?? '' ); ?>">
                                </div>
                            </div>
                            <div class="an-row">
                                <div class="an-field an-field--half">
                                    <label class="an-label">Contact Phone</label>
                                    <input type="tel" id="an-realtor-phone" class="an-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                                </div>
                                <div class="an-field an-field--half">
                                    <label class="an-label">Contact Email</label>
                                    <input type="email" id="an-realtor-email" class="an-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                                </div>
                            </div>

                            <!-- Show selected LO info -->
                            <p class="an-section-label" style="margin-top:24px;">Loan Officer (from Step 1)</p>
                            <div id="an-partner-preview" class="an-lo-preview">
                                <!-- Populated by JS -->
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Success State -->
                <div class="an-step an-step--success" data-step="success" style="display:none;">
                    <div class="an-success">
                        <div class="an-success__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                        <h2 id="an-success-title">Your Apply Now Page is Live!</h2>
                        <p id="an-success-subtitle">Share this link with your clients</p>
                        <div class="an-success__url-box">
                            <input type="text" id="an-success-url" readonly>
                            <button type="button" id="an-copy-url" class="an-btn an-btn--primary">Copy Link</button>
                        </div>
                        <div class="an-success__actions">
                            <a id="an-view-page" href="#" class="an-btn an-btn--secondary" target="_blank">View Page</a>
                            <button type="button" id="an-create-another" class="an-btn an-btn--ghost">Create Another</button>
                        </div>
                    </div>
                </div>

                </div>

                <div class="an-wizard__footer">
                    <button type="button" class="an-btn an-btn--secondary" id="an-prev-btn" style="display:none;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Back
                    </button>
                    <button type="button" class="an-btn an-btn--primary" id="an-next-btn">
                        Continue
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <?php echo InstantImages::render_search_styles( 'an', '#2563EB' ); ?>
        <?php echo InstantImages::render_search_scripts( 'an', 'an-hero-image', 'an-images-grid' ); ?>
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
        return '<div class="an-message an-message--warning">Please log in to create an apply now page.</div>';
    }

    /**
     * Render access denied message
     */
    private static function render_access_denied(): string {
        return '<div class="an-message an-message--error">You do not have permission to create apply now pages.</div>';
    }

    /**
     * Enqueue wizard assets
     */
    private static function enqueue_assets(): void {
        $base_url = plugins_url( 'includes/ApplyNow/', FRS_LEAD_PAGES_PLUGIN_FILE );
        $version  = FRS_LEAD_PAGES_VERSION;

        wp_enqueue_style(
            'frs-apply-now-wizard',
            $base_url . 'style.css',
            [],
            $version
        );

        wp_enqueue_script(
            'frs-apply-now-wizard',
            $base_url . 'script.js',
            [],
            $version,
            true
        );

        wp_localize_script( 'frs-apply-now-wizard', 'frsApplyNowWizard', [
            'triggerClass' => self::TRIGGER_CLASS,
            'triggerHash'  => self::TRIGGER_HASH,
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'frs_lead_pages' ),
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
        // Assets are already enqueued by render_modal_styles()
        return '';
    }

    /**
     * AJAX handler for creating apply now page
     */
    public static function ajax_create_apply_now(): void {
        check_ajax_referer( 'frs_lead_pages', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not authorized' ] );
        }

        $user = wp_get_current_user();
        $allowed_roles = [ 'administrator', 'editor', 'author', 'contributor', 'loan_officer', 'realtor_partner' ];

        if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
            wp_send_json_error( [ 'message' => 'Not authorized' ] );
        }

        $headline = sanitize_text_field( $_POST['headline'] ?? 'Ready to Own Your Dream Home?' );
        $subheadline = sanitize_text_field( $_POST['subheadline'] ?? '' );
        $schedule_type = sanitize_text_field( $_POST['schedule_type'] ?? 'form' );
        $calendar_id = absint( $_POST['calendar_id'] ?? 0 );
        $hero_image = esc_url_raw( $_POST['hero_image'] ?? '' );
        $arrive_link = esc_url_raw( $_POST['arrive_link'] ?? '' );

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
        update_post_meta( $post_id, '_frs_page_type', 'apply_now' );
        update_post_meta( $post_id, '_frs_headline', $headline );
        update_post_meta( $post_id, '_frs_subheadline', $subheadline );
        update_post_meta( $post_id, '_frs_schedule_type', $schedule_type );
        update_post_meta( $post_id, '_frs_calendar_id', $calendar_id );
        update_post_meta( $post_id, '_frs_hero_image_url', $hero_image );
        update_post_meta( $post_id, '_frs_arrive_link', $arrive_link );

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

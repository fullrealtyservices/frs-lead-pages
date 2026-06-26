<?php
/**
 * Customer Spotlight Wizard
 *
 * Multi-step wizard for creating Customer Spotlight landing pages.
 * Target specific buyer types with tailored messaging.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\CustomerSpotlight;

use FRSLeadPages\Core\LoanOfficers;
use FRSLeadPages\Core\Realtors;
use FRSLeadPages\Core\UserMode;
use FRSLeadPages\Integrations\InstantImages;

class Wizard {

    /**
     * Trigger class for opening modal
     */
    const TRIGGER_CLASS = 'cs-wizard-trigger';

    /**
     * Hash for URL triggering
     */
    const TRIGGER_HASH = 'customer-spotlight-wizard';

    /**
     * Initialize
     */
    public static function init() {
        add_shortcode( 'customer_spotlight_wizard', [ __CLASS__, 'render' ] );
        add_shortcode( 'customer_spotlight_wizard_button', [ __CLASS__, 'render_button' ] );
        add_action( 'wp_ajax_frs_create_spotlight', [ __CLASS__, 'ajax_create_spotlight' ] );

        // Add modal to footer on frontend
    }

    /**
     * Render trigger button shortcode
     */
    public static function render_button( array $atts = [] ): string {
        $atts = shortcode_atts([
            'text'  => 'Create Customer Spotlight',
            'class' => '',
        ], $atts, 'customer_spotlight_wizard_button' );

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
        <div id="cs-wizard-modal" class="cs-modal">
            <div class="cs-modal__backdrop"></div>
            <div class="cs-modal__container">
                <button type="button" class="cs-modal__close" aria-label="Close">
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
        $partners = $partner_config['partners'];

        $spotlight_types = [
            'first_time_buyer' => [
                'label' => 'First-Time Buyer',
                'desc'  => 'New to homeownership',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            ],
            'move_up_buyer' => [
                'label' => 'Move-Up Buyer',
                'desc'  => 'Ready for more space',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>',
            ],
            'downsizer' => [
                'label' => 'Downsizer',
                'desc'  => 'Simplifying lifestyle',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/></svg>',
            ],
            'investor' => [
                'label' => 'Investor',
                'desc'  => 'Building portfolio',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            ],
            'relocating' => [
                'label' => 'Relocating',
                'desc'  => 'Moving to a new area',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 7 8 11.7z"/></svg>',
            ],
            'veteran' => [
                'label' => 'Veteran',
                'desc'  => 'Military & VA benefits',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
            ],
        ];

        ob_start();
        ?>
        <div id="cs-wizard" class="cs-wizard" data-user='<?php echo esc_attr( wp_json_encode( $user_data ) ); ?>'>
            <div class="cs-wizard__hero">
                <div class="cs-wizard__hero-content">
                    <h1>Create Your<br>Customer Spotlight</h1>
                    <p>Build a targeted landing page for specific buyer types with personalized messaging.</p>
                </div>
            </div>

            <div class="cs-wizard__form">
                <div class="cs-wizard__progress">
                    <div class="cs-wizard__progress-bar" style="width: 14.3%"></div>
                </div>

                <div class="cs-wizard__header">
                    <p class="cs-wizard__title">Customer Spotlight Wizard</p>
                    <p class="cs-wizard__subtitle">Step <span id="cs-step-num">1</span> of 8</p>
                </div>

                <div class="cs-wizard__nav-top">
                    <button type="button" id="cs-back-top" class="cs-btn cs-btn--ghost cs-btn--sm" style="display:none;">Back</button>
                    <button type="button" id="cs-next-top" class="cs-btn cs-btn--primary cs-btn--sm">Continue</button>
                </div>

                <div class="cs-wizard__content">
                <!-- Step 0: Page Type Selection -->
                <div class="cs-step" data-step="0">
                    <div class="cs-step__header">
                        <h2><?php echo $is_loan_officer ? 'What type of page?' : esc_html( $partner_config['title'] ); ?></h2>
                        <p><?php echo $is_loan_officer ? 'Choose how you want to brand this page' : esc_html( $partner_config['subtitle'] ); ?></p>
                    </div>
                    <div class="cs-step__body">
                        <?php if ( $is_loan_officer ) : ?>
                            <!-- LO Mode: Choose Solo or Co-branded -->
                            <input type="hidden" id="cs-page-type" name="page_type" value="">
                            <input type="hidden" id="cs-partner" name="partner" value="">

                            <div class="cs-page-type-cards">
                                <div class="cs-page-type-card" data-type="solo">
                                    <div class="cs-page-type-card__icon">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                    </div>
                                    <h3>Solo Page</h3>
                                    <p>Just your branding</p>
                                </div>
                                <div class="cs-page-type-card" data-type="cobranded">
                                    <div class="cs-page-type-card__icon">
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
                            <div id="cs-partner-selection" class="cs-partner-selection" style="display: none;">
                                <p class="cs-section-label" style="margin-top:24px;">Partner Real Estate Agent</p>
                                <div class="cs-row">
                                    <div class="cs-field cs-field--half">
                                        <label class="cs-label">Partner Name</label>
                                        <input type="text" id="cs-partner-name-input" class="cs-input" placeholder="Jane Smith">
                                    </div>
                                    <div class="cs-field cs-field--half">
                                        <label class="cs-label">Phone</label>
                                        <input type="tel" id="cs-partner-phone-input" class="cs-input" placeholder="(555) 123-4567">
                                    </div>
                                </div>
                                <div class="cs-field">
                                    <label class="cs-label">Email</label>
                                    <input type="email" id="cs-partner-email-input" class="cs-input" placeholder="jane@realestate.com">
                                </div>

                                <!-- Partner Headshot Upload -->
                                <div class="cs-field" style="margin-top: 24px;">
                                    <label class="cs-label">Partner Headshot (optional)</label>
                                    <div class="cs-photo-upload" id="cs-partner-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="cs-partner-photo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="cs-partner-photo-preview" style="margin-top: 12px; display: none;">
                                        <img id="cs-partner-photo-preview-img" src="" alt="Headshot preview" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                                        <button type="button" id="cs-partner-photo-remove" class="cs-btn cs-btn--ghost cs-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="cs-partner-photo-url" value="">
                                </div>

                                <!-- Company Logo Upload -->
                                <div class="cs-field" style="margin-top: 16px;">
                                    <label class="cs-label">Company Logo (optional)</label>
                                    <div class="cs-photo-upload" id="cs-partner-logo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="cs-partner-logo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="cs-partner-logo-preview" style="margin-top: 12px; display: none;">
                                        <img id="cs-partner-logo-preview-img" src="" alt="Logo preview" style="width: 120px; height: 120px; border-radius: 8px; object-fit: contain; background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0;">
                                        <button type="button" id="cs-partner-logo-remove" class="cs-btn cs-btn--ghost cs-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="cs-partner-logo-url" value="">
                                </div>
                                <p class="cs-helper">Both images are optional — add whichever you want on the landing page</p>
                            </div>
                        <?php else : ?>
                            <!-- Partner Mode: Select LO (required) -->
                            <label class="cs-label"><?php echo esc_html( $partner_config['label'] ); ?></label>
                            <div class="cs-dropdown" id="cs-partner-dropdown"
                                 data-mode="<?php echo esc_attr( $user_mode ); ?>"
                                 data-required="true"
                                 data-preferred="<?php echo esc_attr( $partner_config['preferred_id'] ?? 0 ); ?>"
                                 data-auto-selected="<?php echo ( $partner_config['auto_selected'] ?? false ) ? 'true' : 'false'; ?>">
                                <input type="hidden" id="cs-partner" name="partner" value="">
                                <button type="button" class="cs-dropdown__trigger">
                                    <span class="cs-dropdown__value"><?php echo esc_html( $partner_config['placeholder'] ); ?></span>
                                    <svg class="cs-dropdown__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <div class="cs-dropdown__menu">
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
                                        <div class="cs-dropdown__item<?php echo $is_preferred ? ' cs-dropdown__item--preferred' : ''; ?>"
                                             data-value="<?php echo esc_attr( $partner_id ); ?>"
                                             data-name="<?php echo esc_attr( $partner_name ); ?>"
                                             data-nmls="<?php echo esc_attr( $partner_nmls ); ?>"
                                             data-photo="<?php echo esc_attr( $partner_photo ); ?>"
                                             data-email="<?php echo esc_attr( $partner_email ); ?>"
                                             data-phone="<?php echo esc_attr( $partner_phone ); ?>">
                                            <img src="<?php echo esc_url( $partner_photo ); ?>" alt="" class="cs-dropdown__photo">
                                            <div class="cs-dropdown__info">
                                                <span class="cs-dropdown__name"><?php echo esc_html( $partner_name ); ?></span>
                                                <?php if ( $partner_nmls ) : ?>
                                                    <span class="cs-dropdown__nmls">NMLS# <?php echo esc_html( $partner_nmls ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( $is_preferred ) : ?>
                                                <span class="cs-dropdown__preferred-badge">★ Preferred</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="cs-helper"><?php echo esc_html( $partner_config['helper'] ); ?></p>

                            <?php if ( $partner_config['show_remember'] ?? false ) : ?>
                                <label class="cs-checkbox" style="margin-top: 12px;">
                                    <input type="checkbox" id="cs-remember-partner" name="remember_partner" value="1">
                                    <span class="cs-checkbox__label">Remember my choice for next time</span>
                                </label>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 1: Spotlight Type -->
                <div class="cs-step" data-step="1" style="display:none;">
                    <div class="cs-step__header">
                        <h2>Who's Your Target?</h2>
                        <p>Select the buyer type you want to reach</p>
                    </div>
                    <div class="cs-step__body">
                        <div class="cs-type-grid">
                            <?php foreach ( $spotlight_types as $key => $type ) : ?>
                                <label class="cs-type-card">
                                    <input type="radio" name="cs-spotlight-type" value="<?php echo esc_attr( $key ); ?>">
                                    <div class="cs-type-card__content">
                                        <div class="cs-type-card__icon"><?php echo $type['icon']; ?></div>
                                        <div class="cs-type-card__text">
                                            <strong><?php echo esc_html( $type['label'] ); ?></strong>
                                            <span><?php echo esc_html( $type['desc'] ); ?></span>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Customize Page -->
                <div class="cs-step" data-step="2" style="display:none;">
                    <div class="cs-step__header">
                        <h2>Customize Your Page</h2>
                        <p>Create compelling messaging for your audience</p>
                    </div>
                    <div class="cs-step__body">
                        <div class="cs-field">
                            <label class="cs-label">Headline</label>
                            <div id="cs-headline-options" class="cs-radio-group">
                                <!-- Populated by JS based on spotlight type -->
                            </div>
                            <input type="text" id="cs-headline-custom" class="cs-input" placeholder="Enter custom headline" style="display:none; margin-top:12px;">
                        </div>
                        <div class="cs-field">
                            <label class="cs-label">Subheadline</label>
                            <input type="text" id="cs-subheadline" class="cs-input" placeholder="A short description of what you're offering">
                        </div>
                        <div class="cs-field">
                            <label class="cs-label">Value Propositions <span class="cs-label-hint">(one per line)</span></label>
                            <textarea id="cs-value-props" class="cs-textarea" rows="4" placeholder="Expert market knowledge
Personalized service
Smooth closing process"></textarea>
                            <p class="cs-helper">These will appear as bullet points on your page</p>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Hero Image -->
                <div class="cs-step" data-step="3" style="display:none;">
                    <div class="cs-step__header">
                        <h2>Choose Your Photo</h2>
                        <p>Select a lifestyle image for your audience</p>
                    </div>
                    <div class="cs-step__body">
                        <div id="cs-images-grid" class="cs-images-grid">
                            <!-- Stock images populated by JS -->
                        </div>
                        <div class="cs-upload-section">
                            <p>Or find the perfect stock photo:</p>
                            <?php echo InstantImages::render_search_button( 'cs', '#1e293b' ); ?>
                            <p style="margin-top: 16px;">Or upload your own image:</p>
                            <input type="file" id="cs-image-upload" accept="image/*" class="cs-file-input">
                            <label for="cs-image-upload" class="cs-btn cs-btn--secondary">Upload Image</label>
                        </div>
                        <input type="hidden" id="cs-hero-image" value="">
                        <?php echo InstantImages::render_search_modal( 'cs', 'cs-hero-image' ); ?>
                    </div>
                </div>

                <!-- Step 4: Contact Fields -->
                <div class="cs-step" data-step="4" style="display:none;">
                    <div class="cs-step__header">
                        <h2>Contact Fields</h2>
                        <p>Required info from visitors</p>
                    </div>
                    <div class="cs-step__body">
                        <div class="cs-toggle-list">
                            <label class="cs-toggle">
                                <input type="checkbox" checked disabled> Full Name <span class="cs-toggle__required">Required</span>
                            </label>
                            <label class="cs-toggle">
                                <input type="checkbox" checked disabled> Email <span class="cs-toggle__required">Required</span>
                            </label>
                            <label class="cs-toggle">
                                <input type="checkbox" checked disabled> Phone <span class="cs-toggle__required">Required</span>
                            </label>
                            <label class="cs-toggle">
                                <input type="checkbox" name="cs-q-comments" checked> Comments
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Qualifying Questions -->
                <div class="cs-step" data-step="5" style="display:none;">
                    <div class="cs-step__header">
                        <h2>Qualifying Questions</h2>
                        <p>Optional questions to qualify leads</p>
                    </div>
                    <div class="cs-step__body">
                        <div class="cs-toggle-list">
                            <label class="cs-toggle">
                                <input type="checkbox" name="cs-q-agent" checked> Are you working with an agent?
                            </label>
                            <label class="cs-toggle">
                                <input type="checkbox" name="cs-q-preapproved" checked> Are you pre-approved?
                            </label>
                            <label class="cs-toggle">
                                <input type="checkbox" name="cs-q-interested" checked> Interested in pre-approval?
                            </label>
                            <label class="cs-toggle">
                                <input type="checkbox" name="cs-q-timeline" checked> Buying timeline
                            </label>
                            <label class="cs-toggle">
                                <input type="checkbox" name="cs-q-pricerange"> Ideal price range
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Step 6: Branding (bi-directional) -->
                <div class="cs-step" data-step="6" style="display:none;">
                    <div class="cs-step__header">
                        <h2>Your Team Info</h2>
                        <p>Confirm your contact details</p>
                    </div>
                    <div class="cs-step__body">
                        <p class="cs-section-label">Your Information</p>
                        <?php if ( $is_loan_officer ) : ?>
                            <!-- LO Mode: Show LO fields -->
                            <div class="cs-row">
                                <div class="cs-field cs-field--half">
                                    <label class="cs-label">Your Name</label>
                                    <input type="text" id="cs-lo-name" class="cs-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                                </div>
                                <div class="cs-field cs-field--half">
                                    <label class="cs-label">NMLS #</label>
                                    <input type="text" id="cs-lo-nmls" class="cs-input" value="<?php echo esc_attr( $user_data['nmls'] ?? '' ); ?>">
                                </div>
                            </div>
                            <div class="cs-row">
                                <div class="cs-field cs-field--half">
                                    <label class="cs-label">Phone</label>
                                    <input type="tel" id="cs-lo-phone" class="cs-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                                </div>
                                <div class="cs-field cs-field--half">
                                    <label class="cs-label">Email</label>
                                    <input type="email" id="cs-lo-email" class="cs-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                                </div>
                            </div>

                            <!-- Headshot: show their actual profile photo + option to upload a different one -->
                            <div class="cs-field" style="margin-top: 24px;">
                                <label class="cs-label">Your Headshot</label>
                                <div style="display:flex; align-items:center; gap:16px;">
                                    <img id="cs-lo-photo-img" src="<?php echo esc_url( $user_data['photo'] ); ?>" alt="Your headshot" style="width:84px; height:84px; border-radius:50%; object-fit:cover; border:2px solid #e2e8f0; background:#f1f5f9; flex-shrink:0;">
                                    <div>
                                        <button type="button" id="cs-lo-photo-btn" class="cs-btn cs-btn--secondary" style="padding:8px 16px;">Upload a different photo</button>
                                        <input type="file" id="cs-lo-photo-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                                        <p id="cs-lo-photo-status" style="margin:8px 0 0; font-size:13px; color:#64748b;">Using your profile headshot</p>
                                    </div>
                                </div>
                                <input type="hidden" id="cs-lo-photo-url" value="">
                            </div>

                            <p class="cs-section-label" style="margin-top:24px;">Realtor Partner (from Step 1)</p>
                            <div id="cs-partner-preview" class="cs-lo-preview">
                                <p style="color:#94a3b8;font-size:14px;margin:0;" id="cs-no-partner-msg">No realtor partner selected (solo page)</p>
                            </div>
                        <?php else : ?>
                            <!-- Realtor Mode: Show Realtor fields -->
                            <div class="cs-row">
                                <div class="cs-field cs-field--half">
                                    <label class="cs-label">Your Name</label>
                                    <input type="text" id="cs-realtor-name" class="cs-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                                </div>
                                <div class="cs-field cs-field--half">
                                    <label class="cs-label">License #</label>
                                    <input type="text" id="cs-realtor-license" class="cs-input" value="<?php echo esc_attr( $user_data['license'] ?? '' ); ?>">
                                </div>
                            </div>
                            <div class="cs-row">
                                <div class="cs-field cs-field--half">
                                    <label class="cs-label">Phone</label>
                                    <input type="tel" id="cs-realtor-phone" class="cs-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                                </div>
                                <div class="cs-field cs-field--half">
                                    <label class="cs-label">Email</label>
                                    <input type="email" id="cs-realtor-email" class="cs-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                                </div>
                            </div>

                            <!-- Photo Upload -->
                            <div class="cs-field" style="margin-top: 24px;">
                                <label class="cs-label">Your Photo (Optional)</label>
                                <div class="cs-photo-upload" id="cs-realtor-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                    <input type="file" id="cs-realtor-photo-file" accept="image/*" style="display: none;">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                    <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                </div>
                                <div id="cs-realtor-photo-preview" style="margin-top: 12px; display: none;">
                                    <img id="cs-realtor-photo-preview-img" src="" alt="Preview" style="width: 100px; height: 100px; border-radius: 8px; object-fit: cover;">
                                    <button type="button" id="cs-realtor-photo-remove" class="cs-btn cs-btn--ghost cs-btn--sm" style="margin-left: 12px;">Remove</button>
                                </div>
                                <input type="hidden" id="cs-realtor-photo-url" value="">
                            </div>

                            <p class="cs-section-label" style="margin-top:24px;">Loan Officer (from Step 1)</p>
                            <div id="cs-partner-preview" class="cs-lo-preview">
                                <!-- Populated by JS -->
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 7: Preview & Publish -->
                <div class="cs-step" data-step="7" style="display:none;">
                    <div class="cs-step__header">
                        <h2>Review & Publish</h2>
                        <p>Everything looks good? Let's make it live.</p>
                    </div>
                    <div class="cs-step__body">
                        <div id="cs-summary" class="cs-summary">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                </div>

                <!-- Success State -->
                <div class="cs-step cs-step--success" data-step="success" style="display:none;">
                    <div class="cs-success">
                        <div class="cs-success__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <h2>Your Page is Live!</h2>
                        <p id="cs-success-type"></p>
                        <div class="cs-success__actions">
                            <a id="cs-success-link" href="#" class="cs-btn cs-btn--primary" target="_blank">View Page</a>
                            <button type="button" id="cs-copy-link" class="cs-btn cs-btn--secondary">Copy Link</button>
                        </div>
                        <a href="<?php echo esc_url( remove_query_arg( 'created' ) ); ?>" class="cs-link">Create Another</a>
                    </div>
                </div>

                </div><!-- .cs-wizard__content -->

                <div class="cs-wizard__footer">
                    <button type="button" id="cs-back" class="cs-btn cs-btn--ghost" style="display:none;">Back</button>
                    <button type="button" id="cs-next" class="cs-btn cs-btn--primary">Continue</button>
                    <button type="button" id="cs-publish" class="cs-btn cs-btn--primary" style="display:none;">
                        <span class="cs-btn__text">Publish Page</span>
                        <span class="cs-btn__loading" style="display:none;">Creating...</span>
                    </button>
                </div>
            </div><!-- .cs-wizard__form -->
        </div><!-- .cs-wizard -->

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
            .cs-wizard {
                display: flex;
                position: relative;
                min-height: 100dvh;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .cs-wizard__hero {
                width: 60%;
                height: 100dvh;
                background: linear-gradient(135deg, #0f172a 0%, #0f172a 100%);
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding: 64px;
                position: fixed;
                left: 0;
                top: 0;
                overflow: hidden;
            }
            .cs-wizard__hero::before {
                content: "";
                position: absolute;
                top: -50%;
                right: -50%;
                width: 100%;
                height: 100%;
                background: radial-gradient(circle, rgba(16,185,129,0.2) 0%, transparent 70%);
                pointer-events: none;
            }
            .cs-wizard__hero-content {
                position: relative;
                z-index: 1;
            }
            .cs-wizard__hero h1 {
                font-size: 48px;
                font-weight: 700;
                color: #fff;
                margin: 0 0 16px;
                line-height: 1.1;
            }
            .cs-wizard__hero p {
                font-size: 18px;
                color: rgba(255,255,255,0.8);
                margin: 0;
                max-width: 400px;
            }
            .cs-wizard__form {
                position: absolute;
                left: 60%;
                right: 0;
                top: 0;
                min-height: 100dvh;
                background: #fff;
                padding: 48px 56px 96px;
                box-sizing: border-box;
            }
            .cs-wizard__progress {
                height: 3px;
                background: #e5e7eb;
                margin-bottom: 40px;
            }
            .cs-wizard__progress-bar {
                height: 100%;
                background: #1e293b;
                transition: width 0.3s ease;
            }
            .cs-wizard__header {
                margin-bottom: 8px;
            }
            .cs-wizard__title {
                font-size: 12px;
                font-weight: 600;
                color: #1e293b;
                margin: 0 0 4px;
                text-transform: uppercase;
                letter-spacing: 0.1em;
            }
            .cs-wizard__subtitle {
                font-size: 13px;
                color: #94a3b8;
                margin: 0;
            }
            .cs-wizard__nav-top {
                display: flex;
                gap: 12px;
                justify-content: flex-end;
                margin-bottom: 16px;
            }
            .cs-btn--sm {
                padding: 8px 16px;
                font-size: 13px;
            }
            .cs-wizard__content {
            }
            .cs-step {
                display: flex;
                flex-direction: column;
            }
            .cs-step__body {
                padding-right: 8px;
            }
            .cs-step__header h2 {
                font-size: 32px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 8px;
            }
            .cs-step__header p {
                font-size: 15px;
                color: #64748b;
                margin: 0 0 32px;
            }
            .cs-label {
                display: block !important;
                font-size: 15px !important;
                font-weight: 600 !important;
                color: #374151 !important;
                margin-bottom: 12px !important;
            }
            .cs-label-hint {
                font-weight: 400;
                color: #94a3b8;
            }
            #cs-wizard .cs-input,
            #cs-wizard input[type="text"],
            #cs-wizard input[type="email"],
            #cs-wizard input[type="tel"],
            #cs-wizard textarea {
                width: 100%;
                padding: 18px 20px;
                font-size: 16px;
                border: 2px solid #e5e7eb;
                border-radius: 10px;
                background-color: #fff;
                box-sizing: border-box;
                min-height: 56px;
            }
            .cs-textarea {
                min-height: 600px;
                resize: vertical;
            }
            .cs-input:focus, .cs-textarea:focus,
            #cs-wizard input:focus,
            #cs-wizard select:focus,
            #cs-wizard textarea:focus {
                outline: none;
                border: 1px solid #1e293b !important;
                border-bottom: 1px solid #1e293b !important;
            }
            .cs-dropdown {
                position: relative;
                width: 100%;
            }
            .cs-dropdown__trigger {
                width: 100%;
                height: 60px;
                padding: 0 20px;
                font-size: 16px;
                border: 2px solid #e5e7eb;
                border-radius: 10px;
                background-color: #fff;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: space-between;
                text-align: left;
                color: #374151;
            }
            .cs-dropdown.open .cs-dropdown__trigger {
                border-color: #1e293b;
                box-shadow: 0 0 0 4px rgba(16,185,129,0.1);
            }
            .cs-dropdown__menu {
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
            .cs-dropdown.open .cs-dropdown__menu {
                display: block;
            }
            .cs-dropdown__item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                cursor: pointer;
                transition: background 0.15s;
            }
            .cs-dropdown__item:hover {
                background: #f3f4f6;
            }
            .cs-dropdown__item.selected {
                background: #f8fafc;
            }
            .cs-dropdown__photo {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                object-fit: cover;
            }
            .cs-dropdown__name {
                font-size: 15px;
                font-weight: 600;
                color: #1f2937;
                display: block;
            }
            .cs-dropdown__nmls {
                font-size: 13px;
                color: #6b7280;
            }
            .cs-dropdown__item--preferred {
                background: #cffafe;
                border-left: 3px solid #2DD4DA;
            }
            .cs-dropdown__item--preferred:hover {
                background: #a5f3fc;
            }
            .cs-dropdown__preferred-badge {
                margin-left: auto;
                font-size: 11px;
                font-weight: 600;
                color: #0891b2;
                background: #cffafe;
                padding: 2px 8px;
                border-radius: 10px;
            }
            .cs-checkbox {
                display: flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
            }
            .cs-checkbox input[type="checkbox"] {
                width: 18px;
                height: 18px;
                accent-color: #1e293b;
                cursor: pointer;
            }
            .cs-checkbox__label {
                font-size: 14px;
                color: #6b7280;
            }
            /* Page Type Cards (Step 0 for LOs) */
            .cs-page-type-cards {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
                margin-bottom: 8px;
            }
            .cs-page-type-card {
                border: 2px solid #e2e8f0;
                border-radius: 12px;
                padding: 24px 16px;
                text-align: center;
                cursor: pointer;
                transition: all 0.2s ease;
                background: #fff;
            }
            .cs-page-type-card:hover {
                border-color: #1e293b;
                background: #f0fdf4;
            }
            .cs-page-type-card.selected {
                border-color: #1e293b;
                background: #f0fdf4;
                box-shadow: 0 0 0 4px rgba(16,185,129,0.15);
            }
            .cs-page-type-card__icon {
                margin-bottom: 12px;
                color: #64748b;
            }
            .cs-page-type-card.selected .cs-page-type-card__icon {
                color: #1e293b;
            }
            .cs-page-type-card h3 {
                font-size: 16px;
                font-weight: 600;
                color: #1e293b;
                margin: 0 0 4px 0;
            }
            .cs-page-type-card p {
                font-size: 13px;
                color: #64748b;
                margin: 0;
            }
            .cs-partner-selection {
                animation: csFadeIn 0.3s ease;
            }
            @keyframes csFadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .cs-type-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            .cs-type-card {
                cursor: pointer;
            }
            .cs-type-card input {
                position: absolute;
                opacity: 0;
            }
            .cs-type-card__content {
                display: flex;
                align-items: center;
                gap: 16px;
                padding: 20px;
                background: #fff;
                border: 2px solid #e5e7eb;
                border-radius: 12px;
                transition: all 0.2s;
            }
            .cs-type-card:hover .cs-type-card__content {
                border-color: #1e293b;
                background: #f0fdf4;
            }
            .cs-type-card input:checked + .cs-type-card__content {
                border-color: #1e293b;
                background: #f8fafc;
                box-shadow: 0 0 0 4px rgba(16,185,129,0.15);
            }
            .cs-type-card__icon {
                width: 48px;
                height: 48px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f0fdf4;
                border-radius: 12px;
                flex-shrink: 0;
            }
            .cs-type-card__icon svg {
                width: 24px;
                height: 24px;
                stroke: #0f172a;
            }
            .cs-type-card input:checked + .cs-type-card__content .cs-type-card__icon {
                background: #1e293b;
            }
            .cs-type-card input:checked + .cs-type-card__content .cs-type-card__icon svg {
                stroke: #fff;
            }
            .cs-type-card__text {
                display: flex;
                flex-direction: column;
                gap: 2px;
            }
            .cs-type-card__text strong {
                font-size: 15px;
                font-weight: 600;
                color: #1f2937;
            }
            .cs-type-card__text span {
                font-size: 13px;
                color: #6b7280;
            }
            .cs-radio-group {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .cs-radio-btn {
                position: relative;
                cursor: pointer;
            }
            .cs-radio-btn input {
                position: absolute;
                opacity: 0;
            }
            .cs-radio-btn__label {
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
            .cs-radio-btn input:checked + .cs-radio-btn__label {
                background: #1e293b;
                border-color: #1e293b;
                color: #fff;
            }
            .cs-images-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 16px;
                margin-bottom: 24px;
            }
            .cs-image-option {
                aspect-ratio: 4/3;
                border-radius: 12px;
                overflow: hidden;
                cursor: pointer;
                border: 3px solid transparent;
                transition: all 0.2s;
            }
            .cs-image-option img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .cs-image-option--selected {
                border-color: #1e293b;
                box-shadow: 0 0 0 4px rgba(16,185,129,0.2);
            }
            .cs-upload-section {
                text-align: center;
                padding: 24px;
                background: #f8fafc;
                border-radius: 12px;
                border: 2px dashed #cbd5e1;
            }
            .cs-upload-section p {
                margin: 0 0 12px;
                color: #64748b;
            }
            .cs-file-input {
                display: none;
            }
            .cs-toggle-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            .cs-toggle {
                display: flex;
                align-items: center;
                gap: 14px;
                font-size: 15px;
                color: #374151;
                cursor: pointer;
                padding: 12px 16px;
                background: #f8fafc;
                border-radius: 10px;
            }
            .cs-toggle input {
                width: 20px;
                height: 20px;
                accent-color: #1e293b;
            }
            .cs-toggle__required {
                font-size: 11px;
                font-weight: 600;
                color: #94a3b8;
                margin-left: auto;
                text-transform: uppercase;
            }
            .cs-section-label {
                font-size: 11px;
                font-weight: 700;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.1em;
                margin: 0 0 16px;
            }
            .cs-field {
                margin-bottom: 24px !important;
            }
            .cs-row {
                display: flex;
                gap: 20px;
            }
            .cs-field--half {
                flex: 1;
            }
            .cs-helper {
                font-size: 13px;
                color: #94a3b8;
                margin: 10px 0 0;
            }
            .cs-lo-preview {
                display: flex;
                align-items: center;
                gap: 16px;
                padding: 20px;
                background: #f8fafc;
                border-radius: 12px;
            }
            .cs-lo-preview img {
                width: 64px;
                height: 64px;
                border-radius: 50%;
                object-fit: cover;
            }
            .cs-lo-preview__info h4 {
                font-size: 17px;
                font-weight: 600;
                color: #0f172a;
                margin: 0 0 4px;
            }
            .cs-lo-preview__info p {
                font-size: 14px;
                color: #64748b;
                margin: 0;
            }
            .cs-summary {
                background: #f8fafc;
                border-radius: 16px;
                padding: 28px;
            }
            .cs-summary__row {
                display: flex;
                justify-content: space-between;
                padding: 14px 0;
                border-bottom: 1px solid #e5e7eb;
            }
            .cs-summary__row:last-child {
                border-bottom: none;
            }
            .cs-summary__label {
                font-size: 14px;
                color: #64748b;
            }
            .cs-summary__value {
                font-size: 14px;
                font-weight: 600;
                color: #0f172a;
            }
            .cs-btn {
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
            .cs-btn--primary {
                background: #1e293b;
                color: #fff;
            }
            .cs-btn--primary:hover {
                background: #0f172a;
            }
            .cs-btn--secondary {
                background: #f1f5f9;
                color: #0f172a;
            }
            .cs-btn--ghost {
                background: transparent;
                color: #64748b;
            }
            .cs-wizard__footer {
                display: flex;
                justify-content: space-between;
                padding: 24px 0;
                margin-top: auto;
                border-top: 0;
                flex-shrink: 0;
                background: #fff;
            }
            .cs-success {
                text-align: center;
                padding: 48px 24px;
            }
            .cs-success__icon {
                width: 88px;
                height: 88px;
                background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
                color: #fff;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 28px;
                box-shadow: 0 8px 24px rgba(16,185,129,0.3);
            }
            .cs-success h2 {
                font-size: 28px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 8px;
            }
            .cs-success p {
                font-size: 16px;
                color: #64748b;
                margin: 0 0 28px;
            }
            .cs-success__actions {
                display: flex;
                gap: 12px;
                justify-content: center;
                margin-bottom: 24px;
            }
            .cs-link {
                font-size: 14px;
                color: #64748b;
                text-decoration: none;
            }
            @media (max-width: 1024px) {
                .cs-wizard {
                    flex-direction: column;
                    height: auto;
                    min-height: 100dvh;
                }
                .cs-wizard__hero {
                    position: relative;
                    width: 100%;
                    height: auto;
                    padding: 48px 32px;
                }
                .cs-wizard__hero h1 {
                    font-size: 32px;
                }
                .cs-wizard__form {
                    position: relative;
                    left: auto;
                    right: auto;
                    top: auto;
                    bottom: auto;
                    width: 100%;
                    height: auto;
                    flex: 1;
                }
                .cs-type-grid {
                    grid-template-columns: 1fr;
                }
            }
            @media (max-width: 640px) {
                .cs-row {
                    flex-direction: column;
                    gap: 0;
                }
                .cs-images-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>' . InstantImages::render_search_styles( 'cs', '#1e293b' );
    }

    /**
     * Render scripts
     */
    private static function render_scripts(): string {
        $headline_options = [
            'first_time_buyer' => [
                'Ready to Buy Your First Home?',
                'First Home? We Can Help!',
                'Your Dream Home Awaits',
                'Start Your Homeownership Journey',
            ],
            'move_up_buyer' => [
                'Ready for Your Next Chapter?',
                'Time to Upgrade Your Space?',
                'Find Your Forever Home',
                'More Space for Your Growing Family',
            ],
            'downsizer' => [
                'Ready to Simplify?',
                'Right-Size Your Life',
                'Less Space, More Freedom',
                'Your Perfect-Fit Home Awaits',
            ],
            'investor' => [
                'Build Your Real Estate Portfolio',
                'Smart Investing Starts Here',
                'Grow Your Wealth Through Property',
                'Your Next Investment Property',
            ],
            'relocating' => [
                'Welcome to Your New City!',
                'Making Your Move Easy',
                'Find Home in a New Place',
                'Your Relocation Experts',
            ],
            'veteran' => [
                'Thank You for Your Service',
                'VA Home Loan Benefits Await',
                'Serving Those Who Served',
                'Your Military Home Benefits',
            ],
        ];

        $stock_images = [
            'first_time_buyer' => [
                'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1582268611958-ebfd161ef9cf?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=600&h=400&fit=crop',
            ],
            'move_up_buyer' => [
                'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1613490493576-7fde63acd811?w=600&h=400&fit=crop',
            ],
            'downsizer' => [
                'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=600&h=400&fit=crop',
            ],
            'investor' => [
                'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1560520653-9e0e4c89eb11?w=600&h=400&fit=crop',
            ],
            'relocating' => [
                'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1600573472591-ee6981cf35b6?w=600&h=400&fit=crop',
            ],
            'veteran' => [
                'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=600&h=400&fit=crop',
            ],
        ];

        $type_labels = [
            'first_time_buyer' => 'First-Time Buyer',
            'move_up_buyer' => 'Move-Up Buyer',
            'downsizer' => 'Downsizer',
            'investor' => 'Investor',
            'relocating' => 'Relocating',
            'veteran' => 'Veteran',
        ];

        return '
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const wizard = document.getElementById("cs-wizard");
            if (!wizard) return;

            const headlineOptions = ' . wp_json_encode( $headline_options ) . ';
            const stockImages = ' . wp_json_encode( $stock_images ) . ';
            const typeLabels = ' . wp_json_encode( $type_labels ) . ';

            const steps = wizard.querySelectorAll(".cs-step[data-step]");
            const progressBar = wizard.querySelector(".cs-wizard__progress-bar");
            const stepNum = document.getElementById("cs-step-num");
            const backBtn = document.getElementById("cs-back");
            const nextBtn = document.getElementById("cs-next");
            const publishBtn = document.getElementById("cs-publish");
            const backBtnTop = document.getElementById("cs-back-top");
            const nextBtnTop = document.getElementById("cs-next-top");

            let currentStep = 0;
            const userData = JSON.parse(wizard.dataset.user || "{}");
            const userMode = userData.mode || "realtor";
            const isLoanOfficer = userMode === "loan_officer";

            let data = {
                userMode: userMode,
                partner: {},
                spotlightType: "",
                customize: {},
                questions: {},
                branding: {}
            };

            // Dropdown handling
            document.querySelectorAll(".cs-dropdown").forEach(dropdown => {
                const trigger = dropdown.querySelector(".cs-dropdown__trigger");
                const menu = dropdown.querySelector(".cs-dropdown__menu");
                const items = dropdown.querySelectorAll(".cs-dropdown__item");
                const hiddenInput = dropdown.querySelector("input[type=hidden]");
                const valueDisplay = dropdown.querySelector(".cs-dropdown__value");

                function positionMenu() {
                    const rect = trigger.getBoundingClientRect();
                    menu.style.top = (rect.bottom + 4) + "px";
                    menu.style.left = rect.left + "px";
                    menu.style.width = rect.width + "px";
                }

                trigger.addEventListener("click", (e) => {
                    e.stopPropagation();
                    dropdown.classList.toggle("open");
                    if (dropdown.classList.contains("open")) positionMenu();
                });

                items.forEach(item => {
                    item.addEventListener("click", () => {
                        items.forEach(i => i.classList.remove("selected"));
                        item.classList.add("selected");
                        hiddenInput.value = item.dataset.value;
                        valueDisplay.textContent = item.querySelector(".cs-dropdown__name").textContent;
                        dropdown.classList.remove("open");

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

            document.addEventListener("click", () => {
                document.querySelectorAll(".cs-dropdown.open").forEach(d => d.classList.remove("open"));
            });

            // Page type card selection (LO mode only)
            const pageTypeCards = document.querySelectorAll(".cs-page-type-card");
            const pageTypeInput = document.getElementById("cs-page-type");
            const partnerSelection = document.getElementById("cs-partner-selection");

            if (pageTypeCards.length > 0) {
                pageTypeCards.forEach(card => {
                    card.addEventListener("click", () => {
                        pageTypeCards.forEach(c => c.classList.remove("selected"));
                        card.classList.add("selected");
                        const pageType = card.dataset.type;
                        if (pageTypeInput) pageTypeInput.value = pageType;

                        if (partnerSelection) {
                            if (pageType === "cobranded") {
                                partnerSelection.style.display = "block";
                            } else {
                                partnerSelection.style.display = "none";
                                const partnerInput = document.getElementById("cs-partner");
                                if (partnerInput) {
                                    partnerInput.value = "";
                                    partnerInput.dataset.name = "";
                                }
                            }
                        }
                        console.log("CS Wizard: Page type selected:", pageType);
                    });
                });
            }

            // Auto-select preferred partner if set (partner mode only)
            const partnerDropdown = document.getElementById("cs-partner-dropdown");
            if (partnerDropdown && !isLoanOfficer) {
                const preferredId = partnerDropdown.dataset.preferred;
                if (preferredId && preferredId !== "0") {
                    const preferredItem = partnerDropdown.querySelector(`.cs-dropdown__item[data-value="${preferredId}"]`);
                    if (preferredItem) {
                        preferredItem.click();
                        console.log("CS Wizard: Auto-selected preferred partner ID:", preferredId);
                    }
                }
            }

            // Update headline options when spotlight type changes
            wizard.querySelectorAll("input[name=\"cs-spotlight-type\"]").forEach(radio => {
                radio.addEventListener("change", function() {
                    data.spotlightType = this.value;
                    updateHeadlineOptions(this.value);
                    updateStockImages(this.value);
                });
            });

            function updateHeadlineOptions(type) {
                const container = document.getElementById("cs-headline-options");
                const options = headlineOptions[type] || [];
                container.innerHTML = options.map((opt, i) => `
                    <label class="cs-radio-btn">
                        <input type="radio" name="cs-headline" value="${opt}" ${i === 0 ? "checked" : ""}>
                        <span class="cs-radio-btn__label">${opt}</span>
                    </label>
                `).join("") + `
                    <label class="cs-radio-btn">
                        <input type="radio" name="cs-headline" value="custom">
                        <span class="cs-radio-btn__label">Custom...</span>
                    </label>
                `;

                container.querySelectorAll("input").forEach(radio => {
                    radio.addEventListener("change", function() {
                        const customInput = document.getElementById("cs-headline-custom");
                        customInput.style.display = this.value === "custom" ? "block" : "none";
                    });
                });
            }

            function updateStockImages(type) {
                const grid = document.getElementById("cs-images-grid");
                const images = stockImages[type] || stockImages.first_time_buyer;
                grid.innerHTML = images.map((img, i) => `
                    <div class="cs-image-option ${i === 0 ? "cs-image-option--selected" : ""}" data-url="${img}">
                        <img src="${img}" alt="Stock image">
                    </div>
                `).join("");

                document.getElementById("cs-hero-image").value = images[0];

                grid.querySelectorAll(".cs-image-option").forEach(opt => {
                    opt.addEventListener("click", () => {
                        grid.querySelectorAll(".cs-image-option").forEach(o => o.classList.remove("cs-image-option--selected"));
                        opt.classList.add("cs-image-option--selected");
                        document.getElementById("cs-hero-image").value = opt.dataset.url;
                    });
                });
            }

            function showStep(step) {
                steps.forEach(s => s.style.display = "none");
                const target = wizard.querySelector(`[data-step="${step}"]`);
                if (target) target.style.display = "flex";

                const progress = ((step + 1) / 8) * 100;
                progressBar.style.width = progress + "%";
                stepNum.textContent = step + 1;

                backBtn.style.display = step > 0 ? "inline-flex" : "none";
                nextBtn.style.display = step < 7 ? "inline-flex" : "none";
                publishBtn.style.display = step === 7 ? "inline-flex" : "none";

                // Update top buttons too
                if (backBtnTop) backBtnTop.style.display = step > 0 ? "inline-flex" : "none";
                if (nextBtnTop) nextBtnTop.style.display = step < 7 ? "inline-flex" : "none";

                if (step === 6) updatePartnerPreview();
                if (step === 7) updateSummary();
            }

            function validateStep(step) {
                if (step === 0) {
                    if (isLoanOfficer) {
                        // LO Mode: Check page type selection
                        const pageType = document.getElementById("cs-page-type")?.value;

                        if (!pageType) {
                            alert("Please select a page type (Solo or Co-branded)");
                            return false;
                        }

                        data.pageType = pageType;

                        if (pageType === "cobranded") {
                            const partnerName     = document.getElementById("cs-partner-name-input")?.value.trim() || "";
                            const partnerEmail    = document.getElementById("cs-partner-email-input")?.value.trim() || "";
                            const partnerPhone    = document.getElementById("cs-partner-phone-input")?.value.trim() || "";
                            const partnerHeadshot = document.getElementById("cs-partner-photo-url")?.value || "";
                            const partnerLogo     = document.getElementById("cs-partner-logo-url")?.value || "";

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
                            data.partner = {};
                        }

                        console.log("CS Wizard: LO mode - pageType:", pageType, "partner:", data.partner);
                    } else {
                        // Partner Mode: LO selection required
                        const partner = document.getElementById("cs-partner");

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

                        data.loanOfficer = data.partner;

                        const rememberCheckbox = document.getElementById("cs-remember-partner");
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
                                console.log("CS Wizard: Saved preferred LO:", res);
                            }).catch(err => {
                                console.error("CS Wizard: Failed to save preference:", err);
                            });
                        }

                        console.log("CS Wizard: Partner mode - LO selected:", data.partner);
                    }
                }
                if (step === 1) {
                    const typeRadio = wizard.querySelector("input[name=\"cs-spotlight-type\"]:checked");
                    if (!typeRadio) {
                        alert("Please select a spotlight type");
                        return false;
                    }
                    data.spotlightType = typeRadio.value;
                }
                if (step === 2) {
                    const headlineRadio = wizard.querySelector("input[name=\"cs-headline\"]:checked");
                    const headline = headlineRadio ? (headlineRadio.value === "custom" ? document.getElementById("cs-headline-custom").value : headlineRadio.value) : "";
                    if (!headline) {
                        alert("Please select or enter a headline");
                        return false;
                    }
                    data.customize = {
                        headline: headline,
                        subheadline: document.getElementById("cs-subheadline").value,
                        valueProps: document.getElementById("cs-value-props").value
                    };
                }
                if (step === 3) {
                    const heroImg = document.getElementById("cs-hero-image").value;
                    if (!heroImg) {
                        alert("Please select a hero image");
                        return false;
                    }
                    data.heroImage = heroImg;
                }
                if (step === 4) {
                    data.questions = {
                        comments: wizard.querySelector("[name=cs-q-comments]")?.checked || false,
                        agent: wizard.querySelector("[name=cs-q-agent]")?.checked || false,
                        preapproved: wizard.querySelector("[name=cs-q-preapproved]")?.checked || false,
                        interested: wizard.querySelector("[name=cs-q-interested]")?.checked || false,
                        timeline: wizard.querySelector("[name=cs-q-timeline]")?.checked || false,
                        pricerange: wizard.querySelector("[name=cs-q-pricerange]")?.checked || false
                    };
                }
                if (step === 5) {
                    // Collect branding based on user mode
                    if (isLoanOfficer) {
                        data.branding = {
                            loName: document.getElementById("cs-lo-name")?.value || "",
                            loNmls: document.getElementById("cs-lo-nmls")?.value || "",
                            loPhone: document.getElementById("cs-lo-phone")?.value || "",
                            loEmail: document.getElementById("cs-lo-email")?.value || "",
                            loPhoto: document.getElementById("cs-lo-photo-url")?.value || ""
                        };
                    } else {
                        data.branding = {
                            realtorName: document.getElementById("cs-realtor-name")?.value || "",
                            realtorLicense: document.getElementById("cs-realtor-license")?.value || "",
                            realtorPhone: document.getElementById("cs-realtor-phone")?.value || "",
                            realtorEmail: document.getElementById("cs-realtor-email")?.value || "",
                            realtorPhoto: document.getElementById("cs-realtor-photo-url")?.value || ""
                        };
                    }
                }
                return true;
            }

            function updatePartnerPreview() {
                const preview = document.getElementById("cs-partner-preview");
                const noPartnerMsg = document.getElementById("cs-no-partner-msg");

                if (data.partner && data.partner.name) {
                    // Show partner info
                    const subtitle = isLoanOfficer
                        ? (data.partner.company || data.partner.license ? `License# ${data.partner.license}` : "")
                        : (data.partner.nmls ? `NMLS# ${data.partner.nmls}` : "");

                    preview.innerHTML = `
                        <img src="${data.partner.photo || ""}" alt="">
                        <div class="cs-lo-preview__info">
                            <h4>${data.partner.name}</h4>
                            <p>${subtitle}</p>
                        </div>
                    `;
                } else if (noPartnerMsg) {
                    // Show no partner message (LO mode only)
                    preview.innerHTML = `<p style="color:#94a3b8;font-size:14px;margin:0;">No ${isLoanOfficer ? "realtor partner" : "loan officer"} selected (solo page)</p>`;
                }
            }

            function updateSummary() {
                const summary = document.getElementById("cs-summary");
                const partnerLabel = isLoanOfficer ? "Realtor Partner" : "Loan Officer";
                const partnerName = data.partner?.name || "None (solo page)";

                summary.innerHTML = `
                    <div class="cs-summary__row">
                        <span class="cs-summary__label">Spotlight Type</span>
                        <span class="cs-summary__value">${typeLabels[data.spotlightType] || data.spotlightType}</span>
                    </div>
                    <div class="cs-summary__row">
                        <span class="cs-summary__label">Headline</span>
                        <span class="cs-summary__value">${data.customize.headline}</span>
                    </div>
                    <div class="cs-summary__row">
                        <span class="cs-summary__label">${partnerLabel}</span>
                        <span class="cs-summary__value">${partnerName}</span>
                    </div>
                `;
            }

            nextBtn.addEventListener("click", function() {
                if (validateStep(currentStep)) {
                    currentStep++;
                    showStep(currentStep);
                }
            });

            backBtn.addEventListener("click", function() {
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

            // Publish
            publishBtn.addEventListener("click", async () => {
                publishBtn.querySelector(".cs-btn__text").style.display = "none";
                publishBtn.querySelector(".cs-btn__loading").style.display = "inline";

                try {
                    const response = await fetch("' . admin_url( 'admin-ajax.php' ) . '", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            action: "frs_create_spotlight",
                            nonce: "' . wp_create_nonce( 'frs_create_spotlight' ) . '",
                            data: JSON.stringify(data)
                        })
                    });
                    const result = await response.json();

                    if (result.success) {
                        document.getElementById("cs-success-type").textContent = typeLabels[data.spotlightType] + " Spotlight";
                        document.getElementById("cs-success-link").href = result.data.url;
                        document.getElementById("cs-copy-link").onclick = () => {
                            navigator.clipboard.writeText(result.data.url);
                            alert("Link copied!");
                        };
                        showStep("success");
                        wizard.querySelector(".cs-wizard__footer").style.display = "none";
                    } else {
                        alert(result.data || "Failed to create page");
                    }
                } catch (e) {
                    alert("An error occurred");
                }

                publishBtn.querySelector(".cs-btn__text").style.display = "inline";
                publishBtn.querySelector(".cs-btn__loading").style.display = "none";
            });

            // Image upload
            const imageUpload = document.getElementById("cs-image-upload");
            if (imageUpload) imageUpload.addEventListener("change", (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (ev) => {
                        document.getElementById("cs-hero-image").value = ev.target.result;
                        const grid = document.getElementById("cs-images-grid");
                        grid.querySelectorAll(".cs-image-option").forEach(o => o.classList.remove("cs-image-option--selected"));
                    };
                    reader.readAsDataURL(file);
                }
            });

            // Photo upload handlers
            function setupPhotoUpload(photoType) {
                // photoType: "lo" or "realtor"
                const uploadDiv = document.getElementById("cs-" + photoType + "-photo-upload");
                const fileInput = document.getElementById("cs-" + photoType + "-photo-file");
                const preview = document.getElementById("cs-" + photoType + "-photo-preview");
                const previewImg = document.getElementById("cs-" + photoType + "-photo-preview-img");
                const removeBtn = document.getElementById("cs-" + photoType + "-photo-remove");
                const photoUrlInput = document.getElementById("cs-" + photoType + "-photo-url");

                if (!uploadDiv || !fileInput) return;

                // Click to upload
                uploadDiv.addEventListener("click", () => fileInput.click());

                // Drag and drop
                uploadDiv.addEventListener("dragover", (e) => {
                    e.preventDefault();
                    uploadDiv.style.borderColor = "#10b981";
                    uploadDiv.style.backgroundColor = "rgba(16, 185, 129, 0.05)";
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
                const img = document.getElementById("cs-lo-photo-img");
                const btn = document.getElementById("cs-lo-photo-btn");
                const fileInput = document.getElementById("cs-lo-photo-file");
                const statusEl = document.getElementById("cs-lo-photo-status");
                const urlInput = document.getElementById("cs-lo-photo-url");
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
                const uploadDiv  = document.getElementById("cs-partner-logo-upload");
                const fileInput  = document.getElementById("cs-partner-logo-file");
                const preview    = document.getElementById("cs-partner-logo-preview");
                const previewImg = document.getElementById("cs-partner-logo-preview-img");
                const removeBtn  = document.getElementById("cs-partner-logo-remove");
                const urlInput   = document.getElementById("cs-partner-logo-url");
                if (!uploadDiv || !fileInput) return;

                uploadDiv.addEventListener("click", () => fileInput.click());
                uploadDiv.addEventListener("dragover", (e) => { e.preventDefault(); uploadDiv.style.borderColor = "#10b981"; });
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
        });
        </script>' . InstantImages::render_search_scripts( 'cs', 'cs-hero-image', 'cs-images-grid' );
    }

    /**
     * AJAX: Create spotlight page
     */
    public static function ajax_create_spotlight() {
        check_ajax_referer( 'frs_create_spotlight', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in' );
        }

        $data = json_decode( stripslashes( $_POST['data'] ?? '{}' ), true );

        if ( empty( $data['spotlightType'] ) ) {
            wp_send_json_error( 'Missing spotlight type' );
        }

        $type_labels = [
            'first_time_buyer' => 'First-Time Buyer',
            'move_up_buyer' => 'Move-Up Buyer',
            'downsizer' => 'Downsizer',
            'investor' => 'Investor',
            'relocating' => 'Relocating',
            'veteran' => 'Veteran',
        ];

        $type_label = $type_labels[ $data['spotlightType'] ] ?? ucwords( str_replace( '_', ' ', $data['spotlightType'] ) );

        // Create the landing page
        $page_id = wp_insert_post([
            'post_type'   => 'frs_lead_page',
            'post_title'  => 'Spotlight: ' . $type_label,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ]);

        if ( is_wp_error( $page_id ) ) {
            wp_send_json_error( $page_id->get_error_message() );
        }

        // Save common meta
        update_post_meta( $page_id, '_frs_page_type', 'customer_spotlight' );
        update_post_meta( $page_id, '_frs_spotlight_type', $data['spotlightType'] );
        update_post_meta( $page_id, '_frs_headline', $data['customize']['headline'] ?? '' );
        update_post_meta( $page_id, '_frs_subheadline', $data['customize']['subheadline'] ?? '' );
        update_post_meta( $page_id, '_frs_value_props', $data['customize']['valueProps'] ?? '' );
        update_post_meta( $page_id, '_frs_hero_image_url', $data['heroImage'] ?? '' );
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

            // LO partner (required for realtor mode)
            $lo_id = $data['partner']['id'] ?? '';
            if ( ! empty( $lo_id ) ) {
                update_post_meta( $page_id, '_frs_loan_officer_id', $lo_id );
                
                // LO Photo (new feature - custom photo upload for partner)
                if ( ! empty( $data['branding']['loPhoto'] ) ) {
                    update_post_meta( $page_id, '_frs_lo_photo', $data['branding']['loPhoto'] );
                }
            }
        }

        wp_send_json_success([
            'id'  => $page_id,
            'url' => get_permalink( $page_id ),
        ]);
    }

    /**
     * Render login required
     */
    private static function render_login_required(): string {
        return '<div class="cs-wizard" style="text-align:center;padding:48px;">
            <h2>Login Required</h2>
            <p>Please log in to create a Customer Spotlight page.</p>
            <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="cs-btn cs-btn--primary">Log In</a>
        </div>';
    }

    /**
     * Render access denied
     */
    private static function render_access_denied(): string {
        return '<div class="cs-wizard" style="text-align:center;padding:48px;">
            <h2>Access Denied</h2>
            <p>You do not have permission to create Customer Spotlight pages.</p>
        </div>';
    }

    /**
     * Enqueue modal assets
     */
    private static function enqueue_assets(): void {
        $base_url = plugins_url( 'includes/CustomerSpotlight/', FRS_LEAD_PAGES_PLUGIN_FILE );
        $version  = FRS_LEAD_PAGES_VERSION;

        wp_enqueue_style( 'frs-customer-spotlight-wizard', $base_url . 'style.css', [], $version );
        wp_enqueue_script( 'frs-customer-spotlight-wizard', $base_url . 'script.js', [], $version, true );

        wp_localize_script( 'frs-customer-spotlight-wizard', 'frsCustomerSpotlightWizard', [
            'triggerClass' => self::TRIGGER_CLASS,
            'triggerHash'  => self::TRIGGER_HASH,
        ] );
    }

    /**
     * Render modal-specific styles
     */
    private static function render_modal_styles(): string {
        self::enqueue_assets();
        return '';
    }

    /**
     * Render modal-specific scripts
     */
    private static function render_modal_scripts(): string {
        return '';
    }
}

<?php
/**
 * Special Event Wizard
 *
 * Multi-step wizard for creating Special Event landing pages.
 * Seminars, workshops, webinars, and networking events.
 *
 * @package FRSLeadPages
 */

namespace FRSLeadPages\SpecialEvent;

use FRSLeadPages\Core\LoanOfficers;
use FRSLeadPages\Core\Realtors;
use FRSLeadPages\Core\UserMode;
use FRSLeadPages\Integrations\InstantImages;

class Wizard {

    const TRIGGER_CLASS = 'se-wizard-trigger';
    const TRIGGER_HASH = 'special-event-wizard';

    public static function init() {
        add_shortcode( 'special_event_wizard', [ __CLASS__, 'render' ] );
        add_shortcode( 'special_event_wizard_button', [ __CLASS__, 'render_button' ] );
        add_action( 'wp_ajax_frs_create_event', [ __CLASS__, 'ajax_create_event' ] );
    }

    public static function render_button( array $atts = [] ): string {
        $atts = shortcode_atts([
            'text'  => 'Create Special Event',
            'class' => '',
        ], $atts, 'special_event_wizard_button' );

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

    private static function render_modal(): string {
        ob_start();
        ?>
        <div id="se-wizard-modal" class="se-modal">
            <div class="se-modal__backdrop"></div>
            <div class="se-modal__container">
                <button type="button" class="se-modal__close" aria-label="Close">
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

        $event_types = [
            'seminar' => [
                'label' => 'Homebuyer Seminar',
                'desc'  => 'Educational presentation',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
            ],
            'workshop' => [
                'label' => 'Workshop',
                'desc'  => 'Interactive learning session',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
            ],
            'webinar' => [
                'label' => 'Webinar',
                'desc'  => 'Online virtual event',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
            ],
            'happy_hour' => [
                'label' => 'Happy Hour',
                'desc'  => 'Casual networking event',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>',
            ],
            'networking' => [
                'label' => 'Networking Event',
                'desc'  => 'Meet industry professionals',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            ],
            'other' => [
                'label' => 'Other Event',
                'desc'  => 'Custom event type',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',
            ],
        ];

        ob_start();
        ?>
        <div id="se-wizard" class="se-wizard" data-user='<?php echo esc_attr( wp_json_encode( $user_data ) ); ?>'>
            <div class="se-wizard__hero">
                <div class="se-wizard__hero-content">
                    <h1>Create Your<br>Event Page</h1>
                    <p>Build a registration page for your seminar, workshop, or networking event.</p>
                </div>
            </div>

            <div class="se-wizard__form">
                <div class="se-wizard__progress">
                    <div class="se-wizard__progress-bar" style="width: 12.5%"></div>
                </div>

                <div class="se-wizard__header">
                    <p class="se-wizard__title">Special Event Wizard</p>
                    <p class="se-wizard__subtitle">Step <span id="se-step-num">1</span> of 9</p>
                </div>

                <div class="se-wizard__nav-top">
                    <button type="button" id="se-back-top" class="se-btn se-btn--ghost se-btn--sm" style="display:none;">Back</button>
                    <button type="button" id="se-next-top" class="se-btn se-btn--primary se-btn--sm">Continue</button>
                </div>

                <div class="se-wizard__content">
                <!-- Step 0: Page Type Selection -->
                <div class="se-step" data-step="0">
                    <div class="se-step__header">
                        <h2><?php echo $is_loan_officer ? 'What type of page?' : esc_html( $partner_config['title'] ); ?></h2>
                        <p><?php echo $is_loan_officer ? 'Choose how you want to brand this page' : esc_html( $partner_config['subtitle'] ); ?></p>
                    </div>
                    <div class="se-step__body">
                        <?php if ( $is_loan_officer ) : ?>
                            <input type="hidden" id="se-page-type" name="page_type" value="">
                            <input type="hidden" id="se-partner" name="partner" value="">

                            <div class="se-page-type-cards">
                                <div class="se-page-type-card" data-type="solo">
                                    <div class="se-page-type-card__icon">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                    </div>
                                    <h3>Solo Page</h3>
                                    <p>Just your branding</p>
                                </div>
                                <div class="se-page-type-card" data-type="cobranded">
                                    <div class="se-page-type-card__icon">
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

                            <div id="se-partner-selection" class="se-partner-selection" style="display: none;">
                                <p class="se-section-label" style="margin-top:24px;">Partner Real Estate Agent</p>
                                <div class="se-row">
                                    <div class="se-field se-field--half">
                                        <label class="se-label">Partner Name</label>
                                        <input type="text" id="se-partner-name-input" class="se-input" placeholder="Jane Smith">
                                    </div>
                                    <div class="se-field se-field--half">
                                        <label class="se-label">Phone</label>
                                        <input type="tel" id="se-partner-phone-input" class="se-input" placeholder="(555) 123-4567">
                                    </div>
                                </div>
                                <div class="se-field">
                                    <label class="se-label">Email</label>
                                    <input type="email" id="se-partner-email-input" class="se-input" placeholder="jane@realestate.com">
                                </div>

                                <!-- Partner Headshot Upload -->
                                <div class="se-field" style="margin-top: 24px;">
                                    <label class="se-label">Partner Headshot (optional)</label>
                                    <div class="se-photo-upload" id="se-partner-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="se-partner-photo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="se-partner-photo-preview" style="margin-top: 12px; display: none;">
                                        <img id="se-partner-photo-preview-img" src="" alt="Headshot preview" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                                        <button type="button" id="se-partner-photo-remove" class="se-btn se-btn--ghost se-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="se-partner-photo-url" value="">
                                </div>

                                <!-- Company Logo Upload -->
                                <div class="se-field" style="margin-top: 16px;">
                                    <label class="se-label">Company Logo (optional)</label>
                                    <div class="se-photo-upload" id="se-partner-logo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                        <input type="file" id="se-partner-logo-file" accept="image/*" style="display: none;">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                    </div>
                                    <div id="se-partner-logo-preview" style="margin-top: 12px; display: none;">
                                        <img id="se-partner-logo-preview-img" src="" alt="Logo preview" style="width: 120px; height: 120px; border-radius: 8px; object-fit: contain; background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0;">
                                        <button type="button" id="se-partner-logo-remove" class="se-btn se-btn--ghost se-btn--sm" style="margin-left: 12px;">Remove</button>
                                    </div>
                                    <input type="hidden" id="se-partner-logo-url" value="">
                                </div>
                                <p class="se-helper">Both images are optional — add whichever you want on the landing page</p>
                            </div>
                        <?php else : ?>
                            <label class="se-label"><?php echo esc_html( $partner_config['label'] ); ?></label>
                            <div class="se-dropdown" id="se-partner-dropdown" data-mode="<?php echo esc_attr( $user_mode ); ?>" data-required="true" data-preferred="<?php echo esc_attr( $partner_config['preferred_id'] ?? 0 ); ?>">
                                <input type="hidden" id="se-partner" name="partner" value="">
                                <button type="button" class="se-dropdown__trigger">
                                    <span class="se-dropdown__value"><?php echo esc_html( $partner_config['placeholder'] ); ?></span>
                                    <svg class="se-dropdown__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <div class="se-dropdown__menu">
                                    <?php foreach ( $partners as $partner ) : ?>
                                        <?php
                                        $partner_id = $partner['user_id'] ?? $partner['id'];
                                        $partner_name = $partner['name'];
                                        $partner_photo = $partner['photo_url'] ?? '';
                                        $partner_nmls = $partner['nmls'] ?? '';
                                        $is_preferred = ( (int) $partner_id === (int) ( $partner_config['preferred_id'] ?? 0 ) );
                                        ?>
                                        <div class="se-dropdown__item<?php echo $is_preferred ? ' se-dropdown__item--preferred' : ''; ?>"
                                             data-value="<?php echo esc_attr( $partner_id ); ?>"
                                             data-name="<?php echo esc_attr( $partner_name ); ?>"
                                             data-nmls="<?php echo esc_attr( $partner_nmls ); ?>"
                                             data-photo="<?php echo esc_attr( $partner_photo ); ?>">
                                            <img src="<?php echo esc_url( $partner_photo ); ?>" alt="" class="se-dropdown__photo">
                                            <div class="se-dropdown__info">
                                                <span class="se-dropdown__name"><?php echo esc_html( $partner_name ); ?></span>
                                                <?php if ( $partner_nmls ) : ?>
                                                    <span class="se-dropdown__nmls">NMLS# <?php echo esc_html( $partner_nmls ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( $is_preferred ) : ?>
                                                <span class="se-dropdown__preferred-badge">★ Preferred</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p class="se-helper"><?php echo esc_html( $partner_config['helper'] ); ?></p>

                            <?php if ( $partner_config['show_remember'] ?? false ) : ?>
                                <label class="se-checkbox" style="margin-top: 12px;">
                                    <input type="checkbox" id="se-remember-partner" name="remember_partner" value="1">
                                    <span class="se-checkbox__label">Remember my choice for next time</span>
                                </label>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 1: Event Type -->
                <div class="se-step" data-step="1" style="display:none;">
                    <div class="se-step__header">
                        <h2>What Type of Event?</h2>
                        <p>Select the event format</p>
                    </div>
                    <div class="se-step__body">
                        <div class="se-type-grid">
                            <?php foreach ( $event_types as $key => $type ) : ?>
                                <label class="se-type-card">
                                    <input type="radio" name="se-event-type" value="<?php echo esc_attr( $key ); ?>">
                                    <div class="se-type-card__content">
                                        <div class="se-type-card__icon"><?php echo $type['icon']; ?></div>
                                        <div class="se-type-card__text">
                                            <strong><?php echo esc_html( $type['label'] ); ?></strong>
                                            <span><?php echo esc_html( $type['desc'] ); ?></span>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Event Details -->
                <div class="se-step" data-step="2" style="display:none;">
                    <div class="se-step__header">
                        <h2>Event Details</h2>
                        <p>Tell us about your event</p>
                    </div>
                    <div class="se-step__body">
                        <div class="se-field">
                            <label class="se-label">Event Name</label>
                            <input type="text" id="se-event-name" class="se-input" placeholder="First-Time Homebuyer Seminar">
                        </div>
                        <div class="se-row">
                            <div class="se-field se-field--half">
                                <label class="se-label">Date</label>
                                <input type="date" id="se-event-date" class="se-input">
                            </div>
                            <div class="se-field se-field--half">
                                <label class="se-label">Time</label>
                                <input type="time" id="se-event-time" class="se-input">
                            </div>
                        </div>
                        <div class="se-field">
                            <label class="se-label">Venue Name</label>
                            <input type="text" id="se-venue-name" class="se-input" placeholder="Community Center">
                        </div>
                        <div class="se-field">
                            <label class="se-label">Address</label>
                            <input type="text" id="se-venue-address" class="se-input" placeholder="123 Main St, City, CA 90210">
                        </div>
                    </div>
                </div>

                <!-- Step 3: Customize -->
                <div class="se-step" data-step="3" style="display:none;">
                    <div class="se-step__header">
                        <h2>Customize Your Page</h2>
                        <p>Create compelling messaging</p>
                    </div>
                    <div class="se-step__body">
                        <div class="se-field">
                            <label class="se-label">Headline</label>
                            <div id="se-headline-options" class="se-radio-group"></div>
                            <input type="text" id="se-headline-custom" class="se-input" placeholder="Enter custom headline" style="display:none; margin-top:12px;">
                        </div>
                        <div class="se-field">
                            <label class="se-label">Description</label>
                            <textarea id="se-description" class="se-textarea" rows="3" placeholder="Join us for an informative session about..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Hero Image -->
                <div class="se-step" data-step="4" style="display:none;">
                    <div class="se-step__header">
                        <h2>Choose Your Photo</h2>
                        <p>Select an image for your event</p>
                    </div>
                    <div class="se-step__body">
                        <div id="se-images-grid" class="se-images-grid"></div>
                        <div class="se-upload-section">
                            <p>Or find the perfect stock photo:</p>
                            <?php echo InstantImages::render_search_button( 'se', '#1e293b' ); ?>
                            <p style="margin-top: 16px;">Or upload your own:</p>
                            <input type="file" id="se-image-upload" accept="image/*" class="se-file-input">
                            <label for="se-image-upload" class="se-btn se-btn--secondary">Upload Image</label>
                        </div>
                        <input type="hidden" id="se-hero-image" value="">
                        <?php echo InstantImages::render_search_modal( 'se', 'se-hero-image' ); ?>
                    </div>
                </div>

                <!-- Step 5: Contact Fields -->
                <div class="se-step" data-step="5" style="display:none;">
                    <div class="se-step__header">
                        <h2>Contact Fields</h2>
                        <p>Required info from attendees</p>
                    </div>
                    <div class="se-step__body">
                        <div class="se-toggle-list">
                            <label class="se-toggle">
                                <input type="checkbox" checked disabled> Full Name <span class="se-toggle__required">Required</span>
                            </label>
                            <label class="se-toggle">
                                <input type="checkbox" checked disabled> Email <span class="se-toggle__required">Required</span>
                            </label>
                            <label class="se-toggle">
                                <input type="checkbox" checked disabled> Phone <span class="se-toggle__required">Required</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Step 6: Qualifying Questions -->
                <div class="se-step" data-step="6" style="display:none;">
                    <div class="se-step__header">
                        <h2>Qualifying Questions</h2>
                        <p>Optional questions to qualify leads</p>
                    </div>
                    <div class="se-step__body">
                        <div class="se-toggle-list">
                            <label class="se-toggle">
                                <input type="checkbox" name="se-q-agent" checked> Working with an agent?
                            </label>
                            <label class="se-toggle">
                                <input type="checkbox" name="se-q-preapproved" checked> Pre-approved?
                            </label>
                            <label class="se-toggle">
                                <input type="checkbox" name="se-q-timeline" checked> Buying timeline
                            </label>
                            <label class="se-toggle">
                                <input type="checkbox" name="se-q-firsttime"> First-time buyer?
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Step 7: Branding (bi-directional) -->
                <div class="se-step" data-step="7" style="display:none;">
                    <div class="se-step__header">
                        <h2>Your Team Info</h2>
                        <p>Confirm your contact details</p>
                    </div>
                    <div class="se-step__body">
                        <p class="se-section-label">Your Information</p>
                        <?php if ( $is_loan_officer ) : ?>
                            <!-- LO Mode: Show LO fields -->
                            <div class="se-row">
                                <div class="se-field se-field--half">
                                    <label class="se-label">Your Name</label>
                                    <input type="text" id="se-lo-name" class="se-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                                </div>
                                <div class="se-field se-field--half">
                                    <label class="se-label">NMLS #</label>
                                    <input type="text" id="se-lo-nmls" class="se-input" value="<?php echo esc_attr( $user_data['nmls'] ?? '' ); ?>">
                                </div>
                            </div>
                            <div class="se-row">
                                <div class="se-field se-field--half">
                                    <label class="se-label">Phone</label>
                                    <input type="tel" id="se-lo-phone" class="se-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                                </div>
                                <div class="se-field se-field--half">
                                    <label class="se-label">Email</label>
                                    <input type="email" id="se-lo-email" class="se-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                                </div>
                            </div>
                            <div class="se-field" style="margin-top: 24px;">
                                <label class="se-label">Your Photo (Optional)</label>
                                <div class="se-photo-upload" id="se-lo-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                    <input type="file" id="se-lo-photo-file" accept="image/*" style="display: none;">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                    <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                </div>
                                <div id="se-lo-photo-preview" style="margin-top: 12px; display: none;">
                                    <img id="se-lo-photo-preview-img" src="" alt="Preview" style="width: 100px; height: 100px; border-radius: 8px; object-fit: cover;">
                                    <button type="button" id="se-lo-photo-remove" class="se-btn se-btn--ghost se-btn--sm" style="margin-left: 12px;">Remove</button>
                                </div>
                                <input type="hidden" id="se-lo-photo-url" value="">
                            </div>

                            <p class="se-section-label" style="margin-top:24px;">Realtor Partner (from Step 1)</p>
                            <div id="se-partner-preview" class="se-lo-preview">
                                <p style="color:#94a3b8;font-size:14px;margin:0;" id="se-no-partner-msg">No realtor partner selected (solo page)</p>
                            </div>
                        <?php else : ?>
                            <!-- Realtor Mode: Show Realtor fields -->
                            <div class="se-row">
                                <div class="se-field se-field--half">
                                    <label class="se-label">Your Name</label>
                                    <input type="text" id="se-realtor-name" class="se-input" value="<?php echo esc_attr( $user_data['name'] ); ?>">
                                </div>
                                <div class="se-field se-field--half">
                                    <label class="se-label">License #</label>
                                    <input type="text" id="se-realtor-license" class="se-input" value="<?php echo esc_attr( $user_data['license'] ?? '' ); ?>">
                                </div>
                            </div>
                            <div class="se-row">
                                <div class="se-field se-field--half">
                                    <label class="se-label">Phone</label>
                                    <input type="tel" id="se-realtor-phone" class="se-input" value="<?php echo esc_attr( $user_data['phone'] ); ?>">
                                </div>
                                <div class="se-field se-field--half">
                                    <label class="se-label">Email</label>
                                    <input type="email" id="se-realtor-email" class="se-input" value="<?php echo esc_attr( $user_data['email'] ); ?>">
                                </div>
                            </div>
                            <div class="se-field" style="margin-top: 24px;">
                                <label class="se-label">Your Photo (Optional)</label>
                                <div class="se-photo-upload" id="se-realtor-photo-upload" style="border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; text-align: center; cursor: pointer;">
                                    <input type="file" id="se-realtor-photo-file" accept="image/*" style="display: none;">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin: 0 auto 8px; opacity: 0.5;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    <p style="margin: 0; font-weight: 500;">Click to upload or drag and drop</p>
                                    <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">PNG, JPG or GIF (max 5MB)</p>
                                </div>
                                <div id="se-realtor-photo-preview" style="margin-top: 12px; display: none;">
                                    <img id="se-realtor-photo-preview-img" src="" alt="Preview" style="width: 100px; height: 100px; border-radius: 8px; object-fit: cover;">
                                    <button type="button" id="se-realtor-photo-remove" class="se-btn se-btn--ghost se-btn--sm" style="margin-left: 12px;">Remove</button>
                                </div>
                                <input type="hidden" id="se-realtor-photo-url" value="">
                            </div>

                            <p class="se-section-label" style="margin-top:24px;">Loan Officer (from Step 1)</p>
                            <div id="se-partner-preview" class="se-lo-preview">
                                <!-- Populated by JS -->
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 8: Preview & Publish -->
                <div class="se-step" data-step="8" style="display:none;">
                    <div class="se-step__header">
                        <h2>Review & Publish</h2>
                        <p>Everything looks good? Let's make it live.</p>
                    </div>
                    <div class="se-step__body">
                        <div id="se-summary" class="se-summary"></div>
                    </div>
                </div>

                <!-- Success State -->
                <div class="se-step se-step--success" data-step="success" style="display:none;">
                    <div class="se-success">
                        <div class="se-success__icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <h2>Your Event Page is Live!</h2>
                        <p id="se-success-name"></p>
                        <div class="se-success__actions">
                            <a id="se-success-link" href="#" class="se-btn se-btn--primary" target="_blank">View Page</a>
                            <button type="button" id="se-copy-link" class="se-btn se-btn--secondary">Copy Link</button>
                        </div>
                        <a href="#" class="se-link" onclick="location.reload()">Create Another</a>
                    </div>
                </div>

                </div>

                <div class="se-wizard__footer">
                    <button type="button" id="se-back" class="se-btn se-btn--ghost" style="display:none;">Back</button>
                    <button type="button" id="se-next" class="se-btn se-btn--primary">Continue</button>
                    <button type="button" id="se-publish" class="se-btn se-btn--primary" style="display:none;">
                        <span class="se-btn__text">Publish Event</span>
                        <span class="se-btn__loading" style="display:none;">Creating...</span>
                    </button>
                </div>
            </div>
        </div>

        <?php echo self::render_styles(); ?>
        <?php echo self::render_scripts(); ?>
        <?php
        return ob_get_clean();
    }

    private static function render_styles(): string {
        return '
        <style>
            .se-wizard { display: flex; min-height: 100dvh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .se-wizard__hero { width: 60%; height: 100dvh; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); display: flex; flex-direction: column; justify-content: center; padding: 64px; position: fixed; left: 0; top: 0; overflow: hidden; }
            .se-wizard__hero::before { content: ""; position: absolute; top: -50%; right: -50%; width: 100%; height: 100%; background: radial-gradient(circle, rgba(45,212,218,0.15) 0%, transparent 70%); pointer-events: none; }
            .se-wizard__hero-content { position: relative; z-index: 1; }
            .se-wizard__hero h1 { font-size: 48px; font-weight: 700; color: #fff; margin: 0 0 16px; line-height: 1.1; }
            .se-wizard__hero p { font-size: 18px; color: rgba(255,255,255,0.9); margin: 0; max-width: 400px; }
            .se-wizard__form { position: fixed; left: 60%; right: 0; top: 0; bottom: 0; height: 100dvh; overflow-y: auto; background: #fff; padding: 48px 56px; box-sizing: border-box; }
            .se-wizard__progress { height: 3px; background: #e5e7eb; margin-bottom: 40px; }
            .se-wizard__progress-bar { height: 100%; background: #1e293b; transition: width 0.3s ease; }
            .se-wizard__header { margin-bottom: 8px; }
            .se-wizard__title { font-size: 12px; font-weight: 600; color: #1e293b; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.1em; }
            .se-wizard__subtitle { font-size: 13px; color: #94a3b8; margin: 0; }
            .se-wizard__nav-top { display: flex; gap: 12px; justify-content: flex-end; margin-bottom: 16px; }
            .se-btn--sm { padding: 8px 16px; font-size: 13px; }
            .se-wizard__content { }
            .se-step { display: flex; flex-direction: column; }
            .se-step__body { padding-right: 8px; }
            .se-step__header h2 { font-size: 32px; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
            .se-step__header p { font-size: 15px; color: #64748b; margin: 0 0 32px; }
            .se-label { display: block !important; font-size: 15px !important; font-weight: 600 !important; color: #374151 !important; margin-bottom: 12px !important; }
            #se-wizard .se-input, #se-wizard input[type="text"], #se-wizard input[type="email"], #se-wizard input[type="tel"], #se-wizard input[type="date"], #se-wizard input[type="time"], #se-wizard textarea { width: 100%; padding: 18px 20px; font-size: 16px; border: 2px solid #e5e7eb; border-radius: 10px; background-color: #fff; box-sizing: border-box; min-height: 56px; }
            .se-textarea { min-height: 100px; resize: vertical; }
            .se-input:focus, .se-textarea:focus, #se-wizard input:focus, #se-wizard select:focus, #se-wizard textarea:focus { outline: none; border: 2px solid #1e293b !important; border-bottom: 2px solid #1e293b !important; box-shadow: 0 0 0 4px rgba(45,212,218,0.15); }
            .se-dropdown { position: relative; width: 100%; }
            .se-dropdown__trigger { width: 100%; height: 60px; padding: 0 20px; font-size: 16px; border: 2px solid #e5e7eb; border-radius: 10px; background-color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: space-between; text-align: left; color: #374151; }
            .se-dropdown.open .se-dropdown__trigger { border-color: #1e293b; box-shadow: 0 0 0 4px rgba(45,212,218,0.15); }
            .se-dropdown__menu { position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; background: #fff; border: 2px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); max-height: 300px; overflow-y: auto; z-index: 100; display: none; }
            .se-dropdown.open .se-dropdown__menu { display: block; }
            .se-dropdown__item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; transition: background 0.15s; }
            .se-dropdown__item:hover { background: #f3f4f6; }
            .se-dropdown__item.selected { background: #f8fafc; }
            .se-dropdown__photo { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
            .se-dropdown__name { font-size: 15px; font-weight: 600; color: #1f2937; display: block; }
            .se-dropdown__nmls { font-size: 13px; color: #6b7280; }
            .se-dropdown__item--preferred { background: #f1f5f9; border-left: 3px solid #1e293b; }
            .se-dropdown__item--preferred:hover { background: #a5f3fc; }
            .se-dropdown__preferred-badge { margin-left: auto; font-size: 11px; font-weight: 600; color: #0891b2; background: #f1f5f9; padding: 2px 8px; border-radius: 10px; }
            .se-checkbox { display: flex; align-items: center; gap: 8px; cursor: pointer; }
            .se-checkbox input[type="checkbox"] { width: 18px; height: 18px; accent-color: #1e293b; cursor: pointer; }
            .se-checkbox__label { font-size: 14px; color: #6b7280; }
            .se-type-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; align-items: stretch; }
            .se-type-card { cursor: pointer; height: 100%; }
            .se-type-card input { position: absolute; opacity: 0; }
            .se-type-card__content { display: flex; align-items: center; gap: 16px; padding: 20px; background: #fff; border: 2px solid #e5e7eb; border-radius: 12px; transition: all 0.2s; height: 100%; box-sizing: border-box; }
            .se-type-card:hover .se-type-card__content { border-color: #1e293b; background: #f8fafc; }
            .se-type-card input:checked + .se-type-card__content { border-color: #1e293b; background: #f1f5f9; box-shadow: 0 0 0 4px rgba(45,212,218,0.15); }
            .se-type-card__icon { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; border-radius: 12px; flex-shrink: 0; }
            .se-type-card__icon svg { width: 24px; height: 24px; stroke: #0f172a; }
            .se-type-card input:checked + .se-type-card__content .se-type-card__icon { background: #1e293b; }
            .se-type-card input:checked + .se-type-card__content .se-type-card__icon svg { stroke: #fff; }
            .se-type-card__text { display: flex; flex-direction: column; gap: 2px; }
            .se-type-card__text strong { font-size: 15px; font-weight: 600; color: #1f2937; }
            .se-type-card__text span { font-size: 13px; color: #6b7280; }
            .se-radio-group { display: flex; flex-wrap: wrap; gap: 10px; }
            .se-radio-btn { position: relative; cursor: pointer; }
            .se-radio-btn input { position: absolute; opacity: 0; }
            .se-radio-btn__label { display: inline-block; padding: 14px 20px; font-size: 15px; font-weight: 500; color: #374151; background: #fff; border: 2px solid #e5e7eb; border-radius: 10px; transition: all 0.15s ease; cursor: pointer; }
            .se-radio-btn input:checked + .se-radio-btn__label { background: #1e293b; border-color: #1e293b; color: #fff; }
            .se-images-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
            .se-image-option { aspect-ratio: 4/3; border-radius: 12px; overflow: hidden; cursor: pointer; border: 3px solid transparent; transition: all 0.2s; }
            .se-image-option img { width: 100%; height: 100%; object-fit: cover; }
            .se-image-option--selected { border-color: #1e293b; box-shadow: 0 0 0 4px rgba(45,212,218,0.2); }
            .se-upload-section { text-align: center; padding: 24px; background: #f8fafc; border-radius: 12px; border: 2px dashed #cbd5e1; }
            .se-upload-section p { margin: 0 0 12px; color: #64748b; }
            .se-file-input { display: none; }
            .se-toggle-list { display: flex; flex-direction: column; gap: 12px; }
            .se-toggle { display: flex; align-items: center; gap: 14px; font-size: 15px; color: #374151; cursor: pointer; padding: 12px 16px; background: #f8fafc; border-radius: 10px; }
            .se-toggle input { width: 20px; height: 20px; accent-color: #1e293b; }
            .se-toggle__required { font-size: 11px; font-weight: 600; color: #94a3b8; margin-left: auto; text-transform: uppercase; }
            .se-section-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em; margin: 0 0 16px; }
            .se-field { margin-bottom: 24px !important; }
            .se-row { display: flex; gap: 20px; }
            .se-field--half { flex: 1; }
            .se-lo-preview { display: flex; align-items: center; gap: 16px; padding: 20px; background: #f8fafc; border-radius: 12px; }
            .se-lo-preview img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; }
            .se-lo-preview__info h4 { font-size: 17px; font-weight: 600; color: #0f172a; margin: 0 0 4px; }
            .se-lo-preview__info p { font-size: 14px; color: #64748b; margin: 0; }
            .se-summary { background: #f8fafc; border-radius: 16px; padding: 28px; }
            .se-summary__row { display: flex; justify-content: space-between; padding: 14px 0; border-bottom: 1px solid #e5e7eb; }
            .se-summary__row:last-child { border-bottom: none; }
            .se-summary__label { font-size: 14px; color: #64748b; }
            .se-summary__value { font-size: 14px; font-weight: 600; color: #0f172a; }
            .se-btn { display: inline-flex; align-items: center; justify-content: center; padding: 18px 36px; font-size: 17px; font-weight: 600; border-radius: 12px; border: none; cursor: pointer; transition: all 0.2s; }
            .se-btn--primary { background: #1e293b; color: #fff; }
            .se-btn--primary:hover { background: #0f172a; }
            .se-btn--secondary { background: #f1f5f9; color: #0f172a; }
            .se-btn--ghost { background: transparent; color: #64748b; }
            .se-wizard__footer { display: flex; justify-content: space-between; padding: 24px 0; margin-top: auto; border-top: 0; flex-shrink: 0; background: #fff; }
            .se-success { text-align: center; padding: 48px 24px; }
            .se-success__icon { width: 88px; height: 88px; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 28px; box-shadow: 0 8px 24px rgba(45,212,218,0.3); }
            .se-success h2 { font-size: 28px; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
            .se-success p { font-size: 16px; color: #64748b; margin: 0 0 28px; }
            .se-success__actions { display: flex; gap: 12px; justify-content: center; margin-bottom: 24px; }
            .se-link { font-size: 14px; color: #64748b; text-decoration: none; }

            /* Page Type Cards (LO mode) */
            .se-page-type-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 8px; }
            .se-page-type-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 24px 16px; text-align: center; cursor: pointer; transition: all 0.2s ease; background: #fff; }
            .se-page-type-card:hover { border-color: #334155; background: #f8fafc; }
            .se-page-type-card.selected { border-color: #1e293b; background: #f1f5f9; box-shadow: 0 0 0 4px rgba(45,212,218,0.15); }
            .se-page-type-card__icon { width: 64px; height: 64px; margin: 0 auto 12px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; border-radius: 50%; }
            .se-page-type-card__icon svg { stroke: #0f172a; }
            .se-page-type-card.selected .se-page-type-card__icon { background: #1e293b; }
            .se-page-type-card.selected .se-page-type-card__icon svg { stroke: #fff; }
            .se-page-type-card h3 { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 4px; }
            .se-page-type-card p { font-size: 13px; color: #64748b; margin: 0; }
            .se-partner-selection { margin-top: 16px; }

            @media (max-width: 1024px) {
                .se-wizard { flex-direction: column; height: auto; min-height: 100dvh; }
                .se-wizard__hero { width: 100%; height: auto; position: relative; padding: 48px 32px; }
                .se-wizard__hero h1 { font-size: 32px; }
                .se-wizard__form { position: relative; left: auto; right: auto; top: auto; bottom: auto; width: 100%; height: auto; }
                .se-type-grid { grid-template-columns: 1fr; }
            }
            @media (max-width: 640px) {
                .se-row { flex-direction: column; gap: 0; }
                .se-images-grid { grid-template-columns: repeat(2, 1fr); }
            }
        </style>' . InstantImages::render_search_styles( 'se', '#1e293b' );
    }

    private static function render_scripts(): string {
        $headline_options = [
            'seminar' => ['Join Our Free Homebuyer Seminar', 'Learn How to Buy Your First Home', 'Your Path to Homeownership Starts Here'],
            'workshop' => ['Hands-On Workshop', 'Learn & Grow Together', 'Interactive Learning Experience'],
            'webinar' => ['Join Our Free Webinar', 'Learn From Home', 'Register for Our Online Event'],
            'happy_hour' => ['Join Us for Happy Hour', 'Networking & Refreshments', 'Mix & Mingle With Us'],
            'networking' => ['Connect With Industry Pros', 'Expand Your Network', 'Meet Your Next Connection'],
            'other' => ['Join Our Event', 'You\'re Invited', 'Save Your Spot'],
        ];

        $stock_images = [
            'seminar' => [
                'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1475721027785-f74eccf877e2?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1591115765373-5207764f72e4?w=600&h=400&fit=crop',
            ],
            'workshop' => [
                'https://images.unsplash.com/photo-1552664730-d307ca884978?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1517048676732-d65bc937f952?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1542744173-8e7e53415bb0?w=600&h=400&fit=crop',
            ],
            'webinar' => [
                'https://images.unsplash.com/photo-1588196749597-9ff075ee6b5b?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1609234656388-0ff363383899?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=600&h=400&fit=crop',
            ],
            'happy_hour' => [
                'https://images.unsplash.com/photo-1575444758702-4a6b9222336e?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1551024709-8f23befc6f87?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1470337458703-46ad1756a187?w=600&h=400&fit=crop',
            ],
            'networking' => [
                'https://images.unsplash.com/photo-1515187029135-18ee286d815b?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1528605248644-14dd04022da1?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1559136555-9303baea8ebd?w=600&h=400&fit=crop',
            ],
            'other' => [
                'https://images.unsplash.com/photo-1505373877841-8d25f7d46678?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1511578314322-379afb476865?w=600&h=400&fit=crop',
                'https://images.unsplash.com/photo-1464047736614-af63643285bf?w=600&h=400&fit=crop',
            ],
        ];

        $type_labels = [
            'seminar' => 'Homebuyer Seminar',
            'workshop' => 'Workshop',
            'webinar' => 'Webinar',
            'happy_hour' => 'Happy Hour',
            'networking' => 'Networking Event',
            'other' => 'Event',
        ];

        return '
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const wizard = document.getElementById("se-wizard");
            if (!wizard) return;

            const headlineOptions = ' . wp_json_encode( $headline_options ) . ';
            const stockImages = ' . wp_json_encode( $stock_images ) . ';
            const typeLabels = ' . wp_json_encode( $type_labels ) . ';

            const steps = wizard.querySelectorAll(".se-step[data-step]");
            const progressBar = wizard.querySelector(".se-wizard__progress-bar");
            const stepNum = document.getElementById("se-step-num");
            const backBtn = document.getElementById("se-back");
            const nextBtn = document.getElementById("se-next");
            const publishBtn = document.getElementById("se-publish");
            const backBtnTop = document.getElementById("se-back-top");
            const nextBtnTop = document.getElementById("se-next-top");

            let currentStep = 0;
            const userData = JSON.parse(wizard.dataset.user || "{}");
            const userMode = userData.mode || "realtor";
            const isLoanOfficer = userMode === "loan_officer";

            let data = { userMode: userMode, partner: {}, eventType: "", eventDetails: {}, customize: {}, questions: {}, branding: {} };

            // Page type card selection (LO mode)
            const pageTypeCards = wizard.querySelectorAll(".se-page-type-card");
            const pageTypeInput = document.getElementById("se-page-type");
            const partnerSelection = document.getElementById("se-partner-selection");
            const partnerInput = document.getElementById("se-partner");

            if (pageTypeCards.length > 0 && isLoanOfficer) {
                pageTypeCards.forEach(card => {
                    card.addEventListener("click", () => {
                        // Deselect all cards
                        pageTypeCards.forEach(c => c.classList.remove("selected"));
                        // Select clicked card
                        card.classList.add("selected");
                        const pageType = card.dataset.type;
                        if (pageTypeInput) pageTypeInput.value = pageType;

                        // Show/hide partner selection
                        if (partnerSelection) {
                            if (pageType === "cobranded") {
                                partnerSelection.style.display = "block";
                            } else {
                                partnerSelection.style.display = "none";
                                // Clear partner selection for solo pages
                                if (partnerInput) partnerInput.value = "";
                                data.partner = {};
                                const dropdownValue = wizard.querySelector("#se-partner-dropdown .se-dropdown__value");
                                if (dropdownValue) dropdownValue.textContent = "Choose a partner...";
                                wizard.querySelectorAll("#se-partner-dropdown .se-dropdown__item").forEach(i => i.classList.remove("selected"));
                            }
                        }
                    });
                });
            }

            // Skip partner button (LO mode only)
            const skipPartnerBtn = document.getElementById("se-skip-partner");
            if (skipPartnerBtn) {
                skipPartnerBtn.addEventListener("click", () => {
                    data.partner = {}; // Clear partner
                    currentStep++;
                    showStep(currentStep);
                });
            }

            // Dropdown
            document.querySelectorAll(".se-dropdown").forEach(dropdown => {
                const trigger = dropdown.querySelector(".se-dropdown__trigger");
                const menu = dropdown.querySelector(".se-dropdown__menu");
                const items = dropdown.querySelectorAll(".se-dropdown__item");
                const hiddenInput = dropdown.querySelector("input[type=hidden]");
                const valueDisplay = dropdown.querySelector(".se-dropdown__value");

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
                        valueDisplay.textContent = item.querySelector(".se-dropdown__name").textContent;
                        dropdown.classList.remove("open");
                        Object.keys(item.dataset).forEach(k => hiddenInput.dataset[k] = item.dataset[k]);
                    });
                });
            });

            document.addEventListener("click", () => {
                document.querySelectorAll(".se-dropdown.open").forEach(d => d.classList.remove("open"));
            });

            // Auto-select preferred partner if set
            const partnerDropdown = document.getElementById("se-partner-dropdown");
            if (partnerDropdown) {
                const preferredId = partnerDropdown.dataset.preferred;
                if (preferredId && preferredId !== "0") {
                    const preferredItem = partnerDropdown.querySelector(`.se-dropdown__item[data-value="${preferredId}"]`);
                    if (preferredItem) {
                        preferredItem.click();
                        console.log("SE Wizard: Auto-selected preferred partner ID:", preferredId);
                    }
                }
            }

            wizard.querySelectorAll("input[name=\"se-event-type\"]").forEach(radio => {
                radio.addEventListener("change", function() {
                    data.eventType = this.value;
                    updateHeadlineOptions(this.value);
                    updateStockImages(this.value);
                });
            });

            function updateHeadlineOptions(type) {
                const container = document.getElementById("se-headline-options");
                const options = headlineOptions[type] || headlineOptions.other;
                container.innerHTML = options.map((opt, i) => `
                    <label class="se-radio-btn">
                        <input type="radio" name="se-headline" value="${opt}" ${i === 0 ? "checked" : ""}>
                        <span class="se-radio-btn__label">${opt}</span>
                    </label>
                `).join("") + `
                    <label class="se-radio-btn">
                        <input type="radio" name="se-headline" value="custom">
                        <span class="se-radio-btn__label">Custom...</span>
                    </label>
                `;
                container.querySelectorAll("input").forEach(radio => {
                    radio.addEventListener("change", function() {
                        document.getElementById("se-headline-custom").style.display = this.value === "custom" ? "block" : "none";
                    });
                });
            }

            function updateStockImages(type) {
                const grid = document.getElementById("se-images-grid");
                const images = stockImages[type] || stockImages.other;
                grid.innerHTML = images.map((img, i) => `
                    <div class="se-image-option ${i === 0 ? "se-image-option--selected" : ""}" data-url="${img}">
                        <img src="${img}" alt="">
                    </div>
                `).join("");
                document.getElementById("se-hero-image").value = images[0];
                grid.querySelectorAll(".se-image-option").forEach(opt => {
                    opt.addEventListener("click", () => {
                        grid.querySelectorAll(".se-image-option").forEach(o => o.classList.remove("se-image-option--selected"));
                        opt.classList.add("se-image-option--selected");
                        document.getElementById("se-hero-image").value = opt.dataset.url;
                    });
                });
            }

            function showStep(step) {
                steps.forEach(s => s.style.display = "none");
                const target = wizard.querySelector(`[data-step="${step}"]`);
                if (target) target.style.display = "flex";
                progressBar.style.width = ((step + 1) / 9) * 100 + "%";
                stepNum.textContent = step + 1;
                backBtn.style.display = step > 0 ? "inline-flex" : "none";
                nextBtn.style.display = step < 8 ? "inline-flex" : "none";
                publishBtn.style.display = step === 8 ? "inline-flex" : "none";
                if (step === 7) updatePartnerPreview();
                if (step === 8) updateSummary();

                // Update top buttons too
                if (backBtnTop) backBtnTop.style.display = step > 0 ? "inline-flex" : "none";
                if (nextBtnTop) nextBtnTop.style.display = step < 8 ? "inline-flex" : "none";
            }

            function validateStep(step) {
                if (step === 0) {
                    if (isLoanOfficer) {
                        // LO Mode: Check page type is selected
                        const pageType = document.getElementById("se-page-type")?.value;
                        if (!pageType) {
                            alert("Please select Solo Page or Co-branded");
                            return false;
                        }

                        // If co-branded, collect partner info from inputs
                        if (pageType === "cobranded") {
                            const partnerName     = document.getElementById("se-partner-name-input")?.value.trim() || "";
                            const partnerEmail    = document.getElementById("se-partner-email-input")?.value.trim() || "";
                            const partnerPhone    = document.getElementById("se-partner-phone-input")?.value.trim() || "";
                            const partnerHeadshot = document.getElementById("se-partner-photo-url")?.value || "";
                            const partnerLogo     = document.getElementById("se-partner-logo-url")?.value || "";

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
                    } else {
                        // Partner Mode: Require LO selection
                        const partner = document.getElementById("se-partner");
                        const dropdown = document.getElementById("se-partner-dropdown");
                        const isRequired = dropdown?.dataset.required === "true";

                        if (isRequired && !partner.value) {
                            alert("Please select a loan officer");
                            return false;
                        }

                        if (partner.value) {
                            data.partner = {
                                id: partner.value,
                                name: partner.dataset.name || "",
                                nmls: partner.dataset.nmls || "",
                                photo: partner.dataset.photo || "",
                                email: partner.dataset.email || "",
                                phone: partner.dataset.phone || ""
                            };

                            // Save preference if "Remember my choice" is checked
                            const rememberCheckbox = document.getElementById("se-remember-partner");
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
                                    console.log("SE Wizard: Saved preferred LO:", res);
                                }).catch(err => {
                                    console.error("SE Wizard: Failed to save preference:", err);
                                });
                            }
                        } else {
                            data.partner = {};
                        }
                    }
                }
                if (step === 1) {
                    const typeRadio = wizard.querySelector("input[name=\"se-event-type\"]:checked");
                    if (!typeRadio) { alert("Please select an event type"); return false; }
                    data.eventType = typeRadio.value;
                }
                if (step === 2) {
                    const name = document.getElementById("se-event-name").value;
                    if (!name) { alert("Please enter an event name"); return false; }
                    data.eventDetails = {
                        name: name,
                        date: document.getElementById("se-event-date").value,
                        time: document.getElementById("se-event-time").value,
                        venue: document.getElementById("se-venue-name").value,
                        address: document.getElementById("se-venue-address").value
                    };
                }
                if (step === 3) {
                    const headlineRadio = wizard.querySelector("input[name=\"se-headline\"]:checked");
                    const headline = headlineRadio ? (headlineRadio.value === "custom" ? document.getElementById("se-headline-custom").value : headlineRadio.value) : "";
                    data.customize = { headline: headline, description: document.getElementById("se-description").value };
                }
                if (step === 4) {
                    data.heroImage = document.getElementById("se-hero-image").value;
                }
                if (step === 5) {
                    data.questions = {
                        agent: wizard.querySelector("[name=se-q-agent]")?.checked,
                        preapproved: wizard.querySelector("[name=se-q-preapproved]")?.checked,
                        timeline: wizard.querySelector("[name=se-q-timeline]")?.checked,
                        firsttime: wizard.querySelector("[name=se-q-firsttime]")?.checked
                    };
                }
                if (step === 6) {
                    // Collect branding based on user mode
                    if (isLoanOfficer) {
                        data.branding = {
                            loName: document.getElementById("se-lo-name")?.value || "",
                            loNmls: document.getElementById("se-lo-nmls")?.value || "",
                            loPhone: document.getElementById("se-lo-phone")?.value || "",
                            loEmail: document.getElementById("se-lo-email")?.value || ""
                        };
                    } else {
                        data.branding = {
                            realtorName: document.getElementById("se-realtor-name")?.value || "",
                            realtorLicense: document.getElementById("se-realtor-license")?.value || "",
                            realtorPhone: document.getElementById("se-realtor-phone")?.value || "",
                            realtorEmail: document.getElementById("se-realtor-email")?.value || ""
                        };
                    }
                }
                return true;
            }

            function updatePartnerPreview() {
                const preview = document.getElementById("se-partner-preview");
                const noPartnerMsg = document.getElementById("se-no-partner-msg");

                if (data.partner && data.partner.name) {
                    const subtitle = isLoanOfficer
                        ? (data.partner.company || data.partner.license ? `License# ${data.partner.license}` : "")
                        : (data.partner.nmls ? `NMLS# ${data.partner.nmls}` : "");

                    preview.innerHTML = `<img src="${data.partner.photo || ""}" alt=""><div class="se-lo-preview__info"><h4>${data.partner.name}</h4><p>${subtitle}</p></div>`;
                } else if (noPartnerMsg) {
                    preview.innerHTML = `<p style="color:#94a3b8;font-size:14px;margin:0;">No ${isLoanOfficer ? "realtor partner" : "loan officer"} selected (solo page)</p>`;
                }
            }

            function updateSummary() {
                const partnerLabel = isLoanOfficer ? "Realtor Partner" : "Loan Officer";
                const partnerName = data.partner?.name || "None (solo page)";

                document.getElementById("se-summary").innerHTML = `
                    <div class="se-summary__row"><span class="se-summary__label">Event</span><span class="se-summary__value">${data.eventDetails.name}</span></div>
                    <div class="se-summary__row"><span class="se-summary__label">Type</span><span class="se-summary__value">${typeLabels[data.eventType] || data.eventType}</span></div>
                    <div class="se-summary__row"><span class="se-summary__label">Date</span><span class="se-summary__value">${data.eventDetails.date || "TBD"}</span></div>
                    <div class="se-summary__row"><span class="se-summary__label">${partnerLabel}</span><span class="se-summary__value">${partnerName}</span></div>
                `;
            }

            nextBtn.addEventListener("click", () => { if (validateStep(currentStep)) { currentStep++; showStep(currentStep); } });
            backBtn.addEventListener("click", () => { currentStep--; showStep(currentStep); });
            if (nextBtnTop) nextBtnTop.addEventListener("click", () => { if (validateStep(currentStep)) { currentStep++; showStep(currentStep); } });
            if (backBtnTop) backBtnTop.addEventListener("click", () => { currentStep--; showStep(currentStep); });

            publishBtn.addEventListener("click", async () => {
                publishBtn.querySelector(".se-btn__text").style.display = "none";
                publishBtn.querySelector(".se-btn__loading").style.display = "inline";
                try {
                    const response = await fetch("' . admin_url( 'admin-ajax.php' ) . '", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({ action: "frs_create_event", nonce: "' . wp_create_nonce( 'frs_create_event' ) . '", data: JSON.stringify(data) })
                    });
                    const result = await response.json();
                    if (result.success) {
                        document.getElementById("se-success-name").textContent = data.eventDetails.name;
                        document.getElementById("se-success-link").href = result.data.url;
                        document.getElementById("se-copy-link").onclick = () => { navigator.clipboard.writeText(result.data.url); alert("Link copied!"); };
                        showStep("success");
                        wizard.querySelector(".se-wizard__footer").style.display = "none";
                    } else { alert(result.data || "Failed"); }
                } catch (e) { alert("Error"); }
                publishBtn.querySelector(".se-btn__text").style.display = "inline";
                publishBtn.querySelector(".se-btn__loading").style.display = "none";
            });

            const imageUpload = document.getElementById("se-image-upload");
            if (imageUpload) imageUpload.addEventListener("change", (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (ev) => { document.getElementById("se-hero-image").value = ev.target.result; };
                    reader.readAsDataURL(file);
                }
            });

            // Partner headshot + company logo uploads (separate fields)
            function setupSePartnerUpload(suffix, brandColor) {
                const uploadDiv  = document.getElementById("se-partner-" + suffix + "-upload");
                const fileInput  = document.getElementById("se-partner-" + suffix + "-file");
                const preview    = document.getElementById("se-partner-" + suffix + "-preview");
                const previewImg = document.getElementById("se-partner-" + suffix + "-preview-img");
                const removeBtn  = document.getElementById("se-partner-" + suffix + "-remove");
                const urlInput   = document.getElementById("se-partner-" + suffix + "-url");
                if (!uploadDiv || !fileInput) return;

                uploadDiv.addEventListener("click", () => fileInput.click());
                uploadDiv.addEventListener("dragover", (e) => { e.preventDefault(); uploadDiv.style.borderColor = brandColor; });
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
            }
            setupSePartnerUpload("photo", "#2dd4da");
            setupSePartnerUpload("logo", "#2dd4da");

            showStep(0);
        });
        </script>' . InstantImages::render_search_scripts( 'se', 'se-hero-image', 'se-images-grid' );
    }

    public static function ajax_create_event() {
        check_ajax_referer( 'frs_create_event', 'nonce' );
        if ( ! is_user_logged_in() ) { wp_send_json_error( 'Not logged in' ); }

        $data = json_decode( stripslashes( $_POST['data'] ?? '{}' ), true );
        if ( empty( $data['eventDetails']['name'] ) ) { wp_send_json_error( 'Missing event name' ); }

        $page_id = wp_insert_post([
            'post_type'   => 'frs_lead_page',
            'post_title'  => $data['eventDetails']['name'],
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ]);

        if ( is_wp_error( $page_id ) ) { wp_send_json_error( $page_id->get_error_message() ); }

        // Save common meta
        update_post_meta( $page_id, '_frs_page_type', 'special_event' );
        update_post_meta( $page_id, '_frs_event_type', $data['eventType'] );
        update_post_meta( $page_id, '_frs_event_name', $data['eventDetails']['name'] );
        update_post_meta( $page_id, '_frs_event_date', $data['eventDetails']['date'] );
        update_post_meta( $page_id, '_frs_event_time', $data['eventDetails']['time'] );
        update_post_meta( $page_id, '_frs_event_venue', $data['eventDetails']['venue'] );
        update_post_meta( $page_id, '_frs_event_address', $data['eventDetails']['address'] );
        update_post_meta( $page_id, '_frs_headline', $data['customize']['headline'] ?? '' );
        update_post_meta( $page_id, '_frs_description', $data['customize']['description'] ?? '' );
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

            // LO partner (required for realtor mode)
            $lo_id = $data['partner']['id'] ?? '';
            if ( ! empty( $lo_id ) ) {
                update_post_meta( $page_id, '_frs_loan_officer_id', $lo_id );
            }
        }

        wp_send_json_success([ 'id' => $page_id, 'url' => get_permalink( $page_id ) ]);
    }

    private static function render_login_required(): string {
        return '<div style="text-align:center;padding:48px;"><h2>Login Required</h2><p>Please log in to create an event page.</p></div>';
    }

    private static function render_access_denied(): string {
        return '<div style="text-align:center;padding:48px;"><h2>Access Denied</h2><p>You do not have permission to create event pages.</p></div>';
    }

    /**
     * Enqueue modal assets
     */
    private static function enqueue_assets(): void {
        $base_url = plugins_url( 'includes/SpecialEvent/', FRS_LEAD_PAGES_PLUGIN_FILE );
        $version  = FRS_LEAD_PAGES_VERSION;

        wp_enqueue_style( 'frs-special-event-wizard', $base_url . 'style.css', [], $version );
        wp_enqueue_script( 'frs-special-event-wizard', $base_url . 'script.js', [], $version, true );

        wp_localize_script( 'frs-special-event-wizard', 'frsSpecialEventWizard', [
            'triggerClass' => self::TRIGGER_CLASS,
            'triggerHash'  => self::TRIGGER_HASH,
        ] );
    }

    private static function render_modal_styles(): string {
        self::enqueue_assets();
        return '';
    }

    private static function render_modal_scripts(): string {
        return '';
    }
}

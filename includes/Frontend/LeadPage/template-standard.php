<?php
/**
 * Standard Lead Page Template
 *
 * 65/35 split layout: hero image left, form right.
 * Used for Open House, Customer Spotlight, and Special Event pages.
 *
 * @package FRSLeadPages
 * @var array $data Page data from Template::get_page_data()
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FRSLeadPages\Frontend\LeadPage\Template;

// Extract commonly used variables
$page_id         = $data['page_id'];
$page_type       = $data['page_type'];
$headline        = $data['headline'];
$subheadline     = $data['subheadline'];
$consent_text    = $data['consent_text'];
$hero_image_url  = $data['hero_image_url'];
$form_id         = $data['form_id'];
$value_props     = $data['value_props'];
$lo_data         = $data['lo_data'];
$realtor_data    = $data['realtor_data'];
$accent_color    = $data['accent_color'];
// Partner logo is per-page only (set on partner/co-branded pages via the wizard).
// No global default — the 21st Century Lending logo is the only default branding.
$brokerage_logo  = $data['brokerage_logo'];

// Property details (open house)
$property_address = $data['property_address'];
$property_price   = $data['property_price'];
$property_beds    = $data['property_beds'];
$property_baths   = $data['property_baths'];

// Event details (special event)
$event_name        = $data['event_name'];
$event_date        = $data['event_date'];
$event_time        = $data['event_time'];
$event_end_time    = $data['event_end_time'];
$event_venue       = $data['event_venue'];
$event_description = $data['event_description'];

// Get form headers
$form_headers = Template::get_form_headers( $page_type );

// Get calendar URLs for events
$calendar_urls = Template::get_calendar_urls( $data );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $headline ?: get_the_title() ); ?> | <?php bloginfo( 'name' ); ?></title>

    <meta property="og:title" content="<?php echo esc_attr( $headline ?: get_the_title() ); ?>">
    <meta property="og:description" content="<?php echo esc_attr( $subheadline ); ?>">
    <meta property="og:url" content="<?php echo esc_url( get_permalink() ); ?>">
    <?php if ( $hero_image_url ) : ?>
    <meta property="og:image" content="<?php echo esc_url( $hero_image_url ); ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <?php wp_head(); ?>
</head>
<body <?php body_class( 'frs-lead-page frs-lead-page--' . esc_attr( $page_type ) ); ?>>

<div class="lead-page" data-lead-page-data="<?php echo esc_attr( wp_json_encode( [
    'page_id'    => $page_id,
    'page_type'  => $page_type,
    'lo_id'      => $data['lo_id'],
    'realtor_id' => $data['realtor_id'],
] ) ); ?>">
    <div class="lead-page__hero">
        <?php if ( $hero_image_url ) : ?>
            <img src="<?php echo esc_url( $hero_image_url ); ?>" 
                 alt="" 
                 class="lead-page__hero-image"
                 onerror="this.style.display='none';">
        <?php endif; ?>
        <div class="lead-page__hero-overlay"></div>

        <div class="lead-page__hero-content">
            <!-- Top Left: 21st Century Lending (far left, always). Optional partner logo second — partner pages only. -->
            <div class="lead-page__branding">
                <div class="lead-page__logos">
                    <div class="lead-page__company-logo lead-page__company-logo--dark">
                        <img src="<?php echo esc_url( \FRSLeadPages\get_21c_logo_url() ); ?>" alt="21st Century Lending">
                    </div>
                    <?php if ( $brokerage_logo ) : ?>
                        <div class="lead-page__company-logo">
                            <img src="<?php echo esc_url( $brokerage_logo ); ?>" alt="Partner">
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Center/Middle: Headline, Value Props -->
            <div class="lead-page__content">
                <?php if ( $headline ) : ?>
                    <h1 class="lead-page__headline"><?php echo esc_html( $headline ); ?></h1>
                <?php endif; ?>

                <?php if ( $subheadline ) : ?>
                    <p class="lead-page__subheadline"><?php echo esc_html( $subheadline ); ?></p>
                <?php endif; ?>

                <?php if ( $value_props ) : ?>
                    <div class="lead-page__value-props">
                        <?php
                        $props = array_filter( array_map( 'trim', explode( "\n", $value_props ) ) );
                        foreach ( $props as $prop ) :
                        ?>
                            <div class="lead-page__value-prop">
                                <div class="lead-page__value-prop-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </div>
                                <span><?php echo esc_html( $prop ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bottom Left: Property/Event Info -->
            <div class="lead-page__bottom-left">
                <?php if ( $page_type === 'open_house' && $property_address ) : ?>
                    <div class="lead-page__info-badge">
                        <h3><?php echo esc_html( $property_address ); ?></h3>
                        <?php if ( $property_beds || $property_baths || $property_price ) : ?>
                            <div class="lead-page__property-details">
                                <?php if ( $property_beds ) : ?>
                                    <div class="lead-page__property-detail">
                                        <strong><?php echo esc_html( $property_beds ); ?></strong>
                                        <span>Beds</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $property_baths ) : ?>
                                    <div class="lead-page__property-detail">
                                        <strong><?php echo esc_html( $property_baths ); ?></strong>
                                        <span>Baths</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $property_price ) : ?>
                                    <div class="lead-page__property-detail">
                                        <strong>$<?php echo esc_html( number_format( (int) $property_price / 1000 ) ); ?>k</strong>
                                        <span>Price</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ( $page_type === 'special_event' && $event_name ) : ?>
                    <div class="lead-page__info-badge">
                        <h3><?php echo esc_html( $event_name ); ?></h3>
                        <?php if ( $event_date ) : ?>
                            <p>
                                <?php echo esc_html( date_i18n( 'F j, Y', strtotime( $event_date ) ) ); ?>
                                <?php if ( $event_time ) : ?>
                                    at <?php echo esc_html( date_i18n( 'g:i A', strtotime( $event_time ) ) ); ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( $event_venue ) : ?>
                            <p><?php echo esc_html( $event_venue ); ?></p>
                        <?php endif; ?>

                        <!-- Add to Calendar Dropdown -->
                        <?php if ( ! empty( $calendar_urls ) ) : ?>
                        <div class="lead-page__calendar-dropdown">
                            <button class="lead-page__calendar-btn" type="button">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                    <line x1="12" y1="14" x2="12" y2="18"></line>
                                    <line x1="10" y1="16" x2="14" y2="16"></line>
                                </svg>
                                Add to Calendar
                            </button>
                            <div class="lead-page__calendar-options">
                                <a href="<?php echo esc_url( $calendar_urls['google'] ); ?>" target="_blank" rel="noopener" class="lead-page__calendar-option">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12 0C5.383 0 0 5.383 0 12s5.383 12 12 12 12-5.383 12-12S18.617 0 12 0zm-1.5 17.25L6 12.75l1.5-1.5L10.5 14.25l6-6L18 9.75l-7.5 7.5z"/>
                                    </svg>
                                    Google Calendar
                                </a>
                                <a href="<?php echo esc_url( $calendar_urls['outlook'] ); ?>" target="_blank" rel="noopener" class="lead-page__calendar-option">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M7.88 12.04q0 .45-.11.87-.1.41-.33.74-.22.33-.58.52-.37.2-.87.2-.48 0-.85-.2-.36-.19-.58-.52-.22-.33-.33-.74-.1-.42-.1-.87 0-.45.1-.87.11-.42.33-.74.22-.33.58-.52.37-.2.85-.2.5 0 .87.2.36.19.58.52.23.32.33.74.11.42.11.87zm-1.44 0q0-.29-.06-.53-.06-.24-.18-.42-.11-.18-.28-.28-.17-.1-.4-.1-.22 0-.39.1-.17.1-.28.28-.11.18-.17.42-.06.24-.06.53 0 .29.06.53.06.24.17.41.11.18.28.28.17.1.39.1.23 0 .4-.1.17-.1.28-.28.12-.17.18-.41.06-.24.06-.53zm8.27-.92v3.76h-1.21v-3.76h-.96v-.93h3.13v.93h-.96zm-2.32 3.76h-1.21v-4.69h1.21v4.69zm-8.57 0h-1.2v-4.69h1.2v4.69zm15.89-3.54q-.26-.04-.48-.04-.38 0-.6.13-.21.13-.34.35-.12.21-.17.48-.05.27-.05.56 0 .62.23.95.23.33.72.33.22 0 .46-.06.24-.07.47-.17v.95q-.26.11-.53.16-.27.05-.54.05-.55 0-.92-.19-.37-.19-.6-.51-.22-.33-.33-.74-.1-.42-.1-.87 0-.5.12-.93.13-.43.38-.75.25-.32.62-.5.38-.18.88-.18.24 0 .49.05.26.06.5.17l-.3.91z"/>
                                    </svg>
                                    Outlook
                                </a>
                                <a href="<?php echo esc_url( $calendar_urls['ics'] ); ?>" download="<?php echo esc_attr( sanitize_title( $event_name ) ); ?>.ics" class="lead-page__calendar-option">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <polyline points="7 10 12 15 17 10"></polyline>
                                        <line x1="12" y1="15" x2="12" y2="3"></line>
                                    </svg>
                                    Download .ics
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="lead-page__form">
        <!-- Agent Cards - Inline -->
        <div class="lead-page__agents-row">
            <?php if ( ! empty( $lo_data ) ) : ?>
                <?php
                $lo_vcard_url = add_query_arg( [
                    'frs_vcard' => $lo_data['id'],
                    'type'      => 'lo',
                ], get_permalink() );
                ?>
                <div class="lead-page__agent-card-light">
                    <?php if ( ! empty( $lo_data['photo'] ) ) : ?>
                        <img src="<?php echo esc_url( $lo_data['photo'] ); ?>" 
                             alt="<?php echo esc_attr( $lo_data['name'] ); ?>" 
                             class="lead-page__agent-photo"
                             onerror="this.style.display='none';">
                    <?php endif; ?>
                    <div class="lead-page__agent-info">
                        <h4><?php echo esc_html( $lo_data['name'] ); ?></h4>
                        <p><?php echo esc_html( $lo_data['title'] ); ?><?php if ( ! empty( $lo_data['nmls'] ) ) : ?> | NMLS# <?php echo esc_html( $lo_data['nmls'] ); ?><?php endif; ?></p>
                        <a href="<?php echo esc_url( $lo_vcard_url ); ?>" download="<?php echo esc_attr( sanitize_title( $lo_data['name'] ) ); ?>.vcf" class="lead-page__contact-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <line x1="19" y1="8" x2="19" y2="14"></line>
                                <line x1="16" y1="11" x2="22" y2="11"></line>
                            </svg>
                            + Contact
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $realtor_data ) ) : ?>
                <?php
                $realtor_vcard_url = add_query_arg( [
                    'frs_vcard' => $realtor_data['id'] ?: 'manual',
                    'type'      => 'realtor',
                    'page_id'   => $page_id,
                ], get_permalink() );
                ?>
                <div class="lead-page__agent-card-light">
                    <?php if ( ! empty( $realtor_data['photo'] ) ) : ?>
                        <img src="<?php echo esc_url( $realtor_data['photo'] ); ?>" 
                             alt="<?php echo esc_attr( $realtor_data['name'] ); ?>" 
                             class="lead-page__agent-photo"
                             onerror="this.style.display='none';">
                    <?php endif; ?>
                    <div class="lead-page__agent-info">
                        <h4><?php echo esc_html( $realtor_data['name'] ); ?></h4>
                        <p><?php echo esc_html( $realtor_data['title'] ); ?><?php if ( ! empty( $realtor_data['license'] ) ) : ?> | DRE# <?php echo esc_html( $realtor_data['license'] ); ?><?php endif; ?></p>
                        <a href="<?php echo esc_url( $realtor_vcard_url ); ?>" download="<?php echo esc_attr( sanitize_title( $realtor_data['name'] ) ); ?>.vcf" class="lead-page__contact-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <line x1="19" y1="8" x2="19" y2="14"></line>
                                <line x1="16" y1="11" x2="22" y2="11"></line>
                            </svg>
                            + Contact
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="lead-page__form-header">
            <h2 class="lead-page__form-title"><?php echo esc_html( $form_headers['title'] ); ?></h2>
            <p class="lead-page__form-subtitle"><?php echo esc_html( $form_headers['subtitle'] ); ?></p>
        </div>

        <div class="lead-page__form-container">
            <?php
            // Map page type to form file
            $form_files = [
                'open_house'         => 'open-house.html',
                'customer_spotlight' => 'customer-spotlight.html',
                'special_event'      => 'special-event.html',
                'rate_quote'         => 'rate-quote.html',
            ];

            $form_file = $form_files[ $page_type ] ?? 'open-house.html';
            $form_path = FRS_LEAD_PAGES_PLUGIN_DIR . 'forms/' . $form_file;

            if ( file_exists( $form_path ) ) {
                // Get form HTML and inject hidden fields
                $form_html = file_get_contents( $form_path );

                // Hidden fields for tracking
                $hidden_fields = sprintf(
                    '<input type="hidden" name="page_id" value="%d">
                    <input type="hidden" name="page_type" value="%s">
                    <input type="hidden" name="loan_officer_id" value="%d">
                    <input type="hidden" name="realtor_id" value="%d">
                    <input type="hidden" name="lo_name" value="%s">
                    <input type="hidden" name="lo_email" value="%s">
                    <input type="hidden" name="realtor_name" value="%s">
                    <input type="hidden" name="realtor_email" value="%s">',
                    $page_id,
                    esc_attr( $page_type ),
                    $data['lo_id'] ?? 0,
                    $data['realtor_id'] ?? 0,
                    esc_attr( $lo_data['name'] ?? '' ),
                    esc_attr( $lo_data['email'] ?? '' ),
                    esc_attr( $realtor_data['name'] ?? '' ),
                    esc_attr( $realtor_data['email'] ?? '' )
                );

                // Insert hidden fields after opening form tag
                $form_html = preg_replace( '/(<form[^>]*>)/', '$1' . $hidden_fields, $form_html );

                echo $form_html;
            } else {
                echo '<p style="color: #64748b; text-align: center; padding: 40px 20px;">Form not available.</p>';
            }
            ?>
        </div>

        <?php if ( $consent_text ) : ?>
            <div class="lead-page__consent"><?php echo esc_html( $consent_text ); ?></div>
        <?php endif; ?>

        <div class="lead-page__powered">Powered by 21st Century Lending</div>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>

<?php
/**
 * Mortgage Calculator Lead Page Template
 *
 * Single-column layout: team header at top, calculator widget below.
 *
 * @package FRSLeadPages
 * @var array $data Page data from Template::get_page_data()
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Extract commonly used variables
$page_id        = $data['page_id'];
$headline       = $data['headline'];
$lo_data        = $data['lo_data'];
$realtor_data   = $data['realtor_data'];
$gradient_start = $data['gradient_start'];
$gradient_end   = $data['gradient_end'];
$accent_color   = $data['accent_color'];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $headline ?: get_the_title() ); ?> | <?php bloginfo( 'name' ); ?></title>

    <meta property="og:title" content="<?php echo esc_attr( $headline ?: get_the_title() ); ?>">
    <meta property="og:description" content="<?php echo esc_attr( $data['subheadline'] ); ?>">
    <meta property="og:url" content="<?php echo esc_url( get_permalink() ); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --accent: <?php echo esc_attr( $accent_color ); ?>;
            --accent-light: <?php echo esc_attr( $accent_color ); ?>15;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            background: #f8fafc;
            margin: 0;
            padding: 0;
        }

        #wpadminbar { display: none !important; }
        html { margin-top: 0 !important; }
    </style>

    <?php wp_head(); ?>
</head>
<body <?php body_class( 'frs-lead-page frs-lead-page--mortgage_calculator' ); ?>>

<div class="lead-page lead-page--calculator">
    <!-- Team Header -->
    <header class="calc-header">
        <!-- Top Left: 21st Century Lending (far left, always). Optional partner logo second — partner pages only. -->
        <div class="calc-header__brand">
            <div class="calc-header__logo calc-header__logo--dark">
                <img src="<?php echo esc_url( \FRSLeadPages\get_21c_logo_url() ); ?>" alt="21st Century Lending">
            </div>
            <?php if ( ! empty( $brokerage_logo ) ) : ?>
                <div class="calc-header__logo">
                    <img src="<?php echo esc_url( $brokerage_logo ); ?>" alt="Partner">
                </div>
            <?php endif; ?>
        </div>
        <?php if ( $headline ) : ?>
            <h1 class="calc-header__headline"><?php echo esc_html( $headline ); ?></h1>
        <?php endif; ?>
        <h2 class="calc-header__title">Your Lending Team</h2>
        <div class="calc-header__team">
            <?php if ( ! empty( $lo_data ) ) : ?>
                <div class="calc-team-card">
                    <?php if ( ! empty( $lo_data['photo'] ) ) : ?>
                        <img src="<?php echo esc_url( $lo_data['photo'] ); ?>" alt="<?php echo esc_attr( $lo_data['name'] ); ?>" class="calc-team-card__photo">
                    <?php endif; ?>
                    <div class="calc-team-card__info">
                        <strong class="calc-team-card__name"><?php echo esc_html( $lo_data['name'] ); ?></strong>
                        <span class="calc-team-card__role"><?php echo esc_html( $lo_data['title'] ?: 'Loan Officer' ); ?></span>
                        <?php if ( ! empty( $lo_data['nmls'] ) ) : ?>
                            <span class="calc-team-card__nmls">NMLS# <?php echo esc_html( $lo_data['nmls'] ); ?></span>
                        <?php endif; ?>
                        <div class="calc-team-card__contact">
                            <?php if ( ! empty( $lo_data['phone'] ) ) : ?>
                                <a href="tel:<?php echo esc_attr( $lo_data['phone'] ); ?>"><?php echo esc_html( $lo_data['phone'] ); ?></a>
                            <?php endif; ?>
                            <?php if ( ! empty( $lo_data['email'] ) ) : ?>
                                <a href="mailto:<?php echo esc_attr( $lo_data['email'] ); ?>">Email</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $realtor_data ) && ! empty( $realtor_data['name'] ) ) : ?>
                <div class="calc-team-card">
                    <?php if ( ! empty( $realtor_data['photo'] ) ) : ?>
                        <img src="<?php echo esc_url( $realtor_data['photo'] ); ?>" alt="<?php echo esc_attr( $realtor_data['name'] ); ?>" class="calc-team-card__photo">
                    <?php endif; ?>
                    <div class="calc-team-card__info">
                        <strong class="calc-team-card__name"><?php echo esc_html( $realtor_data['name'] ); ?></strong>
                        <span class="calc-team-card__role"><?php echo esc_html( $realtor_data['title'] ?: 'Sales Associate' ); ?></span>
                        <?php if ( ! empty( $realtor_data['company'] ) ) : ?>
                            <span class="calc-team-card__company"><?php echo esc_html( $realtor_data['company'] ); ?></span>
                        <?php endif; ?>
                        <div class="calc-team-card__contact">
                            <?php if ( ! empty( $realtor_data['phone'] ) ) : ?>
                                <a href="tel:<?php echo esc_attr( $realtor_data['phone'] ); ?>"><?php echo esc_html( $realtor_data['phone'] ); ?></a>
                            <?php endif; ?>
                            <?php if ( ! empty( $realtor_data['email'] ) ) : ?>
                                <a href="mailto:<?php echo esc_attr( $realtor_data['email'] ); ?>">Email</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <p class="calc-header__powered">Powered by 21st Century Lending</p>
    </header>

    <!-- Calculator Widget -->
    <main class="calc-main" style="--gradient-start: <?php echo esc_attr( $gradient_start ); ?>; --gradient-end: <?php echo esc_attr( $gradient_end ); ?>;">
        <?php
        // Enqueue frs-mortgage-calculator widget assets
        if ( function_exists( '\FRSMortgageCalculator\enqueue_assets' ) ) {
            \FRSMortgageCalculator\enqueue_assets();
        }

        // Build data attributes for calculator
        $calc_attrs = [
            'data-loan-officer-id' => esc_attr( $data['lo_id'] ),
            'data-show-lead-form'  => 'true',
            'data-gradient-start'  => esc_attr( $gradient_start ),
            'data-gradient-end'    => esc_attr( $gradient_end ),
        ];

        if ( ! empty( $lo_data['name'] ) ) {
            $calc_attrs['data-loan-officer-name'] = esc_attr( $lo_data['name'] );
        }
        if ( ! empty( $lo_data['email'] ) ) {
            $calc_attrs['data-loan-officer-email'] = esc_attr( $lo_data['email'] );
        }
        if ( ! empty( $lo_data['phone'] ) ) {
            $calc_attrs['data-loan-officer-phone'] = esc_attr( $lo_data['phone'] );
        }
        if ( ! empty( $lo_data['nmls'] ) ) {
            $calc_attrs['data-loan-officer-nmls'] = esc_attr( $lo_data['nmls'] );
        }

        $attr_string = '';
        foreach ( $calc_attrs as $key => $value ) {
            $attr_string .= sprintf( ' %s="%s"', $key, $value );
        }
        ?>
        <div id="mortgage-calculator" class="frs-mortgage-calculator-widget"<?php echo $attr_string; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></div>
    </main>
</div>

<?php wp_footer(); ?>
</body>
</html>

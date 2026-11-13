<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gift cards — sold on the site, redeemed against bookings.
 *
 * A buyer picks a session (a package) or an amount, says who it's for, and
 * pays by card (WiPay) or bank transfer. Once payment is in, the card goes out
 * with its code (MINI-JD427: the session, the recipient's initials, 3 digits):
 *
 *   - buyer:     receipt + the card (view / print link)
 *   - recipient: a gift email on the chosen day (or straight away), with a
 *                "Book your session" button that opens the gifted session
 *   - studio:    a "gift card sold" alert
 *
 * A card is a TT$ balance. It is redeemed in the booking form; whatever the
 * session costs comes off the balance and the rest stays on the card. Every
 * movement is written to a ledger, so a balance can always be explained.
 *
 * Statuses: pending (awaiting payment) → verifying (receipt uploaded) →
 * active → used (balance spent). void / expired are terminal.
 */
class TwellerFlow2_Gift_Cards {

    const DB_VERSION   = '1';
    const OPT_DB       = 'tweller_flow_2_giftcards_db';
    const OPT_SETTINGS = 'tweller_flow_2_giftcards';
    const OPT_PAGE     = 'tweller_flow_2_giftcards_page';
    const OPT_SESSION  = 'tf2_giftcard_';           // + session id => {code, amount, card_id}
    const SHORTCODE    = 'tweller_gift_cards';
    const CRON         = 'tweller_giftcards_deliver';
    const ALPHABET     = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I (order refs)
    const FAIL_LIMIT   = 10;                        // wrong codes per IP per 10 minutes

    public static function init() {
        self::maybe_create_tables();
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'tweller_wipay_paid', array( __CLASS__, 'on_wipay_paid' ), 10, 3 );
        add_action( self::CRON, array( __CLASS__, 'deliver_due' ) );
        add_filter( 'tweller_flow_2_email_test_tokens', array( __CLASS__, 'sample_tokens' ), 10, 2 );
        self::register_assets();
        add_action( 'admin_init', array( __CLASS__, 'handle_admin_post' ) );
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::CRON );
        }
        self::ensure_page();
    }

    // ── Storage ───────────────────────────────────────

    public static function table() { global $wpdb; return $wpdb->prefix . 'tweller_gift_cards'; }
    public static function ledger_table() { global $wpdb; return $wpdb->prefix . 'tweller_gift_card_ledger'; }

    public static function maybe_create_tables() {
        if ( get_option( self::OPT_DB ) === self::DB_VERSION ) return;
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(20) NOT NULL,
            token varchar(40) NOT NULL,
            order_ref varchar(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            kind varchar(20) NOT NULL DEFAULT 'amount',
            package_type varchar(50) DEFAULT '',
            session_type varchar(50) DEFAULT '',
            amount decimal(10,2) NOT NULL DEFAULT 0,
            balance decimal(10,2) NOT NULL DEFAULT 0,
            purchaser_name varchar(255) DEFAULT '',
            purchaser_email varchar(255) DEFAULT '',
            purchaser_phone varchar(50) DEFAULT '',
            recipient_name varchar(255) DEFAULT '',
            recipient_email varchar(255) DEFAULT '',
            message text,
            send_to varchar(20) DEFAULT 'recipient',
            deliver_on date DEFAULT NULL,
            delivered_at datetime DEFAULT NULL,
            payment_method varchar(20) DEFAULT '',
            paid_at datetime DEFAULT NULL,
            receipt_url varchar(500) DEFAULT '',
            receipt_bank varchar(100) DEFAULT '',
            receipt_amount decimal(10,2) DEFAULT NULL,
            receipt_ref varchar(60) DEFAULT '',
            receipt_confirmed tinyint(1) DEFAULT 0,
            notes text,
            expires_at date DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            UNIQUE KEY order_ref (order_ref),
            KEY status (status)
        ) $c;" );
        dbDelta( "CREATE TABLE " . self::ledger_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            card_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned DEFAULT NULL,
            amount decimal(10,2) NOT NULL,
            balance_after decimal(10,2) NOT NULL,
            note varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY card_id (card_id)
        ) $c;" );
        update_option( self::OPT_DB, self::DB_VERSION );
    }

    // ── Settings ──────────────────────────────────────

    public static function settings() {
        $s = get_option( self::OPT_SETTINGS, array() );
        return array_merge( array(
            'enabled'       => true,
            'presets'       => array( 300, 500, 800, 1000 ),
            'min'           => 100,
            'max'           => 5000,
            'packages'      => true,  // offer "gift a session" cards
            'expiry_months' => 0,     // 0 = never expires
        ), is_array( $s ) ? $s : array() );
    }

    public static function save_settings( $in ) {
        $presets = array_values( array_filter( array_map( 'floatval', preg_split( '/[\s,]+/', (string) ( $in['presets'] ?? '' ) ) ), function ( $v ) { return $v > 0; } ) );
        update_option( self::OPT_SETTINGS, array(
            'enabled'       => ! empty( $in['enabled'] ),
            'presets'       => $presets ?: array( 300, 500, 800, 1000 ),
            'min'           => max( 1, (float) ( $in['min'] ?? 100 ) ),
            'max'           => max( 1, (float) ( $in['max'] ?? 5000 ) ),
            'packages'      => ! empty( $in['packages'] ),
            'expiry_months' => max( 0, (int) ( $in['expiry_months'] ?? 0 ) ),
        ) );
    }

    // ── Lookup ────────────────────────────────────────

    /** "mini jd-427" → "MINIJD427": what a typed code is compared as. */
    public static function normalize_code( $in ) {
        return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $in ) );
    }

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
    }

    /**
     * Forgiving lookup: case, dashes and spaces don't matter, and the old
     * TW-XXXX-XXXX-XXXX codes still match (dashes are stripped on both sides).
     */
    public static function get_by_code( $code ) {
        global $wpdb;
        $key = self::normalize_code( $code );
        if ( strlen( $key ) < 4 || strlen( $key ) > 24 ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE REPLACE(UPPER(code),'-','') = %s LIMIT 1", $key
        ) );
    }

    public static function get_by_ref( $ref ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_ref = %s', (string) $ref ) );
    }

    public static function all( $status = '' ) {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::table();
        if ( $status !== '' ) $sql .= $wpdb->prepare( ' WHERE status = %s', $status );
        return $wpdb->get_results( $sql . ' ORDER BY created_at DESC LIMIT 500' );
    }

    public static function ledger( $card_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::ledger_table() . ' WHERE card_id = %d ORDER BY id ASC', (int) $card_id ) );
    }

    private static function random( $len ) {
        $out = '';
        $max = strlen( self::ALPHABET ) - 1;
        for ( $i = 0; $i < $len; $i++ ) $out .= self::ALPHABET[ random_int( 0, $max ) ];
        return $out;
    }

    /** First word of a name as a code word: "Grads" → GRADS, "1 Hour Event" → 1HOUR. */
    private static function word_from_name( $name ) {
        $words = preg_split( '/[^A-Za-z0-9]+/', (string) $name, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $words ) return '';
        $w = $words[0];
        if ( ctype_digit( $w ) && isset( $words[1] ) ) $w .= $words[1];
        return strtoupper( substr( $w, 0, 8 ) );
    }

    /** The session type's code word: its own short name, else the first word of its name. */
    public static function type_word( $session_type ) {
        $types = get_option( 'tweller_flow_2_session_types', array() );
        if ( ! $session_type || ! isset( $types[ $session_type ] ) ) return '';
        $t = $types[ $session_type ];
        $short = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $t['short'] ?? '' ) ), 0, 8 ) );
        return $short !== '' ? $short : self::word_from_name( $t['name'] ?? $session_type );
    }

    /** Package's code word: "Mini Session" → MINI. */
    public static function package_word( $package_type ) {
        $p = get_option( 'tweller_flow_2_packages', array() );
        return $package_type && isset( $p[ $package_type ] ) ? self::word_from_name( $p[ $package_type ]['name'] ?? $package_type ) : '';
    }

    /** WORD: the chosen session type, else the package, else GIFT. */
    public static function code_word( $session_type, $package_type ) {
        return self::type_word( $session_type ) ?: ( self::package_word( $package_type ) ?: 'GIFT' );
    }

    /** "Alex Gomez" → "AG", "Alex" → "A". */
    public static function initials( $name ) {
        $words = preg_split( '/\s+/', trim( remove_accents( (string) $name ) ), -1, PREG_SPLIT_NO_EMPTY );
        $out = '';
        foreach ( array( reset( $words ), count( $words ) > 1 ? end( $words ) : '' ) as $w ) {
            if ( $w && preg_match( '/[A-Za-z]/', (string) $w, $m ) ) $out .= strtoupper( $m[0] );
        }
        return $out;
    }

    /**
     * MINI-JD427 — WORD, the recipient's initials (else the buyer's, else TW)
     * and 3 digits. Retries until unused, then widens to 4 digits.
     */
    private static function new_code( $session_type = '', $package_type = '', $recipient = '', $buyer = '', $skip_id = 0 ) {
        $prefix = self::code_word( $session_type, $package_type ) . '-' . ( self::initials( $recipient ) ?: ( self::initials( $buyer ) ?: 'TW' ) );
        for ( $i = 0; $i < 60; $i++ ) {
            $c = $prefix . ( $i < 12 ? random_int( 100, 999 ) : random_int( 1000, 9999 ) );
            $hit = self::get_by_code( $c );
            if ( ! $hit || (int) $hit->id === (int) $skip_id ) return $c;
        }
        return $prefix . random_int( 100000, 999999 );
    }

    private static function new_ref() {
        do {
            $r = 'GC-' . self::random( 8 );
        } while ( self::get_by_ref( $r ) );
        return $r;
    }

    // ── Presentation ──────────────────────────────────

    public static function money( $n ) {
        $n = (float) $n;
        return 'TT$' . number_format( $n, ( $n == (int) $n ) ? 0 : 2 );
    }

    public static function package_name( $key ) {
        $p = get_option( 'tweller_flow_2_packages', array() );
        return $p[ $key ]['name'] ?? ucfirst( (string) $key );
    }

    /** "A Full Session" / "TT$500 gift card". */
    public static function label( $card ) {
        if ( $card->kind === 'package' && $card->package_type ) {
            $type = $card->session_type ? TwellerFlow2_Session::session_type_label( $card->session_type ) . ' — ' : '';
            return $type . self::package_name( $card->package_type );
        }
        return self::money( $card->amount ) . ' gift card';
    }

    public static function status_label( $status ) {
        $map = array(
            'pending'   => 'Awaiting payment',
            'verifying' => 'Receipt to verify',
            'active'    => 'Active',
            'used'      => 'Fully used',
            'void'      => 'Void',
            'expired'   => 'Expired',
        );
        return $map[ $status ] ?? ucfirst( $status );
    }

    public static function page_url() {
        $url = get_option( self::OPT_PAGE, '' );
        return $url ?: home_url( '/gift-cards/' );
    }

    /** The buyer's private page for this order: payment, then the card. */
    public static function view_url( $card ) {
        return add_query_arg( array( 'gc' => $card->order_ref, 't' => $card->token ), self::page_url() );
    }

    /** Where the recipient goes to spend it — straight to the gifted session when there is one. */
    public static function book_url( $card ) {
        if ( $card->kind === 'package' && $card->session_type && class_exists( 'TwellerFlow2_Booking_Links' ) ) {
            $link = TwellerFlow2_Booking_Links::link_for( $card->session_type, $card->package_type );
            if ( $link ) return add_query_arg( 'gift', $card->code, $link );
        }
        $base = class_exists( 'TwellerFlow2_Photo_Automation' ) ? TwellerFlow2_Photo_Automation::get_booking_page_url() : '';
        return $base ? add_query_arg( 'gift', $card->code, $base ) : home_url( '/' );
    }

    // ── Page ──────────────────────────────────────────

    /** Build the public "Gift Cards" page once (published, not in any menu). */
    public static function ensure_page() {
        $url = get_option( self::OPT_PAGE, '' );
        if ( $url ) return;   // cheap: one autoloaded read on every request after the first

        $existing = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => '[' . self::SHORTCODE . ']', 'numberposts' => 1 ) );
        if ( $existing ) {
            update_option( self::OPT_PAGE, get_permalink( $existing[0]->ID ) );
            return;
        }
        $id = wp_insert_post( array(
            'post_title'   => 'Gift Cards',
            'post_name'    => 'gift-cards',
            'post_content' => '[' . self::SHORTCODE . ']',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );
        if ( $id && ! is_wp_error( $id ) ) update_option( self::OPT_PAGE, get_permalink( $id ) );
    }

    public static function register_assets() {
        wp_register_style( 'tweller-gift-cards', TWELLER_FLOW_2_PLUGIN_URL . 'public/css/giftcards.css', array(), TWELLER_FLOW_2_VERSION );
        wp_register_script( 'tweller-gift-cards', TWELLER_FLOW_2_PLUGIN_URL . 'public/js/giftcards.js', array(), TWELLER_FLOW_2_VERSION, true );
    }

    /** Everything the form's script needs. */
    private static function form_config( $embed ) {
        $s = self::settings();
        $packages = array();
        $words = array( 'types' => array(), 'packages' => array() );
        if ( ! empty( $s['packages'] ) ) {
            $all_types = get_option( 'tweller_flow_2_session_types', array() );
            foreach ( (array) get_option( 'tweller_flow_2_packages', array() ) as $key => $pkg ) {
                if ( (float) ( $pkg['price'] ?? 0 ) <= 0 ) continue;
                $types = array();
                foreach ( (array) $all_types as $tk => $t ) {
                    if ( in_array( $key, (array) ( $t['allowed_packages'] ?? array() ), true ) ) {
                        $types[ $tk ] = $t['name'] ?? $tk;
                        $words['types'][ $tk ] = self::type_word( $tk );
                    }
                }
                $words['packages'][ $key ] = self::package_word( $key );
                $packages[] = array(
                    'key'      => $key,
                    'name'     => $pkg['name'] ?? $key,
                    'price'    => (float) $pkg['price'],
                    'features' => array_slice( (array) ( $pkg['features'] ?? array() ), 0, 3 ),
                    'types'    => $types,
                );
            }
        }
        return array(
            'rest'     => rest_url( 'tweller-flow-2/v1/giftcards/' ),
            'embed'    => (bool) $embed,
            'presets'  => array_values( $s['presets'] ),
            'min'      => (float) $s['min'],
            'max'      => (float) $s['max'],
            'packages' => $packages,
            'words'    => $words,
            'card'     => class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled(),
        );
    }

    /**
     * [tweller_gift_cards]. With ?gc=REF&t=TOKEN it shows that buyer's order
     * (the form stays available behind it while the order is unpaid).
     */
    public static function render_shortcode() {
        $s = self::settings();
        wp_enqueue_style( 'tweller-gift-cards' );
        wp_enqueue_script( 'tweller-gift-cards' );

        $ref   = isset( $_GET['gc'] ) ? sanitize_text_field( wp_unslash( $_GET['gc'] ) ) : '';
        $token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
        $card  = null;
        if ( $ref !== '' ) {
            $card = self::get_by_ref( $ref );
            if ( ! $card || ! hash_equals( (string) $card->token, $token ) ) {
                return '<div class="tgc"><div class="tgc-panel"><h2>Gift card not found</h2><p>Please check the link in your email.</p></div></div>';
            }
            if ( $card->status !== 'pending' ) return self::render_order( $card );
        }

        if ( empty( $s['enabled'] ) ) {
            return '<div class="tgc"><div class="tgc-panel"><h2>Gift cards</h2><p>Gift cards are not available right now — please contact us.</p></div></div>';
        }
        return self::render_app( false, $card );
    }

    /** Whether the sales form is on at all (the Book Us tile follows this). */
    public static function selling() {
        $s = self::settings();
        return ! empty( $s['enabled'] );
    }

    /**
     * The purchase flow: form (Gift → For → You), then the payment step in
     * place. $embed puts it inside the booking page, which supplies the
     * heading and the progress dots. $card is an unpaid order being revisited.
     */
    public static function render_app( $embed, $card = null ) {
        $config = self::form_config( $embed );
        if ( $card ) {
            $config['order'] = array( 'ref' => $card->order_ref, 'token' => $card->token, 'status' => $card->status, 'fields' => self::order_fields( $card ) );
        }
        $s      = self::settings();
        $expiry = (int) $s['expiry_months'];
        $has_pk = ! empty( $config['packages'] );
        $wipay  = $config['card'];
        $steps  = array( 1 => 'Gift', 2 => 'For', 3 => 'You', 4 => 'Pay' );

        ob_start();
        echo self::config_script( $config );
        ?>
        <div class="tgc<?php echo $embed ? ' tgc--embed' : ''; ?>" id="tgc-app">
            <?php if ( ! $embed ) : ?>
            <header class="tgc-hero">
                <h1>Give the gift of a photo session</h1>
                <p>Pick a session or an amount. We'll send a beautiful gift card by email — straight to them on the day you choose, or to you to hand over in person.</p>
            </header>
            <ol class="tgc-progress" id="tgc-progress" aria-label="Gift card steps">
                <?php foreach ( $steps as $n => $label ) : ?>
                    <li data-p="<?php echo (int) $n; ?>"><span class="tgc-progress__n"><?php echo (int) $n; ?></span><span class="tgc-progress__l"><?php echo esc_html( $label ); ?></span></li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>

            <div class="tgc-stage" id="tgc-stage" aria-live="polite"<?php echo $card ? '' : ' hidden'; ?>>
                <?php if ( $card ) { echo self::payment_alert(); echo self::order_markup( $card ); } ?>
            </div>

            <form class="tgc-form" id="tgc-form" novalidate<?php echo $card ? ' hidden' : ''; ?>>
                <div class="tgc-steps">
                    <section class="tgc-panel" data-gstep="1" aria-labelledby="tgc-h1">
                        <?php if ( $embed ) : ?><button type="button" class="tgc-link tgc-link--back" data-tgc-exit>&larr; Back to Sessions</button><?php endif; ?>
                        <h2 id="tgc-h1">Choose the gift</h2>
                        <?php if ( $has_pk ) : ?>
                        <div class="tgc-tabs" role="tablist">
                            <button type="button" class="tgc-tab is-on" data-kind="package" role="tab" aria-selected="true">A session</button>
                            <button type="button" class="tgc-tab" data-kind="amount" role="tab" aria-selected="false">An amount</button>
                        </div>
                        <?php endif; ?>
                        <div class="tgc-kind" data-kind="package"<?php echo $has_pk ? '' : ' hidden'; ?>>
                            <div class="tgc-pkgs" id="tgc-pkgs"></div>
                            <label class="tgc-field" id="tgc-type-wrap" hidden>For which kind of shoot? <span class="tgc-opt">(optional — they can choose later)</span>
                                <select id="tgc-type"><option value="">Let them choose</option></select>
                            </label>
                        </div>
                        <div class="tgc-kind" data-kind="amount"<?php echo $has_pk ? ' hidden' : ''; ?>>
                            <div class="tgc-chips" id="tgc-chips"></div>
                            <label class="tgc-field">Or another amount (TT$<?php echo esc_html( number_format( $s['min'] ) ); ?>–<?php echo esc_html( number_format( $s['max'] ) ); ?>)
                                <input type="number" id="tgc-custom" min="<?php echo esc_attr( $s['min'] ); ?>" max="<?php echo esc_attr( $s['max'] ); ?>" step="1" inputmode="numeric" placeholder="e.g. 650">
                            </label>
                        </div>
                        <p class="tgc-error" id="tgc-error-1" role="alert" hidden></p>
                        <div class="tgc-nav"><button type="button" class="tgc-btn" data-tgc-next>Continue</button></div>
                    </section>

                    <section class="tgc-panel" data-gstep="2" aria-labelledby="tgc-h2" hidden>
                        <h2 id="tgc-h2">Who's it for?</h2>
                        <div class="tgc-row">
                            <label class="tgc-field">Their name<input type="text" id="tgc-rname" maxlength="120" required></label>
                            <label class="tgc-field">Their email <span class="tgc-opt">(to email it to them)</span><input type="email" id="tgc-remail" maxlength="190"></label>
                        </div>
                        <label class="tgc-field">A message for them <span class="tgc-opt">(optional)</span>
                            <textarea id="tgc-message" rows="3" maxlength="500" placeholder="Happy graduation! So proud of you…"></textarea>
                        </label>
                        <fieldset class="tgc-send">
                            <legend>How should it get to them?</legend>
                            <label class="tgc-choice"><input type="radio" name="tgc-sendto" value="recipient" checked><span>Email it to them</span></label>
                            <div class="tgc-when" id="tgc-when">
                                <div class="tgc-seg" role="radiogroup" aria-label="When to send it">
                                    <label class="tgc-seg__opt"><input type="radio" name="tgc-when" value="now" checked><span>Right away</span></label>
                                    <label class="tgc-seg__opt"><input type="radio" name="tgc-when" value="date"><span>On a date</span></label>
                                </div>
                                <div class="tgc-date" id="tgc-date-wrap" hidden>
                                    <input type="date" id="tgc-date" min="<?php echo esc_attr( gmdate( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS ) ); ?>" aria-label="Delivery date">
                                    <button type="button" class="tgc-clear" id="tgc-date-clear">Clear</button>
                                </div>
                            </div>
                            <label class="tgc-choice"><input type="radio" name="tgc-sendto" value="me"><span>Send it to me — I'll give it in person</span></label>
                        </fieldset>
                        <p class="tgc-error" id="tgc-error-2" role="alert" hidden></p>
                        <div class="tgc-nav"><button type="button" class="tgc-btn tgc-btn--ghost" data-tgc-prev>Back</button><button type="button" class="tgc-btn" data-tgc-next>Continue</button></div>
                    </section>

                    <section class="tgc-panel" data-gstep="3" aria-labelledby="tgc-h3" hidden>
                        <h2 id="tgc-h3">Your details</h2>
                        <div class="tgc-row">
                            <label class="tgc-field">Your name<input type="text" id="tgc-pname" maxlength="120" required autocomplete="name"></label>
                            <label class="tgc-field">Your email<input type="email" id="tgc-pemail" maxlength="190" required autocomplete="email"></label>
                        </div>
                        <label class="tgc-field">Your phone<input type="tel" id="tgc-pphone" maxlength="40" autocomplete="tel"></label>
                        <?php if ( $wipay ) : ?>
                        <fieldset class="tgc-send tgc-paywith">
                            <legend>How would you like to pay?</legend>
                            <label class="tgc-choice"><input type="radio" name="tgc-pay" value="card" checked><span>Card (Visa / Mastercard)</span></label>
                            <label class="tgc-choice"><input type="radio" name="tgc-pay" value="bank"><span>Bank transfer</span></label>
                        </fieldset>
                        <?php else : ?>
                            <input type="hidden" name="tgc-pay" value="bank">
                            <p class="tgc-note">You'll pay by bank transfer — details on the next screen.</p>
                        <?php endif; ?>
                        <div class="tgc-sum"><span id="tgc-sum-what">&mdash;</span><strong id="tgc-sum-total">&mdash;</strong></div>
                        <p class="tgc-error" id="tgc-error-3" role="alert" hidden></p>
                        <div class="tgc-nav"><button type="button" class="tgc-btn tgc-btn--ghost" data-tgc-prev>Back</button><button type="submit" class="tgc-btn" id="tgc-buy">Buy gift card</button></div>
                        <p class="tgc-fine">Redeemable for any session<?php echo $expiry ? ' within ' . (int) $expiry . ' months' : ''; ?>. Any balance left over stays on the card.</p>
                    </section>
                </div>

                <aside class="tgc-preview" aria-label="Gift card preview">
                    <div class="tgc-card">
                        <div class="tgc-card__brand">TWELLER <span>STUDIOS</span></div>
                        <div class="tgc-card__what" id="tgc-pv-what">Choose a gift</div>
                        <div class="tgc-card__to" id="tgc-pv-to"></div>
                        <div class="tgc-card__msg" id="tgc-pv-msg"></div>
                        <div class="tgc-card__code" id="tgc-pv-code">GIFT-TW&bull;&bull;&bull;</div>
                    </div>
                    <div class="tgc-total"><span>Total</span><strong id="tgc-total" class="is-empty">&mdash;</strong></div>
                </aside>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Page config, printed inline with the markup. wp_localize_script() is
     * not used on purpose: block themes render shortcodes before scripts are
     * registered, and the localisation is then silently dropped.
     */
    private static function config_script( $config ) {
        return '<script>window.tweller_gc = ' . wp_json_encode( $config ) . ';</script>';
    }

    private static function payment_alert() {
        $pmsg = isset( $_GET['payment'] ) ? sanitize_text_field( wp_unslash( $_GET['payment'] ) ) : '';
        if ( $pmsg !== 'failed' && $pmsg !== 'error' ) return '';
        $why = isset( $_GET['pmsg'] ) ? rtrim( trim( (string) wp_unslash( $_GET['pmsg'] ) ), '.' ) : '';
        return '<div class="tgc-alert tgc-alert--bad" role="alert">The card payment didn\'t go through'
            . ( $why !== '' ? ': ' . esc_html( $why ) : '' )
            . '. You can try again, or pay by bank transfer below.</div>';
    }

    /** A paid, receipt-pending or finished order on its own page (no form). */
    private static function render_order( $card ) {
        ob_start();
        echo self::config_script( array( 'rest' => rest_url( 'tweller-flow-2/v1/giftcards/' ), 'embed' => false, 'order' => array( 'ref' => $card->order_ref, 'token' => $card->token, 'status' => $card->status ) ) );
        echo '<div class="tgc tgc--order" id="tgc-app"><div class="tgc-stage" id="tgc-stage">' . self::payment_alert() . self::order_markup( $card ) . '</div></div>';
        if ( $card->status === 'pending' || $card->status === 'verifying' ) {
            // Same free in-browser receipt reader the booking tracker uses.
            wp_enqueue_script( 'tesseract-js', 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js', array(), null, true );
        }
        return ob_get_clean();
    }

    /** The buyer's order: pay / upload a receipt, or show the finished card. */
    public static function order_markup( $card ) {
        $banking = (string) get_option( 'tweller_flow_2_banking', '' );
        $wipay   = class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled();
        ob_start();
        ?>
        <div class="tgc-order" data-ref="<?php echo esc_attr( $card->order_ref ); ?>" data-token="<?php echo esc_attr( $card->token ); ?>" data-status="<?php echo esc_attr( $card->status ); ?>" data-expected="<?php echo esc_attr( (float) $card->amount ); ?>">
            <?php if ( in_array( $card->status, array( 'active', 'used' ), true ) ) : ?>
                <div class="tgc-panel tgc-center">
                    <h2>Your gift card</h2>
                    <?php echo self::card_html( $card ); ?>
                    <p class="tgc-balance">Balance: <strong><?php echo esc_html( self::money( $card->balance ) ); ?></strong><?php echo $card->expires_at ? ' · valid until ' . esc_html( date_i18n( 'F j, Y', strtotime( $card->expires_at ) ) ) : ''; ?></p>
                    <?php if ( $card->send_to === 'recipient' && $card->recipient_email ) : ?>
                        <p class="tgc-note"><?php echo $card->delivered_at
                            ? 'Emailed to ' . esc_html( $card->recipient_name ?: $card->recipient_email ) . ' on ' . esc_html( date_i18n( 'F j', strtotime( $card->delivered_at ) ) ) . '.'
                            : 'We\'ll email it to ' . esc_html( $card->recipient_name ?: $card->recipient_email ) . ( $card->deliver_on ? ' on ' . esc_html( date_i18n( 'F j, Y', strtotime( $card->deliver_on ) ) ) : ' shortly' ) . '.'; ?></p>
                    <?php endif; ?>
                    <div class="tgc-actions">
                        <button type="button" class="tgc-btn" onclick="window.print()">Print this card</button>
                        <?php if ( (float) $card->balance > 0 ) : ?>
                            <a class="tgc-btn tgc-btn--ghost" href="<?php echo esc_url( self::book_url( $card ) ); ?>">Book with it</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ( $card->status === 'void' || $card->status === 'expired' ) : ?>
                <div class="tgc-panel"><h2>This gift card is no longer valid</h2><p>Please contact us if you think this is a mistake.</p></div>
            <?php else : ?>
                <div class="tgc-panel">
                    <?php if ( $card->status === 'pending' ) : ?>
                        <a class="tgc-link tgc-link--back" href="<?php echo esc_url( self::page_url() ); ?>" data-tgc-edit>&larr; Change gift</a>
                    <?php endif; ?>
                    <h2><?php echo esc_html( self::label( $card ) . ( $card->kind === 'package' ? ' · ' . self::money( $card->amount ) : '' ) ); ?></h2>
                    <p class="tgc-note tgc-note--tight">For <?php echo esc_html( $card->recipient_name ?: 'your recipient' ); ?> · order <?php echo esc_html( $card->order_ref ); ?></p>
                    <?php if ( $card->status === 'verifying' ) : ?>
                        <div class="tgc-alert tgc-alert--ok">Thanks — we've got your receipt. We'll check it and email the gift card as soon as the transfer is confirmed.</div>
                    <?php else : ?>
                        <p>Your gift card is reserved. As soon as payment is in, we'll email it out.</p>
                        <?php if ( $wipay ) : ?>
                            <p><a class="tgc-btn" href="<?php echo esc_url( TwellerFlow2_WiPay::checkout_url( 'giftcard', $card->order_ref ) ); ?>">Pay <?php echo esc_html( self::money( $card->amount ) ); ?> by card</a></p>
                            <p class="tgc-or"><span>or pay by bank transfer</span></p>
                        <?php endif; ?>
                        <?php if ( $banking ) : ?><div class="tgc-bank"><?php echo wpautop( esc_html( $banking ) ); ?></div><?php endif; ?>
                        <p>Transfer <strong><?php echo esc_html( self::money( $card->amount ) ); ?></strong> using <strong><?php echo esc_html( $card->order_ref ); ?></strong> as the reference, then upload a screenshot of the receipt — we read the amount automatically.</p>
                        <form class="tgc-receipt" id="tgc-receipt" data-expected="<?php echo esc_attr( (float) $card->amount ); ?>">
                            <label class="tgc-field">Sending bank
                                <select id="tgc-receipt-bank" required>
                                    <option value="">Select a bank…</option>
                                    <option value="Republic Bank Limited (RBL)">Republic Bank Limited (RBL)</option>
                                    <option value="First Citizens Bank (FCB)">First Citizens Bank (FCB)</option>
                                    <option value="Royal Bank (RBC)">Royal Bank (RBC)</option>
                                    <option value="Scotiabank">Scotiabank</option>
                                    <option value="JMMB">JMMB</option>
                                    <option value="Other">Other…</option>
                                </select>
                            </label>
                            <input type="text" id="tgc-receipt-bank-other" class="tgc-input" placeholder="Type bank name…" hidden>
                            <label class="tgc-field">Receipt screenshot
                                <input type="file" id="tgc-receipt-file" accept="image/*" required>
                            </label>
                            <div class="tgc-confirm" id="tgc-receipt-confirm" hidden>
                                <p class="tgc-confirm__title">Confirm your transfer amount</p>
                                <p class="tgc-confirm__note" id="tgc-receipt-note"></p>
                                <label class="tgc-confirm__amt">TT$ <input type="number" step="0.01" min="0" id="tgc-receipt-amount"></label>
                            </div>
                            <button type="submit" class="tgc-btn" id="tgc-receipt-btn">Verify receipt upload</button>
                            <p class="tgc-note" id="tgc-receipt-status" hidden></p>
                            <p class="tgc-error" id="tgc-receipt-error" hidden></p>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** The card itself — page and print. */
    public static function card_html( $card ) {
        $to   = $card->recipient_name ? 'For ' . $card->recipient_name : '';
        $from = $card->purchaser_name ? 'From ' . $card->purchaser_name : '';
        return '<div class="tgc-card tgc-card--real">'
            . '<div class="tgc-card__brand">TWELLER <span>STUDIOS</span></div>'
            . '<div class="tgc-card__what">' . esc_html( self::label( $card ) ) . '</div>'
            . ( $to || $from ? '<div class="tgc-card__to">' . esc_html( trim( $to . ( $to && $from ? ' · ' : '' ) . $from ) ) . '</div>' : '' )
            . ( $card->message ? '<div class="tgc-card__msg">“' . esc_html( $card->message ) . '”</div>' : '' )
            . '<div class="tgc-card__code">' . esc_html( $card->code ) . '</div>'
            . '<div class="tgc-card__value">' . esc_html( self::money( $card->amount ) ) . '</div>'
            . '</div>';
    }

    // ── REST ──────────────────────────────────────────

    public static function register_routes() {
        $ns = 'tweller-flow-2/v1';
        foreach ( array(
            array( '/giftcards/order',   'POST', 'rest_order' ),
            array( '/giftcards/update',  'POST', 'rest_update' ),
            array( '/giftcards/view',    'GET',  'rest_view' ),
            array( '/giftcards/receipt', 'POST', 'rest_receipt' ),
            array( '/giftcards/check',   'GET',  'rest_check' ),
        ) as $r ) {
            register_rest_route( $ns, $r[0], array( 'methods' => $r[1], 'callback' => array( __CLASS__, $r[2] ), 'permission_callback' => '__return_true' ) );
        }
    }

    /** Light brake on public endpoints: N calls per IP per 10 minutes. */
    private static function rate_ok( $bucket, $limit ) {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $key = 'tf2_gc_' . $bucket . '_' . md5( $ip );
        $n   = (int) get_transient( $key );
        if ( $n >= $limit ) return false;
        set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
        return true;
    }

    // Codes are short enough to guess, so wrong ones are counted per IP — on
    // the check box and on /book alike — and lookups stop after FAIL_LIMIT.

    private static function fail_key() {
        return 'tf2_gc_fail_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
    }

    public static function guess_blocked() {
        return (int) get_transient( self::fail_key() ) >= self::FAIL_LIMIT;
    }

    public static function guess_failed() {
        set_transient( self::fail_key(), (int) get_transient( self::fail_key() ) + 1, 10 * MINUTE_IN_SECONDS );
    }

    public static function guess_error() {
        return new WP_Error( 'slow_down', 'Too many wrong codes — please wait a few minutes and try again.', array( 'status' => 429 ) );
    }

    /**
     * Validate an order submission (create and update share it).
     * Returns the card fields, or a WP_Error for the buyer to read.
     */
    private static function parse_order( $d ) {
        $s = self::settings();
        $kind = ( ( $d['kind'] ?? '' ) === 'package' && ! empty( $s['packages'] ) ) ? 'package' : 'amount';
        $package_type = '';
        $session_type = '';

        if ( $kind === 'package' ) {
            $packages = get_option( 'tweller_flow_2_packages', array() );
            $package_type = sanitize_key( $d['package'] ?? '' );
            if ( ! isset( $packages[ $package_type ] ) ) return new WP_Error( 'bad_package', 'Please choose a session.', array( 'status' => 400 ) );
            $amount = (float) $packages[ $package_type ]['price'];
            $types  = get_option( 'tweller_flow_2_session_types', array() );
            $st     = sanitize_key( $d['session_type'] ?? '' );
            if ( $st && isset( $types[ $st ] ) && in_array( $package_type, (array) ( $types[ $st ]['allowed_packages'] ?? array() ), true ) ) $session_type = $st;
        } else {
            $amount = round( (float) ( $d['amount'] ?? 0 ), 2 );
            if ( $amount < (float) $s['min'] || $amount > (float) $s['max'] ) {
                return new WP_Error( 'bad_amount', sprintf( 'Please choose an amount between %s and %s.', self::money( $s['min'] ), self::money( $s['max'] ) ), array( 'status' => 400 ) );
            }
        }
        if ( $amount < 1 ) return new WP_Error( 'bad_amount', 'Please choose a gift.', array( 'status' => 400 ) );

        $pname  = sanitize_text_field( $d['purchaser_name'] ?? '' );
        $pemail = sanitize_email( $d['purchaser_email'] ?? '' );
        $rname  = sanitize_text_field( $d['recipient_name'] ?? '' );
        $remail = sanitize_email( $d['recipient_email'] ?? '' );
        $send   = ( $d['send_to'] ?? '' ) === 'me' ? 'me' : 'recipient';
        if ( $pname === '' || ! is_email( $pemail ) ) return new WP_Error( 'bad_buyer', 'Please enter your name and email.', array( 'status' => 400 ) );
        if ( $rname === '' ) return new WP_Error( 'bad_recipient', 'Please enter who the gift is for.', array( 'status' => 400 ) );
        if ( $send === 'recipient' && ! is_email( $remail ) ) return new WP_Error( 'bad_recipient', 'Please enter their email, or choose to have it sent to you.', array( 'status' => 400 ) );

        $deliver_on = null;
        if ( $send === 'recipient' && ! empty( $d['deliver_on'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d['deliver_on'] ) ) {
            $deliver_on = $d['deliver_on'] > current_time( 'Y-m-d' ) ? $d['deliver_on'] : null;
        }

        return array(
            'kind'            => $kind,
            'package_type'    => $package_type,
            'session_type'    => $session_type,
            'amount'          => $amount,
            'purchaser_name'  => $pname,
            'purchaser_email' => $pemail,
            'purchaser_phone' => sanitize_text_field( $d['purchaser_phone'] ?? '' ),
            'recipient_name'  => $rname,
            'recipient_email' => $remail,
            'message'         => sanitize_textarea_field( substr( (string) ( $d['message'] ?? '' ), 0, 500 ) ),
            'send_to'         => $send,
            'deliver_on'      => $deliver_on,
            'payment_method'  => ( $d['pay'] ?? '' ) === 'card' ? 'card' : 'bank',
        );
    }

    /** What a pending order's form looked like — to refill it. */
    private static function order_fields( $card ) {
        return array(
            'kind'            => $card->kind,
            'package'         => $card->package_type,
            'session_type'    => $card->session_type,
            'amount'          => (float) $card->amount,
            'recipient_name'  => $card->recipient_name,
            'recipient_email' => $card->recipient_email,
            'message'         => $card->message,
            'send_to'         => $card->send_to,
            'deliver_on'      => $card->deliver_on ?: '',
            'purchaser_name'  => $card->purchaser_name,
            'purchaser_email' => $card->purchaser_email,
            'purchaser_phone' => $card->purchaser_phone,
            'pay'             => $card->payment_method === 'card' ? 'card' : 'bank',
        );
    }

    /** The reply to order/update/view: the payment step, ready to show in place. */
    private static function order_reply( $card, $extra = array() ) {
        $pay_url = '';
        if ( $card->status === 'pending' && $card->payment_method === 'card' && class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled() ) {
            $pay_url = TwellerFlow2_WiPay::checkout_url( 'giftcard', $card->order_ref );
        }
        return rest_ensure_response( array_merge( array(
            'ok'        => true,
            'order_ref' => $card->order_ref,
            'token'     => $card->token,
            'status'    => $card->status,
            'view_url'  => self::view_url( $card ),
            'pay_url'   => $pay_url,
            'html'      => self::order_markup( $card ),
            'fields'    => $card->status === 'pending' ? self::order_fields( $card ) : null,
        ), $extra ) );
    }

    /** The order for ref + token, or a WP_Error. */
    private static function owned_order( $ref, $token ) {
        $card = self::get_by_ref( sanitize_text_field( (string) $ref ) );
        if ( ! $card || ! hash_equals( (string) $card->token, (string) $token ) ) {
            return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
        }
        return $card;
    }

    public static function rest_order( $request ) {
        $s = self::settings();
        if ( empty( $s['enabled'] ) ) return new WP_Error( 'disabled', 'Gift cards are not available right now.', array( 'status' => 400 ) );
        if ( ! self::rate_ok( 'order', 20 ) ) return new WP_Error( 'slow_down', 'Too many attempts — please try again in a few minutes.', array( 'status' => 429 ) );

        $row = self::parse_order( $request->get_json_params() );
        if ( is_wp_error( $row ) ) return $row;

        global $wpdb;
        $row = array_merge( $row, array(
            'code'       => self::new_code( $row['session_type'], $row['package_type'], $row['recipient_name'], $row['purchaser_name'] ),
            'token'      => wp_generate_password( 32, false ),
            'order_ref'  => self::new_ref(),
            'status'     => 'pending',
            'balance'    => 0,
            'created_at' => current_time( 'mysql' ),
        ) );
        if ( ! $wpdb->insert( self::table(), $row ) ) {
            return new WP_Error( 'save_failed', 'Could not save your order — please try again.', array( 'status' => 500 ) );
        }
        $card = self::get( $wpdb->insert_id );

        if ( ! ( $card->payment_method === 'card' && class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled() ) ) {
            self::email_bank_instructions( $card );
        }
        return self::order_reply( $card );
    }

    /**
     * The buyer stepped back and changed something while the order was still
     * unpaid: update that order (same ref, same token) instead of making a
     * second one. A paid or receipt-uploaded order is no longer theirs to edit.
     */
    public static function rest_update( $request ) {
        $s = self::settings();
        if ( empty( $s['enabled'] ) ) return new WP_Error( 'disabled', 'Gift cards are not available right now.', array( 'status' => 400 ) );
        if ( ! self::rate_ok( 'update', 40 ) ) return new WP_Error( 'slow_down', 'Too many attempts — please try again in a few minutes.', array( 'status' => 429 ) );

        $d = $request->get_json_params();
        $card = self::owned_order( $d['ref'] ?? '', $d['token'] ?? '' );
        if ( is_wp_error( $card ) ) return $card;
        if ( $card->status !== 'pending' ) {
            return new WP_Error( 'locked', $card->status === 'verifying'
                ? 'Your receipt is already with us, so this order can\'t be changed any more.'
                : 'This order can\'t be changed any more.', array( 'status' => 409 ) );
        }

        $row = self::parse_order( $d );
        if ( is_wp_error( $row ) ) return $row;

        // The code is only issued to anyone on payment, so it can follow the
        // new session / recipient.
        $row['code'] = self::new_code( $row['session_type'], $row['package_type'], $row['recipient_name'], $row['purchaser_name'], $card->id );

        global $wpdb;
        $wpdb->update( self::table(), $row, array( 'id' => $card->id ) );
        $fresh = self::get( $card->id );

        // Bank details quote the amount — send them again if it (or the way
        // of paying) changed.
        if ( $fresh->payment_method === 'bank' && ( $card->payment_method !== 'bank' || abs( (float) $card->amount - (float) $fresh->amount ) > 0.001 ) ) {
            self::email_bank_instructions( $fresh );
        }
        return self::order_reply( $fresh );
    }

    /** The buyer's order page, as data: for a reload in the middle of the flow. */
    public static function rest_view( $request ) {
        $card = self::owned_order( $request->get_param( 'ref' ), $request->get_param( 'token' ) );
        if ( is_wp_error( $card ) ) return $card;
        return self::order_reply( $card );
    }

    public static function rest_receipt( $request ) {
        $card = self::owned_order( $request->get_param( 'ref' ), $request->get_param( 'token' ) );
        if ( is_wp_error( $card ) ) return $card;
        if ( ! in_array( $card->status, array( 'pending', 'verifying' ), true ) ) {
            return new WP_Error( 'done', 'This order is already paid.', array( 'status' => 400 ) );
        }
        if ( empty( $_FILES['receipt'] ) ) return new WP_Error( 'no_file', 'Please choose the receipt file.', array( 'status' => 400 ) );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $moved = wp_handle_upload( $_FILES['receipt'], array(
            'test_form' => false,
            'mimes'     => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic', 'pdf' => 'application/pdf' ),
        ) );
        if ( ! $moved || isset( $moved['error'] ) ) {
            return new WP_Error( 'upload_failed', $moved['error'] ?? 'Upload failed.', array( 'status' => 400 ) );
        }

        // What the in-browser reader found, as confirmed (or typed) by the buyer.
        $detected = (float) preg_replace( '/[^\d.]/', '', (string) $request->get_param( 'ocr_amounts' ) );

        global $wpdb;
        $wpdb->update( self::table(), array(
            'status'            => 'verifying',
            'receipt_url'       => $moved['url'],
            'payment_method'    => 'bank',
            'receipt_bank'      => sanitize_text_field( (string) $request->get_param( 'bank_name' ) ),
            'receipt_amount'    => $detected > 0 ? round( $detected, 2 ) : null,
            'receipt_ref'       => sanitize_text_field( (string) $request->get_param( 'ocr_reference' ) ),
            'receipt_confirmed' => $request->get_param( 'amount_confirmed' ) ? 1 : 0,
        ), array( 'id' => $card->id ) );
        $card = self::get( $card->id );

        $N = 'TwellerFlow2_Notifications';
        $rows = self::detail_rows( $card )
            . $N::email_detail_row( 'Bank', esc_html( $card->receipt_bank ?: '—' ) )
            . $N::email_detail_row( 'Amount on receipt', esc_html( self::receipt_check( $card ) ) )
            . ( $card->receipt_ref ? $N::email_detail_row( 'Reference', esc_html( $card->receipt_ref ) ) : '' )
            . $N::email_detail_row( 'Receipt', '<a href="' . esc_url( $card->receipt_url ) . '">Open</a>' );
        $body = '<h2>Gift card receipt to verify</h2>'
            . '<p><strong>' . esc_html( $card->purchaser_name ) . '</strong> uploaded a bank transfer receipt for a gift card.</p>'
            . $N::email_card( 'Order', $rows )
            . $N::email_button_row( admin_url( 'admin.php?page=tweller-flow-2-giftcards&card=' . $card->id ), 'Review & mark paid' );
        $N::send_raw( self::studio_recipients(), 'Gift card receipt — ' . $card->purchaser_name . ' (' . self::money( $card->amount ) . ')', $body );

        return self::order_reply( $card );
    }

    /** "TT$500 ✓ matches" / "TT$450 — expected TT$500" / "not read". */
    public static function receipt_check( $card ) {
        if ( $card->receipt_amount === null || $card->receipt_amount === '' ) return 'Not read — check the receipt';
        $got = (float) $card->receipt_amount;
        $how = ! empty( $card->receipt_confirmed ) ? ' (confirmed by buyer)' : '';
        return abs( $got - (float) $card->amount ) < 0.01
            ? self::money( $got ) . ' ✓ matches' . $how
            : self::money( $got ) . ' — expected ' . self::money( $card->amount ) . $how;
    }

    /** Booking form: is this code good, and for how much? */
    public static function rest_check( $request ) {
        if ( ! self::rate_ok( 'check', 30 ) ) return new WP_Error( 'slow_down', 'Too many attempts — please try again in a few minutes.', array( 'status' => 429 ) );
        if ( self::guess_blocked() ) return self::guess_error();
        $card = self::get_by_code( (string) $request->get_param( 'code' ) );
        $err  = self::usable_error( $card );
        if ( $err ) {
            self::guess_failed();
            return new WP_Error( 'invalid', $err, array( 'status' => 400 ) );
        }
        return rest_ensure_response( array(
            'ok'      => true,
            'code'    => $card->code,
            'balance' => (float) $card->balance,
            'label'   => self::label( $card ),
            'package' => $card->kind === 'package' ? $card->package_type : '',
        ) );
    }

    /** Why a card can't be spent right now, or '' when it can. */
    public static function usable_error( $card ) {
        if ( ! $card ) return 'That gift card code wasn\'t recognised — please check it.';
        self::maybe_expire( $card );
        switch ( $card->status ) {
            case 'active':
                return (float) $card->balance > 0 ? '' : 'This gift card has no balance left.';
            case 'used':      return 'This gift card has already been fully used.';
            case 'pending':
            case 'verifying': return 'This gift card isn\'t active yet — payment is still being confirmed.';
            case 'expired':   return 'This gift card has expired.';
            default:          return 'This gift card is no longer valid.';
        }
    }

    private static function maybe_expire( $card ) {
        if ( $card && $card->status === 'active' && $card->expires_at && $card->expires_at < current_time( 'Y-m-d' ) ) {
            global $wpdb;
            $wpdb->update( self::table(), array( 'status' => 'expired' ), array( 'id' => $card->id ) );
            $card->status = 'expired';
        }
    }

    // ── Payment → activation ──────────────────────────

    /** WiPay checkout asks: what is owed on this order, and by whom? */
    public static function wipay_details( $ref ) {
        $card = self::get_by_ref( $ref );
        if ( ! $card ) return new WP_Error( 'not_found', 'Gift card order not found.' );
        if ( ! in_array( $card->status, array( 'pending', 'verifying' ), true ) ) return new WP_Error( 'paid', 'This gift card is already paid.' );
        return array(
            'amount' => (float) $card->amount,
            'name'   => $card->purchaser_name,
            'email'  => $card->purchaser_email,
            'phone'  => $card->purchaser_phone,
        );
    }

    public static function on_wipay_paid( $type, $ref, $params = array() ) {
        if ( $type !== 'giftcard' ) return;
        $card = self::get_by_ref( $ref );
        if ( $card ) self::activate( $card, 'card', 'Paid by card via WiPay' . ( ! empty( $params['transaction_id'] ) ? ' — TXN ' . $params['transaction_id'] : '' ) );
    }

    /**
     * Payment is in: load the balance and send the card out. Safe to call
     * twice — only a pending/verifying card is activated.
     */
    public static function activate( $card, $method, $note = '' ) {
        global $wpdb;
        $s = self::settings();
        $expires = (int) $s['expiry_months'] > 0 ? date( 'Y-m-d', strtotime( '+' . (int) $s['expiry_months'] . ' months', current_time( 'timestamp' ) ) ) : null;

        $updated = $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'active', balance = amount, payment_method = %s, paid_at = %s, expires_at = %s
             WHERE id = %d AND status IN ('pending','verifying')",
            $method, current_time( 'mysql' ), $expires, $card->id
        ) );
        if ( ! $updated ) return false;   // already active (double callback) — nothing to do

        $card = self::get( $card->id );
        self::write_ledger( $card->id, (float) $card->amount, (float) $card->balance, $note ?: 'Issued', null );

        self::email_buyer( $card );
        self::email_studio_sold( $card );
        self::deliver_if_due( $card );
        return true;
    }

    /** Admin: a card given away (promo, giveaway, apology) — active at once. */
    public static function issue_comp( $amount, $recipient_name, $recipient_email, $message, $note, $send ) {
        global $wpdb;
        $amount = round( (float) $amount, 2 );
        if ( $amount <= 0 ) return new WP_Error( 'bad_amount', 'Enter an amount.' );
        $wpdb->insert( self::table(), array(
            'code'            => self::new_code( '', '', $recipient_name, '' ),
            'token'           => wp_generate_password( 32, false ),
            'order_ref'       => self::new_ref(),
            'status'          => 'pending',
            'kind'            => 'amount',
            'amount'          => $amount,
            'balance'         => 0,
            'purchaser_name'  => 'Tweller Studios',
            'purchaser_email' => '',
            'recipient_name'  => sanitize_text_field( $recipient_name ),
            'recipient_email' => sanitize_email( $recipient_email ),
            'message'         => sanitize_textarea_field( $message ),
            'send_to'         => ( $send && is_email( $recipient_email ) ) ? 'recipient' : 'me',
            'notes'           => sanitize_text_field( $note ),
            'created_at'      => current_time( 'mysql' ),
        ) );
        $card = self::get( $wpdb->insert_id );
        if ( ! $card ) return new WP_Error( 'save_failed', 'Could not create the card.' );
        self::activate( $card, 'comp', 'Issued by the studio' . ( $note ? ' — ' . $note : '' ) );
        return self::get( $card->id );
    }

    /** Admin → Gift Cards form actions. Redirects back with a notice. */
    public static function handle_admin_post() {
        if ( empty( $_POST['tf2_gc_action'] ) || ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'tf2_gift_cards' );
        $action = sanitize_key( $_POST['tf2_gc_action'] );
        $card   = ! empty( $_POST['card_id'] ) ? self::get( (int) $_POST['card_id'] ) : null;
        $back   = admin_url( 'admin.php?page=tweller-flow-2-giftcards' . ( $card ? '&card=' . $card->id : '' ) );
        $msg    = '';
        global $wpdb;

        switch ( $action ) {
            case 'settings':
                self::save_settings( wp_unslash( $_POST ) );
                $msg = 'Gift card settings saved.';
                $back = admin_url( 'admin.php?page=tweller-flow-2-giftcards' );
                break;
            case 'mark_paid':
                if ( $card && self::activate( $card, 'bank', 'Bank transfer verified by ' . wp_get_current_user()->display_name ) ) {
                    $msg = 'Marked paid — the gift card has been sent.';
                } else {
                    $msg = 'That card is already active.';
                }
                break;
            case 'reject_receipt':
                if ( $card && $card->status === 'verifying' ) {
                    $wpdb->update( self::table(), array( 'status' => 'pending' ), array( 'id' => $card->id ) );
                    if ( is_email( $card->purchaser_email ) ) {
                        $N = 'TwellerFlow2_Notifications';
                        $N::send_raw( $card->purchaser_email, 'Quick fix needed — we couldn\'t verify your gift card payment',
                            '<h2>We couldn\'t verify your receipt</h2><p>Hi ' . esc_html( $card->purchaser_name ) . ',</p><p>We weren\'t able to match the receipt you uploaded for gift card order <strong>' . esc_html( $card->order_ref ) . '</strong> to a transfer of <strong>' . esc_html( self::money( $card->amount ) ) . '</strong>. Please check it and upload it again — or reply to this email and we\'ll sort it out.</p>'
                            . $N::email_button_row( self::view_url( $card ), 'Upload my receipt again' ) );
                    }
                    $msg = 'Receipt rejected — the buyer has been asked to upload it again.';
                }
                break;
            case 'resend':
                if ( $card && in_array( $card->status, array( 'active', 'used' ), true ) ) {
                    $to = sanitize_key( $_POST['to'] ?? 'buyer' );
                    if ( $to === 'recipient' && is_email( $card->recipient_email ) ) {
                        self::email_recipient( $card );
                        $wpdb->update( self::table(), array( 'delivered_at' => current_time( 'mysql' ) ), array( 'id' => $card->id ) );
                        $msg = 'Gift email sent to ' . $card->recipient_email . '.';
                    } else {
                        self::email_buyer( $card );
                        $msg = 'Sent to ' . $card->purchaser_email . '.';
                    }
                }
                break;
            case 'adjust':
                $delta = round( (float) ( $_POST['delta'] ?? 0 ), 2 );
                if ( $card && $delta != 0 && in_array( $card->status, array( 'active', 'used' ), true ) ) {
                    self::credit( $card->id, $delta, 'Adjusted by ' . wp_get_current_user()->display_name . ( ! empty( $_POST['note'] ) ? ' — ' . sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '' ) );
                    $msg = 'Balance adjusted.';
                }
                break;
            case 'void':
                if ( $card ) {
                    $wpdb->update( self::table(), array( 'status' => 'void' ), array( 'id' => $card->id ) );
                    self::write_ledger( $card->id, 0, (float) $card->balance, 'Voided by ' . wp_get_current_user()->display_name, null );
                    $msg = 'Card voided.';
                }
                break;
            case 'issue':
                $new = self::issue_comp(
                    $_POST['amount'] ?? 0,
                    wp_unslash( $_POST['recipient_name'] ?? '' ),
                    wp_unslash( $_POST['recipient_email'] ?? '' ),
                    wp_unslash( $_POST['message'] ?? '' ),
                    wp_unslash( $_POST['note'] ?? '' ),
                    ! empty( $_POST['send'] )
                );
                if ( is_wp_error( $new ) ) {
                    $msg = $new->get_error_message();
                } else {
                    $msg  = 'Gift card ' . $new->code . ' issued' . ( $new->delivered_at ? ' and emailed.' : '.' );
                    $back = admin_url( 'admin.php?page=tweller-flow-2-giftcards&card=' . $new->id );
                }
                break;
        }

        wp_safe_redirect( add_query_arg( 'tf2_msg', rawurlencode( $msg ), $back ) );
        exit;
    }

    // ── Spending ──────────────────────────────────────

    private static function write_ledger( $card_id, $amount, $balance_after, $note, $session_id ) {
        global $wpdb;
        $wpdb->insert( self::ledger_table(), array(
            'card_id'       => (int) $card_id,
            'session_id'    => $session_id ? (int) $session_id : null,
            'amount'        => round( (float) $amount, 2 ),
            'balance_after' => round( (float) $balance_after, 2 ),
            'note'          => substr( (string) $note, 0, 255 ),
            'created_at'    => current_time( 'mysql' ),
        ) );
    }

    /**
     * Take up to $max off a card. Atomic: two bookings racing on one card
     * can never both spend the same dollars. Returns the amount taken.
     */
    public static function redeem( $code, $max, $note = '' ) {
        global $wpdb;
        $card = self::get_by_code( $code );
        if ( self::usable_error( $card ) ) return 0.0;
        $take = round( min( (float) $card->balance, (float) $max ), 2 );
        if ( $take <= 0 ) return 0.0;

        $ok = $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table() . " SET balance = balance - %f WHERE id = %d AND status = 'active' AND balance >= %f",
            $take, $card->id, $take
        ) );
        if ( ! $ok ) return 0.0;

        $fresh = self::get( $card->id );
        if ( (float) $fresh->balance <= 0.004 ) {
            $wpdb->update( self::table(), array( 'status' => 'used', 'balance' => 0 ), array( 'id' => $card->id ) );
        }
        self::write_ledger( $card->id, -$take, max( 0, (float) $fresh->balance ), $note ?: 'Redeemed', null );
        return $take;
    }

    /** Put money back (a booking that failed, or an admin correction). */
    public static function credit( $card_id, $amount, $note, $session_id = null ) {
        global $wpdb;
        $amount = round( (float) $amount, 2 );
        if ( $amount == 0 ) return;
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table() . ' SET balance = GREATEST(0, balance + %f) WHERE id = %d', $amount, (int) $card_id
        ) );
        $card = self::get( $card_id );
        if ( $card && in_array( $card->status, array( 'active', 'used' ), true ) ) {
            $wpdb->update( self::table(), array( 'status' => (float) $card->balance > 0 ? 'active' : 'used' ), array( 'id' => $card->id ) );
        }
        self::write_ledger( $card_id, $amount, $card ? (float) $card->balance : 0, $note, $session_id );
    }

    /** Tie a redemption to the booking it paid for. */
    public static function attach_to_session( $code, $amount, $session_id ) {
        global $wpdb;
        $card = self::get_by_code( $code );
        if ( ! $card ) return;
        update_option( self::OPT_SESSION . (int) $session_id, array( 'code' => $card->code, 'amount' => (float) $amount, 'card_id' => (int) $card->id ), false );
        // Point the redemption's ledger line at the booking.
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::ledger_table() . ' SET session_id = %d WHERE card_id = %d AND session_id IS NULL AND amount < 0 ORDER BY id DESC LIMIT 1',
            (int) $session_id, $card->id
        ) );
    }

    /** {code, amount, card_id} of the gift card used on a booking, or null. */
    public static function for_session( $session_id ) {
        $g = get_option( self::OPT_SESSION . (int) $session_id );
        return is_array( $g ) ? $g : null;
    }

    public static function applied_amount( $session_id ) {
        $g = self::for_session( $session_id );
        return $g ? (float) $g['amount'] : 0.0;
    }

    // ── Clients ───────────────────────────────────────
    // A client is whoever shares an email with a booking. Matching is done
    // when asked (not stored), so a recipient who books next month is found
    // without anything having to be updated.

    /** Bookings made with this email, newest first. */
    public static function sessions_for_email( $email, $limit = 10 ) {
        $email = strtolower( trim( (string) $email ) );
        if ( ! is_email( $email ) ) return array();
        global $wpdb;
        $t = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE LOWER(client_email) = %s ORDER BY session_date DESC, id DESC LIMIT %d", $email, (int) $limit
        ) );
    }

    /** Lower-cased emails of everyone who has booked — one query, for list badges. */
    public static function client_emails() {
        global $wpdb;
        $t = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        return array_flip( array_map( 'strtolower', (array) $wpdb->get_col( "SELECT DISTINCT client_email FROM $t WHERE client_email <> ''" ) ) );
    }

    /**
     * Cards a client bought and cards given to them (paid ones only; an
     * unpaid order is not yet a gift).
     * @return array{bought: object[], for: object[]}
     */
    public static function cards_for_email( $email ) {
        $out = array( 'bought' => array(), 'for' => array() );
        $email = strtolower( trim( (string) $email ) );
        if ( ! is_email( $email ) ) return $out;
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE status IN ('active','used','expired') AND (LOWER(purchaser_email) = %s OR LOWER(recipient_email) = %s) ORDER BY created_at DESC LIMIT 50",
            $email, $email
        ) );
        foreach ( (array) $rows as $c ) {
            if ( strtolower( (string) $c->purchaser_email ) === $email ) $out['bought'][] = $c;
            if ( strtolower( (string) $c->recipient_email ) === $email ) $out['for'][] = $c;
        }
        return $out;
    }

    /** Bookings a card paid for: [ { session, amount } ], from the ledger. */
    public static function bookings_for_card( $card_id ) {
        global $wpdb;
        $t = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT l.amount AS spent, l.created_at AS spent_at, s.* FROM ' . self::ledger_table() . " l INNER JOIN $t s ON s.id = l.session_id WHERE l.card_id = %d AND l.amount < 0 ORDER BY l.id ASC", (int) $card_id
        ) );
        return (array) $rows;
    }

    // ── Delivery ──────────────────────────────────────

    public static function deliver_if_due( $card ) {
        if ( $card->status !== 'active' || $card->send_to !== 'recipient' || $card->delivered_at || ! is_email( $card->recipient_email ) ) return;
        if ( $card->deliver_on && $card->deliver_on > current_time( 'Y-m-d' ) ) return;
        if ( self::email_recipient( $card ) ) {
            global $wpdb;
            $wpdb->update( self::table(), array( 'delivered_at' => current_time( 'mysql' ) ), array( 'id' => $card->id ) );
        }
    }

    /** Hourly: send scheduled gift emails whose day has come. */
    public static function deliver_due() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE status = 'active' AND send_to = 'recipient' AND delivered_at IS NULL AND (deliver_on IS NULL OR deliver_on <= %s) LIMIT 50",
            current_time( 'Y-m-d' )
        ) );
        foreach ( (array) $rows as $card ) self::deliver_if_due( $card );
    }

    // ── Emails ────────────────────────────────────────

    public static function studio_recipients() {
        return array_values( array_unique( array_filter( array( get_option( 'admin_email' ), TwellerFlow2_Notifications::STUDIO_EMAIL ) ) ) );
    }

    private static function detail_rows( $card ) {
        $N = 'TwellerFlow2_Notifications';
        $rows  = $N::email_detail_row( 'Gift', esc_html( self::label( $card ) ) );
        $rows .= $N::email_detail_row( 'Value', esc_html( self::money( $card->amount ) ) );
        if ( $card->recipient_name ) $rows .= $N::email_detail_row( 'For', esc_html( $card->recipient_name ) );
        if ( $card->purchaser_name ) $rows .= $N::email_detail_row( 'From', esc_html( $card->purchaser_name ) );
        $rows .= $N::email_detail_row( 'Order', esc_html( $card->order_ref ) );
        return $rows;
    }

    /** Merge tags for the editable gift card emails. */
    public static function tokens( $card ) {
        $N = 'TwellerFlow2_Notifications';
        $card_block = "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='margin:22px 0;'><tr><td align='center' style='background:#101010; border-radius:14px; padding:30px 22px; font-family:Arial,Helvetica,sans-serif;'>"
            . "<div style='color:#fff; font-size:15px; font-weight:600; letter-spacing:6px;'>TWELLER <span style='color:#C9A227; font-size:11px; letter-spacing:4px;'>STUDIOS</span></div>"
            . "<div style='color:#C9A227; font-size:26px; font-weight:700; margin:18px 0 6px;'>" . esc_html( self::money( $card->amount ) ) . "</div>"
            . "<div style='color:#fff; font-size:15px;'>" . esc_html( self::label( $card ) ) . "</div>"
            . ( $card->message ? "<div style='color:#D8D2C6; font-size:14px; font-style:italic; margin-top:14px;'>“" . esc_html( $card->message ) . "”</div>" : '' )
            . "<div style='color:#fff; font-size:20px; font-weight:700; letter-spacing:3px; margin-top:20px; font-family:Courier New,monospace;'>" . esc_html( $card->code ) . "</div>"
            . "<div style='color:#9B958A; font-size:12px; margin-top:6px;'>Enter this code when booking" . ( $card->expires_at ? ' · valid until ' . esc_html( date_i18n( 'F j, Y', strtotime( $card->expires_at ) ) ) : '' ) . "</div>"
            . "</td></tr></table>";

        return array(
            'recipient_name' => esc_html( $card->recipient_name ),
            'purchaser_name' => esc_html( $card->purchaser_name ),
            'gift_label'     => esc_html( self::label( $card ) ),
            'gift_value'     => esc_html( self::money( $card->amount ) ),
            'gift_code'      => esc_html( $card->code ),
            'gift_message'   => $card->message ? '<p style="font-style:italic;">“' . esc_html( $card->message ) . '”</p>' : '',
            'order_ref'      => esc_html( $card->order_ref ),
            'delivery_note'  => esc_html( self::delivery_note( $card ) ),
            'gift_card'      => $card_block,
            'book_button'    => $N::email_button_row( self::book_url( $card ), 'Book your session' ),
            'view_button'    => $N::email_button_row( self::view_url( $card ), 'View & print the gift card', false, 10 ),
        );
    }

    private static function delivery_note( $card ) {
        if ( $card->send_to !== 'recipient' || ! $card->recipient_email ) return 'It\'s yours to hand over — print it or forward this email.';
        if ( $card->deliver_on && $card->deliver_on > current_time( 'Y-m-d' ) ) {
            return 'We\'ll email it to ' . ( $card->recipient_name ?: $card->recipient_email ) . ' on ' . date_i18n( 'l, F j', strtotime( $card->deliver_on ) ) . '.';
        }
        return 'We\'ve emailed it to ' . ( $card->recipient_name ?: $card->recipient_email ) . '.';
    }

    private static function send_template( $key, $to, $card ) {
        $entry = TwellerFlow2_Email_Templates::registry_entry( $key );
        if ( ! $entry ) return false;
        $tokens = self::tokens( $card );
        list( $subject, $body ) = TwellerFlow2_Email_Templates::resolve( $key, $entry['subject'], $entry['body'], $tokens );
        // Defaults carry {{tokens}} too — fill them whether or not overridden.
        $subject = TwellerFlow2_Email_Templates::apply_tokens( $subject, $tokens );
        $body    = TwellerFlow2_Email_Templates::apply_tokens( $body, $tokens );
        return TwellerFlow2_Notifications::send_raw( $to, wp_strip_all_tags( html_entity_decode( $subject, ENT_QUOTES, 'UTF-8' ) ), $body );
    }

    public static function email_buyer( $card ) {
        if ( ! is_email( $card->purchaser_email ) ) return false;
        return self::send_template( 'giftcard_buyer', $card->purchaser_email, $card );
    }

    public static function email_recipient( $card ) {
        return self::send_template( 'giftcard_recipient', $card->recipient_email, $card );
    }

    private static function email_bank_instructions( $card ) {
        if ( ! is_email( $card->purchaser_email ) ) return;
        $N = 'TwellerFlow2_Notifications';
        $banking = trim( (string) get_option( 'tweller_flow_2_banking', '' ) );
        $body = '<h2>Almost there — your gift card is reserved</h2>'
            . '<p>Hi ' . esc_html( $card->purchaser_name ) . ',</p>'
            . '<p>Thank you! To finish, please transfer <strong>' . esc_html( self::money( $card->amount ) ) . '</strong> using <strong>' . esc_html( $card->order_ref ) . '</strong> as the reference, then upload a screenshot of the receipt. We\'ll email the gift card as soon as it\'s confirmed.</p>'
            . ( $banking ? $N::email_card( 'Bank details', '<p style="margin:0;">' . nl2br( esc_html( $banking ) ) . '</p>' ) : '' )
            . $N::email_card( 'Your order', self::detail_rows( $card ) )
            . $N::email_button_row( self::view_url( $card ), 'Upload my receipt' );
        $N::send_raw( $card->purchaser_email, 'Your gift card order ' . $card->order_ref . ' — payment details', $body );
    }

    private static function email_studio_sold( $card ) {
        if ( $card->payment_method === 'comp' ) return;
        $N = 'TwellerFlow2_Notifications';
        $body = '<h2>Gift card sold 🎁</h2>'
            . '<p><strong>' . esc_html( $card->purchaser_name ) . '</strong> bought a gift card (' . esc_html( $card->payment_method === 'card' ? 'paid by card' : 'bank transfer' ) . ').</p>'
            . $N::email_card( 'Gift card', self::detail_rows( $card ) . $N::email_detail_row( 'Code', esc_html( $card->code ) ) )
            . $N::email_button_row( admin_url( 'admin.php?page=tweller-flow-2-giftcards&card=' . $card->id ), 'Open in admin' );
        $N::send_raw( self::studio_recipients(), 'Gift card sold — ' . self::money( $card->amount ) . ' from ' . $card->purchaser_name, $body );
    }

    /** Emails screen "send test": fill gift tags with a sample card. */
    public static function sample_tokens( $tokens, $key ) {
        if ( strpos( (string) $key, 'giftcard_' ) !== 0 ) return $tokens;
        $sample = (object) array(
            'code' => 'MINI-JD427', 'token' => 'x', 'order_ref' => 'GC-SAMPLE1', 'status' => 'active', 'kind' => 'amount',
            'package_type' => '', 'session_type' => '', 'amount' => 500, 'balance' => 500,
            'purchaser_name' => 'Jane Sample', 'purchaser_email' => '', 'recipient_name' => 'Alex Sample', 'recipient_email' => 'alex@example.com',
            'message' => 'Happy birthday — can\'t wait to see the photos!', 'send_to' => 'recipient', 'deliver_on' => null, 'expires_at' => null,
        );
        return array_merge( $tokens, self::tokens( $sample ) );
    }

    /** Editable templates, merged into the Emails screen registry. */
    public static function email_registry() {
        $tags = array( 'recipient_name', 'purchaser_name', 'gift_label', 'gift_value', 'gift_code', 'gift_message', 'gift_card', 'book_button', 'view_button', 'order_ref', 'delivery_note' );
        return array(
            'giftcard_buyer' => array(
                'group'   => 'Gift cards',
                'label'   => 'Gift card — to the buyer',
                'desc'    => 'Sent to whoever bought the card once payment is in: their receipt, the card itself and the code.',
                'tokens'  => $tags,
                'subject' => 'Your Tweller Studios gift card is ready 🎁',
                'body'    => "<h2>Your gift card is ready</h2>\n"
                    . "<p>Hi {{purchaser_name}},</p>\n"
                    . "<p>Thank you for giving a Tweller Studios session! Payment is confirmed and the gift card is below. {{delivery_note}}</p>\n"
                    . "{{gift_card}}\n"
                    . "{{view_button}}\n"
                    . "<p>Order reference: <strong>{{order_ref}}</strong></p>\n"
                    . "<p>Warm regards,<br><strong>The Tweller Studios Team</strong></p>",
            ),
            'giftcard_recipient' => array(
                'group'   => 'Gift cards',
                'label'   => 'Gift card — to the recipient',
                'desc'    => 'The gift itself, emailed to the recipient on the day the buyer chose.',
                'tokens'  => $tags,
                'subject' => '{{purchaser_name}} sent you a gift 🎁',
                'body'    => "<h2>You've received a gift!</h2>\n"
                    . "<p>Hi {{recipient_name}},</p>\n"
                    . "<p><strong>{{purchaser_name}}</strong> has given you a photo session with Tweller Studios.</p>\n"
                    . "{{gift_card}}\n"
                    . "<p>When you're ready, book your session online and enter the code at checkout — it's taken off the price automatically.</p>\n"
                    . "{{book_button}}\n"
                    . "<p>We can't wait to meet you!<br><strong>The Tweller Studios Team</strong></p>",
            ),
        );
    }
}

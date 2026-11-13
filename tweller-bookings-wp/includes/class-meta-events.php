<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Meta Pixel + Conversions API, deduplicated.
 *
 * Every event the booking flow sends goes out twice — once from the browser
 * (fbq with an eventID) and once from this server (Conversions API with the
 * same event_id) — so Meta counts it once and still receives it when a
 * browser blocks the pixel.
 *
 *   ViewContent  browser → POST /pixel → CAPI       (landing, session picked)
 *   AddToCart    browser → POST /pixel → CAPI       (package picked; value from the server's price list)
 *   Contact+Lead browser → POST /pixel → CAPI       (WhatsApp / Call taps; once per visit per method)
 *   Lead         browser, and CAPI from POST /book  (booking form submitted)
 *   Schedule     CAPI when a booking is confirmed, browser on the next tracker visit
 *   Purchase     CAPI when the first payment lands, browser on the next tracker visit
 *
 * Schedule / Purchase IDs are derived from the shoot code (schedule-<code>,
 * purchase-<code>), so the server and a browser visit hours later still
 * agree on the ID without having to talk to each other.
 *
 * Nothing is sent server-side until an access token is configured — either
 * in Tweller Bookings → Christmas Page → Tracking, or by the official
 * "Meta for WordPress" plugin, whose token is reused when present.
 */
class TwellerFlow2_Meta_Events {

    const OPT          = 'tweller_flow_2_meta';
    const DEFAULT_PIXEL = '2573134719661660';
    const API_VERSION  = 'v24.0';

    /** Events the public /pixel endpoint will relay — nothing else. */
    const RELAYABLE = array( 'ViewContent', 'AddToCart', 'Contact', 'Lead' );

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'remember_context' ), 5, 2 );
        add_action( 'tweller_flow_2_payment_updated', array( __CLASS__, 'on_payment_updated' ), 20 );
        add_action( 'tweller_wipay_paid', array( __CLASS__, 'on_wipay_paid' ), 5, 3 );
    }

    // ── Settings ─────────────────────────────────────

    public static function settings() {
        $s = get_option( self::OPT, array() );
        return wp_parse_args( is_array( $s ) ? $s : array(), array(
            'pixel_id'        => '',
            'access_token'    => '',
            'test_event_code' => '',
            'server_events'   => 1,
        ) );
    }

    public static function pixel_id() {
        $s = self::settings();
        if ( ! empty( $s['pixel_id'] ) ) return preg_replace( '/\D/', '', $s['pixel_id'] );
        $fb = self::fb_plugin_option( 'getPixelId' );
        return $fb ? preg_replace( '/\D/', '', $fb ) : self::DEFAULT_PIXEL;
    }

    private static function access_token() {
        $s = self::settings();
        if ( ! empty( $s['access_token'] ) ) return trim( $s['access_token'] );
        return (string) self::fb_plugin_option( 'getAccessToken' );
    }

    /** Read a value from the official Meta for WordPress plugin, if it is active. */
    private static function fb_plugin_option( $method ) {
        $cls = '\\FacebookPixelPlugin\\Core\\FacebookWordpressOptions';
        if ( class_exists( $cls ) && method_exists( $cls, $method ) ) {
            try { return call_user_func( array( $cls, $method ) ); } catch ( \Throwable $e ) {}
        }
        return '';
    }

    public static function server_enabled() {
        $s = self::settings();
        return ! empty( $s['server_events'] ) && self::access_token() !== '' && self::pixel_id() !== '';
    }

    /** Where the server events come from, for the admin screen. */
    public static function token_source() {
        $s = self::settings();
        if ( ! empty( $s['access_token'] ) ) return 'saved here';
        return self::fb_plugin_option( 'getAccessToken' ) ? 'Meta for WordPress plugin' : '';
    }

    // ── Sending ──────────────────────────────────────

    /**
     * Send one event to the Conversions API. Non-blocking: a slow Graph API
     * must never slow down a booking.
     *
     * @param string $name       Standard event name.
     * @param string $event_id   Same ID the browser used, for deduplication.
     * @param array  $user       Raw user data: email, phone, ip, ua, fbp, fbc, external_id.
     * @param array  $custom     custom_data (value, currency, content_name…).
     * @param string $source_url Page the event happened on.
     */
    public static function send( $name, $event_id, $user = array(), $custom = array(), $source_url = '' ) {
        if ( ! self::server_enabled() ) return false;

        $event = array(
            'event_name'       => $name,
            'event_time'       => time(),
            'event_id'         => substr( (string) $event_id, 0, 100 ),
            'action_source'    => 'website',
            'event_source_url' => $source_url ? esc_url_raw( $source_url ) : home_url( '/' ),
            'user_data'        => self::user_data( $user ),
        );
        $custom = array_filter( (array) $custom, function ( $v ) { return $v !== '' && $v !== null; } );
        if ( $custom ) $event['custom_data'] = $custom;

        $body = array( 'data' => array( $event ) );
        $s = self::settings();
        if ( ! empty( $s['test_event_code'] ) ) $body['test_event_code'] = sanitize_text_field( $s['test_event_code'] );

        $url = sprintf( 'https://graph.facebook.com/%s/%s/events?access_token=%s',
            apply_filters( 'tweller_flow_2_meta_api_version', self::API_VERSION ),
            rawurlencode( self::pixel_id() ),
            rawurlencode( self::access_token() )
        );
        wp_remote_post( $url, array(
            'timeout'  => 3,
            'blocking' => false,
            'headers'  => array( 'Content-Type' => 'application/json' ),
            'body'     => wp_json_encode( $body ),
        ) );
        return true;
    }

    /** Meta's user_data: PII hashed (SHA-256, normalised), browser IDs as-is. */
    private static function user_data( $u ) {
        $out = array();
        if ( ! empty( $u['email'] ) ) {
            $out['em'] = array( hash( 'sha256', strtolower( trim( $u['email'] ) ) ) );
        }
        if ( ! empty( $u['phone'] ) ) {
            $digits = preg_replace( '/\D/', '', $u['phone'] );
            // Local T&T numbers (7 digits) get the country code Meta expects.
            if ( strlen( $digits ) === 7 ) $digits = '1868' . $digits;
            if ( $digits !== '' ) $out['ph'] = array( hash( 'sha256', $digits ) );
        }
        if ( ! empty( $u['external_id'] ) ) $out['external_id'] = array( hash( 'sha256', strtolower( trim( $u['external_id'] ) ) ) );
        if ( ! empty( $u['ip'] ) )  $out['client_ip_address'] = $u['ip'];
        if ( ! empty( $u['ua'] ) )  $out['client_user_agent'] = $u['ua'];
        if ( ! empty( $u['fbp'] ) ) $out['fbp'] = $u['fbp'];
        if ( ! empty( $u['fbc'] ) ) $out['fbc'] = $u['fbc'];
        return $out;
    }

    /** IP, user agent and Meta browser IDs of the current request. */
    public static function request_user( $fbclid = '' ) {
        $fbp = isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '';
        $fbc = isset( $_COOKIE['_fbc'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) : '';
        if ( ! $fbc && $fbclid ) $fbc = 'fb.1.' . ( time() * 1000 ) . '.' . sanitize_text_field( $fbclid );
        return array(
            'ip'  => self::client_ip(),
            'ua'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '',
            'fbp' => substr( $fbp, 0, 200 ),
            'fbc' => substr( $fbc, 0, 300 ),
        );
    }

    private static function client_ip() {
        foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $k ) {
            if ( empty( $_SERVER[ $k ] ) ) continue;
            $ip = trim( explode( ',', wp_unslash( $_SERVER[ $k ] ) )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }
        return '';
    }

    /** A browser-supplied event ID, trimmed to something safe. */
    public static function clean_event_id( $id ) {
        return substr( preg_replace( '/[^A-Za-z0-9._:-]/', '', (string) $id ), 0, 64 );
    }

    // ── Public relay for browser events ───────────────

    public static function register_routes() {
        register_rest_route( 'tweller-flow-2/v1', '/pixel', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_relay' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function rest_relay( $request ) {
        $d        = (array) $request->get_json_params();
        $name     = sanitize_text_field( $d['event'] ?? '' );
        $event_id = self::clean_event_id( $d['event_id'] ?? '' );

        if ( ! in_array( $name, self::RELAYABLE, true ) || $event_id === '' ) {
            return new WP_REST_Response( array( 'ok' => false ), 400 );
        }

        // Cheap per-IP cap: a visitor makes a handful of these, a bot many.
        $ip  = self::client_ip();
        $key = 'tf2_px_' . md5( $ip );
        $n   = (int) get_transient( $key );
        if ( $n > 40 ) return new WP_REST_Response( array( 'ok' => false ), 429 );
        set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

        $custom = array();
        $method = sanitize_key( $d['method'] ?? '' );

        if ( $name === 'ViewContent' ) {
            $custom['content_name'] = substr( sanitize_text_field( $d['content_name'] ?? '' ), 0, 80 );
            $custom['content_category'] = 'Session';
        } elseif ( $name === 'AddToCart' ) {
            // Price comes from the server's own list, never from the browser.
            $packages = get_option( 'tweller_flow_2_packages', array() );
            $pkg_key  = sanitize_key( $d['package'] ?? '' );
            if ( ! isset( $packages[ $pkg_key ] ) ) return new WP_REST_Response( array( 'ok' => false ), 400 );
            $custom = array(
                'value'        => (float) $packages[ $pkg_key ]['price'],
                'currency'     => 'TTD',
                'content_name' => $packages[ $pkg_key ]['name'],
                'content_ids'  => array( $pkg_key ),
                'content_type' => 'product',
            );
        } elseif ( $name === 'Contact' || $name === 'Lead' ) {
            // Only the tap-to-contact buttons use the relay for these; the
            // booking form's Lead is sent from POST /book with the client's
            // details attached.
            if ( ! in_array( $method, array( 'whatsapp', 'call' ), true ) ) {
                return new WP_REST_Response( array( 'ok' => false ), 400 );
            }
            $custom['method'] = $method;
            $custom['content_name'] = substr( sanitize_text_field( $d['content_name'] ?? '' ), 0, 80 );
        }

        $src = esc_url_raw( (string) ( $d['source_url'] ?? '' ) );
        if ( $src && wp_parse_url( $src, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) $src = '';

        self::send( $name, $event_id, self::request_user( sanitize_text_field( $d['fbclid'] ?? '' ) ), $custom, $src );
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    // ── Booking events ───────────────────────────────

    /**
     * Server half of the booking form's Lead. Called from POST /book with
     * the event ID the browser used for its own fbq('track','Lead').
     */
    public static function booking_lead( $session_id, $event_id ) {
        $event_id = self::clean_event_id( $event_id );
        $session  = TwellerFlow2_Session::get( $session_id );
        if ( ! $session || $event_id === '' ) return;

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg      = $packages[ $session->package_type ] ?? array();
        $ctx      = get_option( 'tf_meta_ctx_' . $session_id, array() );
        $attr     = TwellerFlow2_Session::attribution( $session );

        self::send( 'Lead', $event_id, self::session_user( $session, $ctx ), array(
            'content_name' => trim( TwellerFlow2_Session::session_type_label( $session ) . ' — ' . ( $pkg['name'] ?? $session->package_type ), ' —' ),
            'value'        => (float) $session->total_amount,
            'currency'     => 'TTD',
            'method'       => 'booking_form',
        ), is_array( $attr ) ? ( $attr['landing'] ?? '' ) : '' );
    }

    /**
     * Keep the booking browser's IP / user agent / Meta cookies with the
     * session: Schedule and Purchase happen later (receipt approved in
     * wp-admin, WiPay callback) when that browser is not the one asking.
     */
    public static function remember_context( $session_id, $data ) {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $attr   = is_array( $data['attribution'] ?? null ) ? $data['attribution'] : array();
            $fbclid = $attr['fbclid'] ?? '';
            update_option( 'tf_meta_ctx_' . (int) $session_id, self::request_user( $fbclid ), false );
        }
    }

    private static function session_user( $session, $ctx ) {
        return array_merge( is_array( $ctx ) ? $ctx : array(), array(
            'email'       => $session->client_email,
            'phone'       => $session->client_phone ?? '',
            'external_id' => $session->client_email,
        ) );
    }

    /** WiPay charges the outstanding balance; remember what was actually paid. */
    public static function on_wipay_paid( $type, $ref, $params ) {
        if ( $type !== 'booking' ) return;
        $session = TwellerFlow2_Session::get_by_code( $ref );
        if ( ! $session ) return;
        $total = isset( $params['total'] ) && $params['total'] !== '' ? (float) $params['total'] : 0;
        if ( $total > 0 ) update_option( 'tf_meta_paid_' . $session->id, $total, false );
        // payment_updated already fired before this hook; send now.
        self::on_payment_updated( $session->id );
    }

    /**
     * Booking confirmed (deposit or full payment verified, WiPay success, or
     * a gift card covering the deposit) → Schedule, and Purchase for the
     * money received. Each is sent once per booking.
     */
    public static function on_payment_updated( $session_id ) {
        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session || ! in_array( $session->payment_status, array( 'deposit', 'paid' ), true ) ) return;

        $sent = get_option( 'tf_meta_sent_' . $session->id, array() );
        if ( ! is_array( $sent ) ) $sent = array();
        $ctx  = get_option( 'tf_meta_ctx_' . $session->id, array() );
        $user = self::session_user( $session, $ctx );
        $attr = TwellerFlow2_Session::attribution( $session );
        $url  = is_array( $attr ) ? ( $attr['landing'] ?? '' ) : '';
        $name = TwellerFlow2_Session::session_type_label( $session );

        $purchase = self::purchase_record( $session );

        if ( empty( $sent['schedule'] ) ) {
            self::send( 'Schedule', self::schedule_id( $session ), $user, array( 'content_name' => $name ), $url );
            $sent['schedule'] = time();
        }
        if ( empty( $sent['purchase'] ) && $purchase['value'] > 0 ) {
            self::send( 'Purchase', $purchase['event_id'], $user, array(
                'value'        => $purchase['value'],
                'currency'     => 'TTD',
                'content_name' => $name,
                'content_ids'  => array( $session->package_type ),
                'content_type' => 'product',
            ), $url );
            $sent['purchase'] = time();
        }
        update_option( 'tf_meta_sent_' . $session->id, $sent, false );
    }

    public static function schedule_id( $session ) {
        return 'schedule-' . $session->tracking_code;
    }

    /**
     * What the first payment was worth: the WiPay charge when paid by card,
     * otherwise the verified amount less any gift card (a gift card is money
     * received earlier, not a new purchase).
     */
    public static function purchase_record( $session ) {
        $value = (float) get_option( 'tf_meta_paid_' . $session->id, 0 );
        if ( $value <= 0 ) {
            $gift  = class_exists( 'TwellerFlow2_Gift_Cards' ) ? (float) TwellerFlow2_Gift_Cards::applied_amount( $session->id ) : 0;
            $value = max( 0, (float) $session->deposit_amount - $gift );
            if ( $value <= 0 && $session->payment_status === 'paid' && $gift <= 0 ) $value = (float) $session->total_amount;
        }
        return array( 'event_id' => 'purchase-' . $session->tracking_code, 'value' => round( $value, 2 ) );
    }

    /**
     * Browser half of Schedule / Purchase for the tracker page: the events
     * this booking has reached, with the IDs the server used.
     */
    public static function tracker_events( $session ) {
        if ( ! $session || ! in_array( $session->payment_status, array( 'deposit', 'paid' ), true ) ) return array();
        $out = array( array( 'name' => 'Schedule', 'id' => self::schedule_id( $session ), 'params' => array( 'content_name' => TwellerFlow2_Session::session_type_label( $session ) ) ) );
        $p = self::purchase_record( $session );
        if ( $p['value'] > 0 ) {
            $out[] = array( 'name' => 'Purchase', 'id' => $p['event_id'], 'params' => array( 'value' => $p['value'], 'currency' => 'TTD', 'content_name' => TwellerFlow2_Session::session_type_label( $session ) ) );
        }
        return $out;
    }
}

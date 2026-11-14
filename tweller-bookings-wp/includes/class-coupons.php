<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Coupon codes: a discount you hand to a client, entered at booking.
 *
 *  - percent off or a fixed TT$ amount off the session price (never the
 *    transport fee);
 *  - optionally limited to some packages / session types, an expiry date, a
 *    number of uses, and a client email (a code only that client can use);
 *  - checked in the booking form for the preview, and again on the server
 *    when the booking is made (the browser's price is never trusted).
 *
 * Stored in one option, so there is nothing to migrate.
 */
class TwellerFlow2_Coupons {

    const OPTION     = 'tweller_flow_2_coupons';
    const FAIL_LIMIT = 8;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 21 );
        add_action( 'admin_post_tweller_flow_2_coupon_save', array( __CLASS__, 'admin_save' ) );
        add_action( 'admin_post_tweller_flow_2_coupon_action', array( __CLASS__, 'admin_action' ) );
    }

    // ── Storage ──────────────────────────────────────

    public static function normalize( $code ) {
        return strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', trim( (string) $code ) ) );
    }

    public static function all() {
        $all = get_option( self::OPTION, array() );
        return is_array( $all ) ? $all : array();
    }

    public static function get( $code ) {
        $code = self::normalize( $code );
        $all  = self::all();
        return ( $code !== '' && isset( $all[ $code ] ) ) ? $all[ $code ] : null;
    }

    private static function lock() {
        for ( $i = 0; $i < 20; $i++ ) {
            if ( add_option( 'tweller_flow_2_coupons_lock', time(), '', 'no' ) ) return true;
            $held = (int) get_option( 'tweller_flow_2_coupons_lock', 0 );
            if ( $held && time() - $held > 10 ) delete_option( 'tweller_flow_2_coupons_lock' ); // stale
            usleep( 100000 );
        }
        return false;
    }

    private static function unlock() {
        delete_option( 'tweller_flow_2_coupons_lock' );
    }

    // ── Rules ────────────────────────────────────────

    /** Why a code can't be used for this booking, or '' when it can. */
    public static function error( $c, $package_key = '', $session_type = '', $email = '' ) {
        if ( ! $c ) return 'That coupon code wasn\'t recognised. Please check it.';
        if ( empty( $c['active'] ) ) return 'That coupon is no longer active.';
        if ( ! empty( $c['expires'] ) && current_time( 'Y-m-d' ) > $c['expires'] ) return 'That coupon has expired.';
        if ( (int) $c['max_uses'] > 0 && (int) $c['uses'] >= (int) $c['max_uses'] ) return 'That coupon has already been used.';
        if ( ! empty( $c['packages'] ) && $package_key !== '' && ! in_array( $package_key, $c['packages'], true ) ) {
            return 'That coupon doesn\'t apply to the package you picked.';
        }
        if ( ! empty( $c['session_types'] ) && $session_type !== '' && ! in_array( $session_type, $c['session_types'], true ) ) {
            return 'That coupon doesn\'t apply to this type of session.';
        }
        if ( ! empty( $c['email'] ) && $email !== '' && strtolower( $email ) !== strtolower( $c['email'] ) ) {
            return 'That coupon is for a different email address. Please use the email it was given to.';
        }
        return '';
    }

    /** TT$ off a session priced $price. */
    public static function discount_for( $c, $price ) {
        $price = max( 0.0, (float) $price );
        $off   = $c['type'] === 'percent' ? $price * (float) $c['amount'] / 100 : (float) $c['amount'];
        return round( min( max( 0.0, $off ), $price ), 2 );
    }

    public static function label( $c ) {
        return $c['type'] === 'percent'
            ? rtrim( rtrim( number_format( (float) $c['amount'], 2 ), '0' ), '.' ) . '% off'
            : 'TT$' . number_format( (float) $c['amount'], 0 ) . ' off';
    }

    /** Count one use (atomic). False when it ran out in the meantime. */
    public static function redeem( $code ) {
        if ( ! self::lock() ) return false;
        $all  = self::all();
        $code = self::normalize( $code );
        $ok   = false;
        if ( isset( $all[ $code ] ) ) {
            $max = (int) $all[ $code ]['max_uses'];
            if ( $max === 0 || (int) $all[ $code ]['uses'] < $max ) {
                $all[ $code ]['uses'] = (int) $all[ $code ]['uses'] + 1;
                update_option( self::OPTION, $all, false );
                $ok = true;
            }
        }
        self::unlock();
        return $ok;
    }

    /** Give a use back (the booking could not be created). */
    public static function release( $code ) {
        if ( ! self::lock() ) return;
        $all  = self::all();
        $code = self::normalize( $code );
        if ( isset( $all[ $code ] ) && (int) $all[ $code ]['uses'] > 0 ) {
            $all[ $code ]['uses'] = (int) $all[ $code ]['uses'] - 1;
            update_option( self::OPTION, $all, false );
        }
        self::unlock();
    }

    // ── Guess protection (same idea as gift cards) ───

    private static function fail_key() {
        return 'tf2_cp_fail_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
    }

    public static function guess_blocked() {
        return (int) get_transient( self::fail_key() ) >= self::FAIL_LIMIT;
    }

    public static function guess_failed() {
        set_transient( self::fail_key(), (int) get_transient( self::fail_key() ) + 1, 10 * MINUTE_IN_SECONDS );
    }

    public static function guess_error() {
        return new WP_Error( 'slow_down', 'Too many wrong codes. Please wait a few minutes and try again.', array( 'status' => 429 ) );
    }

    // ── REST: the booking form's "Apply" button ──────

    public static function register_routes() {
        register_rest_route( 'tweller-flow-2/v1', '/coupons/check', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_check' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function rest_check( $request ) {
        if ( self::guess_blocked() ) return self::guess_error();
        $c   = self::get( (string) $request->get_param( 'code' ) );
        $pk  = sanitize_key( (string) $request->get_param( 'package' ) );
        $st  = sanitize_key( (string) $request->get_param( 'session' ) );
        $em  = sanitize_email( (string) $request->get_param( 'email' ) );
        $err = self::error( $c, '', $st, $em ); // a package mismatch is shown in the summary, not here
        if ( $err ) {
            self::guess_failed();
            return new WP_Error( 'invalid', $err, array( 'status' => 400 ) );
        }
        return rest_ensure_response( array(
            'ok'       => true,
            'code'     => $c['code'],
            'type'     => $c['type'],
            'amount'   => (float) $c['amount'],
            'label'    => self::label( $c ),
            'packages' => array_values( (array) $c['packages'] ),
            'locked'   => ! empty( $c['email'] ),
        ) );
    }

    // ── Admin ────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page( 'tweller-flow-2', 'Coupons', 'Coupons', 'manage_options', 'tweller-flow-2-coupons', array( __CLASS__, 'admin_page' ) );
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $coupons  = array_reverse( self::all(), true );
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $types    = get_option( 'tweller_flow_2_session_types', array() );
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/coupons.php';
    }

    private static function back( $args ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=tweller-flow-2-coupons' ) ) );
        exit;
    }

    private static function random_code() {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'TW-';
            for ( $i = 0; $i < 5; $i++ ) $code .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
        } while ( self::get( $code ) );
        return $code;
    }

    public static function admin_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tweller_flow_2_coupon_save' );
        $p = wp_unslash( $_POST );

        $type   = ( $p['type'] ?? '' ) === 'fixed' ? 'fixed' : 'percent';
        $amount = round( (float) ( $p['amount'] ?? 0 ), 2 );
        if ( $amount <= 0 || ( $type === 'percent' && $amount > 100 ) ) self::back( array( 'error' => 'amount' ) );

        $code = self::normalize( $p['code'] ?? '' );
        if ( $code === '' ) $code = self::random_code();
        if ( strlen( $code ) < 3 || strlen( $code ) > 30 ) self::back( array( 'error' => 'code' ) );
        if ( self::get( $code ) ) self::back( array( 'error' => 'exists', 'code' => $code ) );

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $types    = get_option( 'tweller_flow_2_session_types', array() );
        $pk = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['packages'] ?? array() ) ), array_keys( (array) $packages ) ) );
        $st = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['session_types'] ?? array() ) ), array_keys( (array) $types ) ) );
        // Everything ticked = no restriction.
        if ( count( $pk ) === count( (array) $packages ) ) $pk = array();
        if ( count( $st ) === count( (array) $types ) ) $st = array();

        $expires = sanitize_text_field( $p['expires'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ) $expires = '';

        $all          = self::all();
        $all[ $code ] = array(
            'code'          => $code,
            'type'          => $type,
            'amount'        => $amount,
            'packages'      => $pk,
            'session_types' => $st,
            'expires'       => $expires,
            'max_uses'      => max( 0, (int) ( $p['max_uses'] ?? 1 ) ),
            'uses'          => 0,
            'email'         => sanitize_email( $p['email'] ?? '' ),
            'note'          => sanitize_text_field( $p['note'] ?? '' ),
            'active'        => 1,
            'created'       => current_time( 'Y-m-d' ),
        );
        update_option( self::OPTION, $all, false );
        self::back( array( 'created' => $code ) );
    }

    public static function admin_action() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        $code = self::normalize( $_GET['code'] ?? '' );
        check_admin_referer( 'tweller_flow_2_coupon_' . $code );
        $all = self::all();
        if ( isset( $all[ $code ] ) ) {
            $do = sanitize_key( $_GET['do'] ?? '' );
            if ( $do === 'delete' ) unset( $all[ $code ] );
            elseif ( $do === 'toggle' ) $all[ $code ]['active'] = empty( $all[ $code ]['active'] ) ? 1 : 0;
            update_option( self::OPTION, $all, false );
        }
        self::back( array( 'done' => 1 ) );
    }
}

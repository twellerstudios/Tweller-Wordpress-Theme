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
        add_action( 'admin_post_tweller_flow_2_coupon_csv', array( __CLASS__, 'admin_csv' ) );
        add_action( 'init', array( __CLASS__, 'maybe_seed' ), 40 );
    }

    /**
     * Two one-time 50%-off codes for a returning client (once, when this
     * version is first loaded). Delete or edit them on the Coupons page.
     */
    public static function maybe_seed() {
        if ( get_option( 'tweller_flow_2_coupons_seeded_sj' ) ) return;
        $all = self::all();
        foreach ( array( '50OFF-SJ1', '50OFF-SJ2' ) as $code ) {
            if ( isset( $all[ $code ] ) ) continue;
            $all[ $code ] = array(
                'code' => $code, 'type' => 'percent', 'amount' => 50.0, 'packages' => array(), 'session_types' => array(),
                'expires' => '', 'max_uses' => 1, 'uses' => 0, 'email' => '', 'note' => 'One-time code for a returning client (sale discount)',
                'active' => 1, 'created' => current_time( 'Y-m-d' ),
            );
        }
        update_option( self::OPTION, $all, false );
        update_option( 'tweller_flow_2_coupons_seeded_sj', 1, false );
    }

    /** When the code stops working, as a Unix time (site timezone), or 0 for never. A bare date means the end of that day. */
    public static function expiry_ts( $c ) {
        $e = trim( (string) ( $c['expires'] ?? '' ) );
        if ( $e === '' ) return 0;
        try {
            $dt = new DateTimeImmutable( strlen( $e ) <= 10 ? $e . ' 23:59:59' : $e, wp_timezone() );
            return $dt->getTimestamp();
        } catch ( Exception $ex ) {
            return 0;
        }
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
    public static function error( $c, $package_key = '', $session_type = '', $email = '', $price = null ) {
        if ( ! $c ) return 'That coupon code wasn\'t recognised. Please check it.';
        if ( empty( $c['active'] ) ) return 'That coupon is no longer active.';
        $ts = self::expiry_ts( $c );
        if ( $ts && time() > $ts ) return 'That coupon has expired.';
        if ( (int) $c['max_uses'] > 0 && (int) $c['uses'] >= (int) $c['max_uses'] ) return 'That coupon has already been used.';
        if ( ! empty( $c['packages'] ) && $package_key !== '' && ! in_array( $package_key, $c['packages'], true ) ) {
            return 'That coupon doesn\'t apply to the package you picked.';
        }
        if ( ! empty( $c['session_types'] ) && $session_type !== '' && ! in_array( $session_type, $c['session_types'], true ) ) {
            return 'That coupon doesn\'t apply to this type of session.';
        }
        $min = (float) ( $c['min_spend'] ?? 0 );
        if ( $min > 0 && $price !== null && (float) $price + 0.004 < $min ) {
            return 'This coupon needs a session of at least TT$' . number_format( $min, 0 ) . ' (before the discount).';
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

    // ── Use log and reports ──────────────────────────

    const LOG = 'tweller_flow_2_coupon_log';

    /** One line per booking that used a coupon (newest last, capped). */
    public static function record( $code, $session_id, $client, $package_key, $session_type, $discount, $total ) {
        $log   = get_option( self::LOG, array() );
        $log   = is_array( $log ) ? $log : array();
        $log[] = array(
            'time'       => time(),
            'code'       => $code,
            'session_id' => (int) $session_id,
            'client'     => $client,
            'package'    => $package_key,
            'session'    => $session_type,
            'discount'   => round( (float) $discount, 2 ),
            'total'      => round( (float) $total, 2 ),
        );
        if ( count( $log ) > 3000 ) $log = array_slice( $log, -3000 );
        update_option( self::LOG, $log, false );
    }

    /** Totals overall and per code, from the use log. */
    public static function report() {
        $log = get_option( self::LOG, array() );
        $log = is_array( $log ) ? $log : array();
        $by  = array();
        $sum = array( 'uses' => 0, 'discount' => 0.0, 'revenue' => 0.0 );
        foreach ( $log as $e ) {
            $k = $e['code'];
            if ( ! isset( $by[ $k ] ) ) $by[ $k ] = array( 'uses' => 0, 'discount' => 0.0, 'revenue' => 0.0, 'last' => 0 );
            $by[ $k ]['uses']++;
            $by[ $k ]['discount'] += $e['discount'];
            $by[ $k ]['revenue']  += $e['total'];
            $by[ $k ]['last']      = max( $by[ $k ]['last'], $e['time'] );
            $sum['uses']++;
            $sum['discount'] += $e['discount'];
            $sum['revenue']  += $e['total'];
        }
        return array( 'log' => array_reverse( $log ), 'by' => $by, 'sum' => $sum );
    }

    public static function admin_csv() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tweller_flow_2_coupon_csv' );
        $r = self::report();
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="coupon-uses-' . current_time( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Date', 'Code', 'Client', 'Session type', 'Package', 'Discount TTD', 'Booking total TTD', 'Booking ID' ) );
        foreach ( $r['log'] as $e ) {
            // A client name could start with = + - @ ; keep spreadsheets from running it as a formula.
            $name = preg_match( '/^[=+\-@\t\r]/', (string) $e['client'] ) ? "'" . $e['client'] : $e['client'];
            fputcsv( $out, array( wp_date( 'Y-m-d H:i', $e['time'] ), $e['code'], $name, $e['session'], $e['package'], $e['discount'], $e['total'], $e['session_id'] ) );
        }
        fclose( $out );
        exit;
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
        // A package mismatch is shown in the summary, not here; the minimum
        // spend is checked against the package that is picked (current price).
        $pkgs  = get_option( 'tweller_flow_2_packages', array() );
        $price = ( $pk !== '' && isset( $pkgs[ $pk ] ) ) ? (float) $pkgs[ $pk ]['price'] + ( class_exists( 'TwellerFlow2_Scarcity' ) ? TwellerFlow2_Scarcity::price_add( $st ) : 0 ) : null;
        $err = self::error( $c, '', $st, $em, $price );
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
            'min_spend' => (float) ( $c['min_spend'] ?? 0 ),
            'expires_ts' => self::expiry_ts( $c ),
            'now_ts'   => time(),
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
        $report   = self::report();
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

        // A date, or a date and time ("2026-11-30T18:00"); stored as "Y-m-d H:i" in the site's timezone.
        $expires = str_replace( 'T', ' ', sanitize_text_field( $p['expires'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $expires ) ) $expires = '';

        $all          = self::all();
        $all[ $code ] = array(
            'code'          => $code,
            'type'          => $type,
            'amount'        => $amount,
            'packages'      => $pk,
            'session_types' => $st,
            'expires'       => $expires,
            'max_uses'      => max( 0, (int) ( $p['max_uses'] ?? 1 ) ),
            'min_spend'     => max( 0, round( (float) ( $p['min_spend'] ?? 0 ), 2 ) ),
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

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Limited spots ("Only 7 left") in batches.
 *
 * For a session type (e.g. Christmas Photos) you set a batch size, say 10.
 * Every booking of that session type moves the counter down: 3 bookings
 * today = "Only 7 left". When a batch is used up the next batch starts, and
 * the price can step up by a set TT$ amount per batch.
 *
 * The number is the real count of bookings (never invented): bookings of that
 * session type made since the "count from" date, plus an optional starting
 * number for spots already taken some other way.
 */
class TwellerFlow2_Scarcity {

    const OPTION = 'tweller_flow_2_scarcity';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 22 );
        add_action( 'admin_post_tweller_flow_2_scarcity_save', array( __CLASS__, 'admin_save' ) );
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'clear_cache' ), 5, 2 );
    }

    public static function all() {
        $all = get_option( self::OPTION, array() );
        return is_array( $all ) ? $all : array();
    }

    public static function clear_cache( $session_id = 0, $data = array() ) {
        foreach ( array_keys( self::all() ) as $k ) delete_transient( 'tf2_scar_' . $k );
    }

    /** Bookings of one session type since a date (cached for a short while). */
    private static function bookings_since( $session_key, $since ) {
        $ck  = 'tf2_scar_' . $session_key;
        $hit = get_transient( $ck );
        if ( is_array( $hit ) && ( $hit['since'] ?? '' ) === $since ) return (int) $hit['n'];
        global $wpdb;
        $n = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tweller_sessions WHERE session_type = %s AND created_at >= %s",
            $session_key, $since . ' 00:00:00'
        ) );
        set_transient( $ck, array( 'since' => $since, 'n' => $n ), 60 );
        return $n;
    }

    /**
     * The counter for a session type, or null when limited spots are off for it.
     * left = spots left in this batch; add = TT$ added to every price in it.
     */
    public static function state( $session_key ) {
        $all = self::all();
        $c   = $all[ $session_key ] ?? null;
        if ( ! $c || empty( $c['enabled'] ) ) return null;
        $batch = max( 2, (int) $c['batch'] );
        $count = max( 0, (int) $c['offset'] ) + self::bookings_since( $session_key, $c['since'] );
        $index = intdiv( $count, $batch );
        $step  = max( 0.0, (float) $c['step'] );
        return array(
            'batch'    => $batch,
            'count'    => $count,
            'index'    => $index,
            'left'     => $batch - ( $count % $batch ),
            'step'     => $step,
            'add'      => round( $step * $index, 2 ),
            'next_add' => round( $step * ( $index + 1 ), 2 ),
        );
    }

    /** TT$ added to prices for this session type right now (server side, used when pricing a booking). */
    public static function price_add( $session_key ) {
        $s = $session_key !== '' ? self::state( $session_key ) : null;
        return $s ? (float) $s['add'] : 0.0;
    }

    /** What the booking form needs: session key => { left, batch, add, next_add }. */
    public static function public_map() {
        $out = array();
        foreach ( array_keys( self::all() ) as $k ) {
            $s = self::state( $k );
            if ( $s ) $out[ $k ] = array( 'left' => $s['left'], 'batch' => $s['batch'], 'add' => $s['add'], 'next_add' => $s['next_add'], 'step' => $s['step'] );
        }
        return $out;
    }

    // ── Admin ────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page( 'tweller-flow-2', 'Limited Spots', 'Limited Spots', 'manage_options', 'tweller-flow-2-scarcity', array( __CLASS__, 'admin_page' ) );
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $types = get_option( 'tweller_flow_2_session_types', array() );
        $conf  = self::all();
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/scarcity.php';
    }

    public static function admin_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tweller_flow_2_scarcity_save' );
        $p     = wp_unslash( $_POST );
        $types = get_option( 'tweller_flow_2_session_types', array() );
        $old   = self::all();
        $out   = array();
        foreach ( array_keys( (array) $types ) as $k ) {
            $row = $p['s'][ $k ] ?? array();
            $was = $old[ $k ] ?? array();
            $on  = ! empty( $row['enabled'] );
            $since = sanitize_text_field( $row['since'] ?? '' );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) $since = $was['since'] ?? current_time( 'Y-m-d' );
            // Turning it on for the first time starts counting from today.
            if ( $on && empty( $was['enabled'] ) && empty( $row['since'] ) ) $since = current_time( 'Y-m-d' );
            $out[ $k ] = array(
                'enabled' => $on ? 1 : 0,
                'batch'   => min( 500, max( 2, (int) ( $row['batch'] ?? 10 ) ) ),
                'offset'  => max( 0, (int) ( $row['offset'] ?? 0 ) ),
                'step'    => max( 0, round( (float) ( $row['step'] ?? 0 ), 2 ) ),
                'since'   => $since,
            );
        }
        update_option( self::OPTION, $out, false );
        self::clear_cache();
        wp_safe_redirect( admin_url( 'admin.php?page=tweller-flow-2-scarcity&saved=1' ) );
        exit;
    }
}

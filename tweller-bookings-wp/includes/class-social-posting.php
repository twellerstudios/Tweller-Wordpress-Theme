<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Posting client photos to the studio's social media — from the app.
 *
 * Each client chose at booking how their photos may be shared
 * (TwellerFlow2_Image_Consent): none, up to 5, or as many as the studio
 * likes. The app's "Post or save photos" screen reads the limit from here
 * and records what was actually shared, so "up to 5" holds across every
 * time the studio posts from that gallery — not just within one go.
 *
 * Bookings made before the consent question existed have no choice on
 * record; they are treated as "up to 5", the standard, and flagged as such.
 */
class TwellerFlow2_Social_Posting {

    const LIMITED_MAX = 5;
    const OPT_POSTED  = 'tweller_social_posted_'; // + session id => [photo_id => time]

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'tweller-flow-2/v1', '/automation/gallery/(?P<code>[a-zA-Z0-9\-]+)/posted', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_mark_posted' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ) );
    }

    /** Photo ids already shared from this session, keyed id => unix time. */
    public static function posted( $session_id ) {
        $p = get_option( self::OPT_POSTED . (int) $session_id, array() );
        return is_array( $p ) ? $p : array();
    }

    /**
     * What the studio may do with this client's photos.
     *
     * @return array{choice:string, label:string, limit:int|null, posted:int[], remaining:int|null, recorded:bool}
     *         limit/remaining null = no cap; 0 = do not post.
     */
    public static function info( $session ) {
        $choice = class_exists( 'TwellerFlow2_Image_Consent' ) ? TwellerFlow2_Image_Consent::get( $session->id ) : '';
        $recorded = $choice !== '';
        if ( ! $recorded ) $choice = 'limited';

        if ( $choice === 'declined' ) {
            $limit = 0;
            $label = 'Do not post — client asked for privacy';
        } elseif ( $choice === 'unlimited' ) {
            $limit = null;
            $label = 'OK to post — as many as you like';
        } else {
            $limit = self::LIMITED_MAX;
            $label = $recorded ? 'OK to post — up to ' . self::LIMITED_MAX . ' photos' : 'Not recorded (older booking) — treat as up to ' . self::LIMITED_MAX;
        }

        $posted = array_map( 'intval', array_keys( self::posted( $session->id ) ) );
        return array(
            'choice'    => $choice,
            'label'     => $label,
            'limit'     => $limit,
            'posted'    => $posted,
            'remaining' => $limit === null ? null : max( 0, $limit - count( $posted ) ),
            'recorded'  => $recorded,
        );
    }

    /** App: record photos that were just shared out. Enforces the limit. */
    public static function rest_mark_posted( $request ) {
        $session = TwellerFlow2_Session::get_by_code( sanitize_text_field( $request['code'] ) );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $ids = $request->get_param( 'photo_ids' );
        if ( ! is_array( $ids ) ) $ids = explode( ',', (string) $ids );
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( ! $ids ) {
            return new WP_Error( 'no_photos', 'No photos given.', array( 'status' => 400 ) );
        }

        $info   = self::info( $session );
        $posted = self::posted( $session->id );
        $new    = array_values( array_diff( $ids, array_keys( $posted ) ) );

        if ( $info['limit'] === 0 ) {
            return new WP_Error( 'declined', 'This client asked for their photos not to be posted.', array( 'status' => 403 ) );
        }
        if ( $info['limit'] !== null && count( $new ) > $info['remaining'] ) {
            return new WP_Error( 'over_limit', sprintf( 'This client allows %d posted photos; %d already used.', $info['limit'], count( $posted ) ), array( 'status' => 403 ) );
        }

        foreach ( $new as $id ) $posted[ $id ] = time();
        update_option( self::OPT_POSTED . (int) $session->id, $posted, false );

        return rest_ensure_response( array( 'ok' => true, 'social' => self::info( $session ) ) );
    }

    /**
     * Recent delivered galleries the studio may post from, newest first —
     * the app's "Ready to post" list.
     */
    public static function ready_list( $limit = 8 ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        $rows  = $wpdb->get_results(
            "SELECT * FROM $table WHERE current_stage IN ('uploaded','delivered') ORDER BY updated_at DESC LIMIT 40"
        );
        $out = array();
        foreach ( (array) $rows as $s ) {
            $info = self::info( $s );
            if ( $info['limit'] === 0 || $info['remaining'] === 0 ) continue;
            $out[] = array(
                'tracking_code' => $s->tracking_code,
                'client_name'   => $s->client_name,
                'session_type'  => TwellerFlow2_Session::session_type_label( $s ),
                'session_date'  => $s->session_date,
                'label'         => $info['label'],
                'remaining'     => $info['remaining'],
                'posted'        => count( $info['posted'] ),
            );
            if ( count( $out ) >= $limit ) break;
        }
        return $out;
    }
}

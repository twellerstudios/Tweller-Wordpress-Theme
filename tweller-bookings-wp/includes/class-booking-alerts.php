<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WhatsApp alert to the studio for every new online booking.
 *
 * Uses CallMeBot's free WhatsApp API (https://www.callmebot.com), which can
 * message the owner's own number once that number has been registered with
 * CallMeBot and the API key it replies with is saved in
 * Tweller Bookings → Christmas Page → Booking alerts. (The email alert to the
 * studio is TwellerFlow2_Notifications::notify_studio_new_booking.)
 */
class TwellerFlow2_Booking_Alerts {

    const OPT = 'tweller_flow_2_whatsapp_alert';

    public static function init() {
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'on_created' ), 30, 2 );
        add_action( 'admin_post_tweller_flow_2_test_whatsapp', array( __CLASS__, 'admin_test' ) );
    }

    public static function settings() {
        $s = get_option( self::OPT, array() );
        return wp_parse_args( is_array( $s ) ? $s : array(), array( 'enabled' => 0, 'phone' => '18683422948', 'apikey' => '' ) );
    }

    public static function ready() {
        $s = self::settings();
        return ! empty( $s['enabled'] ) && $s['phone'] !== '' && $s['apikey'] !== '';
    }

    /** Only bookings made by clients on the website, not ones the studio enters. */
    public static function on_created( $session_id, $data ) {
        if ( ! self::ready() || ! TwellerFlow2_Booking_API::$public_booking ) return;
        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session ) return;
        self::send( self::message( $session ) );
    }

    public static function message( $session ) {
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg      = $packages[ $session->package_type ]['name'] ?? $session->package_type;
        $when     = $session->session_date ? date( 'D j M', strtotime( $session->session_date ) ) : '';
        if ( ! empty( $session->session_time ) ) $when .= ', ' . date( 'g:i A', strtotime( $session->session_date . ' ' . $session->session_time ) );
        $lines = array(
            '📸 New booking — ' . $session->client_name,
            trim( TwellerFlow2_Session::session_type_label( $session ) . ' · ' . $pkg, ' ·' ),
            $when,
            'Total TT$' . number_format( (float) $session->total_amount, 0 ) . ' (deposit TT$' . number_format( (float) $session->total_amount / 2, 0 ) . ')',
            'Phone: ' . $session->client_phone,
        );
        if ( $session->notes ) $lines[] = "\n" . $session->notes;
        $lines[] = "\n" . admin_url( 'admin.php?page=tweller-flow-2-session&id=' . (int) $session->id );
        return implode( "\n", array_filter( $lines ) );
    }

    /** @return true|WP_Error */
    public static function send( $text, $blocking = false ) {
        $s   = self::settings();
        $url = add_query_arg( array(
            'phone'  => rawurlencode( '+' . preg_replace( '/\D/', '', $s['phone'] ) ),
            'text'   => rawurlencode( $text ),
            'apikey' => rawurlencode( $s['apikey'] ),
        ), 'https://api.callmebot.com/whatsapp.php' );
        $res = wp_remote_get( $url, array( 'timeout' => $blocking ? 15 : 3, 'blocking' => $blocking ) );
        if ( is_wp_error( $res ) ) return $res;
        if ( $blocking && wp_remote_retrieve_response_code( $res ) >= 400 ) {
            return new WP_Error( 'callmebot', wp_strip_all_tags( wp_remote_retrieve_body( $res ) ) ?: 'CallMeBot refused the message.' );
        }
        return true;
    }

    public static function admin_test() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed' );
        check_admin_referer( 'tweller_flow_2_test_whatsapp' );
        $r = self::send( "✅ Tweller Bookings: WhatsApp alerts are working. You'll get a message like this for every new booking.", true );
        $q = is_wp_error( $r ) ? array( 'wa_test' => 'fail', 'wa_msg' => rawurlencode( $r->get_error_message() ) ) : array( 'wa_test' => 'ok' );
        wp_safe_redirect( add_query_arg( $q, admin_url( 'admin.php?page=tweller-flow-2-christmas' ) ) );
        exit;
    }
}

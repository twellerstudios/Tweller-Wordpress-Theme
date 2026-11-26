<?php
/**
 * "Ready to post": every gallery, filtered by what the client agreed to at
 * booking (share freely / up to 5 / keep private).
 *
 * Pick photos album by album into one basket (kept per admin user), preview
 * them as a Facebook and an Instagram post, then download everything in one
 * zip (a folder per client). Downloading can mark the photos as posted, which
 * counts against an "up to 5" client's limit everywhere (the app included,
 * via TwellerFlow2_Social_Posting).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Social_Dashboard {

    const PAGE   = 'tweller-flow-2-social';
    const BASKET = 'tf2_post_basket'; // user meta: session id => [photo ids]

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 31 );
        add_action( 'wp_ajax_tf2_post_basket', array( __CLASS__, 'ajax_basket' ) );
        add_action( 'admin_post_tf2_post_download', array( __CLASS__, 'download' ) );
    }

    public static function url( $args = array() ) {
        return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
    }

    public static function admin_menu() {
        add_submenu_page( 'tweller-flow-2', 'Ready to post', 'Ready to post', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
    }

    // ── Data ─────────────────────────────────────────

    /** Group: free (share freely), five (up to 5), private. */
    public static function group( $info ) {
        if ( $info['limit'] === 0 ) return 'private';
        return $info['limit'] === null ? 'free' : 'five';
    }

    /** Every session with gallery photos, newest first. */
    public static function albums() {
        global $wpdb;
        $pt = $wpdb->prefix . 'tweller_gallery_photos';
        $st = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt ) ) !== $pt ) return array();
        $rows = (array) $wpdb->get_results( "SELECT s.*, COUNT(p.id) AS photo_count FROM $st s JOIN $pt p ON p.session_id = s.id GROUP BY s.id ORDER BY s.session_date DESC, s.id DESC LIMIT 300" );
        $basket = self::basket();
        $out = array();
        foreach ( $rows as $s ) {
            $info  = TwellerFlow2_Social_Posting::info( $s );
            $thumb = $wpdb->get_col( $wpdb->prepare( "SELECT filename FROM $pt WHERE session_id = %d ORDER BY sort_order ASC LIMIT 4", $s->id ) );
            $base  = TwellerFlow2_Gallery::get_gallery_url( $s->tracking_code );
            $out[] = array(
                'session' => $s,
                'info'    => $info,
                'group'   => self::group( $info ),
                'count'   => (int) $s->photo_count,
                'thumbs'  => array_map( function ( $f ) use ( $base ) { return $base . '/thumbs/' . rawurlencode( $f ); }, $thumb ),
                'picked'  => count( $basket[ (int) $s->id ] ?? array() ),
            );
        }
        return $out;
    }

    public static function photos( $session ) {
        $base = TwellerFlow2_Gallery::get_gallery_url( $session->tracking_code );
        return array_map( function ( $p ) use ( $base ) {
            return array( 'id' => (int) $p->id, 'file' => $p->filename, 'thumb' => $base . '/thumbs/' . rawurlencode( $p->filename ), 'full' => $base . '/' . rawurlencode( $p->filename ) );
        }, (array) TwellerFlow2_Gallery::get_photos( $session->id ) );
    }

    // ── Basket (per admin user) ──────────────────────

    public static function basket() {
        $b = get_user_meta( get_current_user_id(), self::BASKET, true );
        return is_array( $b ) ? $b : array();
    }

    private static function save_basket( $b ) {
        $b = array_filter( $b );
        update_user_meta( get_current_user_id(), self::BASKET, $b );
    }

    public static function basket_summary() {
        $b = self::basket();
        $n = 0; foreach ( $b as $ids ) $n += count( $ids );
        return array( 'photos' => $n, 'albums' => count( $b ), 'by' => array_map( 'count', $b ) );
    }

    /** Toggle / set / clear, enforcing each client's limit. */
    public static function ajax_basket() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'msg' => 'Not allowed.' ), 403 );
        check_ajax_referer( 'tf2_post_basket' );
        $b   = self::basket();
        $do  = sanitize_key( $_POST['do'] ?? '' );
        $sid = (int) ( $_POST['session'] ?? 0 );

        if ( $do === 'clear' ) {
            if ( $sid ) unset( $b[ $sid ] ); else $b = array();
            self::save_basket( $b );
            wp_send_json_success( self::basket_summary() );
        }

        $s = $sid ? TwellerFlow2_Session::get( $sid ) : null;
        if ( ! $s ) wp_send_json_error( array( 'msg' => 'Album not found.' ), 404 );
        $info = TwellerFlow2_Social_Posting::info( $s );
        if ( $info['limit'] === 0 ) wp_send_json_error( array( 'msg' => 'This client asked for their photos not to be posted.' ), 403 );

        $valid = wp_list_pluck( self::photos( $s ), 'id' );
        $pid   = (int) ( $_POST['photo'] ?? 0 );
        if ( ! in_array( $pid, $valid, true ) ) wp_send_json_error( array( 'msg' => 'Photo not found.' ), 404 );
        $mine  = array_values( array_map( 'intval', $b[ $sid ] ?? array() ) );
        if ( ! empty( $_POST['on'] ) ) {
            if ( ! in_array( $pid, $mine, true ) ) {
                // Photos already posted can be picked again freely; only new ones use up the limit.
                $new_picks = array_diff( $mine, $info['posted'] );
                if ( $info['remaining'] !== null && ! in_array( $pid, $info['posted'], true ) && count( $new_picks ) >= $info['remaining'] ) {
                    wp_send_json_error( array( 'msg' => $info['remaining'] === 0
                        ? 'You\'ve already posted this client\'s ' . $info['limit'] . ' photos.'
                        : 'This client allows ' . $info['limit'] . ' photos' . ( count( $info['posted'] ) ? ' (' . count( $info['posted'] ) . ' already posted)' : '' ) . '. Unselect one to swap it.' ), 409 );
                }
                $mine[] = $pid;
            }
        } else {
            $mine = array_values( array_diff( $mine, array( $pid ) ) );
        }
        $b[ $sid ] = $mine;
        self::save_basket( $b );
        wp_send_json_success( self::basket_summary() );
    }

    // ── Download (one zip, a folder per client) ──────

    public static function download() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tf2_post_download' );
        $b = self::basket();
        if ( ! $b ) { wp_safe_redirect( self::url() ); exit; }
        if ( ! class_exists( 'ZipArchive' ) ) wp_die( 'Zip is not available on this server.' );

        $mark = ! empty( $_POST['mark'] );
        $dir  = wp_upload_dir()['basedir'];
        $zip_path = trailingslashit( $dir ) . '.tf2-post-' . wp_generate_password( 16, false ) . '.zip';
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE ) !== true ) wp_die( 'Could not build the zip.' );

        $added = 0;
        foreach ( $b as $sid => $ids ) {
            $s = TwellerFlow2_Session::get( (int) $sid );
            if ( ! $s ) continue;
            $info = TwellerFlow2_Social_Posting::info( $s );
            if ( $info['limit'] === 0 ) continue;
            $folder = sanitize_file_name( trim( $s->client_name . ' ' . $s->session_date ) ) ?: 'album-' . (int) $sid;
            $gdir   = TwellerFlow2_Gallery::get_gallery_dir( $s->tracking_code );
            $done   = array();
            foreach ( self::photos( $s ) as $p ) {
                if ( ! in_array( $p['id'], array_map( 'intval', $ids ), true ) ) continue;
                $path = $gdir . '/' . $p['file'];
                if ( ! is_file( $path ) ) continue;
                $zip->addFile( $path, $folder . '/' . $p['file'] );
                $done[] = $p['id']; $added++;
            }
            if ( $mark && $done ) {
                $posted = TwellerFlow2_Social_Posting::posted( $s->id );
                $room   = $info['limit'] === null ? PHP_INT_MAX : max( 0, $info['limit'] - count( $posted ) );
                foreach ( $done as $id ) {
                    if ( isset( $posted[ $id ] ) ) continue;
                    if ( $room-- <= 0 ) break;
                    $posted[ $id ] = time();
                }
                update_option( TwellerFlow2_Social_Posting::OPT_POSTED . (int) $s->id, $posted, false );
            }
        }
        $zip->close();
        if ( ! $added || ! is_file( $zip_path ) ) { @unlink( $zip_path ); wp_die( 'None of the selected photos could be found on the server.' ); }
        self::save_basket( array() );
        TwellerFlow2_Gallery::prepare_for_large_download();
        TwellerFlow2_Gallery::stream_zip( $zip_path, 'tweller-to-post-' . wp_date( 'Y-m-d' ) . '.zip' );
    }

    // ── Page ─────────────────────────────────────────

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $album_id = (int) ( $_GET['album'] ?? 0 );
        $filter   = sanitize_key( $_GET['filter'] ?? 'ok' );
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/social.php';
    }
}

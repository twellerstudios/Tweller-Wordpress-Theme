<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reads the giveaway posts straight from Facebook and Instagram (Meta Graph
 * API): the comments (with their likes), the post's reactions, comments,
 * shares, reach and video views, and how many followers each page has, so
 * the giveaway dashboard fills itself.
 *
 * One access token, stored here (never shown again once saved). The
 * giveaway page and the admin only ever see the numbers.
 *
 * TikTok has no comment feed for a site like this, so its numbers are typed
 * in by hand and its comments are imported from a CSV.
 */
class TwellerFlow2_Giveaway_Meta {

    const VERSION  = 'v23.0';
    const OPTION   = 'tweller_flow_2_giveaway_meta';
    const STATS    = 'tweller_flow_2_giveaway_stats';
    const CRON     = 'tweller_flow_2_giveaway_sync';
    const MAX_COMMENT_PAGES = 12; // 100 comments a page

    public static function init() {
        add_filter( 'cron_schedules', function ( $s ) { $s['tf2_10min'] = array( 'interval' => 600, 'display' => 'Every 10 minutes' ); return $s; } );
        add_action( self::CRON, array( __CLASS__, 'sync' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 25 );
        foreach ( array( 'save', 'test', 'sync', 'tiktok' ) as $a ) add_action( 'admin_post_tweller_flow_2_gwmeta_' . $a, array( __CLASS__, 'admin_' . $a ) );
    }

    public static function schedule() {
        $on = self::connected();
        $next = wp_next_scheduled( self::CRON );
        if ( $on && ! $next ) wp_schedule_event( time() + 120, 'tf2_10min', self::CRON );
        if ( ! $on && $next ) wp_unschedule_event( $next, self::CRON );
    }

    // ── Settings ─────────────────────────────────────

    public static function settings() {
        $s = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $s ) ? $s : array(), array(
            'token' => '', 'page_id' => '', 'ig_id' => '',
            'links'  => array(),   // round id => [ 'facebook' => post id, 'instagram' => media id ]
            'tiktok' => array(),   // round id => [ likes, comments, views ]
        ) );
    }

    public static function connected() {
        $s = self::settings();
        return $s['token'] !== '' && ( $s['page_id'] !== '' || $s['ig_id'] !== '' );
    }

    public static function stats() {
        $s = get_option( self::STATS, array() );
        return is_array( $s ) ? $s : array();
    }

    public static function last_sync() {
        return (int) ( self::stats()['synced'] ?? 0 );
    }

    // ── Graph API ────────────────────────────────────

    /** GET a Graph path. Returns the decoded array or a WP_Error with Meta's own message. */
    public static function api( $path, $params = array() ) {
        $s = self::settings();
        if ( $s['token'] === '' ) return new WP_Error( 'no_token', 'No access token saved yet.' );
        $params['access_token'] = $s['token'];
        $url = 'https://graph.facebook.com/' . self::VERSION . '/' . ltrim( $path, '/' ) . '?' . http_build_query( $params, '', '&' );
        $res = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 2, 'headers' => array( 'Accept' => 'application/json' ) ) );
        if ( is_wp_error( $res ) ) return $res;
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $body ) ) return new WP_Error( 'bad_response', 'Meta sent back something unexpected.' );
        if ( isset( $body['error'] ) ) {
            $e = $body['error'];
            return new WP_Error( 'graph_' . ( $e['code'] ?? 0 ), ( $e['message'] ?? 'Meta refused the request.' ) );
        }
        return $body;
    }

    /** Every page of a list endpoint (comments, posts), up to $pages pages. */
    private static function api_list( $path, $params, $pages = 5 ) {
        $out = array();
        $res = self::api( $path, $params );
        for ( $i = 0; $i < $pages; $i++ ) {
            if ( is_wp_error( $res ) ) return $i === 0 ? $res : $out;
            foreach ( (array) ( $res['data'] ?? array() ) as $row ) $out[] = $row;
            $after = $res['paging']['cursors']['after'] ?? '';
            if ( empty( $res['paging']['next'] ) || $after === '' ) break;
            $res = self::api( $path, array_merge( $params, array( 'after' => $after ) ) );
        }
        return $out;
    }

    /** What works with this token (shown after "Test connection"). */
    public static function test() {
        $s   = self::settings();
        $out = array( 'facebook' => null, 'instagram' => null );
        if ( $s['page_id'] !== '' ) {
            $r = self::api( $s['page_id'], array( 'fields' => 'name,fan_count,followers_count' ) );
            $out['facebook'] = is_wp_error( $r ) ? array( 'error' => $r->get_error_message() ) : array( 'name' => $r['name'] ?? '', 'followers' => (int) ( $r['followers_count'] ?? $r['fan_count'] ?? 0 ) );
        }
        if ( $s['ig_id'] !== '' ) {
            $r = self::api( $s['ig_id'], array( 'fields' => 'username,followers_count,media_count' ) );
            $out['instagram'] = is_wp_error( $r ) ? array( 'error' => $r->get_error_message() ) : array( 'name' => '@' . ( $r['username'] ?? '' ), 'followers' => (int) ( $r['followers_count'] ?? 0 ) );
        }
        return $out;
    }

    /** The latest posts, so the owner picks one from a list instead of finding an ID. */
    public static function recent_posts( $platform ) {
        $s = self::settings();
        if ( $platform === 'facebook' && $s['page_id'] !== '' ) {
            $rows = self::api_list( $s['page_id'] . '/posts', array( 'fields' => 'id,message,created_time,permalink_url', 'limit' => 20 ), 1 );
            if ( is_wp_error( $rows ) ) return $rows;
            return array_map( function ( $r ) { return array( 'id' => $r['id'], 'label' => wp_trim_words( $r['message'] ?? '(photo or video)', 12 ) . ' · ' . wp_date( 'M j', strtotime( $r['created_time'] ) ), 'url' => $r['permalink_url'] ?? '' ); }, $rows );
        }
        if ( $platform === 'instagram' && $s['ig_id'] !== '' ) {
            $rows = self::api_list( $s['ig_id'] . '/media', array( 'fields' => 'id,caption,timestamp,permalink,media_type', 'limit' => 20 ), 1 );
            if ( is_wp_error( $rows ) ) return $rows;
            return array_map( function ( $r ) { return array( 'id' => $r['id'], 'label' => wp_trim_words( $r['caption'] ?? '(no caption)', 12 ) . ' · ' . wp_date( 'M j', strtotime( $r['timestamp'] ) ), 'url' => $r['permalink'] ?? '' ); }, $rows );
        }
        return array();
    }

    // ── Sync ─────────────────────────────────────────

    /** Pull everything for every linked post; remember the numbers and a daily snapshot. */
    public static function sync() {
        if ( ! self::connected() ) return new WP_Error( 'not_connected', 'Connect Meta first.' );
        if ( get_transient( 'tf2_gw_syncing' ) ) return new WP_Error( 'busy', 'A sync is already running.' );
        set_transient( 'tf2_gw_syncing', 1, 120 );
        $s     = self::settings();
        $stats = self::stats();
        $notes = array();
        $stats['rounds'] = isset( $stats['rounds'] ) && is_array( $stats['rounds'] ) ? $stats['rounds'] : array();

        // Followers (the page-growth number)
        $pg = array();
        if ( $s['page_id'] !== '' ) {
            $r = self::api( $s['page_id'], array( 'fields' => 'fan_count,followers_count' ) );
            if ( is_wp_error( $r ) ) $notes[] = 'Facebook page: ' . $r->get_error_message(); else $pg['facebook'] = (int) ( $r['followers_count'] ?? $r['fan_count'] ?? 0 );
        }
        if ( $s['ig_id'] !== '' ) {
            $r = self::api( $s['ig_id'], array( 'fields' => 'followers_count' ) );
            if ( is_wp_error( $r ) ) $notes[] = 'Instagram: ' . $r->get_error_message(); else $pg['instagram'] = (int) ( $r['followers_count'] ?? 0 );
        }
        if ( $pg ) {
            $stats['followers'] = $pg + (array) ( $stats['followers'] ?? array() );
            $day = current_time( 'Y-m-d' );
            $stats['history'] = isset( $stats['history'] ) && is_array( $stats['history'] ) ? $stats['history'] : array();
            if ( empty( $stats['first'] ) ) $stats['first'] = $pg;                 // followers when tracking began
            $stats['history'][ $day ] = $pg;
            $stats['history'] = array_slice( $stats['history'], -90, null, true );
        }

        foreach ( TwellerFlow2_Giveaway::rounds() as $round ) {
            $links = $s['links'][ $round['id'] ] ?? array();
            // Only weeks that have started need syncing (and the week after closing, for final likes).
            if ( time() < $round['start_ts'] - DAY_IN_SECONDS || time() > $round['end_ts'] + 3 * DAY_IN_SECONDS ) continue;
            if ( ! empty( $links['facebook'] ) ) {
                $r = self::sync_facebook( $links['facebook'], $round['id'] );
                if ( is_wp_error( $r ) ) $notes[] = $round['label'] . ' Facebook: ' . $r->get_error_message(); else $stats['rounds'][ $round['id'] ]['facebook'] = $r;
            }
            if ( ! empty( $links['instagram'] ) ) {
                $r = self::sync_instagram( $links['instagram'], $round['id'] );
                if ( is_wp_error( $r ) ) $notes[] = $round['label'] . ' Instagram: ' . $r->get_error_message(); else $stats['rounds'][ $round['id'] ]['instagram'] = $r;
            }
        }
        $stats['synced'] = time();
        $stats['notes']  = $notes;
        update_option( self::STATS, $stats, false );
        delete_transient( 'tf2_gw_board' );
        delete_transient( 'tf2_gw_syncing' );
        return true;
    }

    /** First metric from a list that Meta answers (their metric names change from time to time). */
    private static function first_metric( $path, $metrics ) {
        foreach ( $metrics as $m ) {
            $r = self::api( $path, array( 'metric' => $m ) );
            if ( ! is_wp_error( $r ) && isset( $r['data'][0]['values'][0]['value'] ) && is_numeric( $r['data'][0]['values'][0]['value'] ) ) return (int) $r['data'][0]['values'][0]['value'];
        }
        return null;
    }

    private static function sync_facebook( $post_id, $round_id ) {
        $p = self::api( $post_id, array( 'fields' => 'permalink_url,shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)' ) );
        if ( is_wp_error( $p ) ) return $p;
        $permalink = $p['permalink_url'] ?? '';
        // Comments on the post (top-level), each with its like count
        $rows = self::api_list( $post_id . '/comments', array( 'fields' => 'id,message,like_count,created_time,from{name,id},permalink_url', 'filter' => 'toplevel', 'order' => 'chronological', 'limit' => 100 ), self::MAX_COMMENT_PAGES );
        $comments = array();
        if ( ! is_wp_error( $rows ) ) {
            foreach ( $rows as $c ) {
                if ( empty( $c['message'] ) ) continue;
                $name = $c['from']['name'] ?? '';
                if ( $name === '' ) continue; // Meta did not share who wrote it
                $link = $c['permalink_url'] ?? ( $permalink ? $permalink . ( strpos( $permalink, '?' ) === false ? '?' : '&' ) . 'comment_id=' . rawurlencode( substr( strrchr( '_' . $c['id'], '_' ), 1 ) ) : '' );
                $comments[] = array( 'id' => $c['id'], 'user' => $name, 'text' => $c['message'], 'likes' => (int) ( $c['like_count'] ?? 0 ), 'date' => $c['created_time'] ?? '', 'link' => $link );
            }
            TwellerFlow2_Giveaway::add_api_comments( 'facebook', $round_id, $comments );
        }
        $reach = self::first_metric( $post_id . '/insights', array( 'post_total_media_view_unique', 'post_impressions_unique' ) );
        $views = self::first_metric( $post_id . '/insights', array( 'post_media_view', 'post_impressions' ) );
        $video = self::first_metric( $post_id . '/insights', array( 'post_video_views', 'post_video_views_organic' ) );
        return array(
            'likes'    => (int) ( $p['reactions']['summary']['total_count'] ?? 0 ),
            'comments' => (int) ( $p['comments']['summary']['total_count'] ?? 0 ),
            'shares'   => (int) ( $p['shares']['count'] ?? 0 ),
            'reach'    => $reach, 'views' => $views ?? $video, 'video_views' => $video,
            'url'      => $permalink,
            'imported' => count( $comments ),
            'error'    => is_wp_error( $rows ) ? $rows->get_error_message() : '',
            'at'       => time(),
        );
    }

    private static function sync_instagram( $media_id, $round_id ) {
        $m = self::api( $media_id, array( 'fields' => 'like_count,comments_count,permalink,media_type,timestamp' ) );
        if ( is_wp_error( $m ) ) return $m;
        $permalink = $m['permalink'] ?? '';
        $rows = self::api_list( $media_id . '/comments', array( 'fields' => 'id,text,username,like_count,timestamp', 'limit' => 100 ), self::MAX_COMMENT_PAGES );
        $comments = array();
        if ( ! is_wp_error( $rows ) ) {
            foreach ( $rows as $c ) {
                if ( empty( $c['text'] ) || empty( $c['username'] ) ) continue;
                $comments[] = array( 'id' => $c['id'], 'user' => $c['username'], 'text' => $c['text'], 'likes' => (int) ( $c['like_count'] ?? 0 ), 'date' => $c['timestamp'] ?? '', 'link' => $permalink ? trailingslashit( $permalink ) . 'c/' . rawurlencode( $c['id'] ) . '/' : '' );
            }
            TwellerFlow2_Giveaway::add_api_comments( 'instagram', $round_id, $comments );
        }
        $reach = self::first_metric( $media_id . '/insights', array( 'reach' ) );
        $views = self::first_metric( $media_id . '/insights', array( 'views', 'plays', 'impressions' ) );
        return array(
            'likes'    => (int) ( $m['like_count'] ?? 0 ),
            'comments' => (int) ( $m['comments_count'] ?? 0 ),
            'shares'   => self::first_metric( $media_id . '/insights', array( 'shares' ) ),
            'reach'    => $reach, 'views' => $views, 'video_views' => ( $m['media_type'] ?? '' ) === 'VIDEO' ? $views : null,
            'url'      => $permalink,
            'imported' => count( $comments ),
            'error'    => is_wp_error( $rows ) ? $rows->get_error_message() : '',
            'at'       => time(),
        );
    }

    // ── What the public page and the admin show ──────

    /** Per round: facebook / instagram / tiktok numbers, and totals. */
    public static function round_stats( $round_id ) {
        $st  = self::stats();
        $set = self::settings();
        $out = array(
            'facebook'  => $st['rounds'][ $round_id ]['facebook'] ?? null,
            'instagram' => $st['rounds'][ $round_id ]['instagram'] ?? null,
            'tiktok'    => ! empty( $set['tiktok'][ $round_id ] ) ? $set['tiktok'][ $round_id ] : null,
        );
        $t = array( 'likes' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 0, 'views' => 0 );
        foreach ( $out as $k => $r ) {
            if ( ! $r ) continue;
            foreach ( $t as $f => $_ ) $t[ $f ] += (int) ( $r[ $f ] ?? 0 );
        }
        $out['total'] = $t;
        return $out;
    }

    /** The numbers shown to visitors (no links, no errors). */
    public static function public_stats( $round_id ) {
        $r = self::round_stats( $round_id );
        return array( 'likes' => $r['total']['likes'], 'comments' => $r['total']['comments'], 'shares' => $r['total']['shares'], 'reach' => $r['total']['reach'], 'views' => $r['total']['views'] );
    }

    /** Followers gained since tracking began, per platform. */
    public static function growth() {
        $st = self::stats();
        $out = array();
        foreach ( array( 'facebook', 'instagram' ) as $k ) {
            if ( isset( $st['followers'][ $k ] ) ) $out[ $k ] = array( 'now' => (int) $st['followers'][ $k ], 'gained' => (int) $st['followers'][ $k ] - (int) ( $st['first'][ $k ] ?? $st['followers'][ $k ] ) );
        }
        return $out;
    }

    // ── Admin ────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page( 'tweller-flow-2', 'Giveaway: Meta', 'Giveaway: Meta', 'manage_options', 'tweller-flow-2-giveaway-meta', array( __CLASS__, 'admin_page' ) );
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $set     = self::settings();
        $stats   = self::stats();
        $rounds  = TwellerFlow2_Giveaway::rounds();
        $tested  = get_transient( 'tf2_gwmeta_test' );
        $posts   = array( 'facebook' => array(), 'instagram' => array() );
        if ( self::connected() && ! empty( $_GET['posts'] ) ) {
            foreach ( array_keys( $posts ) as $k ) $posts[ $k ] = self::recent_posts( $k );
        }
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/giveaway-meta.php';
    }

    private static function guard( $nonce ) {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( $nonce );
    }

    private static function back( $args = array() ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=tweller-flow-2-giveaway-meta' ) ) );
        exit;
    }

    /** Pull an ID out of a pasted number or post link (digits only for ids). */
    private static function clean_id( $v ) {
        $v = trim( (string) $v );
        return preg_match( '/^[0-9_]{5,60}$/', $v ) ? $v : '';
    }

    public static function admin_save() {
        self::guard( 'tweller_flow_2_gwmeta_save' );
        $p   = wp_unslash( $_POST );
        $set = self::settings();
        $tok = trim( (string) ( $p['token'] ?? '' ) );
        if ( $tok !== '' ) $set['token'] = preg_replace( '/[^A-Za-z0-9_\-|]/', '', $tok );
        if ( ! empty( $p['clear_token'] ) ) $set['token'] = '';
        $set['page_id'] = preg_replace( '/[^0-9]/', '', (string) ( $p['page_id'] ?? '' ) );
        $set['ig_id']   = preg_replace( '/[^0-9]/', '', (string) ( $p['ig_id'] ?? '' ) );
        $links = array();
        foreach ( TwellerFlow2_Giveaway::rounds() as $r ) {
            $links[ $r['id'] ] = array(
                'facebook'  => self::clean_id( $p['links'][ $r['id'] ]['facebook'] ?? '' ),
                'instagram' => self::clean_id( $p['links'][ $r['id'] ]['instagram'] ?? '' ),
            );
        }
        $set['links'] = $links;
        update_option( self::OPTION, $set, false );
        delete_transient( 'tf2_gwmeta_test' );
        self::schedule();
        self::back( array( 'msg' => 'saved' ) );
    }

    public static function admin_test() {
        self::guard( 'tweller_flow_2_gwmeta_test' );
        set_transient( 'tf2_gwmeta_test', self::test(), 10 * MINUTE_IN_SECONDS );
        self::back( array( 'msg' => 'tested', 'posts' => 1 ) );
    }

    public static function admin_sync() {
        self::guard( 'tweller_flow_2_gwmeta_sync' );
        $r = self::sync();
        self::back( array( 'msg' => is_wp_error( $r ) ? 'syncfail' : 'synced' ) );
    }

    public static function admin_tiktok() {
        self::guard( 'tweller_flow_2_gwmeta_tiktok' );
        $p   = wp_unslash( $_POST );
        $set = self::settings();
        foreach ( TwellerFlow2_Giveaway::rounds() as $r ) {
            $row = $p['tiktok'][ $r['id'] ] ?? array();
            $set['tiktok'][ $r['id'] ] = array(
                'likes' => max( 0, (int) ( $row['likes'] ?? 0 ) ), 'comments' => max( 0, (int) ( $row['comments'] ?? 0 ) ),
                'shares' => max( 0, (int) ( $row['shares'] ?? 0 ) ), 'views' => max( 0, (int) ( $row['views'] ?? 0 ) ),
            );
        }
        update_option( self::OPTION, $set, false );
        delete_transient( 'tf2_gw_board' );
        self::back( array( 'msg' => 'saved' ) );
    }
}

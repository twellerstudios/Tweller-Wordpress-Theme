<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The Christmas Memory Giveaway.
 *
 *  - [tweller_giveaway] page (auto-created as a draft at /giveaway/): people
 *    share their most memorable Christmas memory, get a thank-you gift code,
 *    and earn a bonus entry in the draw for the platform they follow us on.
 *  - Comments from the Facebook, Instagram and TikTok posts are imported
 *    (CSV export or pasted), so the admin can see every entrant, every
 *    @tagged person, likes, and draw winners fairly:
 *      "Most-loved memory"  = the comment with the most likes (Full Session)
 *      "Random draw"        = random pick, weighted by entries (Mini Session)
 *  - Every draw is logged with a fingerprint of the entrant list, so it can
 *    be shown publicly.
 */
class TwellerFlow2_Giveaway {

    const SETTINGS = 'tweller_flow_2_giveaway';
    const COMMENTS = 'tweller_flow_2_giveaway_comments';
    const WEB      = 'tweller_flow_2_giveaway_web';
    const DRAWS    = 'tweller_flow_2_giveaway_draws';

    public static function platforms() {
        return array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok' );
    }

    public static function init() {
        add_shortcode( 'tweller_giveaway', array( __CLASS__, 'shortcode' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 24 );
        add_action( 'admin_init', array( __CLASS__, 'maybe_setup' ) );
        foreach ( array( 'save', 'import', 'draw', 'prize', 'clear', 'csv', 'remove' ) as $a ) {
            add_action( 'admin_post_tweller_flow_2_giveaway_' . $a, array( __CLASS__, 'admin_' . $a ) );
        }
    }

    // ── Settings ─────────────────────────────────────

    public static function settings() {
        $s = get_option( self::SETTINGS, array() );
        return wp_parse_args( is_array( $s ) ? $s : array(), array(
            'title'       => 'The Christmas Memory Giveaway',
            'ends'        => '2026-12-06 20:00',
            'gift_code'   => 'MEMORY50',
            'min_words'   => 8,
            'tag_bonus'   => 1,  // extra entry for tagging at least one person (Instagram, TikTok)
            'web_bonus'   => 1,  // extra entry for sharing the memory on the website too
            'page_id'     => 0,
            'urls'        => array( 'facebook' => '', 'instagram' => '', 'tiktok' => '' ),
        ) );
    }

    public static function ends_ts() {
        try {
            return ( new DateTimeImmutable( self::settings()['ends'], wp_timezone() ) )->getTimestamp();
        } catch ( Exception $e ) {
            return 0;
        }
    }

    /** First time: a draft /giveaway/ page and the thank-you gift code. */
    public static function maybe_setup() {
        if ( ! current_user_can( 'manage_options' ) || get_option( 'tweller_flow_2_giveaway_setup' ) ) return;
        $s = self::settings();
        if ( ! $s['page_id'] || ! get_post( $s['page_id'] ) ) {
            $existing = get_page_by_path( 'giveaway' );
            $s['page_id'] = $existing ? $existing->ID : (int) wp_insert_post( array(
                'post_type'    => 'page',
                'post_status'  => 'draft',
                'post_title'   => 'Christmas Memory Giveaway',
                'post_name'    => 'giveaway',
                'post_content' => '[tweller_giveaway]',
            ) );
            update_option( self::SETTINGS, $s, false );
        }
        if ( class_exists( 'TwellerFlow2_Coupons' ) && ! TwellerFlow2_Coupons::get( 'MEMORY50' ) ) {
            $all = TwellerFlow2_Coupons::all();
            $all['MEMORY50'] = array(
                'code' => 'MEMORY50', 'type' => 'fixed', 'amount' => 50, 'packages' => array(), 'session_types' => array(),
                'expires' => '2026-12-15 23:59', 'max_uses' => 0, 'min_spend' => 0, 'uses' => 0, 'email' => '',
                'note' => 'Christmas Memory Giveaway: thank-you gift for everyone who enters', 'active' => 1, 'created' => current_time( 'Y-m-d' ),
            );
            update_option( TwellerFlow2_Coupons::OPTION, $all, false );
        }
        update_option( 'tweller_flow_2_giveaway_setup', 1, false );
    }

    // ── Storage ──────────────────────────────────────

    private static function get_list( $opt ) {
        $v = get_option( $opt, array() );
        return is_array( $v ) ? $v : array();
    }

    public static function comments() { return self::get_list( self::COMMENTS ); }
    public static function web() { return self::get_list( self::WEB ); }
    public static function draws() { return self::get_list( self::DRAWS ); }

    public static function handle( $h ) {
        return strtolower( ltrim( trim( (string) $h ), '@' ) );
    }

    public static function tags_in( $text ) {
        preg_match_all( '/@([A-Za-z0-9._]{2,30})/', (string) $text, $m );
        return array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
    }

    private static function word_count( $text ) {
        $t = preg_replace( '/@[A-Za-z0-9._]+/', ' ', (string) $text );
        return count( preg_split( '/\s+/u', trim( $t ), -1, PREG_SPLIT_NO_EMPTY ) );
    }

    // ── Importing comments ───────────────────────────

    /**
     * CSV (from a comment-export tool) or pasted lines. Recognised CSV
     * columns: username/name/author, comment/text/message, likes/reactions,
     * date. Pasted lines: "username | likes | comment" or "username: comment".
     */
    public static function parse_import( $raw ) {
        $raw   = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $raw ) );
        $lines = array_values( array_filter( explode( "\n", $raw ), 'strlen' ) );
        if ( ! $lines ) return array();
        $out = array();

        $first = strtolower( $lines[0] );
        $delim = substr_count( $lines[0], "\t" ) > substr_count( $lines[0], ',' ) ? "\t" : ',';
        $head  = str_getcsv( $first, $delim );
        $find  = function ( $names ) use ( $head ) {
            foreach ( $head as $i => $h ) {
                $h = trim( $h, " \"'" );
                foreach ( $names as $n ) if ( $h === $n || strpos( $h, $n ) !== false ) return $i;
            }
            return -1;
        };
        $iu = $find( array( 'username', 'user name', 'handle', 'author', 'profile', 'owner', 'name', 'user' ) );
        $it = $find( array( 'comment', 'text', 'message', 'content', 'body' ) );
        $il = $find( array( 'likes', 'like count', 'like_count', 'reactions', 'digg', 'hearts' ) );
        $id = $find( array( 'date', 'time', 'created', 'timestamp' ) );

        if ( $iu >= 0 && $it >= 0 ) {
            // A real CSV: parse it properly (comments can hold commas and line breaks).
            $fh = fopen( 'php://temp', 'r+' );
            fwrite( $fh, $raw );
            rewind( $fh );
            fgetcsv( $fh, 0, $delim ); // header
            while ( ( $row = fgetcsv( $fh, 0, $delim ) ) !== false ) {
                $user = trim( (string) ( $row[ $iu ] ?? '' ) );
                $text = trim( (string) ( $row[ $it ] ?? '' ) );
                if ( $user === '' && $text === '' ) continue;
                $out[] = array(
                    'user'  => $user,
                    'text'  => $text,
                    'likes' => $il >= 0 ? (int) preg_replace( '/[^0-9]/', '', (string) ( $row[ $il ] ?? '0' ) ) : 0,
                    'date'  => $id >= 0 ? sanitize_text_field( (string) ( $row[ $id ] ?? '' ) ) : '',
                );
            }
            fclose( $fh );
            return $out;
        }

        foreach ( $lines as $line ) {
            if ( strpos( $line, '|' ) !== false ) {
                $p = array_map( 'trim', explode( '|', $line, 3 ) );
                if ( count( $p ) === 3 ) { $out[] = array( 'user' => $p[0], 'likes' => (int) preg_replace( '/[^0-9]/', '', $p[1] ), 'text' => $p[2], 'date' => '' ); continue; }
                if ( count( $p ) === 2 ) { $out[] = array( 'user' => $p[0], 'likes' => 0, 'text' => $p[1], 'date' => '' ); continue; }
            }
            if ( preg_match( '/^@?([A-Za-z0-9._ ]{2,40}):\s*(.+)$/u', $line, $m ) ) {
                $out[] = array( 'user' => $m[1], 'likes' => 0, 'text' => $m[2], 'date' => '' );
            }
        }
        return $out;
    }

    /** Add comments for a platform; the same comment imported twice is kept once (likes updated). */
    public static function add_comments( $platform, $rows ) {
        $all   = self::comments();
        $index = array();
        foreach ( $all as $i => $c ) $index[ $c['id'] ] = $i;
        $added = 0;
        foreach ( $rows as $r ) {
            $user = sanitize_text_field( $r['user'] );
            $text = sanitize_textarea_field( $r['text'] );
            if ( $user === '' ) continue;
            $id = md5( $platform . '|' . self::handle( $user ) . '|' . $text );
            $row = array(
                'id' => $id, 'platform' => $platform, 'user' => $user, 'text' => $text,
                'likes' => max( 0, (int) $r['likes'] ), 'tags' => self::tags_in( $text ), 'date' => $r['date'] ?? '',
            );
            if ( isset( $index[ $id ] ) ) { $all[ $index[ $id ] ]['likes'] = max( $all[ $index[ $id ] ]['likes'], $row['likes'] ); continue; }
            $all[] = $row;
            $added++;
        }
        update_option( self::COMMENTS, $all, false );
        return $added;
    }

    // ── Entrants, metrics and draws ──────────────────

    /** One row per person per platform, with their entries. */
    public static function entrants( $platform ) {
        $s       = self::settings();
        $winners = array();
        foreach ( self::draws() as $d ) if ( empty( $d['void'] ) ) $winners[ $d['platform'] . '|' . self::handle( $d['user'] ) ] = true;
        $web = array();
        foreach ( self::web() as $w ) if ( $w['platform'] === $platform && $w['handle'] !== '' ) $web[ self::handle( $w['handle'] ) ] = $w;

        $people = array();
        foreach ( self::comments() as $c ) {
            if ( $c['platform'] !== $platform ) continue;
            $h = self::handle( $c['user'] );
            if ( ! isset( $people[ $h ] ) ) $people[ $h ] = array( 'handle' => $h, 'user' => $c['user'], 'comments' => 0, 'best' => null, 'tags' => array(), 'memory' => false );
            $p = &$people[ $h ];
            $p['comments']++;
            $p['tags'] = array_values( array_unique( array_merge( $p['tags'], $c['tags'] ) ) );
            $is_memory = self::word_count( $c['text'] ) >= (int) $s['min_words'];
            if ( $is_memory ) $p['memory'] = true;
            if ( $is_memory && ( ! $p['best'] || $c['likes'] > $p['best']['likes'] ) ) $p['best'] = $c;
            unset( $p );
        }
        foreach ( $people as $h => &$p ) {
            $p['web']     = isset( $web[ $h ] );
            $p['won']     = isset( $winners[ $platform . '|' . $h ] );
            // Facebook does not allow tagging friends as a way to enter, so tags only count on Instagram and TikTok.
            $tag_bonus    = ( $platform !== 'facebook' && $p['tags'] ) ? (int) $s['tag_bonus'] : 0;
            $p['entries'] = $p['memory'] ? 1 + $tag_bonus + ( $p['web'] ? (int) $s['web_bonus'] : 0 ) : 0;
        }
        unset( $p );
        uasort( $people, function ( $a, $b ) {
            return ( $b['best']['likes'] ?? -1 ) <=> ( $a['best']['likes'] ?? -1 ) ?: $b['entries'] <=> $a['entries'];
        } );
        return $people;
    }

    public static function metrics( $platform ) {
        $people = self::entrants( $platform );
        $tagged = array();
        $likes  = 0;
        $n      = 0;
        foreach ( self::comments() as $c ) {
            if ( $c['platform'] !== $platform ) continue;
            $n++;
            $likes += $c['likes'];
            foreach ( $c['tags'] as $t ) {
                if ( ! isset( $tagged[ $t ] ) ) $tagged[ $t ] = array( 'count' => 0, 'by' => array() );
                $tagged[ $t ]['count']++;
                $tagged[ $t ]['by'][ self::handle( $c['user'] ) ] = true;
            }
        }
        uasort( $tagged, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
        $web = 0;
        foreach ( self::web() as $w ) if ( $w['platform'] === $platform ) $web++;
        return array(
            'comments' => $n,
            'people'   => count( $people ),
            'eligible' => count( array_filter( $people, function ( $p ) { return $p['entries'] > 0; } ) ),
            'entries'  => array_sum( wp_list_pluck( $people, 'entries' ) ),
            'likes'    => $likes,
            'tags'     => array_sum( wp_list_pluck( $tagged, 'count' ) ),
            'tagged'   => $tagged,
            'web'      => $web,
        );
    }

    /**
     * kind = 'loved'  → the memory with the most likes (ties: first imported)
     * kind = 'random' → a random pick, one ticket per entry (CSPRNG)
     * Past winners are left out.
     */
    public static function draw( $platform, $kind ) {
        $people = array_filter( self::entrants( $platform ), function ( $p ) { return $p['entries'] > 0 && ! $p['won']; } );
        if ( ! $people ) return new WP_Error( 'empty', 'No eligible entrants left on ' . self::platforms()[ $platform ] . '.' );
        $fingerprint = hash( 'sha256', implode( ',', array_map( function ( $p ) { return $p['handle'] . ':' . $p['entries'] . ':' . ( $p['best']['likes'] ?? 0 ); }, $people ) ) );

        if ( $kind === 'loved' ) {
            $win = null;
            foreach ( $people as $p ) if ( ! $win || ( $p['best']['likes'] ?? 0 ) > ( $win['best']['likes'] ?? 0 ) ) $win = $p;
            $tickets = count( $people );
        } else {
            $pool = array();
            foreach ( $people as $p ) for ( $i = 0; $i < $p['entries']; $i++ ) $pool[] = $p;
            $tickets = count( $pool );
            $win     = $pool[ random_int( 0, $tickets - 1 ) ];
        }
        $draw = array(
            'id'          => wp_generate_password( 10, false, false ),
            'time'        => time(),
            'platform'    => $platform,
            'kind'        => $kind,
            'user'        => $win['user'],
            'likes'       => (int) ( $win['best']['likes'] ?? 0 ),
            'memory'      => $win['best']['text'] ?? '',
            'eligible'    => count( $people ),
            'tickets'     => $tickets,
            'fingerprint' => substr( $fingerprint, 0, 16 ),
            'prize_code'  => '',
            'void'        => 0,
        );
        $draws   = self::draws();
        $draws[] = $draw;
        update_option( self::DRAWS, $draws, false );
        return $draw;
    }

    /** A one-time 100%-off code for the prize: any session, the package of the prize, 6 months. */
    public static function prize_code( $draw_id, $prize ) {
        $draws = self::draws();
        foreach ( $draws as $i => $d ) {
            if ( $d['id'] !== $draw_id ) continue;
            if ( $d['prize_code'] ) return $d['prize_code'];
            $pk = $prize === 'full' ? array( 'full', 'xmas_full' ) : array( 'mini', 'xmas_mini' );
            $pk = array_values( array_intersect( $pk, array_keys( (array) get_option( 'tweller_flow_2_packages', array() ) ) ) );
            $all = TwellerFlow2_Coupons::all();
            do {
                $code = 'WIN-' . strtoupper( $prize ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
            } while ( isset( $all[ $code ] ) );
            $all[ $code ] = array(
                'code' => $code, 'type' => 'percent', 'amount' => 100, 'packages' => $pk, 'session_types' => array(),
                'expires' => wp_date( 'Y-m-d', strtotime( '+6 months' ) ) . ' 23:59', 'max_uses' => 1, 'min_spend' => 0, 'uses' => 0, 'email' => '',
                'note' => 'Giveaway prize (' . ucfirst( $prize ) . ' Session): ' . self::platforms()[ $d['platform'] ] . ' winner ' . $d['user'],
                'active' => 1, 'created' => current_time( 'Y-m-d' ),
            );
            update_option( TwellerFlow2_Coupons::OPTION, $all, false );
            $draws[ $i ]['prize_code'] = $code;
            $draws[ $i ]['prize']      = $prize;
            update_option( self::DRAWS, $draws, false );
            return $code;
        }
        return '';
    }

    // ── Public entry form ────────────────────────────

    public static function routes() {
        register_rest_route( 'tweller-flow-2/v1', '/giveaway/enter', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_enter' ), 'permission_callback' => '__return_true',
        ) );
    }

    public static function rest_enter( $request ) {
        $d = $request->get_json_params();
        if ( ! empty( $d['website'] ) ) return rest_ensure_response( array( 'ok' => true ) ); // honeypot
        $key = 'tf2_gw_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( (int) get_transient( $key ) >= 5 ) return new WP_Error( 'slow', 'Too many tries. Please wait a few minutes.', array( 'status' => 429 ) );
        set_transient( $key, (int) get_transient( $key ) + 1, 10 * MINUTE_IN_SECONDS );

        $ends = self::ends_ts();
        if ( $ends && time() > $ends ) return new WP_Error( 'closed', 'The giveaway has closed. Thank you for taking part!', array( 'status' => 400 ) );

        $name     = sanitize_text_field( $d['name'] ?? '' );
        $email    = sanitize_email( $d['email'] ?? '' );
        $platform = sanitize_key( $d['platform'] ?? '' );
        $handle   = substr( sanitize_text_field( $d['handle'] ?? '' ), 0, 60 );
        $memory   = substr( sanitize_textarea_field( $d['memory'] ?? '' ), 0, 1200 );
        if ( $name === '' || ! is_email( $email ) || $memory === '' ) return new WP_Error( 'missing', 'Please add your name, email and your Christmas memory.', array( 'status' => 400 ) );
        if ( ! isset( self::platforms()[ $platform ] ) ) $platform = '';
        if ( self::word_count( $memory ) < (int) self::settings()['min_words'] ) return new WP_Error( 'short', 'Tell us a little more about your memory (a few sentences).', array( 'status' => 400 ) );

        $web = self::web();
        foreach ( $web as $w ) {
            if ( strtolower( $w['email'] ) === strtolower( $email ) ) return new WP_Error( 'dupe', 'You\'re already entered with this email. Good luck!', array( 'status' => 400 ) );
        }
        $web[] = array(
            'time' => time(), 'name' => $name, 'email' => $email, 'phone' => substr( sanitize_text_field( $d['phone'] ?? '' ), 0, 30 ),
            'platform' => $platform, 'handle' => $handle, 'memory' => $memory, 'share_ok' => empty( $d['share_ok'] ) ? 0 : 1,
        );
        update_option( self::WEB, $web, false );

        $gift = self::gift();
        self::email_entrant( $name, $email, $memory, $gift );
        return rest_ensure_response( array( 'ok' => true, 'gift' => $gift ) );
    }

    /** The thank-you code for entrants, if it exists and is live. */
    public static function gift() {
        $code = self::settings()['gift_code'];
        if ( ! $code || ! class_exists( 'TwellerFlow2_Coupons' ) ) return null;
        $c = TwellerFlow2_Coupons::get( $code );
        if ( ! $c || TwellerFlow2_Coupons::error( $c ) ) return null;
        $ts   = TwellerFlow2_Coupons::expiry_ts( $c );
        $book = home_url( '/christmas-photos/' );
        return array(
            'code'  => $c['code'],
            'label' => TwellerFlow2_Coupons::label( $c ),
            'until' => $ts ? wp_date( 'F j', $ts ) : '',
            'url'   => add_query_arg( 'coupon', $c['code'], $book ),
        );
    }

    private static function email_entrant( $name, $email, $memory, $gift ) {
        if ( ! class_exists( 'TwellerFlow2_Notifications' ) ) return;
        $N     = 'TwellerFlow2_Notifications';
        $first = esc_html( trim( explode( ' ', $name )[0] ) );
        $gcard = $gift ? $N::email_card( 'A little thank-you', "
            <p style='margin:4px 0 8px; color:" . $N::C_TEXT . "; line-height:1.7;'>Here's <strong>" . esc_html( $gift['label'] ) . "</strong> your Christmas photos" . ( $gift['until'] ? ', until ' . esc_html( $gift['until'] ) : '' ) . ". Use the code at booking:</p>
            <p style='margin:0 0 12px; font-size:24px; font-weight:700; letter-spacing:3px; color:" . $N::C_BLACK . ";'>" . esc_html( $gift['code'] ) . "</p>
            " . $N::email_button_row( $gift['url'], 'Book Christmas Photos', true, 6 ) ) : '';
        $body = "
            <h2 style='color:" . $N::C_BLACK . "; font-weight:600;'>You're in, {$first} 🎄</h2>
            <p style='color:" . $N::C_TEXT . "; line-height:1.7;'>Thank you for sharing your Christmas memory with us. You now have a bonus entry in the Christmas Memory Giveaway. Make sure you've also commented your memory on our giveaway post, because that's your main entry.</p>
            " . $N::email_card( 'Your memory', "<p style='margin:0; color:" . $N::C_TEXT . "; line-height:1.7; font-style:italic;'>“" . nl2br( esc_html( $memory ) ) . "”</p>" ) . "
            {$gcard}
            <p style='color:" . $N::C_TEXT . ";'>Good luck!<br><strong>The Tweller Studios Team</strong></p>";
        $N::send_raw( $email, "You're in the Christmas Memory Giveaway 🎄", $body );
    }

    public static function shortcode() {
        $s      = self::settings();
        $ends   = self::ends_ts();
        $closed = $ends && time() > $ends;
        $gift   = self::gift();
        $urls   = $s['urls'];
        ob_start();
        include TWELLER_FLOW_2_PLUGIN_DIR . 'templates/giveaway.php';
        return ob_get_clean();
    }

    // ── Admin ────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page( 'tweller-flow-2', 'Giveaway', 'Giveaway', 'manage_options', 'tweller-flow-2-giveaway', array( __CLASS__, 'admin_page' ) );
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        self::maybe_setup();
        $s = self::settings();
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/giveaway.php';
    }

    private static function guard( $nonce ) {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( $nonce );
    }

    private static function back( $args = array() ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=tweller-flow-2-giveaway' ) ) . ( isset( $args['tab'] ) ? '' : '' ) );
        exit;
    }

    public static function admin_save() {
        self::guard( 'tweller_flow_2_giveaway_save' );
        $p = wp_unslash( $_POST );
        $s = self::settings();
        $ends = str_replace( 'T', ' ', sanitize_text_field( $p['ends'] ?? '' ) );
        $urls = array();
        foreach ( array_keys( self::platforms() ) as $k ) $urls[ $k ] = esc_url_raw( $p['urls'][ $k ] ?? '' );
        $s = array_merge( $s, array(
            'title'     => sanitize_text_field( $p['title'] ?? $s['title'] ) ?: $s['title'],
            'ends'      => preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $ends ) ? $ends : $s['ends'],
            'gift_code' => TwellerFlow2_Coupons::normalize( $p['gift_code'] ?? '' ),
            'min_words' => max( 0, min( 60, (int) ( $p['min_words'] ?? 8 ) ) ),
            'tag_bonus' => max( 0, min( 5, (int) ( $p['tag_bonus'] ?? 1 ) ) ),
            'web_bonus' => max( 0, min( 5, (int) ( $p['web_bonus'] ?? 1 ) ) ),
            'urls'      => $urls,
        ) );
        update_option( self::SETTINGS, $s, false );
        self::back( array( 'msg' => 'saved' ) );
    }

    public static function admin_import() {
        self::guard( 'tweller_flow_2_giveaway_import' );
        $platform = sanitize_key( $_POST['platform'] ?? '' );
        if ( ! isset( self::platforms()[ $platform ] ) ) self::back( array( 'msg' => 'platform' ) );
        $raw = (string) wp_unslash( $_POST['raw'] ?? '' );
        if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) && (int) $_FILES['file']['size'] < 5 * MB_IN_BYTES ) {
            $raw = (string) file_get_contents( $_FILES['file']['tmp_name'] );
        }
        $rows  = self::parse_import( $raw );
        $added = self::add_comments( $platform, $rows );
        self::back( array( 'msg' => 'imported', 'n' => $added, 'of' => count( $rows ), 'p' => $platform ) );
    }

    public static function admin_draw() {
        self::guard( 'tweller_flow_2_giveaway_draw' );
        $platform = sanitize_key( $_POST['platform'] ?? '' );
        $kind     = ( $_POST['kind'] ?? '' ) === 'loved' ? 'loved' : 'random';
        if ( ! isset( self::platforms()[ $platform ] ) ) self::back();
        $r = self::draw( $platform, $kind );
        if ( is_wp_error( $r ) ) self::back( array( 'msg' => 'empty', 'p' => $platform ) );
        self::back( array( 'msg' => 'drawn', 'd' => $r['id'], 'p' => $platform ) );
    }

    public static function admin_prize() {
        self::guard( 'tweller_flow_2_giveaway_prize' );
        $code = self::prize_code( sanitize_text_field( $_POST['draw'] ?? '' ), ( $_POST['prize'] ?? '' ) === 'full' ? 'full' : 'mini' );
        self::back( array( 'msg' => 'prize', 'code' => $code ) );
    }

    /** Mark a draw as void (winner didn't reply in time); they can be drawn again only if you un-void. */
    public static function admin_remove() {
        self::guard( 'tweller_flow_2_giveaway_remove' );
        $id    = sanitize_text_field( $_POST['draw'] ?? '' );
        $draws = self::draws();
        foreach ( $draws as $i => $d ) if ( $d['id'] === $id ) $draws[ $i ]['void'] = 1;
        update_option( self::DRAWS, $draws, false );
        self::back( array( 'msg' => 'voided' ) );
    }

    public static function admin_clear() {
        self::guard( 'tweller_flow_2_giveaway_clear' );
        $platform = sanitize_key( $_POST['platform'] ?? '' );
        $keep = array_values( array_filter( self::comments(), function ( $c ) use ( $platform ) { return $c['platform'] !== $platform; } ) );
        update_option( self::COMMENTS, $keep, false );
        self::back( array( 'msg' => 'cleared', 'p' => $platform ) );
    }

    public static function admin_csv() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tweller_flow_2_giveaway_csv' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="giveaway-entrants-' . current_time( 'Y-m-d' ) . '.csv"' );
        $out  = fopen( 'php://output', 'w' );
        $safe = function ( $v ) { $v = (string) $v; return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; };
        fputcsv( $out, array( 'Platform', 'Person', 'Entries', 'Most-liked memory likes', 'Tagged', 'Also entered on website', 'Memory' ) );
        foreach ( self::platforms() as $k => $label ) {
            foreach ( self::entrants( $k ) as $p ) {
                fputcsv( $out, array( $label, $safe( $p['user'] ), $p['entries'], $p['best']['likes'] ?? 0, $safe( implode( ' ', array_map( function ( $t ) { return '@' . $t; }, $p['tags'] ) ) ), $p['web'] ? 'yes' : 'no', $safe( $p['best']['text'] ?? '' ) ) );
            }
        }
        fputcsv( $out, array() );
        fputcsv( $out, array( 'Website entries' ) );
        fputcsv( $out, array( 'Date', 'Name', 'Email', 'Phone', 'Platform', 'Handle', 'OK to share', 'Memory' ) );
        foreach ( self::web() as $w ) fputcsv( $out, array( wp_date( 'Y-m-d H:i', $w['time'] ), $safe( $w['name'] ), $w['email'], $safe( $w['phone'] ), $w['platform'], $safe( $w['handle'] ), $w['share_ok'] ? 'yes' : 'no', $safe( $w['memory'] ) ) );
        fclose( $out );
        exit;
    }
}

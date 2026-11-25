<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Snap It Forward: every client gets a personal Snap Link
 * (twellerstudios.com/snap/sarah-j). A friend who books through it gets a
 * discount on their first session; once the friend's deposit is in, the
 * client who shared it gets a reward code for their next session.
 *
 * Industry terms, for reference: the client who shares is the "referrer",
 * the new client is the "friend", the two discounts are a "double-sided
 * reward". The site never says "referral"; it says Snap It Forward.
 *
 *  - members:  code => { code, slug, email, name, created, clicks }
 *  - log:      one row per friend booking { time, code, session_id, friend…, status, reward_code }
 */
class TwellerFlow2_Referrals {

    const SETTINGS = 'tweller_flow_2_snap_settings';
    const MEMBERS  = 'tweller_flow_2_snap_members';
    const LOG      = 'tweller_flow_2_snap_log';
    const COOKIE   = 'tf2_snap';
    const PATH     = 'snap';

    public static function init() {
        // The plugin's own init runs on 'init' (10), so this must come later.
        add_action( 'init', array( __CLASS__, 'route' ), 99 );
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'on_session_created' ), 15, 2 );
        add_action( 'tweller_flow_2_payment_updated', array( __CLASS__, 'on_payment_updated' ), 20, 1 );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 23 );
        add_action( 'admin_post_tweller_flow_2_snap_save', array( __CLASS__, 'admin_save' ) );
        add_action( 'admin_post_tweller_flow_2_snap_backfill', array( __CLASS__, 'admin_backfill' ) );
    }

    // ── Settings ─────────────────────────────────────

    public static function settings() {
        $s = get_option( self::SETTINGS, array() );
        return wp_parse_args( is_array( $s ) ? $s : array(), array(
            'enabled'          => 1,
            'friend_amount'    => 100,   // TT$ off the friend's first session
            'reward_amount'    => 100,   // TT$ off the sharer's next session
            'new_clients_only' => 1,     // friend must not have booked before
            'reward_when'      => 'paid', // 'paid' = friend's deposit is in; 'booked' = as soon as they book
            'reward_days'      => 365,   // how long a reward code stays valid (0 = no end)
        ) );
    }

    public static function on() {
        return ! empty( self::settings()['enabled'] );
    }

    // ── Members (every client) ───────────────────────

    public static function members() {
        $m = get_option( self::MEMBERS, array() );
        return is_array( $m ) ? $m : array();
    }

    public static function get( $code ) {
        $code = TwellerFlow2_Coupons::normalize( $code );
        $m    = self::members();
        return ( $code !== '' && isset( $m[ $code ] ) ) ? $m[ $code ] : null;
    }

    public static function for_email( $email ) {
        $email = strtolower( trim( (string) $email ) );
        if ( $email === '' ) return null;
        foreach ( self::members() as $row ) {
            if ( strtolower( $row['email'] ) === $email ) return $row;
        }
        return null;
    }

    /** "Sarah Jones" → sarah-j (then sarah-j2, sarah-j3… if taken). */
    private static function new_slug( $name, $members ) {
        $parts = preg_split( '/\s+/', trim( remove_accents( (string) $name ) ) );
        $first = strtolower( preg_replace( '/[^A-Za-z]/', '', $parts[0] ?? '' ) );
        $last  = count( $parts ) > 1 ? strtolower( substr( preg_replace( '/[^A-Za-z]/', '', end( $parts ) ), 0, 1 ) ) : '';
        $base  = substr( $first !== '' ? $first : 'friend', 0, 16 ) . ( $last !== '' ? '-' . $last : '' );
        $slug  = $base;
        $n     = 2;
        while ( isset( $members[ strtoupper( $slug ) ] ) || TwellerFlow2_Coupons::get( $slug ) ) {
            $slug = $base . $n++;
        }
        return $slug;
    }

    /** The client's Snap Link record, made the first time they book. */
    public static function ensure_member( $email, $name ) {
        $email = sanitize_email( $email );
        if ( $email === '' ) return null;
        $have = self::for_email( $email );
        if ( $have ) return $have;
        $m    = self::members();
        $slug = self::new_slug( $name, $m );
        $code = strtoupper( $slug );
        $m[ $code ] = array(
            'code'    => $code,
            'slug'    => $slug,
            'email'   => $email,
            'name'    => sanitize_text_field( $name ),
            'created' => current_time( 'Y-m-d' ),
            'clicks'  => 0,
        );
        update_option( self::MEMBERS, $m, false );
        return $m[ $code ];
    }

    public static function link( $member ) {
        return home_url( '/' . self::PATH . '/' . $member['slug'] . '/' );
    }

    /** Pretty version for showing: twellerstudios.com/snap/sarah-j */
    public static function short_link( $member ) {
        return preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( self::link( $member ) ) );
    }

    public static function first_name( $member ) {
        $f = trim( explode( ' ', trim( (string) $member['name'] ) )[0] );
        return $f !== '' ? $f : 'A friend';
    }

    // ── /snap/sarah-j/ → booking page with the discount ready ──

    public static function route() {
        if ( is_admin() || ! self::on() ) return;
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
        $home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
        if ( $home !== '' && strpos( $path, $home ) === 0 ) $path = trim( substr( $path, strlen( $home ) ), '/' );
        if ( ! preg_match( '#^' . self::PATH . '/([A-Za-z0-9\-]{1,40})$#', $path, $mm ) ) return;

        $member = self::get( $mm[1] );
        $dest   = class_exists( 'TwellerFlow2_Booking_Links' ) ? TwellerFlow2_Booking_Links::page_url() : '';
        if ( ! $dest ) $dest = home_url( '/' );
        if ( $member ) {
            // Count a visit once per browser.
            if ( empty( $_COOKIE[ self::COOKIE ] ) || $_COOKIE[ self::COOKIE ] !== $member['code'] ) {
                $m = self::members();
                if ( isset( $m[ $member['code'] ] ) ) {
                    $m[ $member['code'] ]['clicks'] = (int) $m[ $member['code'] ]['clicks'] + 1;
                    update_option( self::MEMBERS, $m, false );
                }
            }
            setcookie( self::COOKIE, $member['code'], array( 'expires' => time() + 60 * DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
            // Ad / UTM tags on the link are carried along.
            $args = array( 'snap' => $member['code'] );
            foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'fbclid' ) as $k ) {
                if ( isset( $_GET[ $k ] ) ) $args[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
            }
            if ( ! isset( $args['utm_source'] ) ) {
                $args['utm_source']   = 'snap-it-forward';
                $args['utm_medium']   = 'referral';
                $args['utm_campaign'] = 'snap-' . $member['slug'];
            }
            $dest = add_query_arg( $args, $dest );
        }
        nocache_headers();
        wp_safe_redirect( $dest, 302 );
        exit;
    }

    // ── Using a Snap code at booking ─────────────────

    /** Why this Snap code can't be used, or '' when it can. Email may be '' (checked again at booking). */
    public static function error( $member, $email = '' ) {
        if ( ! self::on() ) return 'That code wasn\'t recognised. Please check it.';
        if ( ! $member ) return 'That code wasn\'t recognised. Please check it.';
        $email = strtolower( trim( (string) $email ) );
        if ( $email !== '' && $email === strtolower( $member['email'] ) ) {
            return 'This is your own Snap Link. Share it with friends and family. When they book, you get TT$' . number_format( (float) self::settings()['reward_amount'] ) . ' off your next session.';
        }
        if ( $email !== '' && ! empty( self::settings()['new_clients_only'] ) && self::has_booked( $email ) ) {
            return 'Welcome back! The Snap It Forward discount is for first-time clients. Ask us about returning-client offers.';
        }
        return '';
    }

    private static function has_booked( $email ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tweller_sessions WHERE LOWER(client_email) = %s LIMIT 1", strtolower( $email ) ) );
    }

    public static function friend_discount( $price ) {
        return round( min( max( 0.0, (float) self::settings()['friend_amount'] ), max( 0.0, (float) $price ) ), 2 );
    }

    /** What the booking form's coupon box gets back for a Snap code. */
    public static function check_response( $member ) {
        $amt = (float) self::settings()['friend_amount'];
        return array(
            'ok'         => true,
            'code'       => $member['code'],
            'type'       => 'fixed',
            'amount'     => $amt,
            'label'      => self::first_name( $member ) . '\'s Snap It Forward: TT$' . number_format( $amt ) . ' off',
            'packages'   => array(),
            'locked'     => false,
            'min_spend'  => 0,
            'expires_ts' => 0,
            'now_ts'     => time(),
            'snap'       => true,
        );
    }

    /** A friend booked with a Snap code. */
    public static function record_booking( $member, $session_id, $friend_name, $friend_email, $discount ) {
        $log   = get_option( self::LOG, array() );
        $log   = is_array( $log ) ? $log : array();
        $log[] = array(
            'time'         => time(),
            'code'         => $member['code'],
            'session_id'   => (int) $session_id,
            'friend_name'  => sanitize_text_field( $friend_name ),
            'friend_email' => sanitize_email( $friend_email ),
            'discount'     => round( (float) $discount, 2 ),
            'status'       => 'booked',
            'reward_code'  => '',
        );
        if ( count( $log ) > 3000 ) $log = array_slice( $log, -3000 );
        update_option( self::LOG, $log, false );
        if ( self::settings()['reward_when'] === 'booked' ) self::reward( (int) $session_id );
    }

    // ── Rewards ──────────────────────────────────────

    public static function on_payment_updated( $session_id ) {
        if ( self::settings()['reward_when'] !== 'paid' ) return;
        $s = TwellerFlow2_Session::get( (int) $session_id );
        if ( $s && in_array( $s->payment_status, array( 'deposit', 'paid' ), true ) ) self::reward( (int) $session_id );
    }

    /** Give the sharer their reward code for this friend's booking (once). */
    public static function reward( $session_id ) {
        $log = get_option( self::LOG, array() );
        if ( ! is_array( $log ) ) return;
        foreach ( $log as $i => $row ) {
            if ( (int) $row['session_id'] !== $session_id || $row['status'] !== 'booked' ) continue;
            $member = self::get( $row['code'] );
            if ( ! $member ) return;
            $set    = self::settings();
            $amount = (float) $set['reward_amount'];
            if ( $amount <= 0 ) return;

            // A one-time code only the sharer can use, on any session.
            $all = TwellerFlow2_Coupons::all();
            do {
                $code = 'SNAP-' . strtoupper( preg_replace( '/[^A-Za-z]/', '', self::first_name( $member ) ) ?: 'THANKS' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
            } while ( isset( $all[ $code ] ) );
            $days = (int) $set['reward_days'];
            $all[ $code ] = array(
                'code' => $code, 'type' => 'fixed', 'amount' => $amount, 'packages' => array(), 'session_types' => array(),
                'expires'  => $days > 0 ? wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS ) : '',
                'max_uses' => 1, 'min_spend' => 0, 'uses' => 0, 'email' => $member['email'],
                'note'     => 'Snap It Forward reward: ' . $row['friend_name'] . ' booked',
                'active'   => 1, 'created' => current_time( 'Y-m-d' ),
            );
            update_option( TwellerFlow2_Coupons::OPTION, $all, false );

            $log[ $i ]['status']      = 'rewarded';
            $log[ $i ]['reward_code'] = $code;
            update_option( self::LOG, $log, false );
            self::email_reward( $member, $row, $code, $all[ $code ] );
            return;
        }
    }

    private static function email_reward( $member, $row, $code, $coupon ) {
        if ( ! class_exists( 'TwellerFlow2_Notifications' ) ) return;
        $N      = 'TwellerFlow2_Notifications';
        $first  = esc_html( self::first_name( $member ) );
        $friend = esc_html( trim( explode( ' ', trim( $row['friend_name'] ) )[0] ) ?: 'Your friend' );
        $amount = number_format( (float) $coupon['amount'] );
        $book   = class_exists( 'TwellerFlow2_Booking_Links' ) ? TwellerFlow2_Booking_Links::page_url() : home_url( '/' );
        $use    = add_query_arg( 'coupon', $code, $book ?: home_url( '/' ) );
        $until  = ! empty( $coupon['expires'] ) ? ' It\'s valid until ' . esc_html( wp_date( 'F j, Y', strtotime( $coupon['expires'] ) ) ) . '.' : '';
        $body = "
            <h2 style='color:" . $N::C_BLACK . "; font-weight:600;'>You snapped it forward 📸</h2>
            <p style='color:" . $N::C_TEXT . ";'>Hi {$first},</p>
            <p style='color:" . $N::C_TEXT . "; line-height:1.7;'><strong>{$friend}</strong> booked a session with your Snap Link. Thank you for sharing us! Here's <strong>TT\${$amount} off</strong> your next session.</p>
            " . $N::email_card( 'Your reward code', "
                <p style='margin:4px 0 6px; font-size:26px; font-weight:700; letter-spacing:3px; color:" . $N::C_BLACK . ";'>" . esc_html( $code ) . "</p>
                <p style='margin:0; color:" . $N::C_TEXT . "; font-size:14px; line-height:1.6;'>Use it once, on any session, with this email address.{$until}</p>
            " ) . "
            " . $N::email_button_row( $use, 'Book My Next Session' ) . "
            " . self::email_card_html( $member ) . "
            <p style='color:" . $N::C_TEXT . ";'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>";
        $N::send_raw( $member['email'], "{$friend} booked with your Snap Link — here's TT\${$amount} off 🎁", $body );
    }

    // ── Showing the Snap Link (portal + emails) ──────

    /** The share card for emails (the client's own Snap Link). */
    public static function email_card_html( $member ) {
        if ( ! $member || ! self::on() || ! class_exists( 'TwellerFlow2_Notifications' ) ) return '';
        $N    = 'TwellerFlow2_Notifications';
        $set  = self::settings();
        $link = self::link( $member );
        $wa   = 'https://wa.me/?text=' . rawurlencode( 'Book your photos with Tweller Studios and get TT$' . number_format( (float) $set['friend_amount'] ) . ' off your first session: ' . $link );
        return $N::email_card( 'Snap It Forward', "
            <p style='margin:4px 0 12px; color:" . $N::C_TEXT . "; line-height:1.7;'>Share your Snap Link with friends and family. They get <strong>TT$" . number_format( (float) $set['friend_amount'] ) . " off</strong> their first session, and you get <strong>TT$" . number_format( (float) $set['reward_amount'] ) . " off</strong> your next one.</p>
            <p style='margin:0 0 14px; font-size:17px; font-weight:700;'><a href='" . esc_url( $link ) . "' style='color:" . $N::C_BLACK . "; text-decoration:none;'>" . esc_html( self::short_link( $member ) ) . "</a></p>
            " . $N::email_button_row( $wa, 'Share on WhatsApp', false, 6 ) . "
        " );
    }

    /** The share card on the client portal (tracker page). */
    public static function portal_card( $session ) {
        if ( ! self::on() || empty( $session->client_email ) ) return '';
        $member = self::ensure_member( $session->client_email, $session->client_name );
        if ( ! $member ) return '';
        $set   = self::settings();
        $link  = self::link( $member );
        $short = self::short_link( $member );
        $msg   = 'Book your photos with Tweller Studios and get TT$' . number_format( (float) $set['friend_amount'] ) . ' off your first session: ' . $link;
        $mine  = self::stats_for( $member['code'] );
        ob_start();
        ?>
        <div class="tf2-snap">
            <div class="tf2-snap__kicker"><?php echo in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ? 'Loved your photos? Snap It Forward' : 'Snap It Forward'; ?></div>
            <p class="tf2-snap__lead">Share your Snap Link. Friends and family get <strong>TT$<?php echo esc_html( number_format( (float) $set['friend_amount'] ) ); ?> off</strong> their first session, and you get <strong>TT$<?php echo esc_html( number_format( (float) $set['reward_amount'] ) ); ?> off</strong> your next one.</p>
            <div class="tf2-snap__row">
                <input class="tf2-snap__link" type="text" readonly value="<?php echo esc_attr( $short ); ?>" aria-label="Your Snap Link" data-full="<?php echo esc_attr( $link ); ?>">
                <button type="button" class="tf2-snap__copy" data-copy="<?php echo esc_attr( $link ); ?>">Copy link</button>
            </div>
            <div class="tf2-snap__share">
                <a href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $msg ) ); ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
                <span aria-hidden="true">·</span>
                <span>Your code: <strong><?php echo esc_html( $member['code'] ); ?></strong></span>
                <?php if ( $mine['booked'] ) : ?>
                    <span aria-hidden="true">·</span>
                    <span><?php echo (int) $mine['booked']; ?> friend<?php echo $mine['booked'] === 1 ? '' : 's'; ?> booked</span>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function () {
            document.querySelectorAll('.tf2-snap__copy').forEach(function (b) {
                if (b.dataset.wired) return; b.dataset.wired = 1;
                b.addEventListener('click', function () {
                    var t = b.getAttribute('data-copy'), done = function () { var o = b.textContent; b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = o; }, 1600); };
                    var fallback = function () { var ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} ta.remove(); };
                    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(done, fallback); else fallback();
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── Stats ────────────────────────────────────────

    public static function log() {
        $log = get_option( self::LOG, array() );
        return is_array( $log ) ? $log : array();
    }

    public static function stats_for( $code ) {
        $out = array( 'booked' => 0, 'rewarded' => 0, 'discount' => 0.0 );
        foreach ( self::log() as $r ) {
            if ( $r['code'] !== $code ) continue;
            $out['booked']++;
            if ( $r['status'] === 'rewarded' ) $out['rewarded']++;
            $out['discount'] += (float) $r['discount'];
        }
        return $out;
    }

    // ── Hooks ────────────────────────────────────────

    /** Every client gets a Snap Link the moment they have a booking. */
    public static function on_session_created( $session_id, $data ) {
        if ( ! self::on() ) return;
        if ( ! empty( $data['client_email'] ) ) self::ensure_member( $data['client_email'], $data['client_name'] ?? '' );
    }

    // ── Admin ────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page( 'tweller-flow-2', 'Snap It Forward', 'Snap It Forward', 'manage_options', 'tweller-flow-2-snap', array( __CLASS__, 'admin_page' ) );
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $set     = self::settings();
        $members = self::members();
        $log     = array_reverse( self::log() );
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/snap.php';
    }

    public static function admin_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tweller_flow_2_snap_save' );
        $p = wp_unslash( $_POST );
        update_option( self::SETTINGS, array(
            'enabled'          => empty( $p['enabled'] ) ? 0 : 1,
            'friend_amount'    => max( 0, round( (float) ( $p['friend_amount'] ?? 100 ), 2 ) ),
            'reward_amount'    => max( 0, round( (float) ( $p['reward_amount'] ?? 100 ), 2 ) ),
            'new_clients_only' => empty( $p['new_clients_only'] ) ? 0 : 1,
            'reward_when'      => ( $p['reward_when'] ?? '' ) === 'booked' ? 'booked' : 'paid',
            'reward_days'      => max( 0, (int) ( $p['reward_days'] ?? 365 ) ),
        ), false );
        wp_safe_redirect( admin_url( 'admin.php?page=tweller-flow-2-snap&saved=1' ) );
        exit;
    }

    /** Give every past client a Snap Link (so you can send it to them). */
    public static function admin_backfill() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tweller_flow_2_snap_backfill' );
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT client_email, client_name FROM {$wpdb->prefix}tweller_sessions WHERE client_email <> '' ORDER BY id ASC" );
        $n = 0;
        foreach ( (array) $rows as $r ) {
            if ( ! self::for_email( $r->client_email ) ) { self::ensure_member( $r->client_email, $r->client_name ); $n++; }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=tweller-flow-2-snap&made=' . $n ) );
        exit;
    }
}

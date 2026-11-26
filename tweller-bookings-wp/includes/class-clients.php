<?php
/**
 * Clients: one page per client (by email) with everything they have waiting
 * for their next session and everything they've used.
 *
 *  - Waiting: the thank-you discount for sharing photos, coupons tied to their
 *    email (Snap It Forward rewards, one-time codes), gift cards with a
 *    balance, print credit, an early-bird backdrop not yet chosen, Snap
 *    rewards still to come.
 *  - Their Snap Link and the friends who booked with it.
 *  - Every booking with the discounts on it, coupons and gift cards used,
 *    giveaway entries.
 *
 * Also: the thank-you discount settings (percent and an optional cap), and a
 * small endpoint the booking form uses to show a client's thank-you discount
 * in the order summary before they book.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Clients {

    const PAGE = 'tweller-flow-2-clients';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
        add_action( 'admin_post_tweller_flow_2_client_thanks', array( __CLASS__, 'admin_thanks' ) );
        add_action( 'admin_post_tweller_flow_2_rewards_save', array( __CLASS__, 'admin_rewards_save' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function url( $email = '' ) {
        $u = admin_url( 'admin.php?page=' . self::PAGE );
        return $email !== '' ? add_query_arg( 'email', rawurlencode( strtolower( $email ) ), $u ) : $u;
    }

    public static function money( $n ) {
        $n = (float) $n;
        return 'TT$' . number_format( $n, $n == (int) $n ? 0 : 2 );
    }

    // ── Booking form: the thank-you discount, before booking ─────

    public static function routes() {
        register_rest_route( 'tweller-flow-2/v1', '/perks', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_perks' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function rest_perks( $request ) {
        // A light limit per visitor: this says whether an email has a discount waiting.
        $ip  = preg_replace( '/[^0-9a-f:.]/i', '', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $key = 'tf2_perks_' . md5( $ip );
        $n   = (int) get_transient( $key );
        if ( $n > 30 ) return new WP_Error( 'slow_down', 'Please wait a few minutes.', array( 'status' => 429 ) );
        set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

        $email = sanitize_email( (string) $request->get_param( 'email' ) );
        $out   = array( 'ok' => true, 'thanks' => null );
        if ( is_email( $email ) && class_exists( 'TwellerFlow2_Image_Consent' ) && TwellerFlow2_Image_Consent::has_next_session_discount( $email ) ) {
            $out['thanks'] = array(
                'pct' => TwellerFlow2_Image_Consent::next_discount_pct(),
                'cap' => TwellerFlow2_Image_Consent::next_discount_cap(),
            );
        }
        return rest_ensure_response( $out );
    }

    // ── Data ─────────────────────────────────────────

    /** Every client (one row per email), newest booking first. */
    public static function all( $search = '' ) {
        global $wpdb;
        $t     = $wpdb->prefix . 'tweller_sessions';
        $where = "client_email <> ''";
        $args  = array();
        if ( $search !== '' ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND ( client_email LIKE %s OR client_name LIKE %s OR client_phone LIKE %s )';
            $args  = array( $like, $like, $like );
        }
        $sql = "SELECT LOWER(client_email) AS email, COUNT(*) AS bookings, SUM(total_amount) AS booked, MAX(session_date) AS last_date, MAX(id) AS last_id
                FROM $t WHERE $where GROUP BY LOWER(client_email) ORDER BY last_id DESC LIMIT 500";
        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
        if ( ! $rows ) return array();
        $ids   = array_map( 'intval', wp_list_pluck( $rows, 'last_id' ) );
        $names = array();
        foreach ( (array) $wpdb->get_results( "SELECT id, client_name, client_phone FROM $t WHERE id IN (" . implode( ',', $ids ) . ')' ) as $r ) $names[ (int) $r->id ] = $r;
        foreach ( $rows as $r ) {
            $r->name  = $names[ (int) $r->last_id ]->client_name ?? '';
            $r->phone = $names[ (int) $r->last_id ]->client_phone ?? '';
        }
        return $rows;
    }

    /** Short tags for the list: what each client has waiting. */
    public static function waiting_tags( $email ) {
        $tags = array();
        foreach ( self::waiting( $email, null ) as $p ) $tags[] = $p['tag'];
        return $tags;
    }

    /** Coupons tied to this email that can still be used. */
    public static function open_coupons( $email ) {
        $out = array();
        if ( ! class_exists( 'TwellerFlow2_Coupons' ) ) return $out;
        foreach ( TwellerFlow2_Coupons::all() as $c ) {
            if ( empty( $c['email'] ) || strtolower( $c['email'] ) !== $email ) continue;
            if ( TwellerFlow2_Coupons::error( $c ) !== '' ) continue;
            $out[] = $c;
        }
        return $out;
    }

    /** Gift cards this client holds (recipient, or a buyer keeping it) with money left. */
    public static function gift_cards( $email ) {
        global $wpdb;
        if ( ! class_exists( 'TwellerFlow2_Gift_Cards' ) ) return array();
        $t = TwellerFlow2_Gift_Cards::table();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) return array();
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE LOWER(recipient_email) = %s OR LOWER(purchaser_email) = %s ORDER BY id DESC", $email, $email ) );
    }

    /**
     * Everything waiting for this client's next session.
     * Each: kind, title, value (short), detail, tag (for the list), action (optional).
     */
    public static function waiting( $email, $sessions = null ) {
        $email = strtolower( trim( $email ) );
        $out   = array();

        if ( class_exists( 'TwellerFlow2_Image_Consent' ) ) {
            $IC = 'TwellerFlow2_Image_Consent';
            if ( $IC::has_next_session_discount( $email ) ) {
                $out[] = array(
                    'kind' => 'thanks', 'title' => 'Thank-you discount', 'value' => $IC::next_discount_label(),
                    'detail' => 'For choosing "Share as many as you like" for their photos. Taken off their next booking automatically, then used up.',
                    'tag' => (int) $IC::next_discount_pct() . '% off next',
                );
            }
            $pc = $IC::print_credit_balance( $email );
            if ( $pc > 0 ) $out[] = array( 'kind' => 'print', 'title' => 'Print credit', 'value' => self::money( $pc ), 'detail' => 'Taken off print orders.', 'tag' => self::money( $pc ) . ' prints' );
        }

        foreach ( self::open_coupons( $email ) as $c ) {
            $exp = TwellerFlow2_Coupons::expiry_ts( $c );
            $out[] = array(
                'kind' => 'coupon', 'title' => 'Coupon ' . $c['code'], 'value' => TwellerFlow2_Coupons::label( $c ),
                'detail' => trim( ( $c['note'] ?? '' ) . ( $exp ? ' · Valid until ' . wp_date( 'M j, Y', $exp ) : '' ) . ( (int) $c['max_uses'] > 0 ? ' · ' . max( 0, (int) $c['max_uses'] - (int) $c['uses'] ) . ' use left' : '' ), ' ·' ),
                'tag' => TwellerFlow2_Coupons::label( $c ) . ' coupon', 'code' => $c['code'],
            );
        }

        foreach ( self::gift_cards( $email ) as $g ) {
            if ( $g->status !== 'active' || (float) $g->balance <= 0 ) continue;
            $mine = strtolower( (string) $g->recipient_email ) === $email || $g->send_to !== 'recipient';
            if ( ! $mine ) continue;
            $out[] = array(
                'kind' => 'gift', 'title' => 'Gift card ' . $g->code, 'value' => self::money( $g->balance ) . ' left',
                'detail' => 'Of ' . self::money( $g->amount ) . ( $g->purchaser_name && strtolower( (string) $g->purchaser_email ) !== $email ? ' · from ' . $g->purchaser_name : '' ),
                'tag' => 'Gift ' . self::money( $g->balance ), 'link' => admin_url( 'admin.php?page=tweller-flow-2-giftcards&card=' . (int) $g->id ),
            );
        }

        if ( class_exists( 'TwellerFlow2_Backdrop_Edits' ) ) {
            if ( $sessions === null ) {
                global $wpdb;
                $sessions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tweller_sessions WHERE LOWER(client_email) = %s", $email ) );
            }
            foreach ( (array) $sessions as $s ) {
                $plan = TwellerFlow2_Backdrop_Edits::get_plan( $s->id );
                if ( $plan && TwellerFlow2_Backdrop_Edits::free_available( $plan ) ) {
                    $out[] = array( 'kind' => 'backdrop', 'title' => 'Free early-bird backdrop', 'value' => 'Not chosen yet',
                        'detail' => 'They choose it when picking their photos (' . $s->tracking_code . ').', 'tag' => 'Free backdrop',
                        'link' => admin_url( 'admin.php?page=tweller-flow-2-session&id=' . (int) $s->id ) . '#tf2-backdrops' );
                }
            }
        }

        if ( class_exists( 'TwellerFlow2_Referrals' ) ) {
            $m = TwellerFlow2_Referrals::for_email( $email );
            if ( $m ) {
                $amt = (float) TwellerFlow2_Referrals::settings()['reward_amount'];
                foreach ( TwellerFlow2_Referrals::log() as $r ) {
                    if ( $r['code'] !== $m['code'] || $r['status'] !== 'booked' ) continue;
                    $out[] = array( 'kind' => 'snap', 'title' => 'Snap It Forward reward on the way', 'value' => self::money( $amt ),
                        'detail' => $r['friend_name'] . ' booked with their link. The reward code is sent once that booking is paid.', 'tag' => 'Snap reward due' );
                }
            }
        }
        return $out;
    }

    /** The full picture for one client. */
    public static function profile( $email ) {
        global $wpdb;
        $email    = strtolower( trim( $email ) );
        $sessions = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tweller_sessions WHERE LOWER(client_email) = %s ORDER BY session_date DESC, id DESC", $email ) );
        $ids = array_map( 'intval', wp_list_pluck( $sessions, 'id' ) );

        $p = array( 'email' => $email, 'name' => '', 'phone' => '', 'sessions' => $sessions, 'waiting' => self::waiting( $email, $sessions ) );
        foreach ( $sessions as $s ) {
            if ( $p['name'] === '' && $s->client_name ) $p['name'] = $s->client_name;
            if ( $p['phone'] === '' && $s->client_phone ) $p['phone'] = $s->client_phone;
        }

        // Discounts per booking (from the saved invoice), and totals.
        $p['by_session'] = array();
        $p['booked'] = 0.0; $p['paid'] = 0.0; $p['saved'] = 0.0;
        foreach ( $sessions as $s ) {
            $lines = array();
            $inv   = get_option( 'tweller_invoice_' . (int) $s->id, null );
            if ( is_array( $inv ) && ! empty( $inv['lines'] ) ) {
                foreach ( $inv['lines'] as $l ) if ( (float) $l['amount'] < 0 ) $lines[] = array( 'label' => $l['label'], 'amount' => -(float) $l['amount'] );
            } else {
                // Older bookings: the discount lines written into the notes.
                foreach ( preg_split( '/\r\n|\n/', (string) $s->notes ) as $n ) {
                    if ( preg_match( '/^(Coupon .+|Snap It Forward .+?):\s*-TT\$([0-9.,]+)/', trim( $n ), $m ) ) $lines[] = array( 'label' => $m[1], 'amount' => (float) str_replace( ',', '', $m[2] ) );
                }
            }
            if ( (float) $s->deposit_amount > 0 && $s->payment_status === 'pending' ) $lines[] = array( 'label' => 'Gift card', 'amount' => (float) $s->deposit_amount );
            $p['by_session'][ (int) $s->id ] = array(
                'discounts' => $lines,
                'consent'   => class_exists( 'TwellerFlow2_Image_Consent' ) ? TwellerFlow2_Image_Consent::get( $s->id ) : '',
            );
            $p['booked'] += (float) $s->total_amount;
            if ( in_array( $s->payment_status, array( 'paid', 'deposit' ), true ) ) $p['paid'] += (float) $s->total_amount;
            foreach ( $lines as $l ) if ( $l['label'] !== 'Gift card' ) $p['saved'] += $l['amount'];
        }

        // Coupons used on their bookings.
        $p['coupons_used'] = array();
        if ( class_exists( 'TwellerFlow2_Coupons' ) && $ids ) {
            foreach ( (array) get_option( TwellerFlow2_Coupons::LOG, array() ) as $e ) {
                if ( in_array( (int) $e['session_id'], $ids, true ) ) $p['coupons_used'][] = $e;
            }
        }

        // Gift cards: theirs (bought or received), and money used on their bookings.
        $p['gift_cards'] = self::gift_cards( $email );
        $p['gift_used']  = array();
        if ( class_exists( 'TwellerFlow2_Gift_Cards' ) && $ids ) {
            $lt = TwellerFlow2_Gift_Cards::ledger_table(); $ct = TwellerFlow2_Gift_Cards::table();
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lt ) ) === $lt ) {
                $p['gift_used'] = (array) $wpdb->get_results( "SELECT l.*, c.code FROM $lt l JOIN $ct c ON c.id = l.card_id WHERE l.session_id IN (" . implode( ',', $ids ) . ') ORDER BY l.id DESC' );
            }
        }

        // Snap It Forward: their own link, friends, and whose link brought them.
        $p['snap'] = null; $p['snap_friends'] = array(); $p['snap_from'] = array();
        if ( class_exists( 'TwellerFlow2_Referrals' ) ) {
            $m = TwellerFlow2_Referrals::for_email( $email );
            if ( $m ) {
                $p['snap'] = $m;
                foreach ( TwellerFlow2_Referrals::log() as $r ) if ( $r['code'] === $m['code'] ) $p['snap_friends'][] = $r;
            }
            foreach ( TwellerFlow2_Referrals::log() as $r ) {
                if ( strtolower( (string) $r['friend_email'] ) === $email ) {
                    $from = TwellerFlow2_Referrals::get( $r['code'] );
                    $r['from_name'] = $from['name'] ?? $r['code'];
                    $r['from_email'] = $from['email'] ?? '';
                    $p['snap_from'][] = $r;
                }
            }
        }

        // Giveaway entries on the website.
        $p['giveaway'] = array();
        if ( class_exists( 'TwellerFlow2_Giveaway' ) ) {
            foreach ( TwellerFlow2_Giveaway::web() as $w ) if ( strtolower( (string) ( $w['email'] ?? '' ) ) === $email ) $p['giveaway'][] = $w;
        }
        return $p;
    }

    // ── Admin ────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page( 'tweller-flow-2', 'Clients', 'Clients', 'manage_options', self::PAGE, array( __CLASS__, 'admin_page' ) );
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $email = isset( $_GET['email'] ) ? strtolower( sanitize_email( wp_unslash( rawurldecode( $_GET['email'] ) ) ) ) : '';
        $q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        if ( $email !== '' ) {
            $p = self::profile( $email );
        } else {
            $clients = self::all( $q );
        }
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/clients.php';
    }

    /** Give or remove a client's thank-you discount. */
    public static function admin_thanks() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tweller_flow_2_client_thanks' );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( is_email( $email ) && class_exists( 'TwellerFlow2_Image_Consent' ) ) {
            if ( ( $_POST['do'] ?? '' ) === 'grant' ) TwellerFlow2_Image_Consent::grant_next_session_discount( $email );
            else TwellerFlow2_Image_Consent::redeem_next_session_discount( $email );
        }
        wp_safe_redirect( add_query_arg( 'done', 'thanks', self::url( $email ) ) );
        exit;
    }

    public static function admin_rewards_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'tweller_flow_2_rewards_save' );
        if ( class_exists( 'TwellerFlow2_Image_Consent' ) ) {
            update_option( TwellerFlow2_Image_Consent::OPT_DISCOUNT_PCT, min( 100, max( 0, (float) ( $_POST['pct'] ?? 50 ) ) ) );
            update_option( TwellerFlow2_Image_Consent::OPT_DISCOUNT_CAP, max( 0, (float) ( $_POST['cap'] ?? 0 ) ) );
        }
        $back = esc_url_raw( wp_unslash( $_POST['back'] ?? '' ) );
        wp_safe_redirect( add_query_arg( 'done', 'rewards', $back ?: self::url() ) );
        exit;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Christmas landing page — /book-us/christmas-photos/
 *
 * That address is the Book Us page opened on the Christmas session type
 * (see TwellerFlow2_Booking_Links). For ad traffic it gets its own
 * page instead of the generic menu: a distraction-free template
 * (templates/landing-christmas.php) with the booking wizard embedded at the
 * packages section, locked to Christmas.
 *
 * Everything the owner may want to change lives in one option, edited at
 * Tweller Bookings → Christmas Page: contact numbers, dates, scenes,
 * the "Recent Christmas sessions" gallery (hidden until it has photos),
 * reviews (hidden until there are some), FAQ answers.
 *
 * Christmas has its own packages (xmas_mini / xmas_full / xmas_extended) so
 * its prices and image counts never change Family, Grads and the rest,
 * which share mini / full / extended.
 */
class TwellerFlow2_Landing {

    const OPT       = 'tweller_flow_2_landing_xmas';
    const SEEDED    = 'tweller_flow_2_xmas_packages_seeded';
    const ASSET_VER = '1';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'maybe_seed_packages' ), 30 );
        add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'note_early_bird' ), 20, 2 );
        add_filter( 'tweller_flow_2_booking_backdrops', array( __CLASS__, 'backdrops' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
        add_action( 'admin_init', array( __CLASS__, 'admin_save' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
    }

    // ── Settings ─────────────────────────────────────

    public static function scene_defaults() {
        return array(
            array( 'slug' => 'classic-red-hearth', 'name' => 'Classic Red Hearth', 'desc' => 'Carved cream fireplace, red bows, twin trees.', 'focus' => '50% 55%', 'image_id' => 0 ),
            array( 'slug' => 'fireside-library',   'name' => 'Fireside Library',   'desc' => 'Dark wood, bookshelves, a lit fire and candle glow.', 'focus' => '52% 55%', 'image_id' => 0 ),
            array( 'slug' => 'snowy-window',       'name' => 'Snowy Window',       'desc' => 'Velvet green drapes and a snowy forest view.', 'focus' => '48% 50%', 'image_id' => 0 ),
            array( 'slug' => 'champagne-gold',     'name' => 'Champagne Gold',     'desc' => 'White brick, a gold and champagne tree, airy and bright.', 'focus' => '42% 55%', 'image_id' => 0 ),
        );
    }

    public static function faq_defaults() {
        return array(
            array( 'q' => 'Studio or my home?', 'a' => 'Either. Every package works in our studio or at your home or another location. For digital scenes we bring a portable white backdrop to you. Home and location shoots beyond Couva in the south, Curepe in the north, Macoya in the east or San Juan in the west have a TT$60 transport fee.' ),
            array( 'q' => 'Can we use our own tree and decor?', 'a' => 'Yes. We shoot at your home with your own tree and Christmas setup, styled and lit so it looks its best.' ),
            array( 'q' => 'What do we wear on a white backdrop?', 'a' => 'Rich, solid colours look best in the scenes: deep red, evergreen, cream, gold, navy or burgundy. Avoid all-white outfits and large logos. Coordinate, don\'t match. Send us your options on WhatsApp and we\'ll help you choose.' ),
            array( 'q' => 'Can we bring the dog?', 'a' => 'Of course. Pets are family. Let us know in your booking notes so we plan the time for them.' ),
            array( 'q' => 'How fast are photos delivered?', 'a' => 'Book early: the sooner you shoot, the sooner your edited photos arrive for Christmas. We\'ll confirm your delivery date when you book.' ),
            array( 'q' => 'Is the deposit refundable?', 'a' => 'Your 50% deposit secures your date and time. If something comes up, message us and we\'ll move your session to another available date.' ),
            array( 'q' => 'Can we choose more than one scene?', 'a' => 'Yes. Book before the early-bird deadline and your second scene is free. After that, ask us about adding scenes.' ),
            array( 'q' => 'Do you have gift vouchers?', 'a' => 'Yes. Give a Christmas session or any amount as a gift card, emailed straight to them or printed for you to give in person.' ),
        );
    }

    public static function defaults() {
        return array(
            'enabled'          => 1,
            'session_key'      => 'christmas_photos',
            'whatsapp'         => '18683422948',
            'phone'            => '18683422948',
            'wa_text'          => "Hi! I saw your Christmas photos ad and I'd like to book a session.",
            'last_shoot'       => '2026-12-24',
            'early_bird_end'   => '2026-11-30',
            'early_bird_text'  => '1 extra scene free',
            'from_price'       => 550,
            'require_confirm'  => 1,
            'scenes'           => self::scene_defaults(),
            'gallery_ids'      => array(),
            'reviews'          => '',
            'review_url'       => '',
            'faq'              => self::faq_defaults(),
            'og_image_id'      => 0,
        );
    }

    public static function settings() {
        $s = get_option( self::OPT, array() );
        $s = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
        if ( empty( $s['scenes'] ) || ! is_array( $s['scenes'] ) ) $s['scenes'] = self::scene_defaults();
        if ( empty( $s['faq'] ) || ! is_array( $s['faq'] ) ) $s['faq'] = self::faq_defaults();
        return $s;
    }

    // ── Packages ─────────────────────────────────────

    public static function package_defaults() {
        $common = array( 'All digital copies', 'Studio or on location', 'Digital scene or your own decor' );
        return array(
            'xmas_mini' => array(
                'name' => 'Christmas Mini', 'duration' => 30, 'images' => 10, 'members' => 4,
                'price' => 550, 'old_price' => 650, 'badge' => 'Top Booked',
                'features' => array_merge( array( 'Up to 30 minutes', '5–10 edited images' ), $common ),
            ),
            'xmas_full' => array(
                'name' => 'Christmas Full', 'duration' => 60, 'images' => 20, 'members' => 8,
                'price' => 700, 'old_price' => 850, 'badge' => 'Best Value',
                'features' => array_merge( array( 'Up to 1 hour', '10–20 edited images', 'Outfit change' ), $common ),
            ),
            'xmas_extended' => array(
                'name' => 'Christmas Extended', 'duration' => 120, 'images' => 999, 'members' => 10,
                'price' => 1000, 'old_price' => 0, 'badge' => 'Unlimited images',
                'features' => array_merge( array( 'Up to 2 hours', 'Unlimited edited images', 'Multiple outfit changes' ), $common ),
            ),
        );
    }

    /**
     * Add the Christmas packages once and point the Christmas session type
     * at them. After that the owner edits them in Offerings like any other
     * package; this never overwrites those edits.
     */
    public static function maybe_seed_packages() {
        if ( get_option( self::SEEDED ) ) return;

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $types    = get_option( 'tweller_flow_2_session_types', array() );
        if ( ! is_array( $packages ) || ! is_array( $types ) || ! $types ) return; // seeded defaults not there yet

        foreach ( self::package_defaults() as $key => $pkg ) {
            if ( ! isset( $packages[ $key ] ) ) $packages[ $key ] = $pkg;
        }

        $key = self::settings()['session_key'];
        if ( ! isset( $types[ $key ] ) ) {
            $types[ $key ] = array( 'name' => 'Christmas Photos', 'description' => 'Holiday Photos with your loved ones', 'short' => 'XMAS', 'allowed_packages' => array() );
        }
        $types[ $key ]['allowed_packages'] = array_keys( self::package_defaults() );

        update_option( 'tweller_flow_2_packages', $packages );
        update_option( 'tweller_flow_2_session_types', $types );
        update_option( self::SEEDED, TWELLER_FLOW_2_VERSION );
    }

    /** The packages offered on the page, in order. */
    public static function packages() {
        $s        = self::settings();
        $types    = get_option( 'tweller_flow_2_session_types', array() );
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $out = array();
        foreach ( (array) ( $types[ $s['session_key'] ]['allowed_packages'] ?? array() ) as $k ) {
            if ( isset( $packages[ $k ] ) ) $out[ $k ] = $packages[ $k ];
        }
        return $out;
    }

    // ── Is this the landing page? ────────────────────

    public static function is_landing() {
        static $is = null;
        if ( $is !== null ) return $is;
        $s = self::settings();
        if ( empty( $s['enabled'] ) || is_admin() || ! class_exists( 'TwellerFlow2_Booking_Links' ) ) return $is = false;
        $page = TwellerFlow2_Booking_Links::page_id();
        return $is = ( $page && is_page( $page ) && TwellerFlow2_Booking_Links::requested_key() === $s['session_key'] );
    }

    public static function template( $template ) {
        if ( ! self::is_landing() ) return $template;
        add_filter( 'show_admin_bar', '__return_false' );
        return TWELLER_FLOW_2_PLUGIN_DIR . 'templates/landing-christmas.php';
    }

    public static function enqueue() {
        if ( ! self::is_landing() ) return;
        $base = TWELLER_FLOW_2_PLUGIN_URL . 'public/';
        // The wizard is rendered in the body; enqueue its CSS here so it is
        // in <head> rather than printed late in the footer.
        wp_enqueue_style( 'tweller-flow-2-booking' );
        wp_enqueue_script( 'tweller-flow-2-booking' );
        wp_enqueue_style( 'tweller-landing', $base . 'css/landing.css', array( 'tweller-flow-2-booking' ), TWELLER_FLOW_2_VERSION );
        wp_enqueue_script( 'tweller-landing', $base . 'js/landing.js', array(), TWELLER_FLOW_2_VERSION, true );

        $s = self::settings();
        wp_localize_script( 'tweller-landing', 'twellerLanding', array(
            'earlyBirdEnd' => self::early_bird_iso(),
            'contentName'  => TwellerFlow2_Session::session_type_label( $s['session_key'] ),
        ) );

        // Astra / Elementor header-footer chrome isn't printed by this
        // template; drop the heaviest of their front-end styles too.
        // The theme's own stylesheets style markup this template doesn't
        // print, and their heading colours would fight the page's.
        foreach ( array( 'elementor-frontend', 'hfe-style', 'hfe-widgets-style', 'elementor-icons',
                         'astra-theme-css', 'astra-addon-css', 'woocommerce-general', 'woocommerce-layout',
                         'woocommerce-smallscreen', 'wc-blocks-style', 'global-styles', 'classic-theme-styles' ) as $h ) {
            wp_dequeue_style( $h );
        }
    }

    // ── Dates ────────────────────────────────────────

    private static function tz() {
        return method_exists( 'TwellerFlow2_Session', 'timezone' ) ? TwellerFlow2_Session::timezone() : wp_timezone();
    }

    /** End of the early-bird day, local time, ISO 8601 — or '' once it has passed. */
    public static function early_bird_iso() {
        $s = self::settings();
        if ( empty( $s['early_bird_end'] ) || empty( $s['early_bird_text'] ) ) return '';
        try {
            $end = new DateTime( $s['early_bird_end'] . ' 23:59:59', self::tz() );
        } catch ( \Exception $e ) { return ''; }
        return $end->getTimestamp() > time() ? $end->format( DATE_ATOM ) : '';
    }

    public static function early_bird_active() {
        return self::early_bird_iso() !== '';
    }

    /** "Nov 30", "Dec 24" … */
    public static function short_date( $ymd ) {
        $t = strtotime( (string) $ymd );
        return $t ? date_i18n( 'M j', $t ) : '';
    }

    /**
     * The visual backdrop picker in the booking form for Christmas: the four
     * scenes, plus own decor at home and on location. Shown on this page and
     * on the main Book Us page whenever Christmas is the session.
     */
    public static function backdrops( $all ) {
        $s = self::settings();
        $choices = array();
        foreach ( $s['scenes'] as $sc ) {
            $img = self::scene_images( $sc );
            $choices[] = array( 'key' => sanitize_key( $sc['slug'] ), 'name' => $sc['name'], 'thumb' => $img['sm'], 'kind' => 'scene' );
        }
        $choices[] = array( 'key' => 'own-decor', 'name' => 'Our own decor, at home', 'thumb' => '', 'kind' => 'home', 'note' => 'Your tree, your living room' );
        $choices[] = array( 'key' => 'on-location', 'name' => 'On location', 'thumb' => '', 'kind' => 'location', 'note' => 'A place you choose' );
        $all[ $s['session_key'] ] = array(
            'title'       => 'Choose your backdrop',
            'intro'       => 'Digital scenes are shot on a white backdrop in studio or at your home, then we place your family in the scene.',
            'choices'     => $choices,
            'extra'       => self::early_bird_active(),
            'extra_label' => 'Early bird: pick your free extra scene',
        );
        return $all;
    }

    /** Early-bird bookings carry the bonus on the session's history, so the studio sees it. */
    public static function note_early_bird( $session_id, $data ) {
        $s = self::settings();
        if ( ( $data['session_type'] ?? '' ) !== $s['session_key'] || ! self::early_bird_active() ) return;
        TwellerFlow2_Session::record_stage_history( $session_id, 'booked', 0, 'Early-bird booking: ' . $s['early_bird_text'] . ' (booked before ' . self::short_date( $s['early_bird_end'] ) . ').' . ( ! empty( $data['backdrop'] ) ? ' ' . str_replace( "\n", ' · ', $data['backdrop'] ) : '' ) );
    }

    // ── Media ────────────────────────────────────────

    /**
     * One scene's images: an override picked in the media library, else
     * the web versions shipped in public/landing/christmas/.
     */
    public static function scene_images( $scene ) {
        $id = (int) ( $scene['image_id'] ?? 0 );
        if ( $id && wp_attachment_is_image( $id ) ) {
            $lg = wp_get_attachment_image_src( $id, 'full' );
            $md = wp_get_attachment_image_src( $id, 'large' );
            $sm = wp_get_attachment_image_src( $id, 'medium_large' );
            return array(
                'lg' => $lg[0], 'lg_w' => $lg[1], 'lg_h' => $lg[2],
                'md' => $md ? $md[0] : $lg[0], 'md_w' => $md ? $md[1] : $lg[1],
                'sm' => $sm ? $sm[0] : $lg[0], 'sm_w' => $sm ? $sm[1] : $lg[1], 'sm_h' => $sm ? $sm[2] : $lg[2],
            );
        }
        $slug = sanitize_file_name( $scene['slug'] ?? '' );
        $url  = TWELLER_FLOW_2_PLUGIN_URL . 'public/landing/christmas/' . $slug;
        $v    = '?v=' . self::ASSET_VER;
        return array(
            'lg' => $url . '-1600.webp' . $v, 'lg_w' => 1600, 'lg_h' => 1067,
            'md' => $url . '-800.webp' . $v,  'md_w' => 800,
            'sm' => $url . '-600.webp' . $v,  'sm_w' => 600, 'sm_h' => 400,
        );
    }

    public static function og_image() {
        $s = self::settings();
        if ( ! empty( $s['og_image_id'] ) ) {
            $img = wp_get_attachment_image_src( (int) $s['og_image_id'], 'full' );
            if ( $img ) return array( 'url' => $img[0], 'w' => $img[1], 'h' => $img[2] );
        }
        return array( 'url' => TWELLER_FLOW_2_PLUGIN_URL . 'public/landing/christmas/og-1200x630.jpg?v=' . self::ASSET_VER, 'w' => 1200, 'h' => 630 );
    }

    public static function gallery() {
        $s   = self::settings();
        $out = array();
        foreach ( (array) $s['gallery_ids'] as $id ) {
            $id = (int) $id;
            if ( ! $id || ! wp_attachment_is_image( $id ) ) continue;
            $full = wp_get_attachment_image_src( $id, 'large' );
            $out[] = array(
                'id'  => $id,
                'img' => wp_get_attachment_image( $id, 'medium_large', false, array( 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '(min-width: 900px) 33vw, 80vw' ) ),
                'full'=> $full ? $full[0] : '',
                'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
            );
        }
        return $out;
    }

    /** Reviews, one per line: Name | Source | Review text */
    public static function reviews() {
        $out = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) self::settings()['reviews'] ) as $line ) {
            $parts = array_map( 'trim', explode( '|', $line, 3 ) );
            if ( count( $parts ) < 3 || $parts[0] === '' || $parts[2] === '' ) continue;
            $out[] = array( 'name' => $parts[0], 'source' => $parts[1], 'text' => $parts[2] );
        }
        return $out;
    }

    // ── Contact links ────────────────────────────────

    public static function wa_url() {
        $s = self::settings();
        return 'https://wa.me/' . preg_replace( '/\D/', '', $s['whatsapp'] ) . '?text=' . rawurlencode( $s['wa_text'] );
    }

    public static function tel_url() {
        return 'tel:+' . preg_replace( '/\D/', '', self::settings()['phone'] );
    }

    public static function phone_display() {
        $d = preg_replace( '/\D/', '', self::settings()['phone'] );
        if ( strlen( $d ) === 11 && $d[0] === '1' ) return '(' . substr( $d, 1, 3 ) . ') ' . substr( $d, 4, 3 ) . '-' . substr( $d, 7 );
        return $d;
    }

    // ── <head> ───────────────────────────────────────

    public static function share_title() {
        return 'Christmas Photos — Minis from TTD ' . number_format( (float) self::settings()['from_price'] );
    }

    public static function share_desc() {
        return 'Studio or at your home. 4 Christmas scenes or your own decor. Pick your package and book online with a deposit.';
    }

    /**
     * wp_head() output with every other plugin's social-preview tags and
     * <title> replaced by this page's own — the SEO Framework, the booking
     * links class and Astra would otherwise each print a set, and Facebook
     * reads the first.
     */
    public static function head() {
        ob_start();
        wp_head();
        $html = ob_get_clean();
        $html = preg_replace( '#<meta\s+(?:property|name)=["\'](?:og|twitter|article):[^>]*>\s*#i', '', $html );
        $html = preg_replace( '#<meta\s+name=["\']description["\'][^>]*>\s*#i', '', $html );
        $html = preg_replace( '#<link\s+rel=["\']canonical["\'][^>]*>\s*#i', '', $html );
        $html = preg_replace( '#<title>.*?</title>\s*#is', '', $html );

        $url  = TwellerFlow2_Booking_Links::link_for( self::settings()['session_key'] );
        $og   = self::og_image();
        $tags  = '<title>' . esc_html( self::share_title() . ' | Tweller Studios' ) . "</title>\n";
        $tags .= '<meta name="description" content="' . esc_attr( self::share_desc() ) . "\">\n";
        $tags .= '<link rel="canonical" href="' . esc_url( $url ) . "\">\n";
        $tags .= "<meta property=\"og:type\" content=\"website\">\n";
        $tags .= "<meta property=\"og:site_name\" content=\"Tweller Studios\">\n";
        $tags .= '<meta property="og:title" content="' . esc_attr( self::share_title() ) . "\">\n";
        $tags .= '<meta property="og:description" content="' . esc_attr( self::share_desc() ) . "\">\n";
        $tags .= '<meta property="og:url" content="' . esc_url( $url ) . "\">\n";
        $tags .= '<meta property="og:image" content="' . esc_url( $og['url'] ) . "\">\n";
        $tags .= '<meta property="og:image:width" content="' . (int) $og['w'] . "\">\n";
        $tags .= '<meta property="og:image:height" content="' . (int) $og['h'] . "\">\n";
        $tags .= "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        $tags .= '<meta name="twitter:title" content="' . esc_attr( self::share_title() ) . "\">\n";
        $tags .= '<meta name="twitter:image" content="' . esc_url( $og['url'] ) . "\">\n";
        echo $tags . $html; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_head output plus escaped tags
    }

    // ── Admin ────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page( 'tweller-flow-2', 'Christmas Page', 'Christmas Page', 'manage_options', 'tweller-flow-2-christmas', array( __CLASS__, 'admin_page' ) );
    }

    public static function admin_assets( $hook ) {
        if ( ( $_GET['page'] ?? '' ) !== 'tweller-flow-2-christmas' ) return;
        wp_enqueue_media();
        wp_enqueue_style( 'tweller-flow-2-admin', TWELLER_FLOW_2_PLUGIN_URL . 'admin/css/admin.css', array(), TWELLER_FLOW_2_VERSION );
    }

    public static function admin_page() {
        $s    = self::settings();
        $meta = TwellerFlow2_Meta_Events::settings();
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/landing.php';
    }

    public static function admin_save() {
        if ( empty( $_POST['tweller_flow_2_save_landing'] ) || ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'tweller_flow_2_save_landing' );
        $p   = wp_unslash( $_POST );
        $old = self::settings();
        $ymd = function ( $v, $fallback ) {
            $v = sanitize_text_field( (string) $v );
            return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $fallback;
        };

        $scenes = array();
        foreach ( self::scene_defaults() as $i => $def ) {
            $row = $p['scenes'][ $i ] ?? array();
            $scenes[] = array(
                'slug'     => $def['slug'],
                'name'     => sanitize_text_field( $row['name'] ?? $def['name'] ) ?: $def['name'],
                'desc'     => sanitize_text_field( $row['desc'] ?? $def['desc'] ),
                'focus'    => preg_match( '/^\d{1,3}% \d{1,3}%$/', (string) ( $row['focus'] ?? '' ) ) ? $row['focus'] : $def['focus'],
                'image_id' => absint( $row['image_id'] ?? 0 ),
            );
        }

        $faq = array();
        foreach ( (array) ( $p['faq'] ?? array() ) as $row ) {
            $q = sanitize_text_field( $row['q'] ?? '' );
            $a = sanitize_textarea_field( $row['a'] ?? '' );
            if ( $q !== '' && $a !== '' ) $faq[] = array( 'q' => $q, 'a' => $a );
        }

        update_option( self::OPT, array(
            'enabled'         => empty( $p['enabled'] ) ? 0 : 1,
            'session_key'     => $old['session_key'],
            'whatsapp'        => preg_replace( '/\D/', '', $p['whatsapp'] ?? '' ) ?: $old['whatsapp'],
            'phone'           => preg_replace( '/\D/', '', $p['phone'] ?? '' ) ?: $old['phone'],
            'wa_text'         => sanitize_text_field( $p['wa_text'] ?? '' ) ?: $old['wa_text'],
            'last_shoot'      => $ymd( $p['last_shoot'] ?? '', $old['last_shoot'] ),
            'early_bird_end'  => $ymd( $p['early_bird_end'] ?? '', '' ),
            'early_bird_text' => sanitize_text_field( $p['early_bird_text'] ?? '' ),
            'from_price'      => max( 0, (float) ( $p['from_price'] ?? $old['from_price'] ) ),
            'require_confirm' => empty( $p['require_confirm'] ) ? 0 : 1,
            'scenes'          => $scenes,
            'gallery_ids'     => array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $p['gallery_ids'] ?? '' ) ) ) ) ),
            'reviews'         => sanitize_textarea_field( $p['reviews'] ?? '' ),
            'review_url'      => esc_url_raw( $p['review_url'] ?? '' ),
            'faq'             => $faq ?: self::faq_defaults(),
            'og_image_id'     => absint( $p['og_image_id'] ?? 0 ),
        ) );

        $tr = TwellerFlow2_Booking_API::transport();
        update_option( 'tweller_flow_2_transport', array(
            'enabled' => empty( $p['transport_enabled'] ) ? 0 : 1,
            'fee'     => max( 0, (float) ( $p['transport_fee'] ?? $tr['fee'] ) ),
            'area'    => sanitize_text_field( $p['transport_area'] ?? '' ) ?: $tr['area'],
            'polygon' => sanitize_textarea_field( $p['transport_polygon'] ?? '' ) ?: $tr['polygon'],
        ) );

        $wa = TwellerFlow2_Booking_Alerts::settings();
        $wa_key = trim( (string) ( $p['wa_apikey'] ?? '' ) );
        update_option( TwellerFlow2_Booking_Alerts::OPT, array(
            'enabled' => empty( $p['wa_enabled'] ) ? 0 : 1,
            'phone'   => preg_replace( '/\D/', '', $p['wa_phone'] ?? '' ) ?: $wa['phone'],
            'apikey'  => $wa_key !== '' ? sanitize_text_field( $wa_key ) : $wa['apikey'],
        ), false );

        $m = TwellerFlow2_Meta_Events::settings();
        $token = trim( (string) ( $p['meta_access_token'] ?? '' ) );
        update_option( TwellerFlow2_Meta_Events::OPT, array(
            'pixel_id'        => preg_replace( '/\D/', '', $p['meta_pixel_id'] ?? '' ),
            // Blank keeps the saved token; "-" clears it.
            'access_token'    => $token === '-' ? '' : ( $token !== '' ? sanitize_text_field( $token ) : $m['access_token'] ),
            'test_event_code' => sanitize_text_field( $p['meta_test_event_code'] ?? '' ),
            'server_events'   => empty( $p['meta_server_events'] ) ? 0 : 1,
        ), false );

        wp_safe_redirect( admin_url( 'admin.php?page=tweller-flow-2-christmas&saved=1' ) );
        exit;
    }
}

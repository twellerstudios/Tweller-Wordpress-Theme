<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Per-session-type booking links — /book-us/grads/, /book-us/maternity/ …
 *
 * Each link opens the booking page with that session type already chosen,
 * so an ad or a message for Grads lands on the Grads packages instead of the
 * full menu. Built for paid social:
 *
 *  - Clean path links, plus a ?session=grads fallback that works even when
 *    permalinks are plain or the rewrite has not been flushed yet.
 *  - Ad parameters (utm_*, fbclid, gclid) are captured by booking.js and
 *    stored on the booking, so the studio can see which ad paid for it.
 *  - Link previews (Facebook / Instagram / WhatsApp) name the session type.
 *
 * The rewrite only matches the slugs of real session types, so it never
 * shadows a child page of the booking page with some other name.
 */
class TwellerFlow2_Booking_Links {

    const QUERY_VAR = 'tf2_book';
    const PKG_VAR   = 'tf2_pkg';

    /** Parameters worth keeping from an ad click or a shared link. */
    const ATTR_KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'landing', 'referrer', 'link' );

    public static function init() {
        add_action( 'init', array( __CLASS__, 'add_rewrite' ), 20 );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_filter( 'redirect_canonical', array( __CLASS__, 'keep_deep_link' ) );
        add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
        add_action( 'wp_head', array( __CLASS__, 'output_og_tags' ), 4 );
        // SEO plugins print their own preview tags; teach theirs the session.
        add_filter( 'wpseo_opengraph_title', array( __CLASS__, 'seo_title' ) );
        add_filter( 'wpseo_opengraph_desc', array( __CLASS__, 'seo_desc' ) );
        add_filter( 'rank_math/opengraph/facebook/og_title', array( __CLASS__, 'seo_title' ) );
        add_filter( 'rank_math/opengraph/facebook/og_description', array( __CLASS__, 'seo_desc' ) );
    }

    // ── Slugs ─────────────────────────────────────────

    public static function session_types() {
        $types = get_option( 'tweller_flow_2_session_types', array() );
        return is_array( $types ) ? $types : array();
    }

    /** URL slug for a session-type key: mommy_and_me → mommy-and-me. */
    public static function slug_for( $key ) {
        return str_replace( '_', '-', sanitize_key( $key ) );
    }

    /**
     * Session-type key for a slug, or ''. Accepts the key's slug and the
     * name's slug, so /book-us/1-year-photos/ and /book-us/one-year/ both
     * resolve — whichever one an ad or a message happened to use.
     */
    public static function key_for( $slug ) {
        $slug = sanitize_title( (string) $slug );
        if ( $slug === '' ) return '';
        foreach ( self::session_types() as $key => $type ) {
            if ( $slug === self::slug_for( $key ) || $slug === sanitize_title( $type['name'] ?? '' ) ) {
                return (string) $key;
            }
        }
        return '';
    }

    /** Package key for a slug (full, event-1hr), or ''. */
    public static function package_key_for( $slug ) {
        $slug = sanitize_title( (string) $slug );
        if ( $slug === '' ) return '';
        $packages = get_option( 'tweller_flow_2_packages', array() );
        foreach ( (array) $packages as $key => $pkg ) {
            if ( $slug === self::slug_for( $key ) ) return (string) $key;
        }
        return '';
    }

    // ── Booking page ──────────────────────────────────

    public static function page_id() {
        static $id = null;
        if ( $id !== null ) return $id;

        $url = class_exists( 'TwellerFlow2_Photo_Automation' ) ? TwellerFlow2_Photo_Automation::get_booking_page_url() : '';
        if ( ! $url ) return $id = 0;

        // url_to_postid() costs queries and runs on every request (the
        // rewrite needs it), so remember the answer for this URL.
        $cached = get_option( 'tweller_flow_2_booking_page_ref' );
        if ( is_array( $cached ) && ( $cached['url'] ?? '' ) === $url && get_post_status( (int) $cached['id'] ) === 'publish' ) {
            return $id = (int) $cached['id'];
        }
        $id = (int) url_to_postid( $url );
        if ( $id ) update_option( 'tweller_flow_2_booking_page_ref', array( 'url' => $url, 'id' => $id ), false );
        return $id;
    }

    public static function page_url() {
        $id = self::page_id();
        return $id ? get_permalink( $id ) : '';
    }

    /** Whether links can use the /book-us/grads/ form on this site. */
    public static function pretty() {
        $base = self::page_url();
        return $base && get_option( 'permalink_structure' ) && strpos( $base, '?' ) === false;
    }

    /**
     * Shareable link for one session type, optionally with a package:
     * /book-us/grads/ or /book-us/grads/full/ ('' when no booking page).
     */
    public static function link_for( $key, $package = '' ) {
        $base = self::page_url();
        if ( ! $base ) return '';
        $slug = self::slug_for( $key );
        $pkg  = $package !== '' ? self::slug_for( $package ) : '';
        if ( self::pretty() ) {
            return trailingslashit( $base ) . $slug . '/' . ( $pkg ? $pkg . '/' : '' );
        }
        $args = array( 'session' => $slug );
        if ( $pkg ) $args['package'] = $pkg;
        return add_query_arg( $args, $base );
    }

    /** Every bookable type with its link — for admin, the app, and JS. */
    public static function all_links() {
        $out = array();
        foreach ( self::session_types() as $key => $type ) {
            // Inquiry-only types (Weddings) have nothing to deep-link to.
            if ( empty( $type['allowed_packages'] ) ) continue;
            $url = self::link_for( $key );
            if ( ! $url ) continue;
            $out[] = array(
                'key'  => (string) $key,
                'slug' => self::slug_for( $key ),
                'name' => $type['name'] ?? (string) $key,
                'url'  => $url,
            );
        }
        return $out;
    }

    // ── Rewrite ───────────────────────────────────────

    public static function query_vars( $vars ) {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::PKG_VAR;
        return $vars;
    }

    public static function add_rewrite() {
        $id = self::page_id();
        if ( ! $id ) return;
        $path = get_page_uri( $id );
        $slugs = array();
        foreach ( array_keys( self::session_types() ) as $key ) {
            $slugs[] = self::slug_for( $key );
            $name_slug = sanitize_title( self::session_types()[ $key ]['name'] ?? '' );
            if ( $name_slug ) $slugs[] = $name_slug;
        }
        $slugs = array_values( array_unique( array_filter( $slugs ) ) );
        if ( ! $path || ! $slugs ) return;

        // Optional second segment: the package (/book-us/grads/full/).
        $regex = '^' . preg_quote( $path, '#' ) . '/(' . implode( '|', array_map( function ( $s ) { return preg_quote( $s, '#' ); }, $slugs ) ) . ')(?:/([a-z0-9-]+))?/?$';
        add_rewrite_rule( $regex, 'index.php?pagename=' . $path . '&' . self::QUERY_VAR . '=$matches[1]&' . self::PKG_VAR . '=$matches[2]', 'top' );

        // Flush once whenever the page path or the set of session types
        // changes — never on every request. Deferred to wp_loaded: a flush
        // mid-init would save a rule set missing every rule other plugins
        // register later in init, and break their URLs.
        $sig = md5( $regex );
        if ( get_option( 'tweller_flow_2_book_links_sig' ) !== $sig ) {
            add_action( 'wp_loaded', function () use ( $sig ) {
                update_option( 'tweller_flow_2_book_links_sig', $sig, false );
                flush_rewrite_rules( false );
            } );
        }
    }

    /** Don't let WordPress "correct" /book-us/grads/ back to /book-us/. */
    public static function keep_deep_link( $redirect ) {
        return get_query_var( self::QUERY_VAR ) ? false : $redirect;
    }

    // ── The session this request asked for ───────────

    /** Session-type key from the path or ?session=, or ''. */
    public static function requested_key() {
        $slug = get_query_var( self::QUERY_VAR );
        if ( ! $slug && isset( $_GET['session'] ) ) $slug = wp_unslash( $_GET['session'] );
        $key = self::key_for( $slug );
        // Weddings (no packages) is an inquiry, not a bookable deep link.
        $types = self::session_types();
        if ( $key && empty( $types[ $key ]['allowed_packages'] ) ) return '';
        return $key;
    }

    /** Package key from the path or ?package=, if that session offers it. */
    public static function requested_package() {
        $key = self::requested_key();
        if ( ! $key ) return '';
        $slug = get_query_var( self::PKG_VAR );
        if ( ! $slug && isset( $_GET['package'] ) ) $slug = wp_unslash( $_GET['package'] );
        $pkg = self::package_key_for( $slug );
        $allowed = (array) ( self::session_types()[ $key ]['allowed_packages'] ?? array() );
        return ( $pkg && in_array( $pkg, $allowed, true ) ) ? $pkg : '';
    }

    private static function requested_type() {
        $key = self::requested_key();
        if ( ! $key ) return null;
        $types = self::session_types();
        return $types[ $key ] ?? null;
    }

    // ── Link previews ─────────────────────────────────

    private static function preview_title( $type ) {
        return sprintf( 'Book %s — Tweller Studios', $type['name'] );
    }

    private static function preview_desc( $type ) {
        $desc = trim( (string) ( $type['description'] ?? '' ) );
        return ( $desc ? $desc . ' ' : '' ) . 'Pick your package and reserve your date online.';
    }

    public static function document_title( $title ) {
        $type = self::requested_type();
        return $type ? self::preview_title( $type ) : $title;
    }

    public static function seo_title( $title ) {
        $type = self::requested_type();
        return $type ? self::preview_title( $type ) : $title;
    }

    public static function seo_desc( $desc ) {
        $type = self::requested_type();
        return $type ? self::preview_desc( $type ) : $desc;
    }

    public static function output_og_tags() {
        $type = self::requested_type();
        if ( ! $type ) return;
        // Yoast / Rank Math already print og:title — they get the filtered
        // values above, and a second set of tags would only confuse Facebook.
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) return;

        $url = self::link_for( self::requested_key(), self::requested_package() );
        echo "\n<meta property=\"og:type\" content=\"website\" />\n";
        echo '<meta property="og:title" content="' . esc_attr( self::preview_title( $type ) ) . "\" />\n";
        echo '<meta property="og:description" content="' . esc_attr( self::preview_desc( $type ) ) . "\" />\n";
        if ( $url ) echo '<meta property="og:url" content="' . esc_url( $url ) . "\" />\n";
    }

    // ── Attribution ───────────────────────────────────

    /** Whitelisted, length-capped attribution from the booking payload. */
    public static function sanitize_attribution( $raw ) {
        if ( ! is_array( $raw ) ) return array();
        $out = array();
        foreach ( self::ATTR_KEYS as $k ) {
            if ( empty( $raw[ $k ] ) || ! is_scalar( $raw[ $k ] ) ) continue;
            $v = in_array( $k, array( 'landing', 'referrer' ), true )
                ? esc_url_raw( (string) $raw[ $k ] )
                : sanitize_text_field( (string) $raw[ $k ] );
            if ( $v !== '' ) $out[ $k ] = substr( $v, 0, 500 );
        }
        if ( ! empty( $raw['at'] ) && is_numeric( $raw['at'] ) ) {
            $out['at'] = gmdate( 'Y-m-d H:i:s', (int) ( $raw['at'] / 1000 ) );
        }
        return $out;
    }

    /**
     * One-line summary for the admin: "Facebook ad · grads-oct · via /book-us/grads/".
     */
    public static function describe( $attr ) {
        if ( ! is_array( $attr ) || ! $attr ) return '';
        $src = strtolower( $attr['utm_source'] ?? '' );
        $med = strtolower( $attr['utm_medium'] ?? '' );
        $paid = (bool) preg_match( '/cpc|paid|ad|ppc|social_paid/', $med );

        if ( $src === '' && ! empty( $attr['fbclid'] ) ) $src = 'facebook';
        if ( $src === '' && ! empty( $attr['gclid'] ) )  { $src = 'google'; $paid = true; }
        if ( $src === '' && ! empty( $attr['referrer'] ) ) {
            $host = wp_parse_url( $attr['referrer'], PHP_URL_HOST );
            if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) $src = preg_replace( '/^(www|m|l|lm)\./', '', $host );
        }

        $names = array( 'fb' => 'Facebook', 'facebook' => 'Facebook', 'ig' => 'Instagram', 'instagram' => 'Instagram', 'google' => 'Google', 'whatsapp' => 'WhatsApp', 'tiktok' => 'TikTok' );
        $parts = array();
        if ( $src !== '' ) {
            $label = $names[ $src ] ?? ( strpos( $src, 'facebook' ) !== false ? 'Facebook' : ( strpos( $src, 'instagram' ) !== false ? 'Instagram' : $src ) );
            $parts[] = $label . ( $paid ? ' ad' : '' );
        }
        if ( ! empty( $attr['utm_campaign'] ) ) $parts[] = $attr['utm_campaign'];
        if ( ! empty( $attr['utm_content'] ) )  $parts[] = $attr['utm_content'];
        if ( ! empty( $attr['link'] ) )         $parts[] = 'via ' . $attr['link'] . ' link';
        return $parts ? implode( ' · ', $parts ) : 'Direct';
    }
}

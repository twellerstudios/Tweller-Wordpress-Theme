<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Backdrop edits: which digital backdrops a client chose, what they cost,
 * and which photo gets which backdrop.
 *
 *  - At booking: one main backdrop (included), any number of extra digital
 *    backdrops at TT$50 each (an early-bird booking gets its first extra
 *    free), and an optional custom-backdrop idea.
 *  - In the culling portal: under each chosen photo the client picks which
 *    of their backdrops it gets; an early-bird client who did not pick their
 *    free extra yet can redeem it there.
 *  - In the admin: the plan and the photo-by-photo list under the client's
 *    details, plus an "editing brief" to hand to another editing session.
 *
 * One option per session (tweller_bd_plan_<id>), nothing to migrate.
 */
class TwellerFlow2_Backdrop_Edits {

    const KEEP = 'as-shot'; // "no backdrop change" for a photo

    public static function init() {
        add_action( 'admin_post_tweller_flow_2_backdrop_brief', array( __CLASS__, 'admin_brief' ) );
        add_action( 'admin_post_tweller_flow_2_backdrop_assign', array( __CLASS__, 'admin_assign' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function price() {
        return (float) apply_filters( 'tweller_flow_2_backdrop_price', (float) get_option( 'tweller_flow_2_backdrop_price', 50 ) );
    }

    // ── Catalogue ────────────────────────────────────

    /** The backdrop choices for a session type, keyed (digital = can be edited in). */
    public static function catalogue( $session_type ) {
        if ( ! class_exists( 'TwellerFlow2_Booking_Shortcode' ) ) return array();
        $cfg = TwellerFlow2_Booking_Shortcode::backdrops()[ $session_type ] ?? null;
        $out = array();
        foreach ( (array) ( $cfg['choices'] ?? array() ) as $c ) {
            $out[ $c['key'] ] = array(
                'key'     => $c['key'],
                'name'    => $c['name'],
                'thumb'   => $c['thumb'] ?? '',
                'full'    => $c['full'] ?? ( $c['thumb'] ?? '' ),
                'digital' => ( $c['kind'] ?? '' ) === 'scene',
            );
        }
        return $out;
    }

    // ── Plan storage ─────────────────────────────────

    public static function get_plan( $session_id ) {
        $p = get_option( 'tweller_bd_plan_' . (int) $session_id, null );
        return is_array( $p ) ? $p : null;
    }

    public static function save_plan( $session_id, $plan ) {
        update_option( 'tweller_bd_plan_' . (int) $session_id, $plan, false );
    }

    /**
     * The session's plan. Bookings made before 3.73 have no stored plan, only
     * "Backdrop: …" lines in their notes: build one from those, so early-bird
     * clients from then can still redeem their free backdrop.
     */
    public static function plan_for( $session ) {
        if ( ! $session ) return null;
        $plan = self::get_plan( $session->id );
        if ( $plan ) return $plan;
        $cat   = self::catalogue( $session->session_type ?? '' );
        $notes = (string) ( $session->notes ?? '' );
        if ( ! $cat || ! preg_match( '/^Backdrop: (.+)$/m', $notes, $m ) ) return null;
        $by_name = array();
        foreach ( $cat as $k => $c ) $by_name[ strtolower( trim( $c['name'] ) ) ] = $k;
        $primary = $by_name[ strtolower( trim( $m[1] ) ) ] ?? '';
        if ( $primary === '' ) return null;
        $free = '';
        if ( preg_match( '/^Free extra (?:scene|backdrop) \(early bird\): (.+)$/m', $notes, $f ) ) $free = $by_name[ strtolower( trim( $f[1] ) ) ] ?? '';
        if ( $free === $primary ) $free = '';
        $end      = class_exists( 'TwellerFlow2_Landing' ) ? (string) ( TwellerFlow2_Landing::settings()['early_bird_end'] ?? '' ) : '';
        $booked   = substr( (string) ( $session->created_at ?? '' ), 0, 10 );
        $eligible = $free !== '' || ( $end !== '' && $booked !== '' && $booked <= $end );
        $plan = array(
            'session_type'  => $session->session_type,
            'primary'       => $primary,
            'paid'          => array(),
            'free'          => $free,
            'free_eligible' => $eligible ? 1 : 0,
            'price_each'    => self::price(),
            'fee'           => 0,
            'custom'        => '',
            'assign'        => array(),
            'created'       => current_time( 'mysql' ),
            'legacy'        => 1,
        );
        self::save_plan( $session->id, $plan );
        return $plan;
    }

    /** Digital backdrops this session has (main + paid extras + the free one). */
    public static function scenes( $plan ) {
        if ( ! $plan ) return array();
        $cat  = self::catalogue( $plan['session_type'] );
        $keys = array_merge( array( $plan['primary'] ), (array) $plan['paid'], $plan['free'] !== '' ? array( $plan['free'] ) : array() );
        $out  = array();
        foreach ( array_unique( $keys ) as $k ) if ( isset( $cat[ $k ] ) && $cat[ $k ]['digital'] ) $out[] = $k;
        return $out;
    }

    /** Can the early-bird free backdrop still be redeemed? */
    public static function free_available( $plan ) {
        return $plan && ! empty( $plan['free_eligible'] ) && $plan['free'] === '';
    }

    // ── At booking ───────────────────────────────────

    /**
     * Validate the booking's backdrop choices. Returns [ plan, fee, note ] or
     * a WP_Error. $early = the early-bird free extra applies.
     */
    public static function from_booking( $session_type, $primary, $extras, $custom, $early ) {
        $cat = self::catalogue( $session_type );
        if ( ! $cat ) return array( null, 0.0, '' );
        $primary = sanitize_key( $primary );
        if ( ! isset( $cat[ $primary ] ) ) return new WP_Error( 'backdrop', 'Please choose your backdrop.', array( 'status' => 400 ) );

        $picked = array();
        foreach ( (array) $extras as $k ) {
            $k = sanitize_key( $k );
            if ( $k !== $primary && isset( $cat[ $k ] ) && $cat[ $k ]['digital'] && ! in_array( $k, $picked, true ) ) $picked[] = $k;
        }
        $free = '';
        if ( $early && $picked ) $free = array_shift( $picked ); // the first extra is the free one
        $paid  = $picked;
        $fee   = round( count( $paid ) * self::price(), 2 );
        $custom = trim( substr( sanitize_textarea_field( (string) $custom ), 0, 1000 ) );

        $plan = array(
            'session_type'  => $session_type,
            'primary'       => $primary,
            'paid'          => $paid,
            'free'          => $free,
            'free_eligible' => $early ? 1 : 0,
            'price_each'    => self::price(),
            'fee'           => $fee,
            'custom'        => $custom,
            'assign'        => array(),   // proof id => [ 'file' => original filename, 'key' => backdrop key ]
            'created'       => current_time( 'mysql' ),
        );

        $n    = function ( $k ) use ( $cat ) { return $cat[ $k ]['name'] ?? $k; };
        $note = 'Backdrop: ' . $n( $primary );
        if ( $free !== '' ) $note .= "\nFree extra backdrop (early bird): " . $n( $free );
        elseif ( $early ) $note .= "\nFree extra backdrop (early bird): not chosen yet, can be redeemed in the photo selection";
        if ( $paid ) $note .= "\nExtra digital backdrops (+TT$" . number_format( self::price() ) . ' each): ' . implode( ', ', array_map( $n, $paid ) ) . ' = TT$' . number_format( $fee, 2 );
        if ( $custom !== '' ) $note .= "\nCustom backdrop idea (quote to follow): " . $custom;

        return array( $plan, $fee, $note );
    }

    // ── Culling portal ───────────────────────────────

    /** What the photo-selection portal needs (null when the session has no backdrops). */
    public static function portal_data( $session ) {
        $plan = self::plan_for( $session );
        if ( ! $plan ) return null;
        $cat = self::catalogue( $plan['session_type'] );
        $mine = self::scenes( $plan );
        $assign = array();
        foreach ( (array) $plan['assign'] as $pid => $row ) $assign[ (int) $pid ] = $row['key'];
        $all_digital = array_values( array_filter( $cat, function ( $c ) { return $c['digital']; } ) );
        return array(
            'mine'          => array_values( array_map( function ( $k ) use ( $cat ) { return array( 'key' => $k, 'name' => $cat[ $k ]['name'], 'thumb' => $cat[ $k ]['thumb'] ); }, $mine ) ),
            'primary'       => $plan['primary'],
            'primary_name'  => $cat[ $plan['primary'] ]['name'] ?? '',
            'primary_digital' => ! empty( $cat[ $plan['primary'] ]['digital'] ),
            'free_available'=> self::free_available( $plan ),
            'free_options'  => self::free_available( $plan ) ? array_values( array_map( function ( $c ) { return array( 'key' => $c['key'], 'name' => $c['name'], 'thumb' => $c['thumb'] ); }, array_filter( $all_digital, function ( $c ) use ( $mine ) { return ! in_array( $c['key'], $mine, true ); } ) ) ) : array(),
            'assign'        => (object) $assign,
            'keep'          => self::KEEP,
        );
    }

    public static function routes() {
        register_rest_route( 'tweller-flow-2/v1', '/culling/(?P<code>[a-zA-Z0-9\-]+)/redeem-backdrop', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_redeem' ), 'permission_callback' => '__return_true',
        ) );
    }

    /** Same gate as the portal itself: unlocked when there is no password, or with the portal token. */
    public static function portal_unlocked( $session, $token ) {
        $hash = get_option( 'tweller_culling_pw_' . $session->id, '' );
        if ( empty( $hash ) ) return true;
        return $token && class_exists( 'TwellerFlow2_Gallery' ) && TwellerFlow2_Gallery::verify_token( $session->id, $token );
    }

    /** The early-bird client picks their free extra backdrop in the portal. */
    public static function rest_redeem( $request ) {
        $session = TwellerFlow2_Session::get_by_code( sanitize_text_field( $request['code'] ) );
        if ( ! $session ) return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        if ( ! self::portal_unlocked( $session, sanitize_text_field( (string) $request->get_param( 'token' ) ) ) ) return new WP_Error( 'locked', 'Please unlock the portal first.', array( 'status' => 403 ) );
        $plan = self::plan_for( $session );
        if ( ! self::free_available( $plan ) ) return new WP_Error( 'none', 'There is no free backdrop to redeem on this booking.', array( 'status' => 400 ) );
        $key = sanitize_key( (string) $request->get_param( 'key' ) );
        $cat = self::catalogue( $plan['session_type'] );
        if ( empty( $cat[ $key ]['digital'] ) || in_array( $key, self::scenes( $plan ), true ) ) return new WP_Error( 'bad', 'Please choose one of the digital backdrops.', array( 'status' => 400 ) );
        $plan['free'] = $key;
        self::save_plan( $session->id, $plan );
        TwellerFlow2_Session::record_stage_history( $session->id, $session->current_stage, 0, 'Free early-bird backdrop redeemed in the photo selection: ' . $cat[ $key ]['name'] );
        return rest_ensure_response( array( 'ok' => true, 'backdrops' => self::portal_data( $session ) ) );
    }

    /**
     * Store which backdrop each chosen photo gets ($map: proof id => key).
     * Photos the client left unassigned get the main backdrop when it is a
     * digital one, else "as shot".
     */
    public static function save_assignments( $session, $map, $proof_ids ) {
        $plan = self::plan_for( $session );
        if ( ! $plan ) return;
        $mine = self::scenes( $plan );
        $default = in_array( $plan['primary'], $mine, true ) ? $plan['primary'] : ( count( $mine ) === 1 ? $mine[0] : self::KEEP );
        global $wpdb;
        $tbl = $wpdb->prefix . TwellerFlow2_Culling::TABLE_PROOFS;
        $out = array();
        foreach ( (array) $proof_ids as $pid ) {
            $pid  = (int) $pid;
            $file = (string) $wpdb->get_var( $wpdb->prepare( "SELECT original_filename FROM $tbl WHERE id = %d AND session_id = %d", $pid, $session->id ) );
            if ( $file === '' ) continue;
            $key = sanitize_key( (string) ( is_array( $map ) ? ( $map[ $pid ] ?? $map[ (string) $pid ] ?? '' ) : '' ) );
            if ( $key !== self::KEEP && ! in_array( $key, $mine, true ) ) $key = $default;
            $out[ $pid ] = array( 'file' => $file, 'key' => $key );
        }
        $plan['assign'] = $out;
        self::save_plan( $session->id, $plan );
    }

    // ── Admin ────────────────────────────────────────

    /** Rows for the admin and the brief: [ file, key, name, full ] in selection order. */
    public static function rows( $plan ) {
        $cat = self::catalogue( $plan['session_type'] );
        $out = array();
        foreach ( (array) $plan['assign'] as $pid => $r ) {
            $out[] = array(
                'pid'  => (int) $pid,
                'file' => $r['file'],
                'key'  => $r['key'],
                'name' => $r['key'] === self::KEEP ? 'Keep as shot (no backdrop change)' : ( $cat[ $r['key'] ]['name'] ?? $r['key'] ),
                'full' => $cat[ $r['key'] ]['full'] ?? '',
                'thumb'=> $cat[ $r['key'] ]['thumb'] ?? '',
            );
        }
        return $out;
    }

    /** The editing brief, written to be pasted into a separate editing session. */
    public static function brief( $session ) {
        $plan = self::plan_for( $session );
        if ( ! $plan ) return '';
        $cat  = self::catalogue( $plan['session_type'] );
        $rows = self::rows( $plan );
        $mine = self::scenes( $plan );
        $L = array();
        $L[] = '# Backdrop editing brief: ' . $session->client_name . ' (' . $session->tracking_code . ')';
        $L[] = '';
        $L[] = 'You are editing photos for Tweller Studios. The family was photographed in studio on a plain backdrop. Replace the studio background in each photo below with the digital backdrop named for it.';
        $L[] = '';
        $L[] = '## Rules';
        $L[] = '- Change ONLY the background. Do not alter the people: no face, body, skin, hair or clothing changes, no reshaping, no beautifying.';
        $L[] = '- Cut out cleanly around hair and clothing edges; no halos or leftover studio background.';
        $L[] = '- Match the backdrop to the photo: perspective and floor line, light direction and colour temperature, depth of field (keep the backdrop slightly softer than the people).';
        $L[] = '- Add a soft, natural contact shadow where people sit or stand.';
        $L[] = '- Keep the original crop and resolution. Export as high-quality JPEG named like the original, with the backdrop name added, e.g. IMG_0412_snowy-window.jpg.';
        $L[] = '- Photos marked "Keep as shot" are not changed.';
        $L[] = '';
        $L[] = '## Backdrops for this client';
        foreach ( $mine as $k ) $L[] = '- **' . $cat[ $k ]['name'] . '** (`' . $k . '`): reference image ' . $cat[ $k ]['full'];
        if ( ! empty( $cat[ $plan['primary'] ] ) && empty( $cat[ $plan['primary'] ]['digital'] ) ) $L[] = '- Main setting: ' . $cat[ $plan['primary'] ]['name'] . ' (shot on location, not a digital backdrop).';
        if ( $plan['custom'] !== '' ) $L[] = '- Custom backdrop requested by the client: "' . $plan['custom'] . '" (confirm with the studio before using it).';
        $L[] = '';
        $L[] = '## Photo by photo (' . count( $rows ) . ' photos)';
        if ( ! $rows ) {
            $L[] = '_The client has not chosen their photos yet._';
        } else {
            $L[] = '| # | Original file | Backdrop |';
            $L[] = '|---|---|---|';
            foreach ( $rows as $i => $r ) $L[] = '| ' . ( $i + 1 ) . ' | ' . $r['file'] . ' | ' . $r['name'] . ' |';
            $L[] = '';
            $L[] = '## Same list as data';
            $L[] = '```json';
            $L[] = wp_json_encode( array_map( function ( $r ) { return array( 'file' => $r['file'], 'backdrop' => $r['key'], 'backdrop_name' => $r['name'], 'reference' => $r['full'] ); }, $rows ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            $L[] = '```';
        }
        return implode( "\n", $L ) . "\n";
    }

    public static function admin_brief() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        $sid = (int) ( $_GET['session_id'] ?? 0 );
        check_admin_referer( 'tweller_flow_2_backdrop_brief_' . $sid );
        $s = TwellerFlow2_Session::get( $sid );
        if ( ! $s ) wp_die( 'Session not found.' );
        nocache_headers();
        header( 'Content-Type: text/markdown; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="backdrop-brief-' . sanitize_file_name( $s->tracking_code ) . '.md"' );
        echo self::brief( $s ); // phpcs:ignore -- plain-text download
        exit;
    }

    /** The studio changes a photo's backdrop from the admin. */
    public static function admin_assign() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        $sid = (int) ( $_POST['session_id'] ?? 0 );
        check_admin_referer( 'tweller_flow_2_backdrop_assign_' . $sid );
        $plan = self::get_plan( $sid );
        if ( $plan ) {
            $mine = self::scenes( $plan );
            foreach ( (array) ( $_POST['bd'] ?? array() ) as $pid => $key ) {
                $pid = (int) $pid; $key = sanitize_key( wp_unslash( $key ) );
                if ( isset( $plan['assign'][ $pid ] ) && ( $key === self::KEEP || in_array( $key, $mine, true ) ) ) $plan['assign'][ $pid ]['key'] = $key;
            }
            self::save_plan( $sid, $plan );
        }
        wp_safe_redirect( add_query_arg( array( 'page' => 'tweller-flow-2-session', 'id' => $sid, 'bd_saved' => 1 ), admin_url( 'admin.php' ) ) . '#tf2-backdrops' );
        exit;
    }

    /** The "Backdrop edits" card on the session page. */
    public static function admin_card( $session ) {
        $plan = self::plan_for( $session );
        if ( ! $plan ) return '';
        $cat  = self::catalogue( $plan['session_type'] );
        $mine = self::scenes( $plan );
        $rows = self::rows( $plan );
        $nm   = function ( $k ) use ( $cat ) { return $cat[ $k ]['name'] ?? $k; };
        $th   = function ( $k ) use ( $cat ) { return ! empty( $cat[ $k ]['thumb'] ) ? '<img src="' . esc_url( $cat[ $k ]['thumb'] ) . '" alt="" style="width:46px;height:31px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;">' : ''; };
        $brief_url = wp_nonce_url( admin_url( 'admin-post.php?action=tweller_flow_2_backdrop_brief&session_id=' . (int) $session->id ), 'tweller_flow_2_backdrop_brief_' . (int) $session->id );
        ob_start();
        ?>
        <div class="tf2-card tf2-mb-6" id="tf2-backdrops">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <h3 style="margin:0;">Backdrop edits</h3>
                <span>
                    <a class="tf2-btn tf2-btn--secondary tf2-btn--sm" href="<?php echo esc_url( $brief_url ); ?>">Export editing brief</a>
                    <button type="button" class="button button-small tf2-copy" data-copy="<?php echo esc_attr( self::brief( $session ) ); ?>">Copy brief</button>
                </span>
            </div>
            <?php if ( ! empty( $_GET['bd_saved'] ) ) echo '<p style="color:#067647;margin:8px 0 0;">Saved.</p>'; ?>
            <table class="widefat" style="margin-top:12px;">
                <tbody>
                    <tr><td style="width:190px;"><strong>Main backdrop</strong></td><td><?php echo $th( $plan['primary'] ) . esc_html( $nm( $plan['primary'] ) ); // phpcs:ignore ?> <small>(included)</small></td></tr>
                    <?php if ( $plan['paid'] ) : ?>
                        <tr><td><strong>Extra backdrops</strong></td><td><?php foreach ( $plan['paid'] as $k ) echo '<div style="margin:3px 0;">' . $th( $k ) . esc_html( $nm( $k ) ) . ' <small>+TT$' . esc_html( number_format( (float) $plan['price_each'] ) ) . '</small></div>'; // phpcs:ignore ?>
                            <small>Total TT$<?php echo esc_html( number_format( (float) $plan['fee'], 2 ) ); ?>, included in the booking total</small></td></tr>
                    <?php endif; ?>
                    <?php if ( ! empty( $plan['free_eligible'] ) ) : ?>
                        <tr><td><strong>Free early-bird backdrop</strong></td><td><?php echo $plan['free'] !== '' ? $th( $plan['free'] ) . esc_html( $nm( $plan['free'] ) ) : '<em>Not redeemed yet (the client can choose it in the photo selection)</em>'; // phpcs:ignore ?></td></tr>
                    <?php endif; ?>
                    <?php if ( $plan['custom'] !== '' ) : ?>
                        <tr><td><strong>Custom backdrop idea</strong></td><td style="white-space:pre-wrap;"><?php echo esc_html( $plan['custom'] ); ?> <br><small>Send the client a quote.</small></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h4 style="margin:18px 0 8px;">Which backdrop for which photo</h4>
            <?php if ( ! $rows ) : ?>
                <p class="tf2-description" style="margin:0;">Shows up here once the client submits their photo selection.</p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="tweller_flow_2_backdrop_assign">
                    <input type="hidden" name="session_id" value="<?php echo (int) $session->id; ?>">
                    <?php wp_nonce_field( 'tweller_flow_2_backdrop_assign_' . (int) $session->id ); ?>
                    <table class="widefat striped">
                        <thead><tr><th>#</th><th>Photo (original file)</th><th>Backdrop</th></tr></thead>
                        <tbody>
                        <?php foreach ( $rows as $i => $r ) : ?>
                            <tr><td><?php echo (int) $i + 1; ?></td><td><code><?php echo esc_html( $r['file'] ); ?></code></td>
                                <td><?php if ( $r['thumb'] ) echo '<img src="' . esc_url( $r['thumb'] ) . '" alt="" style="width:46px;height:31px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:8px;">'; ?>
                                    <select name="bd[<?php echo (int) $r['pid']; ?>]">
                                        <?php foreach ( $mine as $k ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $r['key'], $k, false ) . '>' . esc_html( $nm( $k ) ) . '</option>'; ?>
                                        <option value="<?php echo esc_attr( self::KEEP ); ?>"<?php selected( $r['key'], self::KEEP ); ?>>Keep as shot</option>
                                    </select></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin:10px 0 0;"><button type="submit" class="button">Save changes</button></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

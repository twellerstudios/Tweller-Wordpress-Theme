<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var string $email  set when one client is open */
/** @var array  $p      TwellerFlow2_Clients::profile() when one client is open */
/** @var array  $clients list rows otherwise */
/** @var string $q */
$C     = 'TwellerFlow2_Clients';
$IC    = class_exists( 'TwellerFlow2_Image_Consent' ) ? 'TwellerFlow2_Image_Consent' : '';
$pkgs  = get_option( 'tweller_flow_2_packages', array() );
$types = get_option( 'tweller_flow_2_session_types', array() );
$pay_label = array( 'pending' => 'Awaiting payment', 'verifying' => 'Verifying payment', 'deposit' => 'Part paid', 'paid' => 'Paid', 'confirmed' => 'Paid' );
$pay_class = array( 'pending' => 'due', 'verifying' => 'wait', 'deposit' => 'wait', 'paid' => 'ok', 'confirmed' => 'ok' );
$consent_label = array( 'unlimited' => 'Share freely', 'limited' => '1–5 images', 'declined' => 'Private' );
$session_url = function ( $id ) { return admin_url( 'admin.php?page=tweller-flow-2-session&id=' . (int) $id ); };
$fmt_date = function ( $d ) { $t = strtotime( (string) $d ); return $t ? date_i18n( 'M j, Y', $t ) : '—'; };
$copy = function ( $text, $label = 'Copy' ) {
    return '<button type="button" class="button button-small tf2-copy" data-copy="' . esc_attr( $text ) . '">' . esc_html( $label ) . '</button>';
};
$icons = array(
    'thanks'   => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
    'coupon'   => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    'gift'     => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8M7.5 8a2.5 2.5 0 1 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 1 1 0 5"/>',
    'print'    => '<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
    'backdrop' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
    'snap'     => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
);
$icon = function ( $k ) use ( $icons ) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ( $icons[ $k ] ?? $icons['coupon'] ) . '</svg>'; };
?>
<style>
.tf2-cl { max-width: 1180px; }
.tf2-cl h1 { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.tf2-cl__sub { margin: 4px 0 20px; color: #6B7280; font-size: 14px; }
.tf2-cl__sub a { color: inherit; }
.tf2-cl__stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 0 0 24px; }
.tf2-cl__stat { padding: 16px 18px; background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; }
.tf2-cl__stat span { display: block; font-size: 12px; color: #6B7280; }
.tf2-cl__stat b { display: block; margin-top: 4px; font-size: 22px; font-weight: 700; color: #111827; font-variant-numeric: tabular-nums; }
.tf2-cl__h { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 28px 0 10px; }
.tf2-cl__h h2 { margin: 0; font-size: 16px; }
.tf2-cl__h p { margin: 0; color: #6B7280; font-size: 13px; }
.tf2-cl__perks { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.tf2-cl__perk { display: grid; grid-template-columns: 40px minmax(0, 1fr); gap: 12px; align-items: start; padding: 16px; background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; }
.tf2-cl__ic { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 10px; background: #F3F4F6; color: #374151; }
.tf2-cl__ic svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.tf2-cl__perk--thanks .tf2-cl__ic { background: #FDF2F8; color: #BE185D; }
.tf2-cl__perk--coupon .tf2-cl__ic { background: #EEF2FF; color: #4338CA; }
.tf2-cl__perk--gift .tf2-cl__ic { background: #ECFDF3; color: #067647; }
.tf2-cl__perk--backdrop .tf2-cl__ic, .tf2-cl__perk--snap .tf2-cl__ic { background: #FFF7ED; color: #C2410C; }
.tf2-cl__pt { margin: 0; font-size: 13px; color: #6B7280; }
.tf2-cl__pv { margin: 2px 0 4px; font-size: 18px; font-weight: 700; color: #111827; overflow-wrap: anywhere; }
.tf2-cl__pd { margin: 0; font-size: 12.5px; line-height: 1.5; color: #6B7280; }
.tf2-cl__pa { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.tf2-cl__empty { padding: 18px; background: #fff; border: 1px dashed #D1D5DB; border-radius: 12px; color: #6B7280; }
.tf2-cl table.widefat { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #E5E7EB; border-radius: 0; }
.tf2-cl table.widefat td, .tf2-cl table.widefat th { padding: 10px 12px; text-align: left; vertical-align: middle; border-bottom: 1px solid #F3F4F6; font-size: 13px; }
.tf2-cl table.widefat thead th { font-size: 12px; font-weight: 600; color: #6B7280; background: #F9FAFB; border-bottom: 1px solid #E5E7EB; }
.tf2-cl table.widefat td.num, .tf2-cl table.widefat th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.tf2-cl__tags { display: flex; flex-wrap: wrap; gap: 4px; }
.tf2-cl__tag { display: inline-block; padding: 2px 8px; border-radius: 99px; background: #F3F4F6; color: #374151; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.tf2-cl__tag--thanks { background: #FDF2F8; color: #BE185D; }
.tf2-cl__pay { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.tf2-cl__pay--due { background: #FFF4E5; color: #93370D; } .tf2-cl__pay--wait { background: #EEF4FF; color: #3538CD; } .tf2-cl__pay--ok { background: #ECFDF3; color: #067647; }
.tf2-cl__disc { margin: 0; padding: 0; list-style: none; font-size: 12.5px; }
.tf2-cl__disc li { display: flex; justify-content: space-between; gap: 12px; }
.tf2-cl__disc li b { color: #067647; font-weight: 600; white-space: nowrap; }
.tf2-cl__muted { color: #9CA3AF; }
.tf2-cl__search { display: flex; gap: 8px; margin: 0 0 14px; max-width: 520px; }
.tf2-cl__search input[type=search] { flex: 1; min-width: 0; }
.tf2-cl__set { margin-top: 28px; padding: 18px 20px; background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; max-width: 760px; }
.tf2-cl__set h2 { margin: 0 0 4px; font-size: 15px; }
.tf2-cl__set .row { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; margin-top: 12px; }
.tf2-cl__set label { display: grid; gap: 4px; align-content: start; font-size: 12px; font-weight: 600; color: #374151; }
.tf2-cl__set label small { font-weight: 400; color: #6B7280; }
.tf2-cl__set .row { align-items: flex-start; }
.tf2-cl__set .row .button { margin-top: 20px; }
.tf2-cl__note { margin: 0; color: #4B5563; font-size: 13px; line-height: 1.5; }
.tf2-cl__snap { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 14px 16px; background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; margin-bottom: 10px; }
.tf2-cl__snap code { font-size: 13px; }
</style>

<div class="wrap tf2-wrap tf2-cl">
<?php if ( ! empty( $_GET['done'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php echo $_GET['done'] === 'rewards' ? 'Thank-you discount settings saved.' : 'Saved.'; ?></p></div>
<?php endif; ?>

<?php if ( $email === '' ) : /* ─────────────── List ─────────────── */ ?>
    <h1>Clients</h1>
    <p class="tf2-cl__sub">Everyone who has booked, by email. Open a client to see the discounts, coupons, gift cards and credits waiting for their next session, and everything they've used so far.</p>

    <form class="tf2-cl__search" method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr( $C::PAGE ); ?>">
        <input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search name, email or phone" aria-label="Search clients">
        <button class="button">Search</button>
        <?php if ( $q !== '' ) : ?><a class="button button-link" href="<?php echo esc_url( $C::url() ); ?>">Clear</a><?php endif; ?>
    </form>

    <table class="widefat striped">
        <thead><tr><th>Client</th><th class="num">Bookings</th><th class="num">Booked</th><th>Last session</th><th>Waiting for their next session</th><th></th></tr></thead>
        <tbody>
        <?php if ( empty( $clients ) ) : ?>
            <tr><td colspan="6" class="tf2-cl__muted"><?php echo $q !== '' ? 'No clients match that search.' : 'No bookings yet.'; ?></td></tr>
        <?php endif; ?>
        <?php foreach ( (array) $clients as $c ) : $tags = $C::waiting_tags( $c->email ); ?>
            <tr>
                <td><a href="<?php echo esc_url( $C::url( $c->email ) ); ?>"><strong><?php echo esc_html( $c->name ?: $c->email ); ?></strong></a><br><span class="tf2-cl__muted"><?php echo esc_html( $c->email ); ?></span></td>
                <td class="num"><?php echo (int) $c->bookings; ?></td>
                <td class="num"><?php echo esc_html( $C::money( $c->booked ) ); ?></td>
                <td><?php echo esc_html( $fmt_date( $c->last_date ) ); ?></td>
                <td><div class="tf2-cl__tags"><?php
                    if ( ! $tags ) echo '<span class="tf2-cl__muted">—</span>';
                    foreach ( $tags as $t ) echo '<span class="tf2-cl__tag' . ( strpos( $t, '% off' ) !== false ? ' tf2-cl__tag--thanks' : '' ) . '">' . esc_html( $t ) . '</span>';
                ?></div></td>
                <td class="num"><a class="button button-small" href="<?php echo esc_url( $C::url( $c->email ) ); ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php else : /* ─────────────── One client ─────────────── */
    $last = $p['sessions'][0] ?? null;
    $last_price = $last && isset( $pkgs[ $last->package_type ] ) ? (float) $pkgs[ $last->package_type ]['price'] : 0;
?>
    <p style="margin:12px 0 0;"><a href="<?php echo esc_url( $C::url() ); ?>">&larr; All clients</a></p>
    <h1><?php echo esc_html( $p['name'] ?: $p['email'] ); ?></h1>
    <p class="tf2-cl__sub"><?php echo esc_html( $p['email'] ); ?><?php if ( $p['phone'] ) : ?> · <?php echo esc_html( $p['phone'] ); ?><?php endif; ?>
        <?php if ( $last ) : ?> · <a href="<?php echo esc_url( $session_url( $last->id ) ); ?>">Latest booking</a><?php endif; ?></p>

    <?php if ( ! $p['sessions'] ) : ?>
        <div class="tf2-cl__empty">No bookings found for this email.</div>
    <?php else : ?>

    <div class="tf2-cl__stats">
        <div class="tf2-cl__stat"><span>Bookings</span><b><?php echo count( $p['sessions'] ); ?></b></div>
        <div class="tf2-cl__stat"><span>Booked value</span><b><?php echo esc_html( $C::money( $p['booked'] ) ); ?></b></div>
        <div class="tf2-cl__stat"><span>Paid</span><b><?php echo esc_html( $C::money( $p['paid'] ) ); ?></b></div>
        <div class="tf2-cl__stat"><span>Saved with discounts</span><b><?php echo esc_html( $C::money( $p['saved'] ) ); ?></b></div>
    </div>

    <div class="tf2-cl__h"><h2>Waiting for their next session</h2><p>Applied when they book, or ready for them to use.</p></div>
    <?php
    $has_thanks = false;
    foreach ( $p['waiting'] as $w ) if ( $w['kind'] === 'thanks' ) $has_thanks = true;
    ?>
    <?php if ( ! $p['waiting'] ) : ?>
        <div class="tf2-cl__empty">Nothing waiting. Their next booking is at the normal price, unless they use a code.</div>
    <?php else : ?>
    <div class="tf2-cl__perks">
        <?php foreach ( $p['waiting'] as $w ) : ?>
            <div class="tf2-cl__perk tf2-cl__perk--<?php echo esc_attr( $w['kind'] ); ?>">
                <span class="tf2-cl__ic"><?php echo $icon( $w['kind'] ); // phpcs:ignore ?></span>
                <div>
                    <p class="tf2-cl__pt"><?php echo esc_html( $w['title'] ); ?></p>
                    <p class="tf2-cl__pv"><?php echo esc_html( $w['value'] ); ?></p>
                    <p class="tf2-cl__pd"><?php echo esc_html( $w['detail'] ); ?>
                        <?php if ( $w['kind'] === 'thanks' && $last_price > 0 && $IC ) : ?><br>On a <?php echo esc_html( $C::money( $last_price ) ); ?> session that's <strong><?php echo esc_html( $C::money( $IC::next_discount_value( $last_price ) ) ); ?> off</strong>.<?php endif; ?></p>
                    <div class="tf2-cl__pa">
                        <?php if ( $w['kind'] === 'thanks' ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remove this client\'s thank-you discount?');">
                                <input type="hidden" name="action" value="tweller_flow_2_client_thanks"><input type="hidden" name="do" value="remove"><input type="hidden" name="email" value="<?php echo esc_attr( $p['email'] ); ?>">
                                <?php wp_nonce_field( 'tweller_flow_2_client_thanks' ); ?>
                                <button class="button button-small">Remove</button>
                            </form>
                            <a class="button button-small button-link" href="#tf2-rewards">Change the % or cap</a>
                        <?php endif; ?>
                        <?php if ( ! empty( $w['code'] ) ) echo $copy( $w['code'], 'Copy code' ); // phpcs:ignore ?>
                        <?php if ( ! empty( $w['link'] ) ) : ?><a class="button button-small" href="<?php echo esc_url( $w['link'] ); ?>">Open</a><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ( ! $has_thanks && $IC ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
            <input type="hidden" name="action" value="tweller_flow_2_client_thanks"><input type="hidden" name="do" value="grant"><input type="hidden" name="email" value="<?php echo esc_attr( $p['email'] ); ?>">
            <?php wp_nonce_field( 'tweller_flow_2_client_thanks' ); ?>
            <button class="button button-small">Give them the thank-you discount (<?php echo esc_html( $IC::next_discount_label() ); ?> their next session)</button>
        </form>
    <?php endif; ?>

    <div class="tf2-cl__h"><h2>Bookings</h2><p>Discounts shown are the ones taken off each booking.</p></div>
    <table class="widefat striped">
        <thead><tr><th>Session date</th><th>Session</th><th>Payment</th><th class="num">Total</th><th>Discounts on this booking</th><th>Photo sharing</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $p['sessions'] as $s ) :
            $bs = $p['by_session'][ (int) $s->id ];
            $pk = $pkgs[ $s->package_type ]['name'] ?? $s->package_type;
            $ty = $types[ $s->session_type ]['name'] ?? $s->session_type; ?>
            <tr>
                <td><?php echo esc_html( $fmt_date( $s->session_date ) ); ?></td>
                <td><strong><?php echo esc_html( $pk ); ?></strong><br><span class="tf2-cl__muted"><?php echo esc_html( $ty ); ?></span></td>
                <td><span class="tf2-cl__pay tf2-cl__pay--<?php echo esc_attr( $pay_class[ $s->payment_status ] ?? 'due' ); ?>"><?php echo esc_html( $pay_label[ $s->payment_status ] ?? ucfirst( $s->payment_status ) ); ?></span></td>
                <td class="num"><?php echo esc_html( $C::money( $s->total_amount ) ); ?></td>
                <td><?php if ( $bs['discounts'] ) : ?><ul class="tf2-cl__disc"><?php foreach ( $bs['discounts'] as $d ) : ?><li><span><?php echo esc_html( $d['label'] ); ?></span><b>−<?php echo esc_html( $C::money( $d['amount'] ) ); ?></b></li><?php endforeach; ?></ul><?php else : ?><span class="tf2-cl__muted">None</span><?php endif; ?></td>
                <td><?php echo esc_html( $consent_label[ $bs['consent'] ] ?? '—' ); ?></td>
                <td class="num"><a class="button button-small" href="<?php echo esc_url( $session_url( $s->id ) ); ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="tf2-cl__h"><h2>Snap It Forward</h2><p>Their own link, and the friends who booked with it.</p></div>
    <?php if ( $p['snap'] ) : $link = TwellerFlow2_Referrals::link( $p['snap'] ); ?>
        <div class="tf2-cl__snap"><strong>Their link</strong> <code><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', $link ) ); ?></code> <?php echo $copy( $link, 'Copy link' ); // phpcs:ignore ?> <span class="tf2-cl__muted">Code <?php echo esc_html( $p['snap']['code'] ); ?></span></div>
        <?php if ( $p['snap_friends'] ) : ?>
            <table class="widefat striped">
                <thead><tr><th>Friend</th><th>Booked</th><th class="num">Friend's discount</th><th>Their reward</th><th></th></tr></thead>
                <tbody><?php foreach ( array_reverse( $p['snap_friends'] ) as $r ) : ?>
                    <tr><td><?php echo esc_html( $r['friend_name'] ); ?><br><span class="tf2-cl__muted"><?php echo esc_html( $r['friend_email'] ); ?></span></td>
                        <td><?php echo esc_html( wp_date( 'M j, Y', (int) $r['time'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $C::money( $r['discount'] ) ); ?></td>
                        <td><?php if ( $r['status'] === 'rewarded' ) : $rc = TwellerFlow2_Coupons::get( $r['reward_code'] ); ?>
                            <code><?php echo esc_html( $r['reward_code'] ); ?></code> <?php echo $rc && (int) $rc['uses'] > 0 ? '<span class="tf2-cl__pay tf2-cl__pay--ok">Used</span>' : '<span class="tf2-cl__pay tf2-cl__pay--wait">Not used yet</span>'; ?>
                        <?php else : ?><span class="tf2-cl__pay tf2-cl__pay--due">Sent once paid</span><?php endif; ?></td>
                        <td class="num"><a class="button button-small" href="<?php echo esc_url( $session_url( $r['session_id'] ) ); ?>">Booking</a></td></tr>
                <?php endforeach; ?></tbody>
            </table>
        <?php else : ?><div class="tf2-cl__empty">No friends have booked with their link yet.</div><?php endif; ?>
    <?php else : ?>
        <div class="tf2-cl__empty">They don't have a Snap Link yet.</div>
    <?php endif; ?>
    <?php foreach ( $p['snap_from'] as $r ) : ?>
        <p style="margin:10px 0 0;">They came through <strong><?php echo esc_html( $r['from_name'] ); ?></strong>'s Snap Link (<?php echo esc_html( $r['code'] ); ?>) and got <?php echo esc_html( $C::money( $r['discount'] ) ); ?> off.
            <?php if ( $r['from_email'] ) : ?><a href="<?php echo esc_url( $C::url( $r['from_email'] ) ); ?>">Open <?php echo esc_html( $r['from_name'] ); ?></a><?php endif; ?></p>
    <?php endforeach; ?>

    <div class="tf2-cl__h"><h2>Coupons used</h2></div>
    <?php if ( $p['coupons_used'] ) : ?>
        <table class="widefat striped">
            <thead><tr><th>Code</th><th>Used</th><th class="num">Discount</th><th class="num">Booking total</th><th></th></tr></thead>
            <tbody><?php foreach ( array_reverse( $p['coupons_used'] ) as $e ) : ?>
                <tr><td><code><?php echo esc_html( $e['code'] ); ?></code></td><td><?php echo esc_html( wp_date( 'M j, Y', (int) $e['time'] ) ); ?></td>
                    <td class="num">−<?php echo esc_html( $C::money( $e['discount'] ) ); ?></td><td class="num"><?php echo esc_html( $C::money( $e['total'] ) ); ?></td>
                    <td class="num"><a class="button button-small" href="<?php echo esc_url( $session_url( $e['session_id'] ) ); ?>">Booking</a></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php else : ?><div class="tf2-cl__empty">No coupons used.</div><?php endif; ?>

    <div class="tf2-cl__h"><h2>Gift cards</h2><p>Bought by them or given to them.</p></div>
    <?php if ( $p['gift_cards'] || $p['gift_used'] ) : ?>
        <?php if ( $p['gift_cards'] ) : ?>
        <table class="widefat striped">
            <thead><tr><th>Card</th><th>From → to</th><th>Status</th><th class="num">Value</th><th class="num">Left</th><th></th></tr></thead>
            <tbody><?php foreach ( $p['gift_cards'] as $g ) : ?>
                <tr><td><code><?php echo esc_html( $g->code ); ?></code></td>
                    <td><?php echo esc_html( ( $g->purchaser_name ?: $g->purchaser_email ) . ' → ' . ( $g->recipient_name ?: ( $g->recipient_email ?: 'themselves' ) ) ); ?></td>
                    <td><?php echo esc_html( class_exists( 'TwellerFlow2_Gift_Cards' ) ? TwellerFlow2_Gift_Cards::status_label( $g->status ) : $g->status ); ?></td>
                    <td class="num"><?php echo esc_html( $C::money( $g->amount ) ); ?></td><td class="num"><?php echo esc_html( $C::money( $g->balance ) ); ?></td>
                    <td class="num"><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-giftcards&card=' . (int) $g->id ) ); ?>">Open</a></td></tr>
            <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>
        <?php if ( $p['gift_used'] ) : ?>
        <p style="margin:12px 0 6px;font-weight:600;">Used on their bookings</p>
        <table class="widefat striped">
            <thead><tr><th>Card</th><th>Date</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody><?php foreach ( $p['gift_used'] as $l ) : ?>
                <tr><td><code><?php echo esc_html( $l->code ); ?></code></td><td><?php echo esc_html( $fmt_date( $l->created_at ) ); ?></td>
                    <td class="num"><?php echo esc_html( $C::money( abs( (float) $l->amount ) ) ); ?></td>
                    <td class="num"><a class="button button-small" href="<?php echo esc_url( $session_url( $l->session_id ) ); ?>">Booking</a></td></tr>
            <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>
    <?php else : ?><div class="tf2-cl__empty">No gift cards.</div><?php endif; ?>

    <?php if ( $p['giveaway'] ) : ?>
        <div class="tf2-cl__h"><h2>Giveaway entries</h2></div>
        <table class="widefat striped">
            <thead><tr><th>Entered</th><th>Platform</th><th>Their memory</th></tr></thead>
            <tbody><?php foreach ( array_reverse( $p['giveaway'] ) as $w ) : ?>
                <tr><td><?php echo esc_html( wp_date( 'M j, Y', (int) $w['time'] ) ); ?></td><td><?php echo esc_html( ucfirst( (string) ( $w['platform'] ?? '' ) ) ?: 'Website' ); ?></td>
                    <td><?php echo esc_html( wp_trim_words( (string) $w['memory'], 28 ) ); ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    <?php endif; ?>

    <?php endif; /* has sessions */ ?>
<?php endif; /* list / one */ ?>

<?php if ( $IC ) : ?>
    <form class="tf2-cl__set" id="tf2-rewards" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <h2>Thank-you discount for sharing photos</h2>
        <p class="tf2-cl__note">Clients who choose "Share as many as you like" at booking get this off their next session. It's shown on the booking form, so a change applies to new promises and to clients who already have one waiting.</p>
        <input type="hidden" name="action" value="tweller_flow_2_rewards_save">
        <input type="hidden" name="back" value="<?php echo esc_attr( $email !== '' ? $C::url( $email ) : $C::url() ); ?>">
        <?php wp_nonce_field( 'tweller_flow_2_rewards_save' ); ?>
        <div class="row">
            <label>Percent off<input type="number" name="pct" min="0" max="100" step="1" value="<?php echo esc_attr( (float) $IC::next_discount_pct() ); ?>" style="width:150px;"><small>Of the next session's price</small></label>
            <label>Most it can take off (TT$)<input type="number" name="cap" min="0" step="1" value="<?php echo esc_attr( (float) $IC::next_discount_cap() ); ?>" style="width:150px;"><small>0 = no limit</small></label>
            <button class="button button-primary">Save</button>
        </div>
        <p class="tf2-cl__note" style="margin-top:12px;">Right now: <?php echo esc_html( $IC::next_discount_label() ); ?> the next session<?php foreach ( array_slice( $pkgs, 0, 3 ) as $pk ) : if ( empty( $pk['price'] ) ) continue; ?> · <?php echo esc_html( $pk['name'] . ' ' . $C::money( $pk['price'] ) . ' → ' . $C::money( $IC::next_discount_value( $pk['price'] ) ) . ' off' ); ?><?php endforeach; ?></p>
    </form>
<?php endif; ?>
</div>
<?php include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/partials/copy-js.php'; ?>

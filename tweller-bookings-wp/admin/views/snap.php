<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var array $set      TwellerFlow2_Referrals::settings() */
/** @var array $members  code => member */
/** @var array $log      friend bookings, newest first */
$by_code = array();
foreach ( $log as $r ) {
    $k = $r['code'];
    if ( ! isset( $by_code[ $k ] ) ) $by_code[ $k ] = array( 'booked' => 0, 'rewarded' => 0 );
    $by_code[ $k ]['booked']++;
    if ( $r['status'] === 'rewarded' ) $by_code[ $k ]['rewarded']++;
}
$total_booked   = count( $log );
$total_rewarded = count( array_filter( $log, function ( $r ) { return $r['status'] === 'rewarded'; } ) );
$total_discount = array_sum( wp_list_pluck( $log, 'discount' ) );
$sessions       = array();
if ( $log ) {
    global $wpdb;
    $ids = array_map( 'intval', wp_list_pluck( $log, 'session_id' ) );
    foreach ( (array) $wpdb->get_results( "SELECT id, total_amount FROM {$wpdb->prefix}tweller_sessions WHERE id IN (" . implode( ',', $ids ?: array( 0 ) ) . ')' ) as $row ) $sessions[ (int) $row->id ] = (float) $row->total_amount;
}
$copy = function ( $text, $label = 'Copy' ) {
    return '<button type="button" class="button button-small tf2-copy" data-copy="' . esc_attr( $text ) . '">' . esc_html( $label ) . '</button>';
};
// Most active first.
uasort( $members, function ( $a, $b ) use ( $by_code ) {
    return ( $by_code[ $b['code'] ]['booked'] ?? 0 ) <=> ( $by_code[ $a['code'] ]['booked'] ?? 0 ) ?: strcmp( $b['created'], $a['created'] );
} );
?>
<div class="wrap tf2-wrap">
    <h1>Snap It Forward</h1>
    <p class="tf2-description" style="max-width:780px;">
        Every client gets their own <strong>Snap Link</strong> (for example <code><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', home_url( '/snap/sarah-j' ) ) ); ?></code>).
        It's shown in their client portal and in their booking, confirmation and gallery emails.
        A friend who books through it gets TT$<?php echo esc_html( number_format( (float) $set['friend_amount'] ) ); ?> off their first session,
        and the client who shared it gets a one-time TT$<?php echo esc_html( number_format( (float) $set['reward_amount'] ) ); ?> reward code by email
        <?php echo $set['reward_when'] === 'paid' ? 'once the friend\'s deposit is in' : 'as soon as the friend books'; ?>.
    </p>

    <?php if ( ! empty( $_GET['saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Saved.</div>
    <?php elseif ( isset( $_GET['made'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success"><?php echo (int) $_GET['made']; ?> past client<?php echo (int) $_GET['made'] === 1 ? '' : 's'; ?> got a Snap Link.</div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:0 0 18px;max-width:900px;">
        <div class="tf2-card" style="margin:0;"><div class="tf2-description">Clients with a Snap Link</div><div style="font-size:24px;font-weight:700;"><?php echo count( $members ); ?></div></div>
        <div class="tf2-card" style="margin:0;"><div class="tf2-description">Friends who booked</div><div style="font-size:24px;font-weight:700;"><?php echo (int) $total_booked; ?></div></div>
        <div class="tf2-card" style="margin:0;"><div class="tf2-description">Rewards sent</div><div style="font-size:24px;font-weight:700;"><?php echo (int) $total_rewarded; ?></div></div>
        <div class="tf2-card" style="margin:0;"><div class="tf2-description">Friend discounts given</div><div style="font-size:24px;font-weight:700;">TT$<?php echo esc_html( number_format( $total_discount ) ); ?></div></div>
    </div>

    <div class="tf2-card tf2-mb-6">
        <h2 style="margin-top:0;">Settings</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tweller_flow_2_snap_save">
            <?php wp_nonce_field( 'tweller_flow_2_snap_save' ); ?>
            <table class="form-table" role="presentation">
                <tr><th>On</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $set['enabled'] ) ); ?>> Snap It Forward is on</label></td></tr>
                <tr><th><label for="sf-friend">Friend gets (TT$ off)</label></th><td><input type="number" id="sf-friend" name="friend_amount" min="0" step="1" value="<?php echo esc_attr( $set['friend_amount'] ); ?>" style="width:100px;"> <span class="tf2-description">Off the friend's session price.</span></td></tr>
                <tr><th><label for="sf-reward">Client who shared gets (TT$ off)</label></th><td><input type="number" id="sf-reward" name="reward_amount" min="0" step="1" value="<?php echo esc_attr( $set['reward_amount'] ); ?>" style="width:100px;"> <span class="tf2-description">A one-time code for their next session, only usable with their email.</span></td></tr>
                <tr><th>Send the reward</th><td>
                    <label style="display:block;"><input type="radio" name="reward_when" value="paid" <?php checked( $set['reward_when'], 'paid' ); ?>> When the friend's deposit is in (recommended)</label>
                    <label style="display:block;"><input type="radio" name="reward_when" value="booked" <?php checked( $set['reward_when'], 'booked' ); ?>> As soon as the friend books</label>
                </td></tr>
                <tr><th><label for="sf-days">Reward code lasts</label></th><td><input type="number" id="sf-days" name="reward_days" min="0" value="<?php echo esc_attr( $set['reward_days'] ); ?>" style="width:90px;"> days <span class="tf2-description">0 = no end date.</span></td></tr>
                <tr><th>First-time clients only</th><td><label><input type="checkbox" name="new_clients_only" value="1" <?php checked( ! empty( $set['new_clients_only'] ) ); ?>> The friend's discount is only for people who haven't booked before</label></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Save</button></p>
        </form>
    </div>

    <div class="tf2-card tf2-mb-6">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <h2 style="margin:0;">Clients and their Snap Links</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                <input type="hidden" name="action" value="tweller_flow_2_snap_backfill">
                <?php wp_nonce_field( 'tweller_flow_2_snap_backfill' ); ?>
                <button type="submit" class="button">Give all past clients a Snap Link</button>
            </form>
        </div>
        <p class="tf2-description">New clients get one automatically when they book. Copy a link to send it yourself (WhatsApp, text, Instagram).</p>
        <?php if ( ! $members ) : ?>
            <p class="tf2-description">None yet.</p>
        <?php else : ?>
        <table class="widefat striped">
            <thead><tr><th>Client</th><th>Snap Link</th><th>Code</th><th>Link visits</th><th>Friends booked</th><th>Rewards sent</th></tr></thead>
            <tbody>
            <?php foreach ( $members as $code => $m ) :
                $link  = TwellerFlow2_Referrals::link( $m );
                $short = TwellerFlow2_Referrals::short_link( $m );
                $st    = $by_code[ $code ] ?? array( 'booked' => 0, 'rewarded' => 0 );
            ?>
                <tr>
                    <td><strong><?php echo esc_html( $m['name'] ); ?></strong><br><small><?php echo esc_html( $m['email'] ); ?></small></td>
                    <td><a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $short ); ?></a> <?php echo $copy( $link, 'Copy link' ); // phpcs:ignore ?></td>
                    <td><code><?php echo esc_html( $code ); ?></code> <?php echo $copy( $code ); // phpcs:ignore ?></td>
                    <td><?php echo (int) $m['clicks']; ?></td>
                    <td><?php echo (int) $st['booked']; ?></td>
                    <td><?php echo (int) $st['rewarded']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="tf2-card">
        <h2 style="margin-top:0;">Friends who booked</h2>
        <?php if ( ! $log ) : ?>
            <p class="tf2-description">No bookings through a Snap Link yet.</p>
        <?php else : ?>
        <table class="widefat striped">
            <thead><tr><th>Date</th><th>Friend</th><th>Shared by</th><th>Friend's discount</th><th>Booking total</th><th>Reward</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $log, 0, 100 ) as $r ) :
                $m = $members[ $r['code'] ] ?? null;
            ?>
                <tr>
                    <td><?php echo esc_html( wp_date( 'M j, Y g:i a', $r['time'] ) ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . (int) $r['session_id'] ) ); ?>"><?php echo esc_html( $r['friend_name'] ); ?></a><br><small><?php echo esc_html( $r['friend_email'] ); ?></small></td>
                    <td><?php echo esc_html( $m ? $m['name'] : $r['code'] ); ?><br><small><?php echo esc_html( $r['code'] ); ?></small></td>
                    <td>TT$<?php echo esc_html( number_format( (float) $r['discount'], 2 ) ); ?></td>
                    <td><?php echo isset( $sessions[ (int) $r['session_id'] ] ) ? 'TT$' . esc_html( number_format( $sessions[ (int) $r['session_id'] ], 2 ) ) : '—'; ?></td>
                    <td>
                        <?php if ( $r['status'] === 'rewarded' ) : ?>
                            Sent: <code><?php echo esc_html( $r['reward_code'] ); ?></code> <?php echo $copy( $r['reward_code'] ); // phpcs:ignore ?>
                        <?php else : ?>
                            <small><?php echo $set['reward_when'] === 'paid' ? 'Waiting for the deposit' : 'Pending'; ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/partials/copy-js.php'; ?>

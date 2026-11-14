<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var array $coupons  code => coupon, newest first */
/** @var array $packages */
/** @var array $types */
$errors = array(
    'amount' => 'Enter an amount above 0 (a percentage can\'t be over 100).',
    'code'   => 'The code must be 3 to 30 letters or numbers.',
    'exists' => 'That code already exists. Pick another or leave it blank for an automatic one.',
);
$act = function ( $c, $do ) {
    return wp_nonce_url( admin_url( 'admin-post.php?action=tweller_flow_2_coupon_action&do=' . $do . '&code=' . rawurlencode( $c ) ), 'tweller_flow_2_coupon_' . $c );
};
?>
<div class="wrap tf2-wrap">
    <h1>Coupons</h1>
    <p class="tf2-description" style="max-width:720px;">
        Give a client a code. They type it in the booking form ("Have a coupon code?") and the discount comes off the session price.
        The transport fee is never discounted. Codes are checked again when the booking is made.
    </p>

    <?php if ( ! empty( $_GET['created'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Coupon created: <strong style="font-size:16px;letter-spacing:.06em;"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['created'] ) ) ); ?></strong>. Send it to the client.</div>
    <?php elseif ( ! empty( $_GET['done'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Done.</div>
    <?php elseif ( ! empty( $_GET['error'] ) && isset( $errors[ $_GET['error'] ] ) ) : ?>
        <div class="tf2-alert tf2-alert--error"><?php echo esc_html( $errors[ $_GET['error'] ] ); ?></div>
    <?php endif; ?>

    <div class="tf2-card tf2-mb-6">
        <h2 style="margin-top:0;">New coupon</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tweller_flow_2_coupon_save">
            <?php wp_nonce_field( 'tweller_flow_2_coupon_save' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="cp-code">Code</label></th>
                    <td><input type="text" id="cp-code" name="code" class="regular-text" placeholder="Leave blank for an automatic code, e.g. TW-7K2QM" style="text-transform:uppercase;" autocomplete="off">
                        <p class="tf2-description">Letters, numbers and dashes. Easy ones work well: WELCOMEBACK10.</p></td>
                </tr>
                <tr>
                    <th>Discount</th>
                    <td>
                        <select name="type" id="cp-type">
                            <option value="percent">Percent off</option>
                            <option value="fixed">TT$ amount off</option>
                        </select>
                        <input type="number" name="amount" step="0.01" min="0.01" required style="width:110px;" aria-label="Amount">
                        <span class="tf2-description" id="cp-unit">% off the session price</span>
                    </td>
                </tr>
                <tr>
                    <th>Packages</th>
                    <td>
                        <?php foreach ( (array) $packages as $key => $pkg ) : ?>
                            <label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="packages[]" value="<?php echo esc_attr( $key ); ?>" checked> <?php echo esc_html( $pkg['name'] ?? $key ); ?></label>
                        <?php endforeach; ?>
                        <p class="tf2-description">Untick a package to leave it out. All ticked = any package.</p>
                    </td>
                </tr>
                <tr>
                    <th>Session types</th>
                    <td>
                        <?php foreach ( (array) $types as $key => $t ) : ?>
                            <label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="session_types[]" value="<?php echo esc_attr( $key ); ?>" checked> <?php echo esc_html( $t['name'] ?? $key ); ?></label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="cp-uses">Times it can be used</label></th>
                    <td><input type="number" id="cp-uses" name="max_uses" min="0" value="1" style="width:90px;"> <span class="tf2-description">1 = one booking only. 0 = unlimited.</span></td>
                </tr>
                <tr>
                    <th><label for="cp-min">Minimum spend (TT$)</label></th>
                    <td><input type="number" id="cp-min" name="min_spend" min="0" step="1" value="0" style="width:110px;"> <span class="tf2-description">Optional. The code only works when the session price is at least this much (before the discount). 0 = no minimum.</span></td>
                </tr>
                <tr>
                    <th><label for="cp-exp">Ends at (countdown)</label></th>
                    <td><input type="datetime-local" id="cp-exp" name="expires"> <span class="tf2-description">Optional. Pick a date and time (site time: <?php echo esc_html( wp_timezone_string() ); ?>). The client sees a live countdown once they enter the code, and it stops working at that moment.</span></td>
                </tr>
                <tr>
                    <th><label for="cp-email">Only for this email</label></th>
                    <td><input type="email" id="cp-email" name="email" class="regular-text" placeholder="client@example.com">
                        <p class="tf2-description">Optional. Only a booking made with this email can use the code.</p></td>
                </tr>
                <tr>
                    <th><label for="cp-note">Note (just for you)</label></th>
                    <td><input type="text" id="cp-note" name="note" class="regular-text" placeholder="e.g. Returning client, Nov sale discount"></td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary">Create coupon</button></p>
        </form>
    </div>

    <?php $sum = $report['sum']; ?>
    <div class="tf2-card tf2-mb-6">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <h2 style="margin:0;">Coupon report</h2>
            <?php if ( $sum['uses'] ) : ?>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tweller_flow_2_coupon_csv' ), 'tweller_flow_2_coupon_csv' ) ); ?>">Download CSV</a>
            <?php endif; ?>
        </div>
        <?php if ( ! $sum['uses'] ) : ?>
            <p class="tf2-description">No bookings with a coupon yet. They will show up here.</p>
        <?php else : ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:14px 0;">
                <div style="background:#f6f7f7;border-radius:8px;padding:12px;"><div class="tf2-description">Bookings with a coupon</div><div style="font-size:24px;font-weight:700;"><?php echo (int) $sum['uses']; ?></div></div>
                <div style="background:#f6f7f7;border-radius:8px;padding:12px;"><div class="tf2-description">Discount given</div><div style="font-size:24px;font-weight:700;">TT$<?php echo esc_html( number_format( $sum['discount'], 2 ) ); ?></div></div>
                <div style="background:#f6f7f7;border-radius:8px;padding:12px;"><div class="tf2-description">Booked value (after discount)</div><div style="font-size:24px;font-weight:700;">TT$<?php echo esc_html( number_format( $sum['revenue'], 2 ) ); ?></div></div>
            </div>
            <h3>By code</h3>
            <table class="widefat striped" style="margin-bottom:18px;">
                <thead><tr><th>Code</th><th>Bookings</th><th>Discount given</th><th>Booked value</th><th>Last used</th></tr></thead>
                <tbody>
                <?php foreach ( $report['by'] as $code => $r ) : ?>
                    <tr><td><strong><?php echo esc_html( $code ); ?></strong></td><td><?php echo (int) $r['uses']; ?></td><td>TT$<?php echo esc_html( number_format( $r['discount'], 2 ) ); ?></td><td>TT$<?php echo esc_html( number_format( $r['revenue'], 2 ) ); ?></td><td><?php echo esc_html( wp_date( 'M j, Y g:i a', $r['last'] ) ); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <h3>Latest bookings with a coupon</h3>
            <table class="widefat striped">
                <thead><tr><th>Date</th><th>Code</th><th>Client</th><th>Package</th><th>Discount</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ( array_slice( $report['log'], 0, 25 ) as $e ) : ?>
                    <tr>
                        <td><?php echo esc_html( wp_date( 'M j, g:i a', $e['time'] ) ); ?></td>
                        <td><?php echo esc_html( $e['code'] ); ?></td>
                        <td><?php echo esc_html( $e['client'] ); ?></td>
                        <td><?php echo esc_html( $packages[ $e['package'] ]['name'] ?? $e['package'] ); ?></td>
                        <td>TT$<?php echo esc_html( number_format( $e['discount'], 2 ) ); ?></td>
                        <td>TT$<?php echo esc_html( number_format( $e['total'], 2 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="tf2-card">
        <h2 style="margin-top:0;">Your coupons</h2>
        <?php if ( ! $coupons ) : ?>
            <p class="tf2-description">None yet.</p>
        <?php else : ?>
        <table class="widefat striped">
            <thead><tr><th>Code</th><th>Discount</th><th>Applies to</th><th>Used</th><th>Ends</th><th>For</th><th>Note</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $coupons as $code => $c ) :
                $pkg_names = array();
                foreach ( (array) $c['packages'] as $k ) $pkg_names[] = $packages[ $k ]['name'] ?? $k;
                $used_up = (int) $c['max_uses'] > 0 && (int) $c['uses'] >= (int) $c['max_uses'];
                $exp_ts  = TwellerFlow2_Coupons::expiry_ts( $c );
                $expired = $exp_ts && time() > $exp_ts;
                $status  = empty( $c['active'] ) ? 'Off' : ( $used_up ? 'Used up' : ( $expired ? 'Expired' : 'Active' ) );
            ?>
                <tr>
                    <td><strong style="letter-spacing:.05em;"><?php echo esc_html( $code ); ?></strong><br><small><?php echo esc_html( $status ); ?></small></td>
                    <td><?php echo esc_html( TwellerFlow2_Coupons::label( $c ) ); ?><?php if ( (float) ( $c['min_spend'] ?? 0 ) > 0 ) echo '<br><small>Min. TT$' . esc_html( number_format( (float) $c['min_spend'] ) ) . '</small>'; ?></td>
                    <td><?php echo esc_html( $pkg_names ? implode( ', ', $pkg_names ) : 'Any package' ); ?></td>
                    <td><?php echo (int) $c['uses']; ?> / <?php echo (int) $c['max_uses'] ? (int) $c['max_uses'] : '∞'; ?></td>
                    <td><?php echo $exp_ts ? esc_html( wp_date( 'M j, Y g:i a', $exp_ts ) ) : '—'; ?></td>
                    <td><?php echo esc_html( $c['email'] ?: 'Anyone' ); ?></td>
                    <td><?php echo esc_html( $c['note'] ); ?></td>
                    <td style="white-space:nowrap;">
                        <a href="<?php echo esc_url( $act( $code, 'toggle' ) ); ?>"><?php echo empty( $c['active'] ) ? 'Turn on' : 'Turn off'; ?></a> ·
                        <a href="<?php echo esc_url( $act( $code, 'delete' ) ); ?>" onclick="return confirm('Delete this coupon?');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var t = document.getElementById('cp-type'), u = document.getElementById('cp-unit');
    if (!t || !u) return;
    t.addEventListener('change', function () { u.textContent = t.value === 'fixed' ? 'TT$ off the session price' : '% off the session price'; });
})();
</script>

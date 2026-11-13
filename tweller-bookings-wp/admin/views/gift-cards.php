<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$GC       = 'TwellerFlow2_Gift_Cards';
$settings = $GC::settings();
$filter   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$open     = isset( $_GET['card'] ) ? $GC::get( (int) $_GET['card'] ) : null;
$cards    = $GC::all( $filter );
$msg      = isset( $_GET['tf2_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['tf2_msg'] ) ) : '';

$all      = $GC::all();
$to_check = count( array_filter( $all, function ( $c ) { return $c->status === 'verifying'; } ) );
$sold     = array_sum( array_map( function ( $c ) { return in_array( $c->status, array( 'active', 'used' ), true ) && $c->payment_method !== 'comp' ? (float) $c->amount : 0; }, $all ) );
$owed     = array_sum( array_map( function ( $c ) { return $c->status === 'active' ? (float) $c->balance : 0; }, $all ) );

$badge = array(
    'pending' => 'pending', 'verifying' => 'pending', 'active' => 'paid', 'used' => 'deposit', 'void' => 'cancelled', 'expired' => 'cancelled',
);
$clients  = $GC::client_emails();   // everyone who has booked, by email
$session_url = function ( $id ) { return admin_url( 'admin.php?page=tweller-flow-2-session&id=' . (int) $id ); };
// "Grads · Mar 3, 2026" links to a client's bookings, newest first.
$booking_links = function ( $sessions ) use ( $session_url ) {
    $out = array();
    foreach ( (array) $sessions as $s ) {
        $when = ! empty( $s->session_date ) ? date_i18n( 'M j, Y', strtotime( $s->session_date ) ) : 'no date';
        $out[] = '<a href="' . esc_url( $session_url( $s->id ) ) . '">' . esc_html( ( TwellerFlow2_Session::session_type_label( $s ) ?: ucfirst( (string) $s->package_type ) ) . ' · ' . $when ) . '</a>';
    }
    return implode( '<br>', $out );
};
$is_client = function ( $email ) use ( $clients ) { return $email && isset( $clients[ strtolower( trim( $email ) ) ] ); };
$form_open = function ( $action, $card = null ) {
    echo '<form method="post" style="display:inline;">';
    wp_nonce_field( 'tf2_gift_cards' );
    echo '<input type="hidden" name="tf2_gc_action" value="' . esc_attr( $action ) . '">';
    if ( $card ) echo '<input type="hidden" name="card_id" value="' . (int) $card->id . '">';
};
?>
<div class="wrap tf2-wrap">
    <div class="tf2-page-header">
        <h1>Gift Cards</h1>
        <a href="<?php echo esc_url( $GC::page_url() ); ?>" target="_blank" class="tf2-btn tf2-btn--secondary">Open the gift card page &#8599;</a>
    </div>

    <?php if ( $msg ) : ?><div class="tf2-alert tf2-alert--success"><?php echo esc_html( $msg ); ?></div><?php endif; ?>

    <div class="tf2-gc-stats">
        <div class="tf2-card"><div class="tf2-gc-stat"><?php echo esc_html( $GC::money( $sold ) ); ?></div><div class="tf2-muted">Sold (paid)</div></div>
        <div class="tf2-card"><div class="tf2-gc-stat"><?php echo esc_html( $GC::money( $owed ) ); ?></div><div class="tf2-muted">Outstanding balances</div></div>
        <a class="tf2-card" href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-giftcards&status=verifying' ) ); ?>" style="text-decoration:none;"><div class="tf2-gc-stat" style="<?php echo $to_check ? 'color:#B45309;' : ''; ?>"><?php echo (int) $to_check; ?></div><div class="tf2-muted">Receipts to verify</div></a>
    </div>

    <?php if ( $open ) : $ledger = $GC::ledger( $open->id ); ?>
    <!-- ══════════ ONE CARD ══════════ -->
    <div class="tf2-card tf2-mb-6">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
                <h2 style="margin:0 0 4px;"><?php echo esc_html( $GC::label( $open ) ); ?> <span class="tf2-badge tf2-badge--<?php echo esc_attr( $badge[ $open->status ] ?? 'pending' ); ?>"><?php echo esc_html( $GC::status_label( $open->status ) ); ?></span></h2>
                <div style="font-family:monospace; font-size:16px; letter-spacing:0.08em;"><?php echo esc_html( $open->code ); ?></div>
                <div class="tf2-muted tf2-text-sm">Order <?php echo esc_html( $open->order_ref ); ?> · created <?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $open->created_at ) ) ); ?></div>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-giftcards' ) ); ?>" class="tf2-btn tf2-btn--ghost tf2-btn--sm">&larr; All gift cards</a>
        </div>

        <div class="tf2-client-info" style="margin-top:16px;">
            <div class="tf2-client-info__item"><span class="tf2-client-info__label">Value</span><span class="tf2-client-info__value"><?php echo esc_html( $GC::money( $open->amount ) ); ?></span></div>
            <div class="tf2-client-info__item"><span class="tf2-client-info__label">Balance</span><span class="tf2-client-info__value"><strong><?php echo esc_html( $GC::money( $open->balance ) ); ?></strong></span></div>
            <div class="tf2-client-info__item"><span class="tf2-client-info__label">Bought by</span><span class="tf2-client-info__value"><?php echo esc_html( $open->purchaser_name ); ?><?php echo $is_client( $open->purchaser_email ) ? ' <span class="tf2-gc-tag">client</span>' : ''; ?><?php echo $open->purchaser_email ? '<br><span class="tf2-muted">' . esc_html( $open->purchaser_email ) . ( $open->purchaser_phone ? ' · ' . esc_html( $open->purchaser_phone ) : '' ) . '</span>' : ''; ?></span></div>
            <div class="tf2-client-info__item"><span class="tf2-client-info__label">For</span><span class="tf2-client-info__value"><?php echo esc_html( $open->recipient_name ?: '—' ); ?><?php echo $is_client( $open->recipient_email ) ? ' <span class="tf2-gc-tag">client</span>' : ''; ?><?php echo $open->recipient_email ? '<br><span class="tf2-muted">' . esc_html( $open->recipient_email ) . '</span>' : ''; ?></span></div>
            <div class="tf2-client-info__item"><span class="tf2-client-info__label">Delivery</span><span class="tf2-client-info__value"><?php
                if ( $open->send_to !== 'recipient' ) echo 'Sent to the buyer to hand over';
                elseif ( $open->delivered_at ) echo 'Emailed ' . esc_html( date_i18n( 'M j, Y', strtotime( $open->delivered_at ) ) );
                elseif ( $open->deliver_on ) echo 'Scheduled for ' . esc_html( date_i18n( 'M j, Y', strtotime( $open->deliver_on ) ) );
                else echo 'When paid';
            ?></span></div>
            <div class="tf2-client-info__item"><span class="tf2-client-info__label">Payment</span><span class="tf2-client-info__value"><?php
                $pm = array( 'card' => 'Card (WiPay)', 'bank' => 'Bank transfer', 'comp' => 'Issued free by the studio' );
                echo esc_html( $pm[ $open->payment_method ] ?? '—' );
                echo $open->paid_at ? ' · ' . esc_html( date_i18n( 'M j, Y', strtotime( $open->paid_at ) ) ) : '';
            ?></span></div>
            <?php if ( $open->expires_at ) : ?>
                <div class="tf2-client-info__item"><span class="tf2-client-info__label">Expires</span><span class="tf2-client-info__value"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $open->expires_at ) ) ); ?></span></div>
            <?php endif; ?>
            <?php if ( $open->message ) : ?>
                <div class="tf2-client-info__item"><span class="tf2-client-info__label">Message</span><span class="tf2-client-info__value"><em>“<?php echo esc_html( $open->message ); ?>”</em></span></div>
            <?php endif; ?>
            <?php if ( $open->notes ) : ?>
                <div class="tf2-client-info__item"><span class="tf2-client-info__label">Note</span><span class="tf2-client-info__value"><?php echo esc_html( $open->notes ); ?></span></div>
            <?php endif; ?>
        </div>

        <?php
        $buyer_sessions = $GC::sessions_for_email( $open->purchaser_email );
        $rec_sessions   = $GC::sessions_for_email( $open->recipient_email );
        $paid_bookings  = $GC::bookings_for_card( $open->id );
        if ( $buyer_sessions || $rec_sessions || $paid_bookings ) : ?>
        <div class="tf2-gc-people">
            <?php if ( $paid_bookings ) : ?>
            <div class="tf2-gc-people__col">
                <div class="tf2-gc-people__title">Paid for these bookings</div>
                <?php foreach ( $paid_bookings as $b ) : ?>
                    <div class="tf2-gc-people__row">
                        <a href="<?php echo esc_url( $session_url( $b->id ) ); ?>"><?php echo esc_html( $b->client_name ); ?></a>
                        <span class="tf2-muted"><?php echo esc_html( ( TwellerFlow2_Session::session_type_label( $b ) ?: ucfirst( (string) $b->package_type ) ) . ( $b->session_date ? ' · ' . date_i18n( 'M j, Y', strtotime( $b->session_date ) ) : '' ) ); ?></span>
                        <span class="tf2-gc-people__amt">&minus; <?php echo esc_html( $GC::money( abs( (float) $b->spent ) ) ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ( $buyer_sessions ) : ?>
            <div class="tf2-gc-people__col">
                <div class="tf2-gc-people__title">Buyer is a client &mdash; <?php echo esc_html( $open->purchaser_name ); ?></div>
                <div class="tf2-gc-people__list"><?php echo $booking_links( $buyer_sessions ); ?></div>
            </div>
            <?php endif; ?>
            <?php if ( $rec_sessions ) : ?>
            <div class="tf2-gc-people__col">
                <div class="tf2-gc-people__title">Recipient is a client &mdash; <?php echo esc_html( $open->recipient_name ); ?></div>
                <div class="tf2-gc-people__list"><?php echo $booking_links( $rec_sessions ); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ( $open->receipt_url ) : ?>
        <div class="tf2-gc-receipt">
            <div>
                <div class="tf2-section-title" style="margin-top:0;">Bank transfer receipt</div>
                <p style="margin:0 0 4px;"><strong>Bank:</strong> <?php echo esc_html( $open->receipt_bank ?: '—' ); ?></p>
                <p style="margin:0 0 4px;"><strong>Amount read from receipt:</strong> <?php echo esc_html( $GC::receipt_check( $open ) ); ?></p>
                <?php if ( $open->receipt_ref ) : ?><p style="margin:0 0 4px;"><strong>Reference on receipt:</strong> <?php echo esc_html( $open->receipt_ref ); ?></p><?php endif; ?>
                <p style="margin:8px 0 0;"><a href="<?php echo esc_url( $open->receipt_url ); ?>" target="_blank">Open full size &#8599;</a></p>
            </div>
            <a href="<?php echo esc_url( $open->receipt_url ); ?>" target="_blank"><img src="<?php echo esc_url( $open->receipt_url ); ?>" alt="Receipt"></a>
        </div>
        <?php endif; ?>

        <div class="tf2-gc-actions">
            <?php if ( in_array( $open->status, array( 'pending', 'verifying' ), true ) ) : ?>
                <?php $form_open( 'mark_paid', $open ); ?><button class="tf2-btn tf2-btn--primary" onclick="return confirm('Mark this gift card as paid and send it out?');">&#10003; Payment received — send the card</button></form>
                <?php if ( $open->status === 'verifying' ) : ?>
                    <?php $form_open( 'reject_receipt', $open ); ?><button class="tf2-btn tf2-btn--secondary" onclick="return confirm('Reject this receipt and ask the buyer to upload it again?');">Reject receipt</button></form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ( in_array( $open->status, array( 'active', 'used' ), true ) ) : ?>
                <?php if ( $open->purchaser_email ) : ?>
                    <?php $form_open( 'resend', $open ); ?><input type="hidden" name="to" value="buyer"><button class="tf2-btn tf2-btn--secondary">Resend to buyer</button></form>
                <?php endif; ?>
                <?php if ( $open->recipient_email ) : ?>
                    <?php $form_open( 'resend', $open ); ?><input type="hidden" name="to" value="recipient"><button class="tf2-btn tf2-btn--secondary"><?php echo $open->delivered_at ? 'Resend to recipient' : 'Send to recipient now'; ?></button></form>
                <?php endif; ?>
                <a class="tf2-btn tf2-btn--ghost" href="<?php echo esc_url( $GC::view_url( $open ) ); ?>" target="_blank">View card &#8599;</a>
            <?php endif; ?>
            <?php if ( ! in_array( $open->status, array( 'void' ), true ) ) : ?>
                <?php $form_open( 'void', $open ); ?><button class="tf2-btn tf2-btn--ghost" style="color:#B91C1C;" onclick="return confirm('Void this gift card? It can no longer be used.');">Void</button></form>
            <?php endif; ?>
        </div>

        <?php if ( in_array( $open->status, array( 'active', 'used' ), true ) ) : ?>
        <?php $form_open( 'adjust', $open ); ?>
            <div class="tf2-gc-adjust">
                <label>Adjust balance (TT$, use − to take off) <input type="number" step="0.01" name="delta" required></label>
                <label>Reason <input type="text" name="note" placeholder="e.g. used in studio"></label>
                <button class="tf2-btn tf2-btn--secondary tf2-btn--sm">Apply</button>
            </div>
        </form>
        <?php endif; ?>

        <?php if ( $ledger ) : ?>
        <div class="tf2-section-title">History</div>
        <table class="tf2-table">
            <thead><tr><th>When</th><th>What</th><th style="text-align:right;">Amount</th><th style="text-align:right;">Balance after</th></tr></thead>
            <tbody>
            <?php foreach ( $ledger as $l ) : ?>
                <tr>
                    <td><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $l->created_at ) ) ); ?></td>
                    <td><?php echo esc_html( $l->note ); ?>
                        <?php if ( $l->session_id ) : ?> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . (int) $l->session_id ) ); ?>">booking</a><?php endif; ?></td>
                    <td style="text-align:right;"><?php echo ( (float) $l->amount > 0 ? '+' : '' ) . esc_html( $GC::money( $l->amount ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $GC::money( $l->balance_after ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════ LIST ══════════ -->
    <div class="tf2-card tf2-mb-6">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
            <h2 style="margin:0;">All gift cards</h2>
            <div class="tf2-gc-filters">
                <?php foreach ( array( '' => 'All', 'verifying' => 'Receipts to verify', 'pending' => 'Awaiting payment', 'active' => 'Active', 'used' => 'Used', 'void' => 'Void' ) as $k => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-giftcards' . ( $k ? '&status=' . $k : '' ) ) ); ?>" class="<?php echo $filter === $k ? 'is-on' : ''; ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ( ! $cards ) : ?>
            <p class="tf2-muted">No gift cards yet. Share <a href="<?php echo esc_url( $GC::page_url() ); ?>" target="_blank"><?php echo esc_html( $GC::page_url() ); ?></a> — or issue one below.</p>
        <?php else : ?>
        <table class="tf2-table">
            <thead><tr><th>Code</th><th>Gift</th><th>From → For</th><th>Status</th><th style="text-align:right;">Balance</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ( $cards as $c ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-giftcards&card=' . $c->id ) ); ?>" style="font-family:monospace;"><?php echo esc_html( in_array( $c->status, array( 'pending', 'verifying' ), true ) ? $c->order_ref : $c->code ); ?></a></td>
                    <td><?php echo esc_html( $GC::label( $c ) ); ?></td>
                    <td><?php echo esc_html( $c->purchaser_name ); ?><?php echo $is_client( $c->purchaser_email ) ? ' <span class="tf2-gc-tag" title="Has booked before">client</span>' : ''; ?>
                        &rarr; <?php echo esc_html( $c->recipient_name ?: '—' ); ?><?php echo $is_client( $c->recipient_email ) ? ' <span class="tf2-gc-tag" title="Has booked before">client</span>' : ''; ?></td>
                    <td><span class="tf2-badge tf2-badge--<?php echo esc_attr( $badge[ $c->status ] ?? 'pending' ); ?>"><?php echo esc_html( $GC::status_label( $c->status ) ); ?></span></td>
                    <td style="text-align:right;"><?php echo esc_html( $GC::money( $c->balance ) ); ?> <span class="tf2-muted">/ <?php echo esc_html( $GC::money( $c->amount ) ); ?></span></td>
                    <td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $c->created_at ) ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- ══════════ ISSUE ══════════ -->
    <div class="tf2-card tf2-mb-6">
        <h2 style="margin:0 0 6px;">Issue a gift card</h2>
        <p class="tf2-description">For giveaways, promos, competitions or a thank-you — active immediately, no payment.</p>
        <?php $form_open( 'issue' ); ?>
            <div class="tf2-row--3">
                <div class="tf2-field"><label class="tf2-field__label">Amount (TT$)</label><input type="number" step="1" min="1" name="amount" required></div>
                <div class="tf2-field"><label class="tf2-field__label">Recipient name</label><input type="text" name="recipient_name"></div>
                <div class="tf2-field"><label class="tf2-field__label">Recipient email</label><input type="email" name="recipient_email"></div>
            </div>
            <div class="tf2-row">
                <div class="tf2-field"><label class="tf2-field__label">Message on the card (optional)</label><input type="text" name="message" maxlength="500"></div>
                <div class="tf2-field"><label class="tf2-field__label">Internal note (optional)</label><input type="text" name="note" placeholder="e.g. Instagram giveaway Nov 2026"></div>
            </div>
            <label style="display:flex; gap:8px; align-items:center; margin:4px 0 14px;"><input type="checkbox" name="send" value="1" checked> Email it to the recipient now</label>
            <button class="tf2-btn tf2-btn--primary">Issue gift card</button>
        </form>
    </div>

    <!-- ══════════ SETTINGS ══════════ -->
    <div class="tf2-card tf2-mb-6">
        <h2 style="margin:0 0 6px;">Settings</h2>
        <?php $form_open( 'settings' ); ?>
            <label style="display:flex; gap:8px; align-items:center; margin:0 0 12px;"><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> Sell gift cards on the website</label>
            <label style="display:flex; gap:8px; align-items:center; margin:0 0 12px;"><input type="checkbox" name="packages" value="1" <?php checked( ! empty( $settings['packages'] ) ); ?>> Offer "gift a session" (your packages, at their current price)</label>
            <div class="tf2-row--3">
                <div class="tf2-field"><label class="tf2-field__label">Preset amounts (TT$)</label><input type="text" name="presets" value="<?php echo esc_attr( implode( ', ', array_map( function ( $v ) { return $v + 0; }, (array) $settings['presets'] ) ) ); ?>"></div>
                <div class="tf2-field"><label class="tf2-field__label">Custom amount: min / max</label>
                    <div style="display:flex; gap:6px;"><input type="number" name="min" value="<?php echo esc_attr( $settings['min'] + 0 ); ?>" style="width:50%;"><input type="number" name="max" value="<?php echo esc_attr( $settings['max'] + 0 ); ?>" style="width:50%;"></div></div>
                <div class="tf2-field"><label class="tf2-field__label">Expires after (months, 0 = never)</label><input type="number" min="0" name="expiry_months" value="<?php echo esc_attr( (int) $settings['expiry_months'] ); ?>"></div>
            </div>
            <p class="tf2-description">Payment uses your bank details from Settings (with receipt upload and automatic amount reading), plus card payments when WiPay is on. The two gift card emails can be reworded under <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-emails' ) ); ?>">Emails</a>.</p>
            <button class="tf2-btn tf2-btn--primary">Save settings</button>
        </form>
    </div>
</div>

<style>
.tf2-gc-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:18px; }
.tf2-gc-stats .tf2-card { margin:0; }
.tf2-gc-stat { font-size:24px; font-weight:700; color:#111827; }
.tf2-gc-actions { display:flex; flex-wrap:wrap; gap:8px; margin:18px 0 6px; }
.tf2-gc-adjust { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin:12px 0; padding:12px; background:#F9FAFB; border-radius:8px; }
.tf2-gc-adjust label { display:flex; flex-direction:column; font-size:12px; color:#6B7280; gap:4px; }
.tf2-gc-receipt { display:flex; gap:18px; flex-wrap:wrap; justify-content:space-between; margin-top:18px; padding:14px; border:1px solid #FDE68A; background:#FFFBEB; border-radius:10px; }
.tf2-gc-receipt img { max-width:220px; max-height:300px; border-radius:8px; border:1px solid #E5E7EB; }
.tf2-gc-tag { display:inline-block; padding:1px 7px; border-radius:999px; background:#FEF3C7; color:#92400E; font-size:11px; font-weight:600; vertical-align:middle; }
.tf2-gc-people { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; margin-top:18px; }
.tf2-gc-people__col { padding:12px 14px; border:1px solid #E5E7EB; border-radius:10px; background:#F9FAFB; }
.tf2-gc-people__title { font-size:12px; font-weight:600; color:#6B7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:8px; }
.tf2-gc-people__row { display:flex; flex-wrap:wrap; align-items:baseline; gap:4px 10px; padding:4px 0; }
.tf2-gc-people__amt { margin-left:auto; font-variant-numeric:tabular-nums; font-weight:600; }
.tf2-gc-people__list { line-height:1.9; }
.tf2-gc-filters { display:flex; flex-wrap:wrap; gap:6px; }
.tf2-gc-filters a { padding:5px 11px; border-radius:999px; background:#F3F4F6; color:#374151; text-decoration:none; font-size:12.5px; }
.tf2-gc-filters a.is-on { background:#111827; color:#fff; }
</style>

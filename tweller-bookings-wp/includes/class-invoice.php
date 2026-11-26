<?php
/**
 * The booking invoice shown on the payment step of the session tracker, and
 * the bank-transfer details with a copy button on every line.
 *
 * The line items are saved when the booking is made (the price the server
 * worked out: session, fees, discounts, extra backdrops, transport, gift
 * card). Bookings made before this existed show one line for the session.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Invoice {

    const OPT = 'tweller_invoice_';

    public static function save( $session_id, $invoice ) {
        update_option( self::OPT . (int) $session_id, $invoice, false );
    }

    /** [ 'lines' => [ [label, amount, sub?, text?] ], 'total', 'gift', 'gift_code', 'due' ] */
    public static function get( $session ) {
        $inv   = get_option( self::OPT . (int) $session->id, null );
        $total = (float) $session->total_amount;
        if ( ! is_array( $inv ) || empty( $inv['lines'] ) ) {
            $pkgs = get_option( 'tweller_flow_2_packages', array() );
            $name = $pkgs[ $session->package_type ]['name'] ?? ucwords( str_replace( array( '-', '_' ), ' ', (string) $session->package_type ) );
            $inv  = array(
                'lines'     => array( array( 'label' => trim( $name . ' session' ), 'amount' => $total ) ),
                'gift'      => (float) $session->deposit_amount,
                'gift_code' => '',
            );
        }
        $inv['total'] = $total;
        $inv['gift']  = (float) ( $inv['gift'] ?? 0 );
        $inv['due']   = max( 0.0, round( $total - (float) $session->deposit_amount, 2 ) );
        return $inv;
    }

    public static function money( $n ) {
        return 'TT$' . number_format( (float) $n, 2 );
    }

    /** "Account Number: 2430006" lines → rows; anything else → notes. */
    public static function bank_rows() {
        $text  = (string) get_option( 'tweller_flow_2_banking', '' );
        $rows  = array();
        $notes = array();
        $nice  = array(
            'name' => 'Account name', 'account name' => 'Account name', 'acct name' => 'Account name',
            'account number' => 'Account number', 'account no' => 'Account number', 'acct no' => 'Account number', 'acct number' => 'Account number', 'account #' => 'Account number', 'acct #' => 'Account number',
            'bank' => 'Bank', 'branch' => 'Branch', 'type' => 'Account type', 'account type' => 'Account type',
        );
        foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            if ( preg_match( '/^([^:]{1,32}):\s*(.+)$/u', $line, $m ) ) {
                $k = strtolower( rtrim( trim( $m[1] ), '.' ) );
                $rows[] = array( 'label' => $nice[ $k ] ?? trim( $m[1] ), 'value' => trim( $m[2] ) );
            } elseif ( strlen( $line ) <= 40 && preg_match( '/\b(savings|chequing|checking|current)\b/i', $line ) ) {
                $rows[] = array( 'label' => 'Account type', 'value' => $line );
            } else {
                $notes[] = $line;
            }
        }
        return array( $rows, $notes );
    }

    private static function status( $session ) {
        switch ( $session->payment_status ) {
            case 'verifying': return array( 'Verifying payment', 'wait' );
            case 'deposit':   return array( 'Part paid', 'wait' );
            case 'paid':
            case 'confirmed': return array( 'Paid', 'paid' );
            default:          return array( 'Payment due', 'due' );
        }
    }

    private static function when( $session ) {
        $ts = strtotime( trim( $session->session_date . ' ' . $session->session_time ) );
        if ( ! $ts ) $ts = strtotime( (string) $session->session_date );
        return $ts ? date_i18n( 'D, M j, Y', $ts ) . ( $session->session_time ? ' · ' . date_i18n( 'g:i A', $ts ) : '' ) : '';
    }

    /** The invoice card. */
    public static function render( $session ) {
        $inv = self::get( $session );
        list( $st_label, $st_kind ) = self::status( $session );
        $pkgs    = get_option( 'tweller_flow_2_packages', array() );
        $pkg     = $pkgs[ $session->package_type ]['name'] ?? '';
        $issued  = $session->created_at ? date_i18n( 'M j, Y', strtotime( $session->created_at ) ) : '';
        $due_lbl = $st_kind === 'paid' ? 'Amount paid' : 'Amount due';
        ob_start(); ?>
        <section class="tf2-inv" aria-label="Invoice">
            <header class="tf2-inv__top">
                <div class="tf2-inv__from">
                    <span class="tf2-inv__kicker">Invoice</span>
                    <span class="tf2-inv__brand"><?php echo esc_html( get_bloginfo( 'name' ) ?: 'Tweller Studios' ); ?></span>
                </div>
                <span class="tf2-inv__status tf2-inv__status--<?php echo esc_attr( $st_kind ); ?>"><?php echo esc_html( $st_label ); ?></span>
            </header>

            <div class="tf2-inv__amount">
                <span class="tf2-inv__amount-k"><?php echo esc_html( $due_lbl ); ?></span>
                <strong class="tf2-inv__amount-v"><?php echo esc_html( self::money( $st_kind === 'paid' ? $inv['total'] : $inv['due'] ) ); ?></strong>
                <?php if ( $st_kind === 'due' && $inv['due'] > 0 ) : ?><span class="tf2-inv__amount-s">Pay by card or bank transfer to confirm your booking.</span><?php endif; ?>
            </div>

            <dl class="tf2-inv__meta">
                <div><dt>Billed to</dt><dd><?php echo esc_html( $session->client_name ); ?><span><?php echo esc_html( $session->client_email ); ?></span></dd></div>
                <div><dt>Session</dt><dd><?php echo esc_html( $pkg ?: 'Photo session' ); ?><span><?php echo esc_html( self::when( $session ) ); ?></span></dd></div>
                <div><dt>Invoice no.</dt><dd><?php echo esc_html( 'TW-' . str_pad( (string) (int) $session->id, 5, '0', STR_PAD_LEFT ) ); ?></dd></div>
                <div><dt>Issued</dt><dd><?php echo esc_html( $issued ); ?></dd></div>
            </dl>

            <div class="tf2-inv__items" role="table" aria-label="Invoice items">
                <div class="tf2-inv__row tf2-inv__row--head" role="row"><span role="columnheader">Description</span><span role="columnheader">Amount</span></div>
                <?php foreach ( $inv['lines'] as $l ) :
                    $amt = (float) ( $l['amount'] ?? 0 );
                    $txt = ! empty( $l['text'] ) ? $l['text'] : ( $amt < 0 ? '−' . self::money( -$amt ) : self::money( $amt ) ); ?>
                    <div class="tf2-inv__row<?php echo $amt < 0 ? ' is-off' : ''; ?>" role="row">
                        <span role="cell"><?php echo esc_html( $l['label'] ); ?><?php if ( ! empty( $l['sub'] ) ) : ?><small><?php echo esc_html( $l['sub'] ); ?></small><?php endif; ?></span>
                        <span role="cell"><?php echo esc_html( $txt ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <dl class="tf2-inv__sum">
                <div><dt>Total</dt><dd><?php echo esc_html( self::money( $inv['total'] ) ); ?></dd></div>
                <?php if ( (float) $session->deposit_amount > 0 ) : ?>
                    <div class="is-off"><dt><?php echo esc_html( $inv['gift'] > 0 ? 'Gift card' . ( ! empty( $inv['gift_code'] ) ? ' ' . $inv['gift_code'] : '' ) : 'Paid' ); ?></dt><dd>−<?php echo esc_html( self::money( $session->deposit_amount ) ); ?></dd></div>
                <?php endif; ?>
                <div class="tf2-inv__sum-due"><dt><?php echo esc_html( $due_lbl ); ?></dt><dd><?php echo esc_html( self::money( $st_kind === 'paid' ? $inv['total'] : $inv['due'] ) ); ?></dd></div>
            </dl>
        </section>
        <?php
        return ob_get_clean();
    }

    /** Bank details, one row per item with its own copy button. */
    public static function render_bank( $amount ) {
        list( $rows, $notes ) = self::bank_rows();
        if ( $amount > 0 ) array_unshift( $rows, array( 'label' => 'Amount (TTD)', 'value' => number_format( (float) $amount, 2, '.', '' ), 'show' => self::money( $amount ) ) );
        if ( ! $rows && ! $notes ) return '';
        $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/></svg>';
        $tick = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>';
        ob_start(); ?>
        <ul class="tf2-bank" aria-label="Bank details">
            <?php foreach ( $rows as $r ) : ?>
                <li class="tf2-bank__row">
                    <span class="tf2-bank__txt">
                        <span class="tf2-bank__k"><?php echo esc_html( $r['label'] ); ?></span>
                        <span class="tf2-bank__v"><?php echo esc_html( $r['show'] ?? $r['value'] ); ?></span>
                    </span>
                    <button type="button" class="tf2-bank__copy" data-copy="<?php echo esc_attr( $r['value'] ); ?>" aria-label="<?php echo esc_attr( 'Copy ' . strtolower( $r['label'] ) ); ?>">
                        <span class="tf2-bank__ic tf2-bank__ic--copy"><?php echo $icon; // phpcs:ignore ?></span>
                        <span class="tf2-bank__ic tf2-bank__ic--done"><?php echo $tick; // phpcs:ignore ?></span>
                        <span class="tf2-bank__lbl">Copy</span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php foreach ( $notes as $n ) : ?><p class="tf2-bank__note"><?php echo esc_html( $n ); ?></p><?php endforeach; ?>
        <p class="tf2-bank__live" role="status" aria-live="polite"></p>
        <?php
        return ob_get_clean();
    }
}

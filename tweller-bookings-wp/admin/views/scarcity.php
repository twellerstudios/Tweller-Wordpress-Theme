<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var array $types  session types */
/** @var array $conf   saved settings, session key => settings */
?>
<div class="wrap tf2-wrap">
    <h1>Limited Spots</h1>
    <p class="tf2-description" style="max-width:760px;">
        Show a real "Only 7 left" badge on the packages. You set a batch size (for example 10). Each booking of that session type
        moves the number down, and when a batch is used up the next one starts. The count comes from your real bookings.
        Optionally each new batch costs a little more.
    </p>

    <?php if ( ! empty( $_GET['saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Saved.</div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="tweller_flow_2_scarcity_save">
        <?php wp_nonce_field( 'tweller_flow_2_scarcity_save' ); ?>

        <div class="tf2-card tf2-mb-6">
            <table class="widefat striped" style="max-width:1100px;">
                <thead>
                    <tr>
                        <th>Session type</th>
                        <th>On</th>
                        <th>Spots per batch</th>
                        <th>Count bookings from</th>
                        <th>Already taken</th>
                        <th>Price rise per batch (TT$)</th>
                        <th>Right now</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( (array) $types as $key => $t ) :
                    $c     = $conf[ $key ] ?? array();
                    $state = TwellerFlow2_Scarcity::state( $key );
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $t['name'] ?? $key ); ?></strong></td>
                        <td><input type="checkbox" name="s[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $c['enabled'] ) ); ?>></td>
                        <td><input type="number" min="2" max="500" style="width:72px;" name="s[<?php echo esc_attr( $key ); ?>][batch]" value="<?php echo esc_attr( $c['batch'] ?? 10 ); ?>"></td>
                        <td><input type="date" name="s[<?php echo esc_attr( $key ); ?>][since]" value="<?php echo esc_attr( $c['since'] ?? '' ); ?>"></td>
                        <td><input type="number" min="0" style="width:72px;" name="s[<?php echo esc_attr( $key ); ?>][offset]" value="<?php echo esc_attr( $c['offset'] ?? 0 ); ?>"></td>
                        <td><input type="number" min="0" step="1" style="width:90px;" name="s[<?php echo esc_attr( $key ); ?>][step]" value="<?php echo esc_attr( $c['step'] ?? 0 ); ?>"></td>
                        <td>
                            <?php if ( $state ) : ?>
                                <strong>Only <?php echo (int) $state['left']; ?> left</strong><br>
                                <small>Batch <?php echo (int) $state['index'] + 1; ?> · <?php echo (int) $state['count']; ?> counted<?php echo $state['add'] > 0 ? ' · +TT$' . esc_html( number_format( $state['add'] ) ) : ''; ?></small>
                            <?php else : ?>
                                <small>Off</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="tf2-description" style="max-width:900px;">
                <strong>Count bookings from:</strong> bookings made on or after this date count (turning it on sets today, so today's bookings count).
                <strong>Already taken:</strong> spots to add on top of the real count, for bookings that did not come through this form.
                <strong>Price rise per batch:</strong> leave at 0 to keep the price the same. At 50, the first batch is the normal price, the second +TT$50, the third +TT$100 and so on.
                Packages shared by several session types are counted per session type.
            </p>
        </div>

        <p><button type="submit" class="button button-primary">Save</button></p>
    </form>
</div>

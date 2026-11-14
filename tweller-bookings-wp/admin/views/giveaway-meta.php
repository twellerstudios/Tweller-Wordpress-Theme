<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var array $set @var array $stats @var array $rounds @var array|false $tested @var array $posts */
$msg   = sanitize_key( $_GET['msg'] ?? '' );
$conn  = TwellerFlow2_Giveaway_Meta::connected();
$sync  = (int) ( $stats['synced'] ?? 0 );
$notes = (array) ( $stats['notes'] ?? array() );
$post  = function ( $action, $label, $class = 'button' ) {
    return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin:0;"><input type="hidden" name="action" value="tweller_flow_2_gwmeta_' . esc_attr( $action ) . '">' . wp_nonce_field( 'tweller_flow_2_gwmeta_' . $action, '_wpnonce', true, false ) . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
};
$n = function ( $v ) { return $v === null || $v === '' ? '—' : number_format( (float) $v ); };
$growth = TwellerFlow2_Giveaway_Meta::growth();
?>
<div class="wrap tf2-wrap">
    <h1>Giveaway: live from Facebook &amp; Instagram</h1>
    <p class="tf2-description" style="max-width:760px;">
        Connect Meta once and the giveaway fills itself: every comment and its likes, the post's likes, comments, shares, reach and video views, and how many followers your pages are gaining.
        It refreshes every 10 minutes (or click <strong>Sync now</strong>). TikTok can't be read by a website, so its numbers are typed in below.
        Step-by-step setup: <a href="https://github.com/twellerstudios/Tweller-Wordpress-Theme/blob/claude/gifted-hopper-czogi6/ads/christmas-2026/GIVEAWAY-META-SETUP.md" target="_blank" rel="noopener">the setup guide</a>.
    </p>

    <?php if ( $msg === 'saved' ) : ?><div class="tf2-alert tf2-alert--success">Saved.</div>
    <?php elseif ( $msg === 'synced' ) : ?><div class="tf2-alert tf2-alert--success">Synced just now.</div>
    <?php elseif ( $msg === 'syncfail' ) : ?><div class="tf2-alert tf2-alert--error">Couldn't sync. Connect Meta first, or try again in a minute.</div>
    <?php endif; ?>

    <?php if ( $conn ) : ?>
    <div class="tf2-card tf2-mb-6">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <p style="margin:0;"><strong>Last sync:</strong> <?php echo $sync ? esc_html( human_time_diff( $sync ) . ' ago' ) : 'not yet'; ?><?php echo wp_next_scheduled( TwellerFlow2_Giveaway_Meta::CRON ) ? ' · next automatic sync in ' . esc_html( human_time_diff( wp_next_scheduled( TwellerFlow2_Giveaway_Meta::CRON ) ) ) : ''; ?></p>
            <?php echo $post( 'sync', 'Sync now', 'button button-primary' ); // phpcs:ignore ?>
        </div>
        <?php foreach ( $notes as $note ) echo '<p style="margin:8px 0 0;color:#B42318;">⚠ ' . esc_html( $note ) . '</p>'; ?>
    </div>

    <?php if ( $growth ) : ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:16px;">
        <?php foreach ( $growth as $k => $g ) : ?>
            <div class="tf2-card" style="margin:0;"><div class="tf2-description"><?php echo esc_html( ucfirst( $k ) ); ?> followers</div>
                <div style="font-size:24px;font-weight:700;"><?php echo esc_html( number_format( $g['now'] ) ); ?></div>
                <div style="color:<?php echo $g['gained'] >= 0 ? '#067647' : '#B42318'; ?>;font-weight:600;"><?php echo ( $g['gained'] >= 0 ? '+' : '' ) . esc_html( number_format( $g['gained'] ) ); ?> since tracking began</div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2>The posts, week by week</h2>
    <?php foreach ( $rounds as $r ) :
        $rs = TwellerFlow2_Giveaway_Meta::round_stats( $r['id'] ); ?>
        <div class="tf2-card tf2-mb-6">
            <h3 style="margin-top:0;"><?php echo esc_html( $r['label'] ); ?> <small style="font-weight:400;color:#6B7280;"><?php echo esc_html( wp_date( 'M j', $r['start_ts'] ) . ' to ' . wp_date( 'M j', $r['end_ts'] ) ); ?></small></h3>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>Platform</th><th>Likes</th><th>Comments</th><th>Shares</th><th>Reach</th><th>Views</th><th>Memories read</th></tr></thead>
                <tbody>
                <?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok (typed in)' ) as $k => $label ) : $row = $rs[ $k ]; ?>
                    <tr><td><strong><?php echo esc_html( $label ); ?></strong><?php if ( $row && ! empty( $row['url'] ) ) echo ' · <a href="' . esc_url( $row['url'] ) . '" target="_blank" rel="noopener">open post</a>'; ?>
                        <?php if ( $row && ! empty( $row['error'] ) ) echo '<br><small style="color:#B42318;">Comments: ' . esc_html( $row['error'] ) . '</small>'; ?></td>
                        <td><?php echo esc_html( $n( $row['likes'] ?? null ) ); ?></td><td><?php echo esc_html( $n( $row['comments'] ?? null ) ); ?></td><td><?php echo esc_html( $n( $row['shares'] ?? null ) ); ?></td>
                        <td><?php echo esc_html( $n( $row['reach'] ?? null ) ); ?></td><td><?php echo esc_html( $n( $row['views'] ?? null ) ); ?></td><td><?php echo esc_html( $n( $row['imported'] ?? null ) ); ?></td></tr>
                <?php endforeach; ?>
                    <tr><td><strong>Total</strong></td><?php foreach ( array( 'likes', 'comments', 'shares', 'reach', 'views' ) as $f ) echo '<td><strong>' . esc_html( number_format( $rs['total'][ $f ] ) ) . '</strong></td>'; ?><td></td></tr>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="tf2-card tf2-mb-6">
        <h2 style="margin-top:0;">Connect Meta</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tweller_flow_2_gwmeta_save">
            <?php wp_nonce_field( 'tweller_flow_2_gwmeta_save' ); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="gm-token">Access token</label></th><td>
                    <input type="password" id="gm-token" name="token" class="regular-text" autocomplete="new-password" placeholder="<?php echo $set['token'] !== '' ? '••••••••  (saved: leave empty to keep it)' : 'Paste the token here'; ?>">
                    <?php if ( $set['token'] !== '' ) : ?><label style="margin-left:10px;"><input type="checkbox" name="clear_token" value="1"> Remove saved token</label><?php endif; ?>
                    <p class="tf2-description">A long-lived (system user) token with permission to read your Page and Instagram account. It is stored on your site and never shown again.</p></td></tr>
                <tr><th><label for="gm-page">Facebook Page ID</label></th><td><input type="text" id="gm-page" name="page_id" value="<?php echo esc_attr( $set['page_id'] ); ?>" placeholder="1480134645625711"></td></tr>
                <tr><th><label for="gm-ig">Instagram account ID</label></th><td><input type="text" id="gm-ig" name="ig_id" value="<?php echo esc_attr( $set['ig_id'] ); ?>" placeholder="17841402344062882"> <span class="tf2-description">The Instagram Business account's ID (not the @name).</span></td></tr>
                <tr><th>The posts</th><td>
                    <table class="widefat" style="max-width:760px;"><thead><tr><th>Week</th><th>Facebook post ID</th><th>Instagram post ID</th></tr></thead><tbody>
                    <?php foreach ( $rounds as $r ) : ?>
                        <tr><td><?php echo esc_html( $r['label'] ); ?></td>
                            <td><input type="text" name="links[<?php echo esc_attr( $r['id'] ); ?>][facebook]" value="<?php echo esc_attr( $set['links'][ $r['id'] ]['facebook'] ?? '' ); ?>" placeholder="pick from the list below"></td>
                            <td><input type="text" name="links[<?php echo esc_attr( $r['id'] ); ?>][instagram]" value="<?php echo esc_attr( $set['links'][ $r['id'] ]['instagram'] ?? '' ); ?>" placeholder="pick from the list below"></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <p class="tf2-description">After you post each week's giveaway, click <strong>Test connection &amp; list my posts</strong> and copy the post's ID into its week.</p></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Save</button></p>
        </form>
        <?php if ( $conn ) echo $post( 'test', 'Test connection & list my posts' ); // phpcs:ignore ?>

        <?php if ( is_array( $tested ) ) : ?>
            <div style="margin-top:14px;">
                <?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $k => $label ) : $t = $tested[ $k ]; if ( $t === null ) continue; ?>
                    <p style="margin:4px 0;"><?php echo ! isset( $t['error'] ) ? '✅' : '❌'; ?> <strong><?php echo esc_html( $label ); ?>:</strong>
                        <?php echo isset( $t['error'] ) ? esc_html( $t['error'] ) : esc_html( $t['name'] . ' · ' . number_format( $t['followers'] ) . ' followers' ); ?></p>
                <?php endforeach; ?>
            </div>
            <?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $k => $label ) :
                $list = $posts[ $k ]; if ( is_wp_error( $list ) ) { echo '<p style="color:#B42318;">' . esc_html( $label ) . ' posts: ' . esc_html( $list->get_error_message() ) . '</p>'; continue; }
                if ( ! $list ) continue; ?>
                <h4><?php echo esc_html( $label ); ?>: latest posts (click an ID to copy it)</h4>
                <table class="widefat striped" style="max-width:900px;"><tbody>
                <?php foreach ( $list as $row ) : ?>
                    <tr><td><?php echo esc_html( $row['label'] ); ?></td><td><code><?php echo esc_html( $row['id'] ); ?></code> <button type="button" class="button button-small tf2-copy" data-copy="<?php echo esc_attr( $row['id'] ); ?>">Copy ID</button></td><td><?php if ( $row['url'] ) echo '<a href="' . esc_url( $row['url'] ) . '" target="_blank" rel="noopener">open</a>'; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="tf2-card">
        <h2 style="margin-top:0;">TikTok (typed in)</h2>
        <p class="tf2-description">TikTok doesn't let a website read comments. Type the numbers from your TikTok analytics, and import the comments from a CSV on the Giveaway page.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tweller_flow_2_gwmeta_tiktok">
            <?php wp_nonce_field( 'tweller_flow_2_gwmeta_tiktok' ); ?>
            <table class="widefat" style="max-width:760px;"><thead><tr><th>Week</th><th>Likes</th><th>Comments</th><th>Shares</th><th>Views</th></tr></thead><tbody>
            <?php foreach ( $rounds as $r ) : $t = $set['tiktok'][ $r['id'] ] ?? array(); ?>
                <tr><td><?php echo esc_html( $r['label'] ); ?></td>
                <?php foreach ( array( 'likes', 'comments', 'shares', 'views' ) as $f ) echo '<td><input type="number" min="0" style="width:100px;" name="tiktok[' . esc_attr( $r['id'] ) . '][' . esc_attr( $f ) . ']" value="' . esc_attr( $t[ $f ] ?? 0 ) . '"></td>'; ?></tr>
            <?php endforeach; ?>
            </tbody></table>
            <p><button type="submit" class="button">Save TikTok numbers</button></p>
        </form>
    </div>
</div>
<?php include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/partials/copy-js.php'; ?>

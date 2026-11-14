<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var array $s settings */
$plats   = TwellerFlow2_Giveaway::platforms();
$tab     = isset( $_GET['p'] ) && isset( $plats[ $_GET['p'] ] ) ? sanitize_key( $_GET['p'] ) : 'instagram';
$draws   = array_reverse( TwellerFlow2_Giveaway::draws() );
$web     = TwellerFlow2_Giveaway::web();
$page_id = (int) $s['page_id'];
$page    = $page_id ? get_post( $page_id ) : null;
$msg     = sanitize_key( $_GET['msg'] ?? '' );
$form    = function ( $action, $fields, $button, $class = 'button', $confirm = '' ) {
    $h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin:0;"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
    $h .= '<input type="hidden" name="action" value="tweller_flow_2_giveaway_' . esc_attr( $action ) . '">' . wp_nonce_field( 'tweller_flow_2_giveaway_' . $action, '_wpnonce', true, false );
    foreach ( $fields as $k => $v ) $h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
    return $h . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $button ) . '</button></form>';
};
$m_all = array();
foreach ( $plats as $k => $l ) $m_all[ $k ] = TwellerFlow2_Giveaway::metrics( $k );
$ends = TwellerFlow2_Giveaway::ends_ts();
?>
<div class="wrap tf2-wrap">
    <h1>Giveaway: <?php echo esc_html( $s['title'] ); ?></h1>

    <?php if ( $msg === 'saved' ) : ?><div class="tf2-alert tf2-alert--success">Saved.</div>
    <?php elseif ( $msg === 'imported' ) : ?><div class="tf2-alert tf2-alert--success">Imported <?php echo (int) $_GET['n']; ?> new comment<?php echo (int) $_GET['n'] === 1 ? '' : 's'; ?> (<?php echo (int) $_GET['of']; ?> read; repeats are skipped and their likes updated).</div>
    <?php elseif ( $msg === 'drawn' ) : ?><div class="tf2-alert tf2-alert--success">Winner drawn. See the winners list below.</div>
    <?php elseif ( $msg === 'empty' ) : ?><div class="tf2-alert tf2-alert--error">Nobody eligible left to draw on that platform.</div>
    <?php elseif ( $msg === 'prize' ) : ?><div class="tf2-alert tf2-alert--success">Prize code created: <strong><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ) ); ?></strong>. Send it to the winner.</div>
    <?php elseif ( $msg === 'voided' ) : ?><div class="tf2-alert tf2-alert--success">That win is cancelled. Draw again for a new winner.</div>
    <?php elseif ( $msg === 'cleared' ) : ?><div class="tf2-alert tf2-alert--success">Comments for that platform removed.</div>
    <?php endif; ?>

    <div class="tf2-card tf2-mb-6">
        <p style="margin:0;">
            Giveaway page:
            <?php if ( $page ) : ?>
                <a href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $page ) ); ?></a>
                <?php if ( $page->post_status !== 'publish' ) : ?>
                    <strong style="color:#B42318;">(draft, not live yet)</strong> <a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>">Open and publish</a>
                <?php else : ?>
                    <button type="button" class="button button-small tf2-copy" data-copy="<?php echo esc_attr( get_permalink( $page ) ); ?>">Copy link</button>
                <?php endif; ?>
            <?php else : ?>
                none. Add <code>[tweller_giveaway]</code> to any page.
            <?php endif; ?>
            · Entries close <strong><?php echo $ends ? esc_html( wp_date( 'D, M j \a\t g:i a', $ends ) ) : '—'; ?></strong>
            · Website entries: <strong><?php echo count( $web ); ?></strong>
        </p>
    </div>

    <h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
        <?php foreach ( $plats as $k => $l ) : ?>
            <a class="nav-tab<?php echo $tab === $k ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-giveaway&p=' . $k ) ); ?>"><?php echo esc_html( $l ); ?> <span class="count">(<?php echo (int) $m_all[ $k ]['people']; ?>)</span></a>
        <?php endforeach; ?>
    </h2>

    <?php $m = $m_all[ $tab ]; $people = TwellerFlow2_Giveaway::entrants( $tab ); ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px;">
        <?php foreach ( array( 'Comments' => $m['comments'], 'People' => $m['people'], 'Eligible' => $m['eligible'], 'Draw tickets' => $m['entries'], 'Comment likes' => $m['likes'], 'Tags' => $m['tags'], 'People tagged' => count( $m['tagged'] ), 'Website entries' => $m['web'] ) as $label => $v ) : ?>
            <div class="tf2-card" style="margin:0;padding:12px 14px;"><div class="tf2-description"><?php echo esc_html( $label ); ?></div><div style="font-size:22px;font-weight:700;"><?php echo esc_html( number_format( (float) $v ) ); ?></div></div>
        <?php endforeach; ?>
    </div>

    <div class="tf2-card tf2-mb-6">
        <h2 style="margin-top:0;">Pick the <?php echo esc_html( $plats[ $tab ] ); ?> winners</h2>
        <p class="tf2-description">Import the latest comments first, after entries close, so the likes are final. Each person can only win once.</p>
        <?php echo $form( 'draw', array( 'platform' => $tab, 'kind' => 'loved' ), 'Most-loved memory (Full Session)', 'button button-primary', 'Pick the most-liked memory on ' . $plats[ $tab ] . ' now?' ); // phpcs:ignore ?>
        <?php echo $form( 'draw', array( 'platform' => $tab, 'kind' => 'random' ), 'Random draw (Mini Session)', 'button button-primary', 'Draw a random winner on ' . $plats[ $tab ] . ' now?' ); // phpcs:ignore ?>
    </div>

    <div class="tf2-card tf2-mb-6">
        <h2 style="margin-top:0;">Import <?php echo esc_html( $plats[ $tab ] ); ?> comments</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="tweller_flow_2_giveaway_import">
            <input type="hidden" name="platform" value="<?php echo esc_attr( $tab ); ?>">
            <?php wp_nonce_field( 'tweller_flow_2_giveaway_import' ); ?>
            <p><label>CSV file from a comment-export tool: <input type="file" name="file" accept=".csv,.txt,text/csv"></label></p>
            <p><label for="gw-raw">Or paste, one comment per line: <code>username | likes | comment</code></label><br>
                <textarea id="gw-raw" name="raw" rows="5" style="width:100%;max-width:900px;" placeholder="@sarah.j | 42 | The Christmas my grandmother taught me to make pastelles… @auntie_m"></textarea></p>
            <p><button type="submit" class="button">Import</button>
                <span class="tf2-description">Importing again is safe: repeats are skipped and their likes are updated.</span></p>
        </form>
        <?php if ( $m['comments'] ) echo $form( 'clear', array( 'platform' => $tab ), 'Remove all ' . $plats[ $tab ] . ' comments', 'button-link', 'Remove every imported ' . $plats[ $tab ] . ' comment?' ); // phpcs:ignore ?>
    </div>

    <div class="tf2-card tf2-mb-6">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <h2 style="margin:0;"><?php echo esc_html( $plats[ $tab ] ); ?> entrants (most-liked memory first)</h2>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tweller_flow_2_giveaway_csv' ), 'tweller_flow_2_giveaway_csv' ) ); ?>">Download all (CSV)</a>
        </div>
        <?php if ( ! $people ) : ?>
            <p class="tf2-description">No comments imported yet.</p>
        <?php else : ?>
        <table class="widefat striped" style="margin-top:10px;">
            <thead><tr><th>Person</th><th>Likes</th><th>Tickets</th><th>Tagged</th><th>Website</th><th>Memory</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $people, 0, 300 ) as $p ) : ?>
                <tr<?php echo $p['won'] ? ' style="background:#FFF7E0;"' : ''; ?>>
                    <td><strong><?php echo esc_html( $p['user'] ); ?></strong><?php echo $p['won'] ? ' 🏆' : ''; ?></td>
                    <td><?php echo (int) ( $p['best']['likes'] ?? 0 ); ?></td>
                    <td><?php echo $p['entries'] ? (int) $p['entries'] : '<small>Not eligible (memory too short)</small>'; ?></td>
                    <td><?php echo esc_html( implode( ' ', array_map( function ( $t ) { return '@' . $t; }, $p['tags'] ) ) ); ?></td>
                    <td><?php echo $p['web'] ? '✓' : ''; ?></td>
                    <td style="max-width:420px;"><?php echo esc_html( wp_trim_words( $p['best']['text'] ?? '', 40 ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if ( $m['tagged'] ) : ?>
    <div class="tf2-card tf2-mb-6">
        <h2 style="margin-top:0;">Everyone tagged on <?php echo esc_html( $plats[ $tab ] ); ?></h2>
        <p class="tf2-description">People your entrants brought in. They have seen your post, so they are warm leads for Christmas photos.</p>
        <table class="widefat striped">
            <thead><tr><th>Tagged</th><th>Times</th><th>Tagged by</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $m['tagged'], 0, 300, true ) as $t => $row ) : ?>
                <tr><td>@<?php echo esc_html( $t ); ?></td><td><?php echo (int) $row['count']; ?></td><td><?php echo esc_html( implode( ', ', array_map( function ( $h ) { return '@' . $h; }, array_keys( $row['by'] ) ) ) ); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="tf2-card tf2-mb-6">
        <h2 style="margin-top:0;">Winners</h2>
        <?php if ( ! $draws ) : ?>
            <p class="tf2-description">No winners drawn yet.</p>
        <?php else : ?>
        <table class="widefat striped">
            <thead><tr><th>When</th><th>Platform</th><th>How</th><th>Winner</th><th>Proof</th><th>Prize code</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $draws as $d ) : ?>
                <tr<?php echo ! empty( $d['void'] ) ? ' style="opacity:.5;text-decoration:line-through;"' : ''; ?>>
                    <td><?php echo esc_html( wp_date( 'M j, g:i a', $d['time'] ) ); ?></td>
                    <td><?php echo esc_html( $plats[ $d['platform'] ] ); ?></td>
                    <td><?php echo $d['kind'] === 'loved' ? 'Most-loved memory (' . (int) $d['likes'] . ' likes)' : 'Random draw'; ?></td>
                    <td><strong><?php echo esc_html( $d['user'] ); ?></strong><br><small><?php echo esc_html( wp_trim_words( $d['memory'], 18 ) ); ?></small></td>
                    <td><small><?php echo (int) $d['eligible']; ?> eligible, <?php echo (int) $d['tickets']; ?> tickets<br>ref <?php echo esc_html( $d['fingerprint'] ); ?></small></td>
                    <td>
                        <?php if ( ! empty( $d['prize_code'] ) ) : ?>
                            <code><?php echo esc_html( $d['prize_code'] ); ?></code>
                            <button type="button" class="button button-small tf2-copy" data-copy="<?php echo esc_attr( $d['prize_code'] ); ?>">Copy</button>
                        <?php elseif ( empty( $d['void'] ) ) : ?>
                            <?php echo $form( 'prize', array( 'draw' => $d['id'], 'prize' => $d['kind'] === 'loved' ? 'full' : 'mini' ), $d['kind'] === 'loved' ? 'Create Full Session code' : 'Create Mini Session code', 'button button-small' ); // phpcs:ignore ?>
                        <?php endif; ?>
                    </td>
                    <td><?php if ( empty( $d['void'] ) ) echo $form( 'remove', array( 'draw' => $d['id'] ), 'No reply: cancel win', 'button-link', 'Cancel this win? You can then draw again.' ); // phpcs:ignore ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="tf2-description">Prize codes are 100% off, one-time, valid for 6 months on any session type (Full or Mini package). "Ref" is a fingerprint of the entrant list at the moment of the draw, so you can post it with the result.</p>
        <?php endif; ?>
    </div>

    <div class="tf2-card">
        <h2 style="margin-top:0;">Settings</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tweller_flow_2_giveaway_save">
            <?php wp_nonce_field( 'tweller_flow_2_giveaway_save' ); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="gs-title">Title</label></th><td><input type="text" id="gs-title" name="title" class="regular-text" value="<?php echo esc_attr( $s['title'] ); ?>"></td></tr>
                <tr><th><label for="gs-ends">Entries close</label></th><td><input type="datetime-local" id="gs-ends" name="ends" value="<?php echo esc_attr( str_replace( ' ', 'T', $s['ends'] ) ); ?>"> <span class="tf2-description">Site time. The page shows a live countdown.</span></td></tr>
                <tr><th><label for="gs-gift">Thank-you code for entrants</label></th><td><input type="text" id="gs-gift" name="gift_code" value="<?php echo esc_attr( $s['gift_code'] ); ?>" style="text-transform:uppercase;"> <span class="tf2-description">A coupon from the Coupons page (MEMORY50 = TT$50 off until Dec 15). Leave empty for none.</span></td></tr>
                <tr><th><label for="gs-min">A memory needs at least</label></th><td><input type="number" id="gs-min" name="min_words" min="0" max="60" value="<?php echo esc_attr( $s['min_words'] ); ?>" style="width:70px;"> words <span class="tf2-description">Filters out "😍😍" and "me!" comments.</span></td></tr>
                <tr><th>Bonus tickets</th><td>
                    <label>Tagging someone (Instagram, TikTok): <input type="number" name="tag_bonus" min="0" max="5" value="<?php echo esc_attr( $s['tag_bonus'] ); ?>" style="width:60px;"></label> &nbsp;
                    <label>Sharing the memory on the website: <input type="number" name="web_bonus" min="0" max="5" value="<?php echo esc_attr( $s['web_bonus'] ); ?>" style="width:60px;"></label>
                    <p class="tf2-description">Facebook doesn't allow "tag a friend to enter", so tags never earn tickets there.</p>
                </td></tr>
                <?php foreach ( $plats as $k => $l ) : ?>
                    <tr><th><label for="gs-u-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?> post link</label></th><td><input type="url" id="gs-u-<?php echo esc_attr( $k ); ?>" name="urls[<?php echo esc_attr( $k ); ?>]" class="regular-text" value="<?php echo esc_attr( $s['urls'][ $k ] ?? '' ); ?>" placeholder="https://"> <span class="tf2-description">Shown as a button on the giveaway page.</span></td></tr>
                <?php endforeach; ?>
            </table>
            <p><button type="submit" class="button button-primary">Save</button></p>
        </form>
    </div>
</div>
<?php include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/partials/copy-js.php'; ?>

<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var array $s    TwellerFlow2_Landing::settings() */
/** @var array $meta TwellerFlow2_Meta_Events::settings() */
$page_url  = TwellerFlow2_Booking_Links::link_for( $s['session_key'] );
$pk        = TwellerFlow2_Landing::packages();
$token_src = TwellerFlow2_Meta_Events::token_source();
$thumb = function ( $id ) {
    return $id ? wp_get_attachment_image( (int) $id, 'thumbnail', false, array( 'style' => 'width:64px;height:64px;object-fit:cover;border-radius:6px;' ) ) : '';
};
?>
<div class="wrap tf2-wrap">
    <h1>Christmas Page</h1>
    <p class="tf2-description" style="max-width:720px;">
        The ad landing page at <a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $page_url ); ?></a>.
        Packages and prices are edited in <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-offerings' ) ); ?>">Offerings</a>
        (Christmas Photos offers: <?php echo esc_html( implode( ', ', wp_list_pluck( $pk, 'name' ) ) ?: 'none yet' ); ?>).
    </p>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Saved. The Christmas page is updated.</div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field( 'tweller_flow_2_save_landing' ); ?>
        <input type="hidden" name="tweller_flow_2_save_landing" value="1">

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Page</h2>
            <label style="display:block;margin-bottom:14px;"><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Use the Christmas landing page (off = the normal Book Us page opened on Christmas)</label>
            <div class="tf2-off-grid">
                <label>WhatsApp number (with country code)<input type="text" name="whatsapp" value="<?php echo esc_attr( $s['whatsapp'] ); ?>"></label>
                <label>Call number (with country code)<input type="text" name="phone" value="<?php echo esc_attr( $s['phone'] ); ?>"></label>
                <label>"From" price shown (TTD)<input type="number" step="1" min="0" name="from_price" value="<?php echo esc_attr( $s['from_price'] ); ?>"></label>
                <label>Last shoot date<input type="date" name="last_shoot" value="<?php echo esc_attr( $s['last_shoot'] ); ?>"></label>
                <label>Early-bird ends (end of day)<input type="date" name="early_bird_end" value="<?php echo esc_attr( $s['early_bird_end'] ); ?>"></label>
                <label>Early-bird bonus (blank = none)<input type="text" name="early_bird_text" value="<?php echo esc_attr( $s['early_bird_text'] ); ?>"></label>
            </div>
            <label style="display:block;margin-top:14px;">WhatsApp pre-filled message<input type="text" class="large-text" name="wa_text" value="<?php echo esc_attr( $s['wa_text'] ); ?>"></label>
            <label style="display:block;margin-top:14px;"><input type="checkbox" name="require_confirm" value="1" <?php checked( $s['require_confirm'] ); ?>> Ask visitors to tick "I have confirmed availability with the photographer" before booking.
                <span class="tf2-description" style="display:block;margin-left:24px;">Unticked: the calendar's free slots are taken as available, and an "Ask on WhatsApp" link is shown instead. Fewer steps for ad visitors.</span></label>
        </div>

        <?php $tr = TwellerFlow2_Booking_API::transport(); ?>
        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Transport fee (all bookings)</h2>
            <p class="tf2-description">Every booking form asks "Where's your shoot?": in studio, at home / on location in the central area, or outside it. Outside adds this fee to the total (and the 50% deposit).</p>
            <label style="display:block;margin-bottom:10px;"><input type="checkbox" name="transport_enabled" value="1" <?php checked( $tr['enabled'] ); ?>> Ask where the shoot is and charge the fee</label>
            <div class="tf2-off-grid">
                <label>Fee (TTD)<input type="number" step="1" min="0" name="transport_fee" value="<?php echo esc_attr( $tr['fee'] ); ?>"></label>
            </div>
            <label style="display:block;margin-top:10px;">"Outside the central area means …"<input type="text" class="large-text" name="transport_area" value="<?php echo esc_attr( $tr['area'] ); ?>"></label>
            <label style="display:block;margin-top:10px;">Central area on the map (no fee): one <code>latitude,longitude</code> point per line, in order around the edge. Clients see it shaded; pins outside it pay the fee.
                <textarea class="large-text code" rows="6" name="transport_polygon"><?php echo esc_textarea( $tr['polygon'] ); ?></textarea></label>
        </div>

        <?php $wa = TwellerFlow2_Booking_Alerts::settings(); ?>
        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Booking alerts</h2>
            <p class="tf2-description">Every new online booking is emailed to <?php echo esc_html( get_option( 'admin_email' ) ); ?> and <?php echo esc_html( TwellerFlow2_Notifications::STUDIO_EMAIL ); ?>, with the shoot location, map link and backdrop.
                It can also go to your WhatsApp through the free CallMeBot service: on the phone with this number, follow the steps at
                <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener">callmebot.com</a> (add their number, send them the activation message), then paste the API key they send back.</p>
            <?php if ( isset( $_GET['wa_test'] ) ) : ?>
                <div class="tf2-alert <?php echo $_GET['wa_test'] === 'ok' ? 'tf2-alert--success' : 'tf2-alert--error'; ?>"><?php echo $_GET['wa_test'] === 'ok' ? 'Test message sent — check WhatsApp.' : 'WhatsApp test failed: ' . esc_html( wp_unslash( $_GET['wa_msg'] ?? '' ) ); ?></div>
            <?php endif; ?>
            <label style="display:block;margin-bottom:10px;"><input type="checkbox" name="wa_enabled" value="1" <?php checked( $wa['enabled'] ); ?>> Send a WhatsApp for every new booking</label>
            <div class="tf2-off-grid">
                <label>Your WhatsApp number (with country code)<input type="text" name="wa_phone" value="<?php echo esc_attr( $wa['phone'] ); ?>"></label>
                <label>CallMeBot API key <?php echo $wa['apikey'] ? '(saved, leave blank to keep)' : ''; ?><input type="password" autocomplete="off" name="wa_apikey" value=""></label>
            </div>
            <?php if ( TwellerFlow2_Booking_Alerts::ready() ) : ?>
                <p style="margin:12px 0 0;"><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tweller_flow_2_test_whatsapp' ), 'tweller_flow_2_test_whatsapp' ) ); ?>">Send a test WhatsApp</a></p>
            <?php endif; ?>
        </div>

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Scenes</h2>
            <p class="tf2-description">The first scene opens the hero. Leave the image empty to use the built-in web version. Focus = which part stays in view on phones ("50% 55%" = centre).</p>
            <?php foreach ( $s['scenes'] as $i => $sc ) : ?>
            <div class="tf2-off-row" style="padding:12px;">
                <div class="tf2-off-grid">
                    <label>Name<input type="text" name="scenes[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $sc['name'] ); ?>"></label>
                    <label>Short description<input type="text" name="scenes[<?php echo (int) $i; ?>][desc]" value="<?php echo esc_attr( $sc['desc'] ); ?>"></label>
                    <label>Focus<input type="text" name="scenes[<?php echo (int) $i; ?>][focus]" value="<?php echo esc_attr( $sc['focus'] ); ?>" pattern="\d{1,3}% \d{1,3}%"></label>
                </div>
                <div class="tf2-media" data-multiple="0" style="display:flex;gap:10px;align-items:center;margin-top:8px;">
                    <input type="hidden" name="scenes[<?php echo (int) $i; ?>][image_id]" value="<?php echo (int) $sc['image_id']; ?>">
                    <span class="tf2-media__preview"><?php echo $thumb( $sc['image_id'] ); // phpcs:ignore ?></span>
                    <button type="button" class="button tf2-media__pick">Choose image</button>
                    <button type="button" class="button-link tf2-media__clear">Use built-in</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Recent Christmas sessions</h2>
            <p class="tf2-description">Real client photos (with their permission). The gallery section stays hidden until at least one photo is added; the first two also illustrate "Or keep it real".</p>
            <div class="tf2-media" data-multiple="1" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="gallery_ids" value="<?php echo esc_attr( implode( ',', (array) $s['gallery_ids'] ) ); ?>">
                <span class="tf2-media__preview" style="display:flex;gap:6px;flex-wrap:wrap;"><?php foreach ( (array) $s['gallery_ids'] as $gid ) echo $thumb( $gid ); // phpcs:ignore ?></span>
                <button type="button" class="button tf2-media__pick">Choose photos</button>
                <button type="button" class="button-link tf2-media__clear">Remove all</button>
            </div>
        </div>

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Reviews</h2>
            <p class="tf2-description">Real reviews only, with permission. One per line: <code>Name | Google | Review text</code>. Hidden until at least one is added; three or more look best.</p>
            <textarea name="reviews" rows="6" class="large-text" placeholder="Shanice R. | Google | Our Christmas photos were the best we've ever had…"><?php echo esc_textarea( $s['reviews'] ); ?></textarea>
            <label style="display:block;margin-top:10px;">"Read our reviews" link (blank = the review link in Settings)<input type="url" class="large-text" name="review_url" value="<?php echo esc_attr( $s['review_url'] ); ?>"></label>
        </div>

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">FAQ</h2>
            <p class="tf2-description">Empty a question to remove it. Check the deposit, pets and delivery answers match how you work.</p>
            <?php $faq = array_merge( $s['faq'], array( array( 'q' => '', 'a' => '' ), array( 'q' => '', 'a' => '' ) ) );
            foreach ( $faq as $i => $f ) : ?>
                <div style="margin-bottom:12px;">
                    <input type="text" class="large-text" name="faq[<?php echo (int) $i; ?>][q]" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="Question">
                    <textarea class="large-text" rows="2" name="faq[<?php echo (int) $i; ?>][a]" placeholder="Answer"><?php echo esc_textarea( $f['a'] ); ?></textarea>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Link preview image</h2>
            <p class="tf2-description">Shown when the page is shared (1200×630). Empty = the built-in Christmas scene.</p>
            <div class="tf2-media" data-multiple="0" style="display:flex;gap:10px;align-items:center;">
                <input type="hidden" name="og_image_id" value="<?php echo (int) $s['og_image_id']; ?>">
                <span class="tf2-media__preview"><?php echo $thumb( $s['og_image_id'] ); // phpcs:ignore ?></span>
                <button type="button" class="button tf2-media__pick">Choose image</button>
                <button type="button" class="button-link tf2-media__clear">Use built-in</button>
            </div>
        </div>

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Tracking (Meta pixel &amp; Conversions API)</h2>
            <p class="tf2-description">The booking flow sends ViewContent, AddToCart, Lead (form, WhatsApp, Call), Contact, Schedule and Purchase from the browser <em>and</em> this server with matching event IDs, so Meta counts each once.
                Server events need an access token: Events Manager → your pixel → Settings → Conversions API → Generate access token.</p>
            <div class="tf2-off-grid">
                <label>Pixel ID (blank = <?php echo esc_html( TwellerFlow2_Meta_Events::pixel_id() ); ?>)<input type="text" name="meta_pixel_id" value="<?php echo esc_attr( $meta['pixel_id'] ); ?>"></label>
                <label>Access token <?php echo $meta['access_token'] ? '(saved, leave blank to keep, "-" to clear)' : ''; ?><input type="password" autocomplete="off" name="meta_access_token" value=""></label>
                <label>Test event code (only while testing)<input type="text" name="meta_test_event_code" value="<?php echo esc_attr( $meta['test_event_code'] ); ?>" placeholder="TEST12345"></label>
            </div>
            <label style="display:block;margin-top:12px;"><input type="checkbox" name="meta_server_events" value="1" <?php checked( $meta['server_events'] ); ?>> Send server events (Conversions API)</label>
            <p style="margin:10px 0 0;"><strong>Status:</strong>
                <?php echo TwellerFlow2_Meta_Events::server_enabled()
                    ? 'sending server events (token from ' . esc_html( $token_src ) . ').'
                    : 'browser events only; add a token to send server events.'; ?></p>
        </div>

        <p><button type="submit" class="tf2-btn tf2-btn--primary">Save Christmas page</button></p>
    </form>
</div>
<script>
(function () {
    if (!window.wp || !wp.media) return;
    document.querySelectorAll('.tf2-media').forEach(function (box) {
        var input = box.querySelector('input[type=hidden]');
        var prev = box.querySelector('.tf2-media__preview');
        var multiple = box.getAttribute('data-multiple') === '1';
        var frame;
        box.querySelector('.tf2-media__pick').addEventListener('click', function () {
            if (!frame) {
                frame = wp.media({ title: 'Choose', library: { type: 'image' }, multiple: multiple ? 'add' : false });
                frame.on('open', function () {
                    var sel = frame.state().get('selection');
                    (input.value || '').split(',').filter(Boolean).forEach(function (id) { sel.add(wp.media.attachment(id)); });
                });
                frame.on('select', function () {
                    var items = frame.state().get('selection').toJSON();
                    input.value = items.map(function (a) { return a.id; }).join(',');
                    prev.innerHTML = '';
                    items.forEach(function (a) {
                        var u = (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url);
                        var img = document.createElement('img');
                        img.src = u; img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:6px;';
                        prev.appendChild(img);
                    });
                });
            }
            frame.open();
        });
        box.querySelector('.tf2-media__clear').addEventListener('click', function () { input.value = multiple ? '' : '0'; prev.innerHTML = ''; });
    });
})();
</script>

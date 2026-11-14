<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var array $s settings  @var int $ends  @var int $all_end  @var bool $closed  @var array|null $current  @var array $rounds  @var array $final  @var array|null $gift  @var array $urls */
$plat = TwellerFlow2_Giveaway::platforms();
$font = TWELLER_FLOW_2_PLUGIN_URL . 'public/landing/fonts/';
?>
<style>
@font-face { font-family: "TwCormorant"; src: url(<?php echo esc_url( $font . 'cormorant-garamond-latin-600-normal.woff2' ); ?>) format("woff2"); font-weight: 600; font-display: swap; }
@font-face { font-family: "TwCormorant"; src: url(<?php echo esc_url( $font . 'cormorant-garamond-latin-500-italic.woff2' ); ?>) format("woff2"); font-weight: 500; font-style: italic; font-display: swap; }
.tw-gw { --red: #9A1B23; --green: #173A2E; --gold: #C9A227; --cream: #FBF7EF; --ink: #1E1A16; --muted: #6E655B;
  font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: var(--ink); max-width: 760px; margin: 0 auto; padding: 0 16px 48px; }
.tw-gw * { box-sizing: border-box; }
.tw-gw__hero { margin: 8px 0 28px; padding: 40px 26px 34px; border-radius: 22px; text-align: center; color: #fff;
  background: radial-gradient(120% 90% at 50% 0%, #24543F 0%, var(--green) 55%, #0F2A20 100%); position: relative; overflow: hidden; }
.tw-gw__kicker { font-size: 12px; font-weight: 700; letter-spacing: .24em; text-transform: uppercase; color: #E7C55C; }
.tw-gw .tw-gw__title { color: #fff !important; margin: 12px 0 10px; font: 600 clamp(34px, 7vw, 54px)/1.02 "TwCormorant", Georgia, serif; letter-spacing: -.01em; }
.tw-gw__title em { font-style: italic; font-weight: 500; color: #F3D98A; }
.tw-gw__lead { max-width: 520px; margin: 0 auto; font-size: 16px; line-height: 1.6; color: rgba(255,255,255,.88); }
.tw-gw__count { display: inline-flex; gap: 10px; margin-top: 22px; }
.tw-gw__count div { min-width: 64px; padding: 10px 8px; border-radius: 12px; background: rgba(255,255,255,.1); border: 1px solid rgba(231,197,92,.35); }
.tw-gw__count b { display: block; font: 600 28px/1 "TwCormorant", Georgia, serif; }
.tw-gw__count span { font-size: 10.5px; letter-spacing: .14em; text-transform: uppercase; opacity: .8; }
.tw-gw__prizes { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin: 0 0 30px; }
.tw-gw__prize { padding: 20px; border-radius: 16px; background: var(--cream); border: 1px solid #EBE2CF; }
.tw-gw__prize h3 { margin: 6px 0 6px; font: 600 26px/1.1 "TwCormorant", Georgia, serif; }
.tw-gw__prize p { margin: 0; font-size: 14px; line-height: 1.55; color: var(--muted); }
.tw-gw__tag { display: inline-block; padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; background: var(--red); color: #fff; }
.tw-gw__tag--g { background: var(--green); }
.tw-gw__weeks { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 12px 0 0; }
.tw-gw__week { padding: 12px 14px; border-radius: 12px; border: 1px solid #E6DECF; background: #fff; font-size: 13px; color: var(--muted); }
.tw-gw__week strong { display: block; color: var(--ink); font-size: 15px; }
.tw-gw__week em { display: block; margin-top: 6px; font-style: normal; font-weight: 700; font-size: 11px; letter-spacing: .1em; text-transform: uppercase; }
.tw-gw__week.is-on { border-color: var(--green); box-shadow: 0 0 0 2px rgba(23,58,46,.15); } .tw-gw__week.is-on em { color: var(--green); }
.tw-gw__week.is-over { opacity: .55; }
.tw-gw h2 { color: var(--ink); margin: 34px 0 12px; font: 600 32px/1.1 "TwCormorant", Georgia, serif; }
.tw-gw__steps { list-style: none; margin: 0; padding: 0; counter-reset: s; }
.tw-gw__steps li { position: relative; padding: 0 0 16px 46px; font-size: 15px; line-height: 1.6; counter-increment: s; }
.tw-gw__steps li::before { content: counter(s); position: absolute; left: 0; top: -2px; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; background: var(--green); color: #fff; font-weight: 700; font-size: 14px; }
.tw-gw__plats { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0 4px; }
.tw-gw__plats a { padding: 9px 14px; border-radius: 99px; border: 1px solid #D9CFBC; color: var(--ink); text-decoration: none; font-weight: 600; font-size: 14px; }
.tw-gw__plats a:hover { border-color: var(--green); }
.tw-gw__form { margin-top: 10px; padding: 22px; border-radius: 18px; background: #fff; border: 1px solid #E6DECF; box-shadow: 0 14px 40px rgba(30, 26, 22, .06); }
.tw-gw__form label { display: block; margin: 0 0 6px; font-size: 13.5px; font-weight: 600; }
.tw-gw__form input[type=text], .tw-gw__form input[type=email], .tw-gw__form input[type=tel], .tw-gw__form select, .tw-gw__form textarea {
  width: 100%; margin: 0 0 14px; padding: 12px 13px; border: 1px solid #D9CFBC; border-radius: 11px; font: inherit; font-size: 16px; background: #FFFDF9; color: var(--ink); }
.tw-gw__form textarea { min-height: 140px; resize: vertical; line-height: 1.55; }
.tw-gw__form input:focus, .tw-gw__form select:focus, .tw-gw__form textarea:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(23, 58, 46, .15); }
.tw-gw__row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 560px) { .tw-gw__row { grid-template-columns: 1fr; } }
.tw-gw__check { display: flex !important; gap: 10px; align-items: flex-start; font-weight: 400 !important; font-size: 13.5px !important; line-height: 1.5; color: var(--muted); }
.tw-gw__check input { margin-top: 3px; width: 18px; height: 18px; flex: none; }
.tw-gw__btn { width: 100%; margin-top: 8px; padding: 15px 18px; border: 0; border-radius: 99px; background: var(--red); color: #fff; font: 700 16px/1 Inter, sans-serif; cursor: pointer; min-height: 50px; }
.tw-gw__btn:hover { background: #82161D; }
.tw-gw__btn:disabled { opacity: .6; cursor: wait; }
.tw-gw__msg { margin: 12px 0 0; font-size: 14px; }
.tw-gw__msg--bad { color: #B42318; }
.tw-gw__done { text-align: center; padding: 10px 4px; }
.tw-gw__done h3 { margin: 0 0 8px; font: 600 30px/1.1 "TwCormorant", Georgia, serif; }
.tw-gw__code { display: inline-block; margin: 8px 0 14px; padding: 10px 18px; border: 2px dashed var(--gold); border-radius: 12px; font-size: 22px; font-weight: 800; letter-spacing: .12em; }
.tw-gw__small { font-size: 12.5px; line-height: 1.6; color: var(--muted); }
.tw-gw__hp { position: absolute !important; left: -9999px !important; }
</style>

<div class="tw-gw" id="tw-gw">
    <section class="tw-gw__hero">
        <div class="tw-gw__kicker">Tweller Studios · Christmas 2026</div>
        <h1 class="tw-gw__title"><?php echo esc_html( $s['title'] ); ?></h1>
        <p class="tw-gw__lead">Tell us your <em>most memorable</em> Christmas memory. A <strong>Mini Session</strong> is given away <strong>every week</strong>, and at the end <strong>2 Full Christmas Sessions</strong> are drawn from the last two weeks.</p>
        <?php if ( $current ) : ?><p class="tw-gw__kicker" style="margin-top:16px;"><?php echo esc_html( $current['label'] ); ?> closes in</p><?php endif; ?>
        <?php if ( $ends && ! $closed ) : ?>
            <div class="tw-gw__count" data-ends="<?php echo (int) $ends; ?>" aria-label="Time left to enter this week">
                <div><b data-u="d">0</b><span>days</span></div><div><b data-u="h">0</b><span>hrs</span></div><div><b data-u="m">0</b><span>min</span></div><div><b data-u="s">0</b><span>sec</span></div>
            </div>
        <?php elseif ( $closed ) : ?>
            <p class="tw-gw__lead" style="margin-top:18px;"><strong>The giveaway has ended.</strong> Winners are announced on our pages.</p>
        <?php endif; ?>
    </section>

    <div class="tw-gw__prizes">
        <div class="tw-gw__prize"><span class="tw-gw__tag tw-gw__tag--g">Every week</span><h3>Mini Session</h3><p>One lucky memory is drawn at random each week. Up to 30 minutes, 5 edited images. A new chance every week, so keep entering.</p></div>
        <div class="tw-gw__prize"><span class="tw-gw__tag">Final draw</span><h3>2 Full Christmas Sessions</h3><p>Drawn from the entries of the last two weeks. Up to 1 hour, 10 edited images.</p></div>
    </div>
    <p class="tw-gw__small">Prizes can be used for any type of session within 6 months. <?php echo $final ? 'The final draw covers <strong>' . esc_html( implode( ' + ', wp_list_pluck( $final, 'label' ) ) ) . '</strong>.' : ''; ?></p>
    <?php if ( $rounds ) : ?>
    <div class="tw-gw__weeks">
        <?php foreach ( $rounds as $r ) : $on = $current && $current['id'] === $r['id']; $over = time() >= $r['end_ts']; ?>
            <div class="tw-gw__week<?php echo $on ? ' is-on' : ( $over ? ' is-over' : '' ); ?>"><strong><?php echo esc_html( $r['label'] ); ?></strong><span><?php echo esc_html( wp_date( 'M j', $r['start_ts'] ) . ' – ' . wp_date( 'M j', $r['end_ts'] ) ); ?></span><em><?php echo $on ? 'Open now' : ( $over ? 'Closed' : 'Coming up' ); ?></em></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2>How to enter</h2>
    <ol class="tw-gw__steps">
        <li><strong>Find this week's giveaway post</strong> on Facebook, Instagram or TikTok and follow / like our page.
            <?php if ( array_filter( $urls ) ) : ?><span class="tw-gw__plats"><?php foreach ( $urls as $k => $u ) if ( $u ) echo '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( $plat[ $k ] ) . ' post</a>'; ?></span><?php endif; ?>
        </li>
        <li><strong>Comment your most memorable Christmas memory.</strong> The funny one, the one that still makes you cry, the year everything went wrong. The more real, the better.</li>
        <li><strong>On Instagram and TikTok, tag the person who was there</strong> in your memory for a bonus entry (on Facebook, tagging isn't needed).</li>
        <li><strong>Share it here too</strong> for one more bonus entry (once a week), and get a thank-you gift<?php echo $gift ? ' (' . esc_html( $gift['label'] ) . ' your Christmas photos)' : ''; ?>.</li>
    </ol>

    <h2 id="enter">Share your memory</h2>
    <div class="tw-gw__form">
        <?php if ( $closed ) : ?>
            <p>Entries are closed. Thank you to everyone who shared a memory. ❤️</p>
        <?php else : ?>
        <form id="tw-gw-form" novalidate>
            <div class="tw-gw__row">
                <div><label for="gw-name">Your name *</label><input type="text" id="gw-name" name="name" autocomplete="name" required></div>
                <div><label for="gw-email">Email *</label><input type="email" id="gw-email" name="email" autocomplete="email" required></div>
            </div>
            <div class="tw-gw__row">
                <div><label for="gw-platform">Where did you comment? *</label>
                    <select id="gw-platform" name="platform" required>
                        <option value="">Choose…</option>
                        <?php foreach ( $plat as $k => $l ) echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>'; ?>
                    </select></div>
                <div><label for="gw-handle">Your name or @handle there *</label><input type="text" id="gw-handle" name="handle" placeholder="@yourhandle" required></div>
            </div>
            <label for="gw-phone">WhatsApp (optional, so we can reach you if you win)</label>
            <input type="tel" id="gw-phone" name="phone" autocomplete="tel">
            <label for="gw-memory">Your most memorable Christmas memory *</label>
            <textarea id="gw-memory" name="memory" maxlength="1200" required placeholder="The Christmas when…"></textarea>
            <input class="tw-gw__hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="tw-gw__check"><input type="checkbox" name="share_ok" value="1"> Tweller Studios may share my memory (first name only) on their pages.</label>
            <label class="tw-gw__check"><input type="checkbox" name="rules" value="1" required> I'm 18+, live in Trinidad &amp; Tobago and agree to the rules below.</label>
            <button type="submit" class="tw-gw__btn">Enter the giveaway</button>
            <p class="tw-gw__msg" id="tw-gw-msg" role="status" aria-live="polite"></p>
        </form>
        <div class="tw-gw__done" id="tw-gw-done" hidden>
            <h3>You're in! 🎄</h3>
            <p>Thank you for sharing your memory. Don't forget to comment it on our giveaway post, because that's your main entry.</p>
            <div id="tw-gw-gift" hidden>
                <p style="margin:14px 0 0;">Your thank-you gift: <strong id="tw-gw-gift-label"></strong> your Christmas photos<span id="tw-gw-gift-until"></span>.</p>
                <div class="tw-gw__code" id="tw-gw-gift-code"></div><br>
                <a class="tw-gw__btn" id="tw-gw-gift-link" style="display:inline-block;width:auto;padding:15px 28px;text-decoration:none;" href="#">Book Christmas photos</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <h2>The rules</h2>
    <div class="tw-gw__small">
        <p>No purchase necessary. Open to residents of Trinidad &amp; Tobago aged 18+. The giveaway runs in weekly rounds (<?php echo $rounds ? esc_html( implode( ', ', array_map( function ( $r ) { return $r['label'] . ': ' . wp_date( 'M j g:i a', $r['start_ts'] ) . ' to ' . wp_date( 'M j g:i a', $r['end_ts'] ); }, $rounds ) ) ) : 'dates shown on our posts'; ?>). One main entry per person per platform each week (your memory comment); bonus entries for tagging the person in your memory (Instagram and TikTok) and for sharing your memory on this page.</p>
        <p>Every week one Mini Session is drawn at random from that week's eligible entrants. After the last week, 2 Full Christmas Sessions are drawn from the entries of the last two weeks (one to the most-loved memory, by likes, and one at random). Likes bought or obtained through fake accounts disqualify an entry. A person can win once. Winners are announced on our pages within 48 hours and must reply within 72 hours, or a new winner is drawn.</p>
        <p>Prizes: one Mini Session per week and two Full Christmas Sessions at the end, usable for any session type within 6 months of the draw, subject to availability. Not exchangeable for cash; the transport fee for home or location shoots outside the central area still applies.</p>
        <p>This promotion is in no way sponsored, endorsed, administered by or associated with Facebook, Instagram, Meta or TikTok. By entering you release them from any liability.</p>
    </div>
</div>

<script>
(function () {
    var c = document.querySelector('.tw-gw__count');
    if (c) {
        var end = +c.dataset.ends * 1000, el = function (u) { return c.querySelector('[data-u="' + u + '"]'); };
        var tick = function () {
            var t = Math.max(0, Math.floor((end - Date.now()) / 1000));
            el('d').textContent = Math.floor(t / 86400); el('h').textContent = Math.floor(t % 86400 / 3600);
            el('m').textContent = Math.floor(t % 3600 / 60); el('s').textContent = t % 60;
        };
        tick(); setInterval(tick, 1000);
    }
    var f = document.getElementById('tw-gw-form');
    if (!f) return;
    var msg = document.getElementById('tw-gw-msg');
    f.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!f.checkValidity()) { f.reportValidity(); return; }
        var btn = f.querySelector('button'); btn.disabled = true; msg.textContent = 'Sending…'; msg.className = 'tw-gw__msg';
        var d = {}; new FormData(f).forEach(function (v, k) { d[k] = v; });
        fetch(<?php echo wp_json_encode( rest_url( 'tweller-flow-2/v1/giveaway/enter' ) ); ?>, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (r) {
                if (!r.ok) throw new Error(r.j.message || 'Something went wrong. Please try again.');
                f.hidden = true; document.getElementById('tw-gw-done').hidden = false;
                if (r.j.gift) {
                    document.getElementById('tw-gw-gift').hidden = false;
                    document.getElementById('tw-gw-gift-label').textContent = r.j.gift.label;
                    document.getElementById('tw-gw-gift-code').textContent = r.j.gift.code;
                    document.getElementById('tw-gw-gift-until').textContent = r.j.gift.until ? ', until ' + r.j.gift.until : '';
                    document.getElementById('tw-gw-gift-link').href = r.j.gift.url;
                }
                // A custom event, so giveaway entries never count as booking Leads in the ads.
                if (window.fbq) try { fbq('trackCustom', 'GiveawayEntry', { content_name: 'Christmas Memory Giveaway' }); } catch (x) {}
                document.getElementById('tw-gw-done').scrollIntoView({ behavior: 'smooth', block: 'center' });
            })
            .catch(function (err) { msg.textContent = err.message; msg.className = 'tw-gw__msg tw-gw__msg--bad'; btn.disabled = false; });
    });
})();
</script>

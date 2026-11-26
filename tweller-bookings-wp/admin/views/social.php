<?php if ( ! defined( 'ABSPATH' ) ) exit;
/** @var int $album_id  @var string $filter */
$D      = 'TwellerFlow2_Social_Dashboard';
$all    = $D::albums();
$counts = array( 'ok' => 0, 'free' => 0, 'five' => 0, 'private' => 0 );
foreach ( $all as $a ) { $counts[ $a['group'] ]++; if ( $a['group'] !== 'private' ) $counts['ok']++; }
$tabs   = array( 'ok' => 'OK to post', 'free' => 'Share freely', 'five' => 'Up to 5', 'private' => 'Do not post' );
if ( ! isset( $tabs[ $filter ] ) ) $filter = 'ok';
$sum    = $D::basket_summary();
$brand  = get_bloginfo( 'name' ) ?: 'Tweller Studios';
$handle = strtolower( preg_replace( '/[^A-Za-z0-9_.]/', '', $brand ) );
$avatar = get_site_icon_url( 96 );
$initial = strtoupper( substr( $brand, 0, 1 ) );
$fmt    = function ( $d ) { $t = strtotime( (string) $d ); return $t ? date_i18n( 'M j, Y', $t ) : ''; };
$badge  = array( 'free' => 'Share freely', 'five' => 'Up to 5', 'private' => 'Do not post' );
$counter = function ( $a ) {
    $i = $a['info'];
    if ( $a['group'] === 'private' ) return 'Private · ' . $a['count'] . ' in album';
    if ( $i['limit'] === null ) return 'All ' . $a['count'] . ' can be posted';
    if ( $i['remaining'] === 0 ) return 'All ' . $i['limit'] . ' posted · ' . $a['count'] . ' in album';
    return $i['remaining'] . ' of ' . $i['limit'] . ' can be posted · ' . $a['count'] . ' in album';
};
$album = null;
if ( $album_id ) foreach ( $all as $a ) if ( (int) $a['session']->id === $album_id ) $album = $a;
?>
<style>
.tf2-sp { --ink:#111827; --mut:#6B7280; --line:#E5E7EB; --bg:#F7F7F5; --free:#067647; --free-bg:#ECFDF3; --five:#B54708; --five-bg:#FFF6E5; --priv:#B42318; --priv-bg:#FEF3F2; --acc:#111827;
    max-width: 1280px; margin: 0; padding: 4px 20px 120px 0; color: var(--ink); font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.tf2-sp * { box-sizing: border-box; }
.tf2-sp h1 { margin: 18px 0 4px; font-size: 26px; font-weight: 700; letter-spacing: -.02em; }
.tf2-sp__lede { margin: 0 0 22px; color: var(--mut); font-size: 14px; max-width: 720px; line-height: 1.55; }
.tf2-sp__tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 22px; padding: 4px; background: #fff; border: 1px solid var(--line); border-radius: 12px; width: fit-content; max-width: 100%; }
.tf2-sp__tab { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 9px; color: var(--mut); font-size: 13px; font-weight: 600; text-decoration: none; }
.tf2-sp__tab:hover { color: var(--ink); background: #F3F4F6; }
.tf2-sp__tab.is-on { background: var(--acc); color: #fff; }
.tf2-sp__tab b { min-width: 20px; padding: 1px 7px; border-radius: 99px; background: rgba(0,0,0,.06); font-size: 11.5px; text-align: center; }
.tf2-sp__tab.is-on b { background: rgba(255,255,255,.18); }
.tf2-sp__dot { width: 8px; height: 8px; border-radius: 50%; }
.tf2-sp__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; }
.tf2-sp__card { display: flex; flex-direction: column; background: #fff; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; text-decoration: none; color: inherit;
    transition: transform .18s ease, box-shadow .18s ease; }
.tf2-sp__card:hover { transform: translateY(-2px); box-shadow: 0 12px 28px -12px rgba(17,24,39,.25); color: inherit; }
.tf2-sp__card:focus-visible { outline: 3px solid #93C5FD; outline-offset: 2px; }
.tf2-sp__mosaic { position: relative; display: grid; grid-template-columns: 2fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px; aspect-ratio: 4 / 3; background: #EEE; overflow: hidden; }
.tf2-sp__mosaic img { width: 100%; height: 100%; object-fit: cover; display: block; }
.tf2-sp__mosaic img:first-child { grid-row: 1 / 3; }
.tf2-sp__mosaic--1 { grid-template-columns: 1fr; } .tf2-sp__mosaic--1 img:first-child { grid-row: auto; }
.tf2-sp__mosaic--2 img:nth-child(2) { grid-row: 1 / 3; }
.tf2-sp__card--private .tf2-sp__mosaic img { filter: blur(10px) grayscale(.6); transform: scale(1.1); }
.tf2-sp__lock { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; font-size: 13px; font-weight: 600; background: rgba(17,24,39,.35); }
.tf2-sp__lock span { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 99px; background: rgba(17,24,39,.6); }
.tf2-sp__picked { position: absolute; top: 10px; right: 10px; padding: 4px 10px; border-radius: 99px; background: #111827; color: #fff; font-size: 12px; font-weight: 600; }
.tf2-sp__body { display: grid; gap: 6px; padding: 14px 16px 16px; }
.tf2-sp__row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.tf2-sp__name { margin: 0; font-size: 15px; font-weight: 700; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tf2-sp__meta { margin: 0; font-size: 12.5px; color: var(--mut); }
.tf2-sp__badge { flex: none; padding: 3px 9px; border-radius: 99px; font-size: 11px; font-weight: 700; letter-spacing: .02em; white-space: nowrap; }
.tf2-sp__badge--free { background: var(--free-bg); color: var(--free); }
.tf2-sp__badge--five { background: var(--five-bg); color: var(--five); }
.tf2-sp__badge--private { background: var(--priv-bg); color: var(--priv); }
.tf2-sp__count { display: flex; align-items: center; gap: 8px; margin-top: 4px; font-size: 13px; font-weight: 600; }
.tf2-sp__meter { flex: 1; height: 6px; border-radius: 99px; background: #F3F4F6; overflow: hidden; }
.tf2-sp__meter i { display: block; height: 100%; border-radius: inherit; background: var(--free); }
.tf2-sp__meter--five i { background: #F79009; }
.tf2-sp__empty { padding: 48px 20px; text-align: center; color: var(--mut); background: #fff; border: 1px dashed #D1D5DB; border-radius: 16px; }

/* Album */
.tf2-sp__back { display: inline-flex; margin-top: 14px; font-size: 13px; text-decoration: none; }
.tf2-sp__head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 6px 0 18px; }
.tf2-sp__head h1 { margin: 0 0 6px; }
.tf2-sp__rule { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; color: var(--mut); }
.tf2-sp__acts { display: flex; gap: 8px; flex-wrap: wrap; }
.tf2-sp__btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; padding: 0 16px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink);
    font-family: inherit; font-size: 13px; font-weight: 600; line-height: 1; cursor: pointer; text-decoration: none; white-space: nowrap; }
.tf2-sp__btn:hover { border-color: #9CA3AF; color: var(--ink); }
.tf2-sp__btn--dark { background: var(--acc); border-color: var(--acc); color: #fff; }
.tf2-sp__btn--dark:hover { background: #000; color: #fff; }
.tf2-sp__btn[disabled] { opacity: .45; cursor: not-allowed; }
.tf2-sp__photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; }
.tf2-sp__ph { position: relative; aspect-ratio: 1; border: 0; padding: 0; border-radius: 12px; overflow: hidden; background: #EEE; cursor: pointer; }
.tf2-sp__ph img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .25s ease; }
.tf2-sp__ph:hover img { transform: scale(1.03); }
.tf2-sp__ph::after { content: ""; position: absolute; inset: 0; border-radius: inherit; box-shadow: inset 0 0 0 0 #111827; transition: box-shadow .15s; }
.tf2-sp__ph.is-on::after { box-shadow: inset 0 0 0 4px #111827; }
.tf2-sp__ph:focus-visible { outline: 3px solid #93C5FD; outline-offset: 2px; }
.tf2-sp__tick { position: absolute; top: 8px; right: 8px; z-index: 1; display: grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; background: rgba(255,255,255,.92); border: 2px solid rgba(0,0,0,.18); color: transparent; font-size: 13px; font-weight: 700; }
.tf2-sp__ph.is-on .tf2-sp__tick { background: #111827; border-color: #111827; color: #fff; }
.tf2-sp__posted { position: absolute; left: 8px; bottom: 8px; z-index: 1; padding: 3px 8px; border-radius: 99px; background: rgba(17,24,39,.75); color: #fff; font-size: 11px; font-weight: 600; }
.tf2-sp__ph[disabled] { cursor: not-allowed; } .tf2-sp__ph[disabled] img { filter: blur(8px) grayscale(.6); }

/* Previews */
.tf2-sp__prev { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin: 0 0 26px; padding: 22px; background: var(--bg); border: 1px solid var(--line); border-radius: 16px; }
.tf2-sp__prev[hidden] { display: none; }
.tf2-sp__prev h3 { margin: 0 0 10px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--mut); }
.tf2-sp__cap { grid-column: 1 / -1; display: grid; gap: 6px; font-size: 12px; font-weight: 600; color: var(--mut); }
.tf2-sp__cap textarea { width: 100%; min-height: 64px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; font-family: inherit; font-size: 14px; line-height: 1.5; color: var(--ink); resize: vertical; }
.fbp, .igp { max-width: 500px; background: #fff; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,.12), 0 6px 20px -10px rgba(0,0,0,.2); overflow: hidden; color: #050505; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; }
.fbp__top, .igp__top { display: flex; align-items: center; gap: 10px; padding: 12px 14px 10px; }
.fbp__av, .igp__av { flex: none; width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; background: #111827; color: #fff; font-weight: 700; overflow: hidden; }
.fbp__av img, .igp__av img { width: 100%; height: 100%; object-fit: cover; }
.fbp__who b { display: block; font-size: 15px; } .fbp__who span { font-size: 12.5px; color: #65676B; }
.fbp__text, .igp__cap { padding: 0 14px 10px; font-size: 15px; line-height: 1.35; white-space: pre-wrap; overflow-wrap: anywhere; }
.fbp__media { display: grid; gap: 2px; background: #fff; }
.fbp__media img { width: 100%; height: 100%; object-fit: cover; display: block; }
.fbp__media--1 { grid-template-columns: 1fr; } .fbp__media--1 img { aspect-ratio: 4 / 5; max-height: 560px; }
.fbp__media--2 { grid-template-columns: 1fr 1fr; } .fbp__media--2 img { aspect-ratio: 1 / 2; max-height: 460px; }
.fbp__media--3 { grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; aspect-ratio: 1; } .fbp__media--3 img:first-child { grid-row: 1 / 3; }
.fbp__media--4 { grid-template-columns: 1fr 1fr; aspect-ratio: 1; }
.fbp__more { position: relative; } .fbp__more span { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(0,0,0,.45); color: #fff; font-size: 30px; font-weight: 600; }
.fbp__bar { display: grid; grid-template-columns: repeat(3, 1fr); margin: 0 14px; padding: 4px 0; border-top: 1px solid #CED0D4; color: #65676B; font-size: 14px; font-weight: 600; text-align: center; }
.fbp__bar span { padding: 8px 0; }
.igp__top b { font-size: 14px; } .igp__av { width: 32px; height: 32px; font-size: 13px; background: linear-gradient(45deg, #F58529, #DD2A7B, #8134AF); padding: 2px; }
.igp__av > * { width: 100%; height: 100%; border-radius: 50%; border: 2px solid #fff; background: #111827; display: grid; place-items: center; }
.igp__stage { position: relative; aspect-ratio: 4 / 5; background: #000; overflow: hidden; }
.igp__stage img { width: 100%; height: 100%; object-fit: cover; display: block; }
.igp__n { position: absolute; top: 12px; right: 12px; padding: 3px 9px; border-radius: 99px; background: rgba(0,0,0,.6); color: #fff; font-size: 12px; font-weight: 600; }
.igp__nav { position: absolute; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; border: 0; border-radius: 50%; background: rgba(255,255,255,.85); color: #111; font-size: 16px; line-height: 28px; cursor: pointer; }
.igp__nav--p { left: 10px; } .igp__nav--n { right: 10px; }
.igp__acts { display: flex; align-items: center; gap: 14px; padding: 10px 14px 6px; font-size: 22px; }
.igp__acts .sp { flex: 1; }
.igp__dots { display: flex; justify-content: center; gap: 4px; margin-top: -24px; padding-bottom: 12px; }
.igp__dots i { width: 6px; height: 6px; border-radius: 50%; background: #C7C7C7; } .igp__dots i.on { background: #0095F6; }
.igp__cap b { margin-right: 4px; }

/* Basket bar */
.tf2-sp__bar { position: fixed; left: 160px; right: 0; bottom: 0; z-index: 99; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 28px; background: rgba(17,24,39,.96); color: #fff;
    backdrop-filter: blur(8px); transform: translateY(110%); transition: transform .25s ease; }
.tf2-sp__bar.is-on { transform: none; }
.folded .tf2-sp__bar { left: 36px; }
@media (max-width: 960px) { .tf2-sp__bar { left: 36px; } }
@media (max-width: 782px) { .tf2-sp__bar { left: 0; } .tf2-sp { padding-right: 10px; } }
.tf2-sp__bar b { font-size: 15px; } .tf2-sp__bar small { display: block; color: #D1D5DB; font-size: 12.5px; }
.tf2-sp__bar form { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin: 0; }
.tf2-sp__bar label { display: inline-flex; align-items: center; gap: 6px; color: #E5E7EB; font-size: 13px; }
.tf2-sp__bar .tf2-sp__btn { border-color: rgba(255,255,255,.25); background: transparent; color: #fff; }
.tf2-sp__bar .tf2-sp__btn--light { background: #fff; color: #111827; border-color: #fff; }
.tf2-sp__toast { position: fixed; left: 50%; bottom: 90px; z-index: 100; transform: translate(-50%, 20px); padding: 10px 16px; border-radius: 10px; background: #B42318; color: #fff; font-size: 13px; font-weight: 600; opacity: 0; pointer-events: none; transition: .2s; max-width: 90vw; }
.tf2-sp__toast.is-on { opacity: 1; transform: translate(-50%, 0); }
</style>

<div class="wrap tf2-sp" id="tf2-sp" data-nonce="<?php echo esc_attr( wp_create_nonce( 'tf2_post_basket' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

<?php if ( ! $album ) : /* ───────── Dashboard ───────── */ ?>
    <h1>Ready to post</h1>
    <p class="tf2-sp__lede">Every client's album, sorted by what they agreed to at booking. Open an album to pick photos and preview them on Facebook and Instagram; your picks from every album gather in one download at the bottom.</p>

    <nav class="tf2-sp__tabs" aria-label="Filter albums">
        <?php foreach ( $tabs as $k => $label ) : $dot = array( 'free' => '#12B76A', 'five' => '#F79009', 'private' => '#F04438' )[ $k ] ?? ''; ?>
            <a class="tf2-sp__tab<?php echo $filter === $k ? ' is-on' : ''; ?>" href="<?php echo esc_url( $D::url( array( 'filter' => $k ) ) ); ?>"<?php echo $filter === $k ? ' aria-current="page"' : ''; ?>>
                <?php if ( $dot ) : ?><span class="tf2-sp__dot" style="background:<?php echo esc_attr( $dot ); ?>"></span><?php endif; ?>
                <?php echo esc_html( $label ); ?> <b><?php echo (int) $counts[ $k ]; ?></b></a>
        <?php endforeach; ?>
    </nav>

    <?php $shown = array_filter( $all, function ( $a ) use ( $filter ) { return $filter === 'ok' ? $a['group'] !== 'private' : $a['group'] === $filter; } ); ?>
    <?php if ( ! $shown ) : ?>
        <div class="tf2-sp__empty"><?php echo $filter === 'private' ? 'No private albums.' : 'No albums here yet. Albums appear once photos are uploaded to a client\'s gallery.'; ?></div>
    <?php else : ?>
    <div class="tf2-sp__grid">
        <?php $card_n = 0; foreach ( $shown as $a ) : $card_n++; $s = $a['session']; $i = $a['info']; $nt = count( $a['thumbs'] );
            $pct = $a['group'] === 'five' ? ( $i['limit'] ? round( 100 * $i['remaining'] / $i['limit'] ) : 0 ) : 100; ?>
            <a class="tf2-sp__card tf2-sp__card--<?php echo esc_attr( $a['group'] ); ?>" href="<?php echo esc_url( $D::url( array( 'album' => (int) $s->id, 'filter' => $filter ) ) ); ?>">
                <div class="tf2-sp__mosaic tf2-sp__mosaic--<?php echo (int) min( 3, $nt ); ?>">
                    <?php foreach ( array_slice( $a['thumbs'], 0, 3 ) as $t ) : ?><img src="<?php echo esc_url( $t ); ?>" alt=""<?php echo $card_n > 8 ? ' loading="lazy"' : ''; ?> decoding="async"><?php endforeach; ?>
                    <?php if ( $a['group'] === 'private' ) : ?><div class="tf2-sp__lock"><span>🔒 Keep private</span></div><?php endif; ?>
                    <?php if ( $a['picked'] ) : ?><span class="tf2-sp__picked" data-picked="<?php echo (int) $s->id; ?>"><?php echo (int) $a['picked']; ?> picked</span><?php endif; ?>
                </div>
                <div class="tf2-sp__body">
                    <div class="tf2-sp__row"><p class="tf2-sp__name"><?php echo esc_html( $s->client_name ); ?></p><span class="tf2-sp__badge tf2-sp__badge--<?php echo esc_attr( $a['group'] ); ?>"><?php echo esc_html( $badge[ $a['group'] ] ); ?></span></div>
                    <p class="tf2-sp__meta"><?php echo esc_html( TwellerFlow2_Session::session_type_label( $s ) . ' · ' . $fmt( $s->session_date ) ); ?><?php echo $i['recorded'] ? '' : ' · older booking'; ?></p>
                    <?php if ( $a['group'] !== 'private' ) : ?>
                        <div class="tf2-sp__count"><span><?php echo esc_html( $counter( $a ) ); ?></span></div>
                        <div class="tf2-sp__meter<?php echo $a['group'] === 'five' ? ' tf2-sp__meter--five' : ''; ?>" aria-hidden="true"><i style="width:<?php echo (int) $pct; ?>%"></i></div>
                    <?php else : ?>
                        <div class="tf2-sp__count" style="color:var(--priv)"><span><?php echo (int) $a['count']; ?> photos · client asked not to post</span></div>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php else : /* ───────── One album ───────── */
    $s = $album['session']; $i = $album['info']; $photos = $D::photos( $s );
    $mine = array_map( 'intval', $D::basket()[ (int) $s->id ] ?? array() );
    $priv = $album['group'] === 'private';
?>
    <a class="tf2-sp__back" href="<?php echo esc_url( $D::url( array( 'filter' => $filter ) ) ); ?>">&larr; All albums</a>
    <div class="tf2-sp__head">
        <div>
            <h1><?php echo esc_html( $s->client_name ); ?></h1>
            <div class="tf2-sp__rule">
                <span class="tf2-sp__badge tf2-sp__badge--<?php echo esc_attr( $album['group'] ); ?>"><?php echo esc_html( $badge[ $album['group'] ] ); ?></span>
                <span><?php echo esc_html( TwellerFlow2_Session::session_type_label( $s ) . ' · ' . $fmt( $s->session_date ) . ' · ' . count( $photos ) . ' photos' ); ?></span>
                <?php if ( ! $priv ) : ?><strong id="tf2-sp-left" style="color:var(--ink)"></strong><?php endif; ?>
            </div>
        </div>
        <?php if ( ! $priv ) : ?>
        <div class="tf2-sp__acts">
            <button type="button" class="tf2-sp__btn" id="tf2-sp-clear">Clear this album</button>
            <button type="button" class="tf2-sp__btn tf2-sp__btn--dark" id="tf2-sp-preview" disabled>Preview on Facebook &amp; Instagram</button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( $priv ) : ?>
        <div class="tf2-sp__empty" style="margin-bottom:18px;">🔒 This client chose to keep their session private. Their photos can't be selected for posting.</div>
    <?php endif; ?>

    <section class="tf2-sp__prev" id="tf2-sp-prev" hidden aria-label="Post previews">
        <label class="tf2-sp__cap">Caption (for the preview)<textarea id="tf2-sp-caption">Christmas magic with the <?php echo esc_html( preg_replace( '/^.*\s/', '', trim( $s->client_name ) ) ); ?> family ✨ Book your holiday session at <?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', home_url() ) ); ?></textarea></label>
        <div><h3>Facebook</h3>
            <div class="fbp"><div class="fbp__top"><span class="fbp__av"><?php echo $avatar ? '<img src="' . esc_url( $avatar ) . '" alt="">' : esc_html( $initial ); ?></span><span class="fbp__who"><b><?php echo esc_html( $brand ); ?></b><span>Just now · 🌐</span></span></div>
                <div class="fbp__text" data-cap></div><div class="fbp__media" id="tf2-fb-media"></div>
                <div class="fbp__bar"><span>👍 Like</span><span>💬 Comment</span><span>↗ Share</span></div></div>
        </div>
        <div><h3>Instagram</h3>
            <div class="igp"><div class="igp__top"><span class="igp__av"><span><?php echo $avatar ? '<img src="' . esc_url( $avatar ) . '" alt="">' : esc_html( $initial ); ?></span></span><b><?php echo esc_html( $handle ); ?></b></div>
                <div class="igp__stage" id="tf2-ig-stage"><img id="tf2-ig-img" alt=""><span class="igp__n" id="tf2-ig-n"></span>
                    <button type="button" class="igp__nav igp__nav--p" id="tf2-ig-p" aria-label="Previous">‹</button><button type="button" class="igp__nav igp__nav--n" id="tf2-ig-n2" aria-label="Next">›</button></div>
                <div class="igp__acts"><span>♡</span><span>💬</span><span>➤</span><span class="sp"></span><span>🔖</span></div>
                <div class="igp__dots" id="tf2-ig-dots"></div>
                <div class="igp__cap"><b><?php echo esc_html( $handle ); ?></b><span data-cap></span></div></div>
        </div>
    </section>

    <div class="tf2-sp__photos" id="tf2-sp-photos" data-session="<?php echo (int) $s->id; ?>" data-limit="<?php echo $i['limit'] === null ? '' : (int) $i['limit']; ?>" data-remaining="<?php echo $i['remaining'] === null ? '' : (int) $i['remaining']; ?>">
        <?php foreach ( $photos as $n => $p ) : $on = in_array( $p['id'], $mine, true ); $was = in_array( $p['id'], $i['posted'], true ); ?>
            <button type="button" class="tf2-sp__ph<?php echo $on ? ' is-on' : ''; ?>" data-id="<?php echo (int) $p['id']; ?>" data-full="<?php echo esc_url( $p['full'] ); ?>" aria-pressed="<?php echo $on ? 'true' : 'false'; ?>" aria-label="Photo <?php echo (int) $n + 1; ?>"<?php echo $priv ? ' disabled' : ''; ?>>
                <img src="<?php echo esc_url( $p['thumb'] ); ?>" alt="" loading="lazy" decoding="async">
                <?php if ( ! $priv ) : ?><span class="tf2-sp__tick" aria-hidden="true">✓</span><?php endif; ?>
                <?php if ( $was ) : ?><span class="tf2-sp__posted">Posted</span><?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

    <div class="tf2-sp__bar<?php echo $sum['photos'] ? ' is-on' : ''; ?>" id="tf2-sp-bar" role="region" aria-label="Your picks">
        <div><b id="tf2-sp-bar-n"><?php echo (int) $sum['photos']; ?> photos picked</b><small id="tf2-sp-bar-a">from <?php echo (int) $sum['albums']; ?> album<?php echo $sum['albums'] === 1 ? '' : 's'; ?></small></div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tf2_post_download"><?php wp_nonce_field( 'tf2_post_download' ); ?>
            <label><input type="checkbox" name="mark" value="1" checked> Mark them as posted</label>
            <button type="button" class="tf2-sp__btn" id="tf2-sp-clearall">Clear all</button>
            <button class="tf2-sp__btn tf2-sp__btn--light">Download all</button>
        </form>
    </div>
    <div class="tf2-sp__toast" id="tf2-sp-toast" role="alert"></div>
</div>

<script>
(function () {
    var root = document.getElementById('tf2-sp'); if (!root) return;
    var ajax = root.dataset.ajax, nonce = root.dataset.nonce;
    var grid = document.getElementById('tf2-sp-photos');
    var toast = document.getElementById('tf2-sp-toast'), tt;
    function say(m) { toast.textContent = m; toast.classList.add('is-on'); clearTimeout(tt); tt = setTimeout(function () { toast.classList.remove('is-on'); }, 3200); }
    function post(data) {
        var f = new FormData(); f.append('action', 'tf2_post_basket'); f.append('_ajax_nonce', nonce);
        Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
        return fetch(ajax, { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function bar(s) {
        var b = document.getElementById('tf2-sp-bar');
        document.getElementById('tf2-sp-bar-n').textContent = s.photos + (s.photos === 1 ? ' photo picked' : ' photos picked');
        document.getElementById('tf2-sp-bar-a').textContent = 'from ' + s.albums + (s.albums === 1 ? ' album' : ' albums');
        b.classList.toggle('is-on', s.photos > 0);
    }
    var clearAll = document.getElementById('tf2-sp-clearall');
    clearAll.addEventListener('click', function () { post({ do: 'clear' }).then(function (r) { if (r.success) { bar(r.data); document.querySelectorAll('.tf2-sp__ph.is-on').forEach(function (b) { b.classList.remove('is-on'); b.setAttribute('aria-pressed', 'false'); }); document.querySelectorAll('[data-picked]').forEach(function (e) { e.remove(); }); refresh(); } }); });

    if (!grid) return;
    var sid = grid.dataset.session, limit = grid.dataset.limit, remaining = grid.dataset.remaining;
    var left = document.getElementById('tf2-sp-left'), prevBtn = document.getElementById('tf2-sp-preview'), prev = document.getElementById('tf2-sp-prev');
    function picked() { return Array.prototype.slice.call(grid.querySelectorAll('.tf2-sp__ph.is-on')); }
    function fresh() { return picked().filter(function (b) { return !b.querySelector('.tf2-sp__posted'); }); }
    function refresh() {
        var n = picked().length;
        if (left) left.textContent = limit === '' ? n + ' picked · post as many as you like' : fresh().length + ' of ' + remaining + ' new picks used' + (remaining !== limit ? ' · ' + (limit - remaining) + ' already posted (free to reuse)' : '');
        if (prevBtn) prevBtn.disabled = !n;
        if (!prev.hidden) render();
    }
    grid.addEventListener('click', function (e) {
        var b = e.target.closest('.tf2-sp__ph'); if (!b || b.disabled) return;
        var on = !b.classList.contains('is-on');
        if (on && remaining !== '' && !b.querySelector('.tf2-sp__posted') && fresh().length >= Number(remaining)) { say(Number(remaining) === 0 ? 'This client\'s ' + limit + ' photos have already been posted.' : 'This client allows ' + limit + ' photos. Unselect one to swap it.'); return; }
        b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); refresh();
        post({ do: 'toggle', session: sid, photo: b.dataset.id, on: on ? 1 : 0 }).then(function (r) {
            if (r.success) bar(r.data); else { b.classList.toggle('is-on', !on); b.setAttribute('aria-pressed', !on ? 'true' : 'false'); refresh(); say((r.data && r.data.msg) || 'Could not update.'); }
        });
    });
    document.getElementById('tf2-sp-clear').addEventListener('click', function () {
        post({ do: 'clear', session: sid }).then(function (r) { if (r.success) { bar(r.data); picked().forEach(function (b) { b.classList.remove('is-on'); b.setAttribute('aria-pressed', 'false'); }); refresh(); } });
    });

    // Previews
    var ig = 0;
    function caps() { var t = document.getElementById('tf2-sp-caption').value; document.querySelectorAll('[data-cap]').forEach(function (e) { e.textContent = t; }); }
    function render() {
        var src = picked().map(function (b) { return b.dataset.full; });
        var fb = document.getElementById('tf2-fb-media'), n = src.length, show = src.slice(0, 4);
        fb.className = 'fbp__media fbp__media--' + Math.min(4, Math.max(1, n));
        fb.innerHTML = show.map(function (s, k) { return k === 3 && n > 4 ? '<div class="fbp__more"><img src="' + s + '" alt=""><span>+' + (n - 4) + '</span></div>' : '<img src="' + s + '" alt="">'; }).join('');
        if (ig >= n) ig = 0;
        document.getElementById('tf2-ig-img').src = src[ig] || '';
        document.getElementById('tf2-ig-n').textContent = n > 1 ? (ig + 1) + '/' + n : '';
        document.getElementById('tf2-ig-p').hidden = ig === 0; document.getElementById('tf2-ig-n2').hidden = ig >= n - 1;
        document.getElementById('tf2-ig-dots').innerHTML = n > 1 ? src.map(function (_, k) { return '<i class="' + (k === ig ? 'on' : '') + '"></i>'; }).join('') : '';
        caps();
    }
    document.getElementById('tf2-ig-p').addEventListener('click', function () { ig = Math.max(0, ig - 1); render(); });
    document.getElementById('tf2-ig-n2').addEventListener('click', function () { ig++; render(); });
    document.getElementById('tf2-sp-caption').addEventListener('input', caps);
    if (prevBtn) prevBtn.addEventListener('click', function () { prev.hidden = !prev.hidden; prevBtn.textContent = prev.hidden ? 'Preview on Facebook & Instagram' : 'Hide preview'; if (!prev.hidden) { render(); prev.scrollIntoView({ behavior: 'smooth', block: 'start' }); } });
    refresh();
})();
</script>

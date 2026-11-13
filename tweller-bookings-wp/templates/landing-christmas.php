<?php
/**
 * Christmas landing page — /book-us/christmas-photos/
 * Rendered by TwellerFlow2_Landing::template(). Mobile first.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$L        = 'TwellerFlow2_Landing';
$s        = $L::settings();
$scenes   = $s['scenes'];
$gallery  = $L::gallery();
$reviews  = $L::reviews();
$from     = number_format( (float) $s['from_price'] );
$last     = $L::short_date( $s['last_shoot'] );
$eb_on    = $L::early_bird_active();
$eb_date  = $L::short_date( $s['early_bird_end'] );
$wa       = $L::wa_url();
$tel      = $L::tel_url();
$review_url = $s['review_url'] ?: get_option( 'tweller_flow_2_review_url', '' );
$hero     = $L::scene_images( $scenes[0] );
$font_url = TWELLER_FLOW_2_PLUGIN_URL . 'public/landing/fonts/cormorant-garamond-latin-600-normal.woff2';
$gift_url = add_query_arg( 'buy', 'gift', TwellerFlow2_Booking_Links::page_url() );

$icon_wa  = '<svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="currentColor" d="M12.04 2a9.9 9.9 0 0 0-8.5 15l-1.4 5 5.1-1.3A9.9 9.9 0 1 0 12.04 2Zm0 18.1a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3a8.2 8.2 0 1 1 6.9 3.8Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.5l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>';
$icon_tel = '<svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg>';

// A scene's <picture>: portrait crop for phones, landscape for wider screens.
$picture = function ( $scene, $img, $attrs = array(), $lazy = true ) {
    $slug = sanitize_file_name( $scene['slug'] );
    $portrait = empty( $scene['image_id'] ) ? TWELLER_FLOW_2_PLUGIN_URL . 'public/landing/christmas/' . $slug . '-portrait-900.webp?v=' . TwellerFlow2_Landing::ASSET_VER : '';
    $a = '';
    foreach ( $attrs as $k => $v ) $a .= ' ' . $k . '="' . esc_attr( $v ) . '"';
    $src_attr = $lazy ? 'data-src' : 'src';
    $set_attr = $lazy ? 'data-srcset' : 'srcset';
    $out = '<picture>';
    if ( $portrait ) $out .= '<source media="(max-width: 700px) and (orientation: portrait)" ' . $set_attr . '="' . esc_url( $portrait ) . ' 900w" sizes="100vw">';
    $out .= '<source ' . $set_attr . '="' . esc_url( $img['md'] ) . ' ' . (int) $img['md_w'] . 'w, ' . esc_url( $img['lg'] ) . ' ' . (int) $img['lg_w'] . 'w" sizes="100vw">';
    $out .= '<img ' . $src_attr . '="' . esc_url( $img['md'] ) . '" width="' . (int) $img['lg_w'] . '" height="' . (int) $img['lg_h'] . '" alt="' . esc_attr( $scene['name'] . ' Christmas scene' ) . '" style="object-position:' . esc_attr( $scene['focus'] ) . '"' . $a . '>';
    return $out . '</picture>';
};
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#14110F">
<?php if ( empty( $scenes[0]['image_id'] ) ) : ?>
<link rel="preload" as="image" href="<?php echo esc_url( TWELLER_FLOW_2_PLUGIN_URL . 'public/landing/christmas/' . sanitize_file_name( $scenes[0]['slug'] ) . '-portrait-900.webp?v=' . TwellerFlow2_Landing::ASSET_VER ); ?>" media="(max-width: 700px) and (orientation: portrait)" fetchpriority="high">
<?php endif; ?>
<link rel="preload" as="font" type="font/woff2" href="<?php echo esc_url( $font_url ); ?>" crossorigin>
<?php $L::head(); ?>
</head>
<body <?php body_class( 'tw-xmas' ); ?>>
<?php if ( function_exists( 'wp_body_open' ) ) wp_body_open(); ?>
<a class="tw-skip" href="#packages">Skip to packages</a>

<header class="tw-top">
    <a class="tw-top__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Tweller Studios home">
        <?php
        $logo_id = (int) get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            echo wp_get_attachment_image( $logo_id, 'thumbnail', false, array( 'class' => 'tw-top__logo', 'alt' => 'Tweller Studios', 'loading' => 'eager', 'decoding' => 'async' ) );
        }
        ?>
        <span class="tw-top__name">Tweller Studios</span>
    </a>
    <a class="tw-top__cta" href="#packages" data-scroll>Book now</a>
</header>

<main id="main">

<!-- 1 · Hero -->
<section class="tw-hero" aria-labelledby="tw-hero-title">
    <div class="tw-hero__media" data-hero>
        <?php foreach ( $scenes as $i => $scene ) : ?>
            <div class="tw-hero__slide<?php echo $i === 0 ? ' is-active' : ''; ?>" data-name="<?php echo esc_attr( $scene['name'] ); ?>">
                <?php echo $picture( $scene, $L::scene_images( $scene ), $i === 0 ? array( 'fetchpriority' => 'high', 'decoding' => 'async' ) : array( 'decoding' => 'async' ), $i !== 0 ); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="tw-hero__scene" aria-live="off"><span>Scene</span> <b data-hero-name><?php echo esc_html( $scenes[0]['name'] ); ?></b></p>
    <div class="tw-hero__body">
        <p class="tw-eyebrow">Christmas sessions · Trinidad &amp; Tobago</p>
        <h1 id="tw-hero-title" class="tw-hero__title">Your Christmas photos, in a scene like&nbsp;this.</h1>
        <p class="tw-hero__sub">Studio or at your home <i>•</i> 4 Christmas scenes or your own decor <i>•</i> <strong>Minis from TTD&nbsp;<?php echo esc_html( $from ); ?></strong></p>
        <div class="tw-actions">
            <a class="tw-btn tw-btn--primary" href="#packages" data-scroll>See Packages &amp; Book</a>
            <div class="tw-actions__pair">
                <a class="tw-btn tw-btn--ghost" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener" data-contact="whatsapp"><?php echo $icon_wa; ?> WhatsApp</a>
                <a class="tw-btn tw-btn--ghost" href="<?php echo esc_url( $tel ); ?>" data-contact="call"><?php echo $icon_tel; ?> Call</a>
            </div>
        </div>
        <?php if ( $last ) : ?>
            <p class="tw-hero__deadline">Last shoot date: <strong><?php echo esc_html( $last ); ?></strong><?php if ( $eb_on ) : ?> · Book by <?php echo esc_html( $eb_date ); ?> for <?php echo esc_html( $s['early_bird_text'] ); ?><?php endif; ?></p>
        <?php endif; ?>
    </div>
</section>

<!-- 2 · Pick your scene -->
<section class="tw-sec tw-scenes" aria-labelledby="tw-scenes-title">
    <div class="tw-wrap">
        <p class="tw-kicker">01 · Digital scenes</p>
        <h2 id="tw-scenes-title" class="tw-h2">Pick your scene</h2>
        <p class="tw-lede">Shot on a white backdrop, then we place your family in the scene you choose.</p>
    </div>
    <ul class="tw-scenes__row" role="list">
        <?php foreach ( $scenes as $i => $scene ) : $img = $L::scene_images( $scene ); ?>
        <li class="tw-scene">
            <button type="button" class="tw-scene__btn" data-scene="<?php echo (int) $i; ?>" aria-label="View <?php echo esc_attr( $scene['name'] ); ?> full screen">
                <img src="<?php echo esc_url( $img['sm'] ); ?>" srcset="<?php echo esc_url( $img['sm'] ); ?> <?php echo (int) $img['sm_w']; ?>w, <?php echo esc_url( $img['md'] ); ?> <?php echo (int) $img['md_w']; ?>w" sizes="(min-width: 900px) 45vw, 80vw" width="<?php echo (int) $img['sm_w']; ?>" height="<?php echo (int) $img['sm_h']; ?>" alt="<?php echo esc_attr( $scene['name'] . ' Christmas scene' ); ?>" loading="lazy" decoding="async">
                <span class="tw-scene__expand" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg></span>
            </button>
            <h3 class="tw-scene__name"><?php echo esc_html( $scene['name'] ); ?></h3>
            <p class="tw-scene__desc"><?php echo esc_html( $scene['desc'] ); ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
    <p class="tw-wrap tw-swipe" aria-hidden="true">Swipe to see all 4 →</p>
</section>

<!-- 3 · Or keep it real -->
<section class="tw-sec tw-real" aria-labelledby="tw-real-title">
    <div class="tw-wrap">
        <p class="tw-kicker">02 · Your own setting</p>
        <h2 id="tw-real-title" class="tw-h2">Or keep it real</h2>
        <p class="tw-lede">Prefer the tree you decorated yourselves? We'll come to you.</p>
        <?php if ( $gallery ) : ?>
            <div class="tw-real__photos">
                <?php foreach ( array_slice( $gallery, 0, 2 ) as $g ) echo '<figure>' . $g['img'] . '</figure>'; // phpcs:ignore -- wp_get_attachment_image ?>
            </div>
        <?php endif; ?>
        <div class="tw-real__grid">
            <article class="tw-option">
                <h3 class="tw-option__title">Your own decor, at home</h3>
                <p>We shoot with your tree, your lights and your living room, styled and lit so it looks the way it feels on Christmas morning.</p>
            </article>
            <article class="tw-option">
                <h3 class="tw-option__title">On location</h3>
                <p>A garden, a porch, a place that means something to your family. You choose the spot; we bring the camera and the lighting.</p>
            </article>
        </div>
    </div>
</section>

<!-- 4 · How it works -->
<section class="tw-sec tw-how" aria-labelledby="tw-how-title">
    <div class="tw-wrap">
        <p class="tw-kicker">03 · How it works</p>
        <h2 id="tw-how-title" class="tw-h2">Three easy steps</h2>
        <ol class="tw-steps">
            <li><span class="tw-steps__n">1</span><div><h3>Choose your package &amp; date</h3><p>Pick a time online and secure it with a deposit.</p></div></li>
            <li><span class="tw-steps__n">2</span><div><h3>Shoot in studio or at your home</h3><p>30 minutes to 2 hours, relaxed and guided, little ones included.</p></div></li>
            <li><span class="tw-steps__n">3</span><div><h3>Get your edited photos</h3><p>Delivered to your online gallery, ready for cards, prints and the family chat. Shoot early to have them before Christmas.</p></div></li>
        </ol>
    </div>
</section>

<!-- 5 · Packages + booking -->
<section class="tw-sec tw-packages" id="packages" aria-labelledby="tw-pk-title" tabindex="-1">
    <div class="tw-wrap">
        <p class="tw-kicker">04 · Packages</p>
        <h2 id="tw-pk-title" class="tw-h2">Choose your package</h2>
        <p class="tw-lede">Every package: studio or on location · digital scene or your own decor.</p>

        <?php if ( $eb_on ) : ?>
        <div class="tw-early" data-countdown="<?php echo esc_attr( $L::early_bird_iso() ); ?>">
            <p class="tw-early__offer"><span class="tw-early__tag">Early bird</span> Book before <?php echo esc_html( $eb_date ); ?>: <strong><?php echo esc_html( $s['early_bird_text'] ); ?></strong></p>
            <p class="tw-early__clock" aria-live="off"><span data-cd="d">--</span>d <span data-cd="h">--</span>h <span data-cd="m">--</span>m <span data-cd="s">--</span>s left</p>
        </div>
        <?php endif; ?>

        <p class="tw-deposit">A 50% deposit secures your date. The balance is due on the day of your shoot.</p>

        <?php
        echo do_shortcode( sprintf(
            '[tweller_booking session="%s" lock="1" max_date="%s" card_cta="%s" submit="%s" confirm="%s"]',
            esc_attr( TwellerFlow2_Booking_Links::slug_for( $s['session_key'] ) ),
            esc_attr( $s['last_shoot'] ),
            'Book & secure with deposit',
            'Book & secure with deposit',
            empty( $s['require_confirm'] ) ? '0' : '1'
        ) );
        ?>
    </div>
</section>

<?php if ( $gallery ) : ?>
<!-- 6 · Recent Christmas sessions (shows once photos are added in Tweller Bookings → Christmas Page) -->
<section class="tw-sec tw-gallery" aria-labelledby="tw-gallery-title">
    <div class="tw-wrap">
        <p class="tw-kicker">Real families</p>
        <h2 id="tw-gallery-title" class="tw-h2">Recent Christmas sessions</h2>
        <div class="tw-gallery__grid">
            <?php foreach ( $gallery as $g ) : ?>
                <figure class="tw-gallery__item"><?php echo $g['img']; // phpcs:ignore -- wp_get_attachment_image ?></figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- 7 · Trust -->
<section class="tw-sec tw-trust" aria-labelledby="tw-trust-title">
    <div class="tw-wrap">
        <p class="tw-trust__years"><span>10</span> years</p>
        <h2 id="tw-trust-title" class="tw-h2">photographing T&amp;T families</h2>
        <?php if ( $reviews ) : ?>
        <div class="tw-reviews">
            <?php foreach ( $reviews as $r ) : ?>
            <figure class="tw-review">
                <div class="tw-review__stars" aria-label="5 stars">★★★★★</div>
                <blockquote><p><?php echo esc_html( $r['text'] ); ?></p></blockquote>
                <figcaption><?php echo esc_html( $r['name'] ); ?><?php if ( $r['source'] ) : ?> <span>· <?php echo esc_html( $r['source'] ); ?></span><?php endif; ?></figcaption>
            </figure>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ( $review_url ) : ?>
            <p class="tw-trust__link"><a href="<?php echo esc_url( preg_replace( '#/review/?$#', '', $review_url ) ); ?>" target="_blank" rel="noopener">Read our reviews on Google →</a></p>
        <?php endif; ?>
    </div>
</section>

<!-- 8 · FAQ -->
<section class="tw-sec tw-faq" aria-labelledby="tw-faq-title">
    <div class="tw-wrap tw-wrap--narrow">
        <h2 id="tw-faq-title" class="tw-h2">Questions</h2>
        <?php foreach ( $s['faq'] as $f ) : ?>
        <details class="tw-q">
            <summary><?php echo esc_html( $f['q'] ); ?></summary>
            <div class="tw-q__a"><?php echo wpautop( esc_html( $f['a'] ) ); ?>
                <?php if ( stripos( $f['q'], 'gift' ) !== false ) : ?><p><a href="<?php echo esc_url( $gift_url ); ?>">Buy a gift card →</a></p><?php endif; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
</section>

<!-- 9 · Final call to action -->
<section class="tw-final" aria-labelledby="tw-final-title">
    <div class="tw-final__media"><?php $fs = $scenes[1] ?? $scenes[0]; echo $picture( $fs, $L::scene_images( $fs ), array( 'decoding' => 'async' ), true ); ?></div>
    <div class="tw-wrap tw-final__body">
        <h2 id="tw-final-title" class="tw-final__title">This year, the Christmas photo you'll actually frame.</h2>
        <p class="tw-final__sub">Minis from TTD <?php echo esc_html( $from ); ?><?php if ( $last ) : ?> · Last shoot date <?php echo esc_html( $last ); ?><?php endif; ?></p>
        <div class="tw-actions">
            <a class="tw-btn tw-btn--primary" href="#packages" data-scroll>See Packages &amp; Book</a>
            <div class="tw-actions__pair">
                <a class="tw-btn tw-btn--ghost" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener" data-contact="whatsapp"><?php echo $icon_wa; ?> WhatsApp</a>
                <a class="tw-btn tw-btn--ghost" href="<?php echo esc_url( $tel ); ?>" data-contact="call"><?php echo $icon_tel; ?> Call</a>
            </div>
        </div>
    </div>
</section>

</main>

<footer class="tw-foot">
    <p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> Tweller Studios · <a href="<?php echo esc_url( $tel ); ?>" data-contact="call"><?php echo esc_html( $L::phone_display() ); ?></a> · <a href="<?php echo esc_url( $gift_url ); ?>">Gift cards</a></p>
</footer>

<!-- 10 · Sticky bar (phones), shown once the hero is out of view -->
<nav class="tw-sticky" aria-label="Book or contact" data-sticky hidden>
    <a class="tw-sticky__book" href="#packages" data-scroll>Book Now</a>
    <a class="tw-sticky__icon" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener" data-contact="whatsapp"><?php echo $icon_wa; ?><span>WhatsApp</span></a>
    <a class="tw-sticky__icon" href="<?php echo esc_url( $tel ); ?>" data-contact="call"><?php echo $icon_tel; ?><span>Call</span></a>
</nav>

<!-- Full-screen scene viewer -->
<dialog class="tw-viewer" data-viewer aria-label="Christmas scene">
    <div class="tw-viewer__frame">
        <img data-viewer-img alt="" width="1600" height="1067">
    </div>
    <div class="tw-viewer__bar">
        <button type="button" class="tw-viewer__nav" data-viewer-prev aria-label="Previous scene">←</button>
        <p class="tw-viewer__name" data-viewer-name></p>
        <button type="button" class="tw-viewer__nav" data-viewer-next aria-label="Next scene">→</button>
    </div>
    <div class="tw-viewer__actions">
        <button type="button" class="tw-btn tw-btn--primary" data-viewer-book>Book this scene</button>
        <button type="button" class="tw-viewer__close" data-viewer-close aria-label="Close">Close</button>
    </div>
</dialog>
<script type="application/json" id="tw-scenes-data"><?php
    echo wp_json_encode( array_map( function ( $sc ) use ( $L ) {
        $im = $L::scene_images( $sc );
        return array( 'name' => $sc['name'], 'src' => $im['lg'] );
    }, $scenes ) );
?></script>

<?php wp_footer(); ?>
</body>
</html>

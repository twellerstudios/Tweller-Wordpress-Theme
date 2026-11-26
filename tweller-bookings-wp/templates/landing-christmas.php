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
?><?php get_header(); ?>
<div class="tw-xmas tw-theme-<?php echo esc_attr( $L::current_theme() ); ?>" id="tw-xmas">
<a class="tw-skip" href="#packages">Skip to packages</a>

<div class="tw-main">


<!-- 1 · Hero -->
<section class="tw-hero" aria-labelledby="tw-hero-title">
    <div class="tw-hero__media" data-hero>
        <?php foreach ( $scenes as $i => $scene ) : ?>
            <div class="tw-hero__slide<?php echo $i === 0 ? ' is-active' : ''; ?>" data-name="<?php echo esc_attr( $scene['name'] ); ?>">
                <?php echo $picture( $scene, $L::scene_images( $scene ), $i === 0 ? array( 'fetchpriority' => 'high', 'decoding' => 'async' ) : array( 'decoding' => 'async' ), $i !== 0 ); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="tw-hero__scene" aria-live="off"><span>Digital scene</span> <b data-hero-name><?php echo esc_html( $scenes[0]['name'] ); ?></b></p>
    <div class="tw-hero__body">
        <p class="tw-eyebrow">Digital Christmas scenes · Trinidad &amp; Tobago</p>
        <h1 id="tw-hero-title" class="tw-hero__title">Your Christmas photos, in a scene like&nbsp;this.</h1>
        <p class="tw-hero__sub">Studio or at your home <i>•</i> 4 digital Christmas scenes or your own decor <i>•</i> <strong>Minis from TTD&nbsp;<?php echo esc_html( $from ); ?></strong></p>
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

<!-- Before & after (web page only) -->
<?php $ba = $L::before_after(); if ( $ba ) : ?>
<section class="tw-sec tw-ba" aria-labelledby="tw-ba-title">
    <div class="tw-wrap">
        <p class="tw-kicker">What to expect</p>
        <h2 id="tw-ba-title" class="tw-h2"><span class="tw-h2__t">Original &amp; after</span></h2>
        <p class="tw-lede">Real Tweller Studios sessions. Drag the slider: on the left, the original photo as we shot it; on the right, the finished photo with the digital backdrop placed in. Only the background changes. You are not altered, so your photos stay authentic.</p>
        <ul class="tw-auth" aria-label="What changes and what doesn't">
            <li>Backgrounds done professionally</li>
            <li>Only the background changes</li>
            <li>No changes to faces or bodies</li>
            <li>Authentic, natural you</li>
        </ul>
        <?php
        $ba_cats = array();
        foreach ( $ba as $pair ) { $ba_cats[ $pair['cat'] ] = array( 'label' => $pair['label'], 'n' => ( $ba_cats[ $pair['cat'] ]['n'] ?? 0 ) + 1 ); }
        ?>
        <div class="tw-ba__tabs" role="group" aria-label="Filter by backdrop">
            <button type="button" class="tw-ba__tab is-active" aria-pressed="true" data-ba-filter="all">All <span><?php echo (int) count( $ba ); ?></span></button>
            <?php foreach ( $ba_cats as $slug => $c ) : ?>
                <button type="button" class="tw-ba__tab" aria-pressed="false" data-ba-filter="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $c['label'] ); ?> <span><?php echo (int) $c['n']; ?></span></button>
            <?php endforeach; ?>
        </div>
        <div class="tw-ba__list" data-ba-list>
            <?php foreach ( $ba as $i => $pair ) : ?>
            <article class="tw-ba__item" data-cat="<?php echo esc_attr( $pair['cat'] ); ?>">
                <div class="tw-ba__inner"><div class="tw-ba__body">
                    <header class="tw-ba__head">
                        <?php if ( $pair['thumb'] ) : ?><img class="tw-ba__scene" src="<?php echo esc_url( $pair['thumb'] ); ?>" alt="" width="72" height="48" loading="lazy" decoding="async"><?php endif; ?>
                        <div class="tw-ba__title">
                            <h3 class="tw-ba__name"><?php echo esc_html( $pair['label'] ); ?></h3>
                            <p class="tw-ba__sub">Digital backdrop &middot; photographed on white</p>
                        </div>
                    </header>
                    <div class="tw-compare" data-compare style="--pos: 50%; --ar: <?php echo esc_attr( round( $pair['w'] / $pair['h'], 4 ) ); ?>; aspect-ratio: <?php echo (int) $pair['w']; ?> / <?php echo (int) $pair['h']; ?>;">
                        <img class="tw-compare__after" src="<?php echo esc_url( $pair['after'] ); ?>" width="<?php echo (int) $pair['w']; ?>" height="<?php echo (int) $pair['h']; ?>" alt="<?php echo esc_attr( 'After: finished photo with the ' . $pair['label'] . ' digital backdrop' ); ?>" loading="lazy" decoding="async">
                        <img class="tw-compare__before" src="<?php echo esc_url( $pair['before'] ); ?>" width="<?php echo (int) $pair['w']; ?>" height="<?php echo (int) $pair['h']; ?>" alt="Original: the photo as shot in studio" loading="lazy" decoding="async">
                        <span class="tw-compare__tag tw-compare__tag--before">Original</span>
                        <span class="tw-compare__tag tw-compare__tag--after">After &middot; <?php echo esc_html( $pair['label'] ); ?></span>
                        <span class="tw-compare__line" aria-hidden="true"><span class="tw-compare__knob"><svg viewBox="0 0 24 24" width="22" height="22"><path d="M9 6l-5 6 5 6M15 6l5 6-5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span></span>
                        <input class="tw-compare__range" type="range" min="0" max="100" value="50" aria-label="<?php echo esc_attr( 'Drag to compare original and after: ' . $pair['label'] ); ?>">
                    </div>
                    <?php if ( $pair['pick'] ) : ?><div class="tw-ba__foot"><button type="button" class="tw-slide__pick tw-ba__pick" data-pick="<?php echo esc_attr( $pair['pick'] ); ?>">Choose this backdrop</button></div><?php endif; ?>
                </div></div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php if ( count( $ba ) > 2 ) : ?>
        <div class="tw-ba__more" data-ba-more hidden>
            <button type="button" class="tw-btn tw-btn--ghost tw-ba__morebtn" aria-expanded="false" data-ba-morebtn><span data-ba-moretext>Show more sets</span> <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- 2 · Pick your scene -->
<section class="tw-sec tw-scenes" aria-labelledby="tw-scenes-title">
    <div class="tw-wrap">
        <p class="tw-kicker">Digital backdrops</p>
        <h2 id="tw-scenes-title" class="tw-h2"><span class="tw-h2__t">Pick your digital scene</span></h2>
        <p class="tw-lede">These are digital backdrops: we photograph your family on a white backdrop, then place the backdrop you choose in. Only the background changes; you stay exactly as photographed.</p>
        <ol class="tw-digital" aria-label="How digital backdrops work">
            <li><span class="tw-digital__n">1</span><b>We photograph you on white</b><small>In studio, or at your home with our portable backdrop</small></li>
            <li><span class="tw-digital__n">2</span><b>You choose your scene</b><small>Any of the 4 below, or mix several (TT$<?php echo esc_html( class_exists( 'TwellerFlow2_Backdrop_Edits' ) ? number_format( TwellerFlow2_Backdrop_Edits::price() ) : '50' ); ?> per extra scene<?php echo $eb_on ? ', the first one free if you book early' : ''; ?>)</small></li>
            <li><span class="tw-digital__n">3</span><b>We place the backdrop in</b><small>Light and colour matched so it looks natural</small></li>
        </ol>
    </div>
    <div class="tw-wrap">
    <div class="tw-slider" data-slider aria-roledescription="carousel" aria-label="Digital Christmas backdrops">
        <div class="tw-slider__track" data-track tabindex="0">
            <?php foreach ( $scenes as $i => $scene ) : $img = $L::scene_images( $scene ); ?>
            <figure class="tw-slide" aria-roledescription="slide" aria-label="<?php echo esc_attr( ( $i + 1 ) . ' of ' . count( $scenes ) . ': ' . $scene['name'] ); ?>">
                <button type="button" class="tw-slide__img" data-scene="<?php echo (int) $i; ?>" aria-label="View <?php echo esc_attr( $scene['name'] ); ?> full screen">
                    <img src="<?php echo esc_url( $img['md'] ); ?>" srcset="<?php echo esc_url( $img['md'] ); ?> <?php echo (int) $img['md_w']; ?>w, <?php echo esc_url( $img['lg'] ); ?> <?php echo (int) $img['lg_w']; ?>w" sizes="(min-width: 1120px) 1060px, 100vw" width="<?php echo (int) $img['lg_w']; ?>" height="<?php echo (int) $img['lg_h']; ?>" alt="<?php echo esc_attr( $scene['name'] . ' digital Christmas backdrop' ); ?>" loading="lazy" decoding="async">
                    <span class="tw-scene__badge">Digital backdrop</span>
                    <span class="tw-scene__expand" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg></span>
                </button>
                <figcaption class="tw-slide__cap">
                    <span class="tw-slide__no"><?php echo esc_html( sprintf( '%02d / %02d', $i + 1, count( $scenes ) ) ); ?></span>
                    <b class="tw-slide__name"><?php echo esc_html( $scene['name'] ); ?></b>
                    <span class="tw-slide__desc"><?php echo esc_html( $scene['desc'] ); ?></span>
                    <button type="button" class="tw-slide__pick" data-pick="<?php echo esc_attr( sanitize_key( $scene['slug'] ) ); ?>">Choose this backdrop</button>
                </figcaption>
            </figure>
            <?php endforeach; ?>
        </div>
        <button type="button" class="tw-slider__arrow tw-slider__arrow--prev" data-prev aria-label="Previous backdrop"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        <button type="button" class="tw-slider__arrow tw-slider__arrow--next" data-next aria-label="Next backdrop"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        <div class="tw-slider__thumbs" role="tablist" aria-label="Choose a backdrop to view">
            <?php foreach ( $scenes as $i => $scene ) : $img = $L::scene_images( $scene ); ?>
            <button type="button" class="tw-thumb<?php echo $i === 0 ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>" data-go="<?php echo (int) $i; ?>">
                <img src="<?php echo esc_url( $img['sm'] ); ?>" width="<?php echo (int) $img['sm_w']; ?>" height="<?php echo (int) $img['sm_h']; ?>" alt="" loading="lazy" decoding="async">
                <span><?php echo esc_html( $scene['name'] ); ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    </div>
</section>

<!-- 2b · More than one scene · Custom created backdrops -->
<?php
$bd_price  = class_exists( 'TwellerFlow2_Backdrop_Edits' ) ? number_format( TwellerFlow2_Backdrop_Edits::price() ) : '50';
$custom_wa = 'https://wa.me/' . preg_replace( '/\D/', '', $s['whatsapp'] ) . '?text=' . rawurlencode( "Hi Tweller Studios! I'd love a custom Christmas backdrop. My idea: " );
?>
<section class="tw-sec tw-more" aria-labelledby="tw-more-title">
    <div class="tw-wrap">
        <p class="tw-kicker">Make it yours</p>
        <h2 id="tw-more-title" class="tw-h2"><span class="tw-h2__t">Love more than one scene?</span></h2>
        <p class="tw-lede">Mix your backdrops in one session. Add any digital scene for TT$<?php echo esc_html( $bd_price ); ?>, then choose which backdrop goes on which photo when you pick your favourites.</p>
        <div class="tw-more__grid">
            <article class="tw-option tw-more__card">
                <div class="tw-more__fan" aria-hidden="true">
                    <?php foreach ( array_slice( $scenes, 0, 3 ) as $sc ) : $im = $L::scene_images( $sc ); ?>
                        <img src="<?php echo esc_url( $im['sm'] ); ?>" width="<?php echo (int) $im['sm_w']; ?>" height="<?php echo (int) $im['sm_h']; ?>" alt="" loading="lazy" decoding="async">
                    <?php endforeach; ?>
                </div>
                <h3 class="tw-option__title">Mix your scenes</h3>
                <p class="tw-more__price"><b>+TT$<?php echo esc_html( $bd_price ); ?></b> per extra digital backdrop</p>
                <?php $n1 = $scenes[0]['name'] ?? ''; $n2 = $scenes[1]['name'] ?? ''; ?>
                <p><?php if ( $n1 && $n2 ) : ?>The family portrait by the <?php echo esc_html( $n1 ); ?>, the little ones in the <?php echo esc_html( $n2 ); ?>. <?php endif; ?>Add as many scenes as you like when you book, then choose them photo by photo when you pick your favourites.</p>
                <?php if ( $eb_on ) : ?>
                    <p class="tw-more__eb"><span>Early bird</span> Book by <?php echo esc_html( $eb_date ); ?> and your first extra scene is free.</p>
                <?php endif; ?>
                <a class="tw-btn tw-btn--primary tw-more__cta" href="#packages" data-scroll>Choose your scenes</a>
            </article>
            <article class="tw-option tw-more__card tw-more__card--custom">
                <div class="tw-more__mark" aria-hidden="true">
                    <svg viewBox="0 0 48 48" width="44" height="44"><path d="M24 5l3.6 10.4L38 19l-10.4 3.6L24 33l-3.6-10.4L10 19l10.4-3.6z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M38 31l1.6 4.4L44 37l-4.4 1.6L38 43l-1.6-4.4L32 37l4.4-1.6z" fill="currentColor"/><circle cx="10" cy="38" r="2.4" fill="currentColor"/></svg>
                </div>
                <p class="tw-more__new">New</p>
                <h3 class="tw-option__title">Custom created backdrops</h3>
                <p>Not seeing a backdrop you love? Tell us your idea and we'll create one just for you: a snowy cabin, your family's colours, a corner of your own Christmas traditions. <em>Bring your ideas to life.</em></p>
                <div class="tw-more__btns">
                    <a class="tw-btn tw-more__btn" href="<?php echo esc_url( $custom_wa ); ?>" target="_blank" rel="noopener" data-contact="whatsapp"><?php echo $icon_wa; // phpcs:ignore ?> Share your idea</a>
                    <a class="tw-more__link" href="#packages" data-scroll>or add it when you book</a>
                </div>
                <p class="tw-more__fine">We'll reply with a quote and a preview before we create it.</p>
            </article>
        </div>
    </div>
</section>

<!-- 3 · Or keep it home -->
<section class="tw-sec tw-real" aria-labelledby="tw-real-title">
    <div class="tw-wrap">
        <p class="tw-kicker">Your own setting</p>
        <h2 id="tw-real-title" class="tw-h2"><span class="tw-h2__t">Or keep it home</span></h2>
        <p class="tw-lede">Love the tree you decorated together? We'll bring the camera and lights to you and photograph your family at home, with your own decor.</p>
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
        <p class="tw-kicker">How it works</p>
        <h2 id="tw-how-title" class="tw-h2"><span class="tw-h2__t">Three easy steps</span></h2>
        <ol class="tw-steps">
            <li><span class="tw-steps__n">1</span><div><h3>Choose your package, date &amp; scene</h3><p>Pick a time and your backdrop online, and secure it with a deposit.</p></div></li>
            <li><span class="tw-steps__n">2</span><div><h3>Shoot in studio or at your home</h3><p>30 minutes to 2 hours, relaxed and guided, little ones included.</p></div></li>
            <li><span class="tw-steps__n">3</span><div><h3>We place your backdrop in</h3><p>Your digital Christmas scene, finished and delivered to your online gallery, ready for cards, prints and the family chat. Shoot early to have them before Christmas.</p></div></li>
        </ol>
    </div>
</section>

<!-- 5 · Packages + booking -->
<section class="tw-sec tw-packages" id="packages" aria-labelledby="tw-pk-title" tabindex="-1">
    <div class="tw-wrap">
        <p class="tw-kicker">Packages</p>
        <h2 id="tw-pk-title" class="tw-h2"><span class="tw-h2__t">Choose your package</span></h2>
        <p class="tw-lede">Every package: studio or on location · digital scene or your own decor.</p>

        <?php if ( $eb_on ) : ?>
        <div class="tw-early" data-countdown="<?php echo esc_attr( $L::early_bird_iso() ); ?>">
            <p class="tw-early__offer"><span class="tw-early__tag">Early bird</span> Book before <?php echo esc_html( $eb_date ); ?>: <strong><?php echo esc_html( $s['early_bird_text'] ); ?></strong></p>
            <p class="tw-early__clock" aria-live="off"><span data-cd="d">--</span>d <span data-cd="h">--</span>h <span data-cd="m">--</span>m <span data-cd="s">--</span>s left</p>
        </div>
        <?php endif; ?>

        <?php $tr = TwellerFlow2_Booking_API::transport(); ?>
        <p class="tw-deposit">A 50% deposit secures your date. The balance is due on the day of your shoot.<?php
            if ( ! empty( $tr['enabled'] ) && (float) $tr['fee'] > 0 ) :
                echo ' ' . esc_html( sprintf( 'Home and location shoots %s add TT$%s transport.', $tr['area'], number_format( (float) $tr['fee'] ) ) );
            endif; ?></p>

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
        <h2 id="tw-gallery-title" class="tw-h2"><span class="tw-h2__t">Recent Christmas sessions</span></h2>
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
        <h2 id="tw-trust-title" class="tw-h2"><span class="tw-h2__t">photographing T&amp;T families</span></h2>
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
        <h2 id="tw-faq-title" class="tw-h2"><span class="tw-h2__t">Questions</span></h2>
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

</div><!-- /.tw-main -->

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
        <p class="tw-viewer__name"><span data-viewer-name></span><small>Digital backdrop: we photograph you on white, then place this backdrop in</small></p>
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
        return array( 'key' => sanitize_key( $sc['slug'] ), 'name' => $sc['name'], 'src' => $im['lg'] );
    }, $scenes ) );
?></script>

</div><!-- /.tw-xmas -->
<?php get_footer(); ?>

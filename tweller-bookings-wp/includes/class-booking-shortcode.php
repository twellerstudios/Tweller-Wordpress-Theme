<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Booking_Shortcode {

    public static function init() {
        add_shortcode( 'tweller_booking', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets() {
        // We only enqueue if the shortcode is present on the page, but WP sometimes needs it registered early.
        wp_register_style(
            'tweller-flow-2-booking',
            TWELLER_FLOW_2_PLUGIN_URL . 'public/css/booking.css',
            array(),
            TWELLER_FLOW_2_VERSION
        );
        wp_register_script(
            'tweller-flow-2-booking',
            TWELLER_FLOW_2_PLUGIN_URL . 'public/js/booking.js',
            array('jquery'),
            TWELLER_FLOW_2_VERSION,
            true
        );
        
        $session_types = get_option('tweller_flow_2_session_types');
        if ( empty( $session_types ) ) {
            TwellerFlow2_Database::seed_defaults();
            $session_types = get_option('tweller_flow_2_session_types', array());
        }
        $packages = get_option('tweller_flow_2_packages', array());
        
        // Per-session links (/book-us/grads/full/): the page opens on the
        // session (and package) the link names, and the address bar follows
        // every pick so whatever is on screen can be copied and sent.
        $links = array();
        $pkg_links = array();
        foreach ( (array) $session_types as $st_key => $st ) {
            $links[ $st_key ] = TwellerFlow2_Booking_Links::link_for( $st_key );
            foreach ( (array) ( $st['allowed_packages'] ?? array() ) as $pk_key ) {
                $pkg_links[ $st_key ][ $pk_key ] = TwellerFlow2_Booking_Links::link_for( $st_key, $pk_key );
            }
        }

        wp_localize_script( 'tweller-flow-2-booking', 'twellerBooking', array(
            'restUrl' => rest_url( 'tweller-flow-2/v1/' ),
            'packages' => $packages,
            'sessionTypes' => $session_types,
            'trackerUrl' => get_option( 'tweller_flow_2_tracker_page', '' ),
            'bookingUrl' => TwellerFlow2_Booking_Links::page_url(),
            'links' => $links,
            'packageLinks' => $pkg_links,
            'preselect' => TwellerFlow2_Booking_Links::requested_key(),
            'preselectPackage' => TwellerFlow2_Booking_Links::requested_package(),
            'gift' => array( 'enabled' => self::gifts_on() ),
            // "Only 7 left" counters by session type (Limited Spots), real counts.
            'scarcity' => class_exists( 'TwellerFlow2_Scarcity' ) ? TwellerFlow2_Scarcity::public_map() : array(),
            // Visual backdrop picker per session type (Christmas scenes…),
            // supplied by TwellerFlow2_Landing; empty = no picker.
            'backdrops' => self::backdrops(),
            // Session types with their own landing page (Christmas): picking
            // them on Book Us opens that page.
            'landings' => (array) apply_filters( 'tweller_flow_2_session_landings', array() ),
        ));
    }

    /**
     * Backdrop choices by session type:
     * { christmas_photos: { choices: [ {key, name, thumb, kind} ], extra: bool, extra_label } }
     */
    public static function backdrops() {
        $b = apply_filters( 'tweller_flow_2_booking_backdrops', array() );
        return is_array( $b ) ? $b : array();
    }

    /** Gift cards are sold from this page too (a tile in the session list). */
    private static function gifts_on() {
        return class_exists( 'TwellerFlow2_Gift_Cards' ) && TwellerFlow2_Gift_Cards::selling();
    }

    public static function render( $atts ) {
        wp_enqueue_style( 'tweller-flow-2-booking' );
        wp_enqueue_script( 'tweller-flow-2-booking' );
        if ( self::gifts_on() ) {
            wp_enqueue_style( 'tweller-gift-cards' );
            wp_enqueue_script( 'tweller-gift-cards' );
        }

        // [tweller_booking session="grads" package="full"] — for a dedicated
        // landing page (e.g. an ad's own page) that should open pre-chosen.
        // lock="1" hides the session list (a page that only sells one kind of
        // shoot); max_date caps the calendar; submit / card_cta relabel the
        // buttons; confirm="0" drops the "I have confirmed availability" tick.
        $atts = shortcode_atts( array(
            'session'  => '',
            'package'  => '',
            'lock'     => '',
            'max_date' => '',
            'submit'   => '',
            'card_cta' => '',
            'confirm'  => '1',
        ), $atts, 'tweller_booking' );
        $locked     = ! empty( $atts['lock'] );
        $max_date   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $atts['max_date'] ) ? $atts['max_date'] : '';
        $submit_lbl = $atts['submit'] !== '' ? $atts['submit'] : 'Reserve Booking · Continue to Payment';
        $confirm    = $atts['confirm'] !== '0';
        $sc_session = TwellerFlow2_Booking_Links::key_for( $atts['session'] );
        $sc_package = $sc_session ? TwellerFlow2_Booking_Links::package_key_for( $atts['package'] ) : '';

        ob_start();
        ?>
        <div id="tweller-booking-app" class="tweller-booking"<?php
            if ( $sc_session ) echo ' data-session="' . esc_attr( $sc_session ) . '"';
            if ( $sc_package ) echo ' data-package="' . esc_attr( $sc_package ) . '"';
            if ( $sc_session && $locked ) echo ' data-lock="1"';
            if ( $max_date ) echo ' data-max-date="' . esc_attr( $max_date ) . '"';
            if ( $atts['card_cta'] !== '' ) echo ' data-card-cta="' . esc_attr( $atts['card_cta'] ) . '"';
        ?>>
            <div class="tf2-chosen" id="tf2-chosen" hidden></div>
            <div class="tweller-booking__header">
                <h2>Book A Session</h2>
                <p>What date/time are you interested in?</p>
                <div class="tweller-booking__progress">
                    <div class="tf2-step active" data-step="1">1</div>
                    <div class="tf2-step-line"></div>
                    <div class="tf2-step" data-step="2">2</div>
                    <div class="tf2-step-line"></div>
                    <div class="tf2-step" data-step="3">3</div>
                    <div class="tf2-step-line"></div>
                    <div class="tf2-step" data-step="4">4</div>
                </div>
            </div>

            <!-- Shown only when a saved draft was picked back up. -->
            <div class="tf2-resume" id="tf2-resume-notice" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 9 8 9"/></svg>
                <span class="tf2-resume__text"></span>
                <button type="button" class="tf2-resume__reset" id="tf2-resume-reset">Start over</button>
            </div>

            <!-- STEP 1: Session Type Selection -->
            <div class="tweller-booking__step active" id="tf2-step-1">
                <div class="tf2-packages-grid" id="tf2-sessions-container">
                    <!-- Session types injected via JS -->
                </div>
            </div>

            <?php if ( self::gifts_on() ) : ?>
            <!-- GIFT CARD: same app, its own four steps (Gift / For / You / Pay) -->
            <div class="tweller-booking__step tweller-booking__step--gift" id="tf2-step-gift" style="display:none;">
                <?php echo TwellerFlow2_Gift_Cards::render_app( true ); ?>
            </div>
            <?php endif; ?>

            <!-- STEP 2: Package Selection -->
            <div class="tweller-booking__step" id="tf2-step-2" style="display:none;">
                <div class="tf2-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Sessions</div>
                <p class="tf2-step-context" id="tf2-step-2-context" style="display:none;"></p>
                <h3>Select a Package</h3>
                <div class="tf2-packages-grid" id="tf2-packages-container">
                    <!-- Packages injected via JS -->
                </div>
            </div>

            <!-- STEP 3: Date & Time -->
            <div class="tweller-booking__step" id="tf2-step-3" style="display:none;">
                <div class="tf2-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Packages</div>
                <p class="tf2-step-context" id="tf2-step-3-context" style="display:none;"></p>
                <h3>Select Date & Time</h3>
                <div class="tf2-datetime-layout">
                    <div class="tf2-calendar">
                        <input type="date" id="tf2-booking-date" class="tf2-input-field" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"<?php if ( $max_date ) echo ' max="' . esc_attr( $max_date ) . '"'; ?>>
                    </div>
                    <div class="tf2-slots" id="tf2-slots-container">
                        <p class="tf2-slots-empty">Please select a date first.</p>
                    </div>
                </div>
            </div>

            <!-- STEP 4: Details -->
            <div class="tweller-booking__step" id="tf2-step-4" style="display:none;">
                <div class="tf2-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Time</div>
                <h3>Your Details</h3>
                
                <div class="tf2-summary-card" id="tf2-summary-card">
                    <!-- JS fills -->
                </div>

                <form id="tf2-booking-form" onsubmit="event.preventDefault(); tfBooking.submitBooking();">
                    <!-- Backdrop picker (filled by booking.js when the session offers backdrops) -->
                    <div class="tf2-backdrop" id="tf2-backdrop" hidden></div>
                    <div class="tf2-form-row">
                        <div class="tf2-field">
                            <label>Full Name *</label>
                            <input type="text" id="tf2-client-name" required>
                        </div>
                        <div class="tf2-field">
                            <label>Email *</label>
                            <input type="email" id="tf2-client-email" required>
                        </div>
                    </div>
                    <div class="tf2-field">
                        <label>Phone *</label>
                        <input type="tel" id="tf2-client-phone" required>
                    </div>
                    <?php $tf2_tr = TwellerFlow2_Booking_API::transport(); if ( ! empty( $tf2_tr['enabled'] ) ) :
                        $tf2_tr_fee = (float) $tf2_tr['fee'];
                        $tf2_fee_txt = 'TT$' . number_format( $tf2_tr_fee, $tf2_tr_fee == (int) $tf2_tr_fee ? 0 : 2 ); ?>
                    <fieldset class="tf2-where" id="tf2-where"
                        data-fee="<?php echo esc_attr( $tf2_tr_fee ); ?>"
                        data-area="<?php echo esc_attr( wp_json_encode( TwellerFlow2_Booking_API::central_polygon() ) ); ?>"
                        data-leaflet-js="<?php echo esc_url( TWELLER_FLOW_2_PLUGIN_URL . 'public/vendor/leaflet/leaflet.js' ); ?>"
                        data-leaflet-css="<?php echo esc_url( TWELLER_FLOW_2_PLUGIN_URL . 'public/vendor/leaflet/leaflet.css' ); ?>">
                        <legend class="tf2-where__title">Where's your shoot? *</legend>
                        <div class="tf2-where__choices">
                            <label class="tf2-where__opt">
                                <input type="radio" name="tf2_shoot_location" value="studio" required>
                                <span class="tf2-where__name">In studio</span>
                            </label>
                            <label class="tf2-where__opt">
                                <input type="radio" name="tf2_shoot_location" value="location">
                                <span class="tf2-where__name">At my home or a location</span>
                            </label>
                        </div>
                        <div class="tf2-map" id="tf2-map-wrap" hidden>
                            <p class="tf2-map__lead">Search an address or area, or tap the map to drop a pin where the shoot will be.</p>
                            <div class="tf2-map__search" role="search">
                                <input type="search" id="tf2-map-q" placeholder="e.g. Chaguanas, or 12 Main Road Couva" autocomplete="street-address" enterkeyhint="search">
                                <button type="button" id="tf2-map-go">Search</button>
                            </div>
                            <ul class="tf2-map__results" id="tf2-map-results" hidden></ul>
                            <div class="tf2-map__canvas" id="tf2-map" aria-label="Map: tap to choose the shoot location"></div>
                            <div class="tf2-map__bar">
                                <button type="button" class="tf2-map__me" id="tf2-map-me">Use my current location</button>
                                <span class="tf2-map__legend"><i></i> No transport fee</span>
                            </div>
                            <p class="tf2-map__status" id="tf2-map-status" aria-live="polite"></p>
                            <p class="tf2-where__help">Outside the shaded central area (<?php echo esc_html( $tf2_tr['area'] ); ?>): +<?php echo esc_html( $tf2_fee_txt ); ?> transport.</p>
                        </div>
                    </fieldset>
                    <?php endif; ?>
                    <div class="tf2-field">
                        <label>Your address or area / notes</label>
                        <textarea id="tf2-client-notes" rows="3" placeholder="Your address or area, and any details you want included…"></textarea>
                    </div>

                    <?php
                    $tf2_fee     = class_exists( 'TwellerFlow2_Image_Consent' ) ? TwellerFlow2_Image_Consent::privacy_fee() : 50;
                    $tf2_pct     = class_exists( 'TwellerFlow2_Image_Consent' ) ? TwellerFlow2_Image_Consent::next_discount_pct() : 50;
                    // The explanation is read in a modal on this page now, not
                    // on the standalone page — that page still exists for links
                    // from emails, but a booking should never navigate away.
                    $tf2_fee_str = 'TT$' . number_format( $tf2_fee, ( $tf2_fee == (int) $tf2_fee ) ? 0 : 2 );
                    ?>
                    <div class="tf2-consent" id="tf2-consent-cfg" data-fee="<?php echo esc_attr( $tf2_fee ); ?>">
                        <div class="tf2-consent__head">
                            <h4 class="tf2-consent__title">Sharing your photos</h4>
                            <p class="tf2-consent__sub">Your call &mdash; we respect it either way.</p>
                        </div>

                        <div class="tf2-consent__opts">
                            <label class="tf2-consent-opt">
                                <input type="radio" name="tf2_image_consent" value="limited" checked onchange="tfBooking.renderSummary()">
                                <span class="tf2-consent-opt__mark" aria-hidden="true"></span>
                                <span class="tf2-consent-opt__body">
                                    <span class="tf2-consent-opt__name">Allow 1&ndash;5 images</span>
                                    <span class="tf2-consent-opt__note">Our standard &mdash; a small, tasteful selection.</span>
                                </span>
                                <span class="tf2-consent-opt__tag tf2-consent-opt__tag--free">No fee</span>
                            </label>

                            <label class="tf2-consent-opt">
                                <input type="radio" name="tf2_image_consent" value="unlimited" onchange="tfBooking.renderSummary()">
                                <span class="tf2-consent-opt__mark" aria-hidden="true"></span>
                                <span class="tf2-consent-opt__body">
                                    <span class="tf2-consent-opt__name">Share as many as you like</span>
                                    <span class="tf2-consent-opt__note">A real help to us &mdash; and we say thank you<?php $tf2_cap = class_exists( 'TwellerFlow2_Image_Consent' ) ? TwellerFlow2_Image_Consent::next_discount_cap() : 0; echo $tf2_cap > 0 ? esc_html( ': ' . (int) $tf2_pct . '% off your next session, up to TT$' . number_format( $tf2_cap ) ) : ''; ?>.</span>
                                </span>
                                <span class="tf2-consent-opt__tag tf2-consent-opt__tag--gift"><?php echo esc_html( (int) $tf2_pct ); ?>% off next</span>
                            </label>

                            <label class="tf2-consent-opt">
                                <input type="radio" name="tf2_image_consent" value="declined" onchange="tfBooking.renderSummary()">
                                <span class="tf2-consent-opt__mark" aria-hidden="true"></span>
                                <span class="tf2-consent-opt__body">
                                    <span class="tf2-consent-opt__name">Keep my session private</span>
                                    <span class="tf2-consent-opt__note">Nothing shared, anywhere.</span>
                                </span>
                                <span class="tf2-consent-opt__tag tf2-consent-opt__tag--fee">+<?php echo esc_html( $tf2_fee_str ); ?></span>
                            </label>
                        </div>

                        <button type="button" class="tf2-consent__why" id="tf2-why-open">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span>Why is there a privacy fee?</span>
                            <span class="tf2-consent__why-cta">Read more</span>
                        </button>
                    </div>

                    <?php if ( class_exists( 'TwellerFlow2_Gift_Cards' ) ) : ?>
                    <div class="tf2-gift" id="tf2-gift">
                        <button type="button" class="tf2-gift__toggle" id="tf2-gift-toggle" aria-expanded="false" aria-controls="tf2-gift-body"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8M7.5 8a2.5 2.5 0 1 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 1 1 0 5"/></svg><span>Have a gift card?</span></button>
                        <div class="tf2-gift__body" id="tf2-gift-body" hidden>
                            <div class="tf2-gift__row">
                                <input type="text" id="tf2-gift-code" placeholder="e.g. MINI-JD427" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-label="Gift card code">
                                <button type="button" id="tf2-gift-apply">Apply</button>
                            </div>
                            <p class="tf2-gift__msg" id="tf2-gift-msg" hidden></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ( class_exists( 'TwellerFlow2_Coupons' ) ) : ?>
                    <div class="tf2-gift tf2-coupon" id="tf2-coupon">
                        <button type="button" class="tf2-gift__toggle" id="tf2-coupon-toggle" aria-expanded="false" aria-controls="tf2-coupon-body"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg><span>Have a coupon code?</span></button>
                        <div class="tf2-gift__body" id="tf2-coupon-body" hidden>
                            <div class="tf2-gift__row">
                                <input type="text" id="tf2-coupon-code" placeholder="Enter your coupon code" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-label="Coupon code">
                                <button type="button" id="tf2-coupon-apply">Apply</button>
                            </div>
                            <p class="tf2-gift__msg" id="tf2-coupon-msg" hidden></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ( $confirm ) : ?>
                    <div class="tf2-field tf2-confirm-availability" style="background:#F9FAFB; padding:12px; border:1px solid #E5E7EB; border-radius:6px; margin-bottom:15px;">
                        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:normal; margin:0;">
                            <input type="checkbox" id="tf2-client-confirmed" required style="width:18px;height:18px; margin-top:3px; flex-shrink:0;">
                            <span style="font-size:14px; color:#374151;">I have confirmed availability with the photographer for this specific date and time.</span>
                        </label>
                        <p style="font-size:12px; color:#6B7280; margin:8px 0 0 28px;">If not, please send a quick inquiry message below first:</p>
                        <div style="display:flex; gap:10px; margin-top:10px; margin-left:28px;">
                            <button type="button" class="tf2-btn-submit" style="background:#25D366; padding:8px 12px; font-size:13px; flex:1;" onclick="tfBooking.sendWhatsApp()">WhatsApp Inquiry</button>
                            <button type="button" class="tf2-btn-submit" style="background:#4F46E5; padding:8px 12px; font-size:13px; flex:1;" onclick="tfBooking.sendEmail()">Email Inquiry</button>
                        </div>
                    </div>
                    <?php else : ?>
                    <div class="tf2-ask">
                        <span>Questions about a date first?</span>
                        <button type="button" class="tf2-ask__btn" onclick="tfBooking.sendWhatsApp()">Ask on WhatsApp</button>
                    </div>
                    <?php endif; ?>

                    <div class="tf2-pay" id="tf2-pay" aria-live="polite" hidden></div>
                    <div id="tf2-booking-error" class="tf2-error-msg" role="alert" tabindex="-1" style="display:none;"></div>
                    <button type="submit" class="tf2-btn-submit" id="tf2-submit-btn"><?php echo esc_html( $submit_lbl ); ?></button>
                    <p class="tf2-submit-hint">This step reserves your session &mdash; nothing is charged yet. On the next screen you&rsquo;ll pay by debit/credit card or bank transfer.</p>
                </form>
            </div>
            
            <!-- Why-this-fee reader. Kept on the page: sending someone
                 away mid-booking is how you lose the booking. -->
            <div class="tf2-why-modal" id="tf2-why-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="tf2-why-modal-title">
                <div class="tf2-why-modal__backdrop" id="tf2-why-close-bg"></div>
                <div class="tf2-why-modal__card">
                    <div class="tf2-why-modal__head">
                        <h3 class="tf2-why-modal__title" id="tf2-why-modal-title">On sharing, and your privacy</h3>
                        <button type="button" class="tf2-why-modal__x" id="tf2-why-close" aria-label="Close">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="tf2-why-modal__body">
                        <?php echo TwellerFlow2_Image_Consent::why_body_html(); ?>
                    </div>
                    <div class="tf2-why-modal__foot">
                        <button type="button" class="tf2-btn-submit tf2-why-modal__done" id="tf2-why-done">Got it</button>
                    </div>
                </div>
            </div>

            <div class="tweller-booking__loading tf2-ld" id="tf2-booking-loading" style="display:none;" role="status" aria-live="polite">
                <div class="tf2-ld__card">
                    <div class="tf2-ld__cam" aria-hidden="true">
                        <svg viewBox="0 0 120 96"><rect x="8" y="22" width="104" height="66" rx="14" class="b"/><path d="M40 22l7-12h26l7 12" class="b"/><circle cx="60" cy="55" r="22" class="r"/><circle cx="60" cy="55" r="12" class="l"/><circle cx="96" cy="36" r="4" class="d"/></svg>
                        <i class="tf2-ld__flash"></i>
                    </div>
                    <div class="tf2-ld__msgs">
                        <p data-ld-step><b>Booking you in&hellip;</b><span>Please wait. This only takes a moment.</span></p>
                        <p data-ld-step><b>Holding your date and time</b><span>Saving your backdrop and details.</span></p>
                        <p data-ld-step><b>Almost there</b><span>Getting your payment page ready.</span></p>
                        <p data-ld-step class="is-final"><b>You're booked in!</b><span>Taking you to payment&hellip;</span></p>
                    </div>
                    <div class="tf2-ld__bar" aria-hidden="true"><i></i></div>
                    <p class="tf2-ld__note">Please don't close this page.</p>
                    <div class="tf2-ld__next" aria-hidden="true"><span>Next</span><svg viewBox="0 0 24 24" width="18" height="18"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Payment</span></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

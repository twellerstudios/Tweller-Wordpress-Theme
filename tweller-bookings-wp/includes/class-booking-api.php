<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Booking_API {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'tweller-flow-2/v1', '/availability', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_get_availability' ),
            'permission_callback' => '__return_true',
        ));

        register_rest_route( 'tweller-flow-2/v1', '/book', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'rest_create_booking' ),
            'permission_callback' => '__return_true',
        ));

        register_rest_route( 'tweller-flow-2/v1', '/upload-receipt', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'rest_upload_receipt' ),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Parse iCal file to get busy events for a specific date
     */
    private static function get_ical_busy_events( $target_date ) {
        $ical_url = get_option( 'tweller_flow_2_booking_ical_url', '' );
        if ( empty( $ical_url ) ) return array();

        $response = wp_remote_get( $ical_url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) return array();
        
        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) return array();

        $lines = explode( "\n", $body );
        $events = array();
        $current_event = null;

        foreach( $lines as $line ) {
            $line = trim( $line );
            if ( $line === 'BEGIN:VEVENT' ) {
                $current_event = array();
            } elseif ( $line === 'END:VEVENT' ) {
                if ( $current_event ) {
                    // Check if event falls on target date
                    if ( isset( $current_event['DTSTART'] ) && isset( $current_event['DTEND'] ) ) {
                        $start_date = date('Y-m-d', $current_event['DTSTART']);
                        $end_date = date('Y-m-d', $current_event['DTEND'] - 1); // DTEND is exclusive
                        
                        if ( $target_date >= $start_date && $target_date <= $end_date ) {
                            $events[] = array(
                                'start' => date('H:i:s', $current_event['DTSTART']),
                                'end'   => date('H:i:s', $current_event['DTEND'])
                            );
                        }
                    }
                }
                $current_event = null;
            } elseif ( $current_event !== null ) {
                if ( strpos( $line, 'DTSTART' ) === 0 ) {
                    $val = self::parse_ical_datetime($line);
                    if ($val) $current_event['DTSTART'] = $val;
                } elseif ( strpos( $line, 'DTEND' ) === 0 ) {
                    $val = self::parse_ical_datetime($line);
                    if ($val) $current_event['DTEND'] = $val;
                }
            }
        }

        return $events;
    }

    private static function parse_ical_datetime($line) {
        $parts = explode(':', $line, 2);
        if (count($parts) < 2) return false;
        $val = trim($parts[1]);
        if (strpos($val, 'T') !== false) {
            return strtotime($val);
        } else {
            // All day event format: 20231015
            return strtotime($val . " 00:00:00");
        }
    }

    public static function rest_get_availability( $request ) {
        $date = sanitize_text_field( $request->get_param('date') );
        $duration = intval( $request->get_param('duration') ) ?: 60; // duration in minutes

        if ( empty( $date ) ) {
            return new WP_Error( 'missing_date', 'Date is required', array( 'status' => 400 ) );
        }

        // Available from 7 AM to 10 PM
        $start_hour = 7;
        $end_hour = 22;

        $slots = array();
        $current_time = strtotime( "$date " . sprintf("%02d:00:00", $start_hour) );
        $end_time = strtotime( "$date " . sprintf("%02d:00:00", $end_hour) );

        // Generate 30 min intervals
        while ( $current_time + ($duration * 60) <= $end_time ) {
            $slots[] = array(
                'time' => date( 'H:i:s', $current_time ),
                'display' => date( 'g:i A', $current_time ),
                'stamp' => $current_time
            );
            $current_time += 30 * 60; // jump 30 mins
        }

        // Remove times in the past
        $now = current_time('timestamp');
        $slots = array_filter( $slots, function($slot) use ($now) {
            return $slot['stamp'] > $now;
        });

        // Check booked sessions from DB
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        $booked_sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_time, package_type FROM $table WHERE session_date = %s AND session_time IS NOT NULL AND current_stage != 'cancelled'",
            $date
        ));

        $packages = get_option('tweller_flow_2_packages', array());

        $busy_blocks = array();

        foreach ( $booked_sessions as $sess ) {
            $sess_dur = 60; // default
            if ( isset( $packages[ $sess->package_type ] ) ) {
                $sess_dur = intval( $packages[ $sess->package_type ]['duration'] );
            }
            $start_stamp = strtotime( "$date {$sess->session_time}" );
            $end_stamp = $start_stamp + ($sess_dur * 60);
            $busy_blocks[] = array( 'start' => $start_stamp, 'end' => $end_stamp );
        }

        // Add iCal events
        $ical_events = self::get_ical_busy_events( $date );
        foreach ( $ical_events as $ev ) {
            $start_stamp = strtotime( "$date {$ev['start']}" );
            $end_stamp = strtotime( "$date {$ev['end']}" );
            $busy_blocks[] = array( 'start' => $start_stamp, 'end' => $end_stamp );
        }

        // Filter slots
        $final_slots = array();
        foreach ( $slots as $slot ) {
            $slot_start = $slot['stamp'];
            $slot_end   = $slot_start + ($duration * 60);
            $conflict   = false;

            foreach ( $busy_blocks as $block ) {
                // If slot overlaps with block
                if ( $slot_start < $block['end'] && $slot_end > $block['start'] ) {
                    $conflict = true;
                    break;
                }
            }

            if ( ! $conflict ) {
                $final_slots[] = array(
                    'time' => $slot['time'],
                    'display' => $slot['display']
                );
            }
        }

        return rest_ensure_response( array(
            'date' => $date,
            'duration' => $duration,
            'available_slots' => array_values( $final_slots )
        ));
    }

    /**
     * Transport fee for shoots away from the studio outside the central area.
     * Edited at Tweller Bookings → Christmas Page (applies to every booking).
     */
    public static function transport() {
        $t = get_option( 'tweller_flow_2_transport', array() );
        return wp_parse_args( is_array( $t ) ? $t : array(), array(
            'enabled' => 1,
            'fee'     => 60,
            'area'    => 'beyond Couva in the south, Curepe in the north, Macoya in the east or San Juan in the west',
            // Central area (no fee), one "lat,lng" per line, drawn on the map.
            'polygon' => implode( "\n", array(
                '10.660,-61.460', '10.660,-61.372', '10.600,-61.360', '10.520,-61.345', '10.440,-61.365',
                '10.395,-61.385', '10.395,-61.480', '10.500,-61.485', '10.600,-61.470',
            ) ),
        ) );
    }

    /** The central area as [[lat, lng], …]. */
    public static function central_polygon() {
        $pts = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) self::transport()['polygon'] ) as $line ) {
            if ( preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $line, $m ) ) $pts[] = array( (float) $m[1], (float) $m[2] );
        }
        return count( $pts ) >= 3 ? $pts : array();
    }

    /** Ray-casting point-in-polygon. */
    public static function in_central_area( $lat, $lng ) {
        $poly = self::central_polygon();
        if ( ! $poly ) return true; // no area configured: never charge
        $in = false;
        for ( $i = 0, $j = count( $poly ) - 1; $i < count( $poly ); $j = $i++ ) {
            list( $yi, $xi ) = $poly[ $i ];
            list( $yj, $xj ) = $poly[ $j ];
            if ( ( $yi > $lat ) !== ( $yj > $lat ) && $lng < ( $xj - $xi ) * ( $lat - $yi ) / ( ( $yj - $yi ) ?: 1e-12 ) + $xi ) $in = ! $in;
        }
        return $in;
    }

    /** Set while POST /book creates its session (for booking alerts). */
    public static $public_booking = false;

    /** A configured session-type key, or '' — never free text from the client. */
    private static function valid_session_type( $key ) {
        $key   = sanitize_key( (string) $key );
        $types = get_option( 'tweller_flow_2_session_types', array() );
        return ( $key !== '' && isset( $types[ $key ] ) ) ? $key : '';
    }

    public static function rest_create_booking( $request ) {
        $data = $request->get_json_params();

        if ( empty($data['client_name']) || empty($data['client_email']) || empty($data['session_date']) || empty($data['session_time']) || empty($data['package_type']) ) {
            return new WP_Error( 'missing_fields', 'Please fill in all required fields.', array( 'status' => 400 ) );
        }

        $packages = get_option('tweller_flow_2_packages', array());
        $package = $packages[ $data['package_type'] ] ?? null;

        if ( ! $package ) {
            return new WP_Error( 'invalid_package', 'Invalid package selected.', array( 'status' => 400 ) );
        }

        // Limited-spots pricing: each full batch of bookings can raise the price
        // (set on the Limited Spots page). Decided here, never by the browser.
        if ( class_exists( 'TwellerFlow2_Scarcity' ) ) {
            $package['price'] = (float) $package['price'] + TwellerFlow2_Scarcity::price_add( self::valid_session_type( $data['session_type'] ?? '' ) );
        }

        // Image-use consent: privacy fee if declined, next-session discount
        // (banked from a prior "unlimited" choice) applied to the total here.
        $client_email = sanitize_email( $data['client_email'] );
        $consent      = 'limited';
        $pricing      = array( 'total' => (float) $package['price'], 'fee' => 0.0, 'discount' => 0.0, 'discount_applied' => false );
        if ( class_exists( 'TwellerFlow2_Image_Consent' ) ) {
            $consent = TwellerFlow2_Image_Consent::sanitize_choice( $data['image_consent'] ?? 'limited' );
            $pricing = TwellerFlow2_Image_Consent::price_booking( $package['price'], $consent, $client_email );
        }

        // Coupon: a discount on the session price (not the transport fee).
        // Checked and priced here; the use is counted just before the booking
        // is created, and given back if that fails.
        $coupon_code = '';
        $coupon_off  = 0.0;
        $coupon_note = '';
        $snap_member = null;
        if ( ! empty( $data['coupon_code'] ) && class_exists( 'TwellerFlow2_Referrals' ) && class_exists( 'TwellerFlow2_Coupons' )
            && ! TwellerFlow2_Coupons::get( $data['coupon_code'] ) && TwellerFlow2_Referrals::get( $data['coupon_code'] ) ) {
            // Snap It Forward: a friend's discount on their first session.
            $snap_member = TwellerFlow2_Referrals::get( $data['coupon_code'] );
            $snap_err    = TwellerFlow2_Referrals::error( $snap_member, $client_email );
            if ( $snap_err ) return new WP_Error( 'coupon', $snap_err, array( 'status' => 400 ) );
            $coupon_off = min( TwellerFlow2_Referrals::friend_discount( (float) $package['price'] ), max( 0.0, (float) $pricing['total'] ) );
            if ( $coupon_off > 0 ) {
                $pricing['total'] = round( $pricing['total'] - $coupon_off, 2 );
                $coupon_note      = 'Snap It Forward (' . $snap_member['name'] . ', ' . $snap_member['code'] . '): -TT$' . number_format( $coupon_off, 2 );
            } else {
                $snap_member = null;
            }
        } elseif ( ! empty( $data['coupon_code'] ) && class_exists( 'TwellerFlow2_Coupons' ) ) {
            if ( TwellerFlow2_Coupons::guess_blocked() ) return TwellerFlow2_Coupons::guess_error();
            $cp     = TwellerFlow2_Coupons::get( $data['coupon_code'] );
            $cp_err = TwellerFlow2_Coupons::error( $cp, sanitize_key( $data['package_type'] ), self::valid_session_type( $data['session_type'] ?? '' ), $client_email, (float) $package['price'] );
            if ( $cp_err ) {
                TwellerFlow2_Coupons::guess_failed();
                return new WP_Error( 'coupon', $cp_err, array( 'status' => 400 ) );
            }
            $coupon_off = min( TwellerFlow2_Coupons::discount_for( $cp, (float) $package['price'] ), max( 0.0, (float) $pricing['total'] ) );
            if ( $coupon_off > 0 ) {
                $coupon_code      = $cp['code'];
                $pricing['total'] = round( $pricing['total'] - $coupon_off, 2 );
                $coupon_note      = 'Coupon ' . $coupon_code . ': -TT$' . number_format( $coupon_off, 2 );
            }
        }

        // Backdrops (Christmas scenes, own decor…): the main one is included;
        // each extra digital backdrop is TT$50 (an early-bird booking's first
        // extra is free); a custom-backdrop idea is noted for a quote. Priced
        // here, never by the browser, and noted at the top of the booking.
        $backdrop_note = '';
        $backdrop_plan = null;
        $session_key   = self::valid_session_type( $data['session_type'] ?? '' );
        $bd_cfg        = TwellerFlow2_Booking_Shortcode::backdrops()[ $session_key ] ?? null;
        if ( $bd_cfg && ! empty( $bd_cfg['choices'] ) && class_exists( 'TwellerFlow2_Backdrop_Edits' ) ) {
            $extras = isset( $data['backdrop_extras'] ) && is_array( $data['backdrop_extras'] ) ? $data['backdrop_extras'] : array_filter( array( $data['backdrop_extra'] ?? '' ) );
            $bd = TwellerFlow2_Backdrop_Edits::from_booking( $session_key, $data['backdrop'] ?? '', $extras, $data['custom_backdrop'] ?? '', ! empty( $bd_cfg['extra'] ) );
            if ( is_wp_error( $bd ) ) return $bd;
            list( $backdrop_plan, $bd_fee, $backdrop_note ) = $bd;
            if ( $bd_fee > 0 ) {
                $pricing['backdrops'] = $bd_fee;
                $pricing['total']     = round( $pricing['total'] + $bd_fee, 2 );
            }
        }

        // Where the shoot is: away from the studio and outside the central
        // area adds the transport fee (after any discount — it is a cost,
        // not part of the session price).
        // The fee is decided here from the pinned point, never by the browser.
        $transport = self::transport();
        $location  = sanitize_key( $data['shoot_location'] ?? '' );
        $location_note = '';
        $location_text = '';
        if ( ! empty( $transport['enabled'] ) ) {
            if ( $location === 'studio' ) {
                $location_note = 'Location: In studio';
                $location_text = 'In studio';
            } elseif ( $location === 'location' ) {
                $lat = isset( $data['shoot_lat'] ) ? (float) $data['shoot_lat'] : 0;
                $lng = isset( $data['shoot_lng'] ) ? (float) $data['shoot_lng'] : 0;
                // Trinidad & Tobago, generously
                if ( $lat < 10.0 || $lat > 11.4 || $lng < -62.0 || $lng > -60.4 ) {
                    return new WP_Error( 'shoot_location', 'Please pin your shoot location on the map.', array( 'status' => 400 ) );
                }
                $addr    = substr( sanitize_text_field( $data['shoot_address'] ?? '' ), 0, 200 );
                $inside  = self::in_central_area( $lat, $lng );
                $map     = sprintf( 'https://www.google.com/maps?q=%.6f,%.6f', $lat, $lng );
                $location_text = ( $addr ?: sprintf( '%.5f, %.5f', $lat, $lng ) );
                $location_note = 'Location: ' . $location_text . ( $inside ? ' (central area)' : ' (outside central area)' );
                if ( ! $inside && (float) $transport['fee'] > 0 ) {
                    $pricing['transport'] = (float) $transport['fee'];
                    $pricing['total']     = round( $pricing['total'] + (float) $transport['fee'], 2 );
                    $location_note       .= ' +TT$' . number_format( (float) $transport['fee'], 0 ) . ' transport';
                }
                $location_note .= "\nMap: " . $map;
                $location_text .= ' — ' . $map;
            } else {
                return new WP_Error( 'shoot_location', 'Please tell us where your shoot will be.', array( 'status' => 400 ) );
            }
        }

        // Gift card: taken off the card up front (atomically, so two bookings
        // can't spend the same balance) and counted as money received. Put
        // back below if the booking itself fails.
        $gift_code = '';
        $gift_take = 0.0;
        if ( ! empty( $data['gift_code'] ) && class_exists( 'TwellerFlow2_Gift_Cards' ) ) {
            // Codes are short enough to guess: wrong ones are counted per IP.
            if ( TwellerFlow2_Gift_Cards::guess_blocked() ) return TwellerFlow2_Gift_Cards::guess_error();
            $gift = TwellerFlow2_Gift_Cards::get_by_code( $data['gift_code'] );
            $gift_err = TwellerFlow2_Gift_Cards::usable_error( $gift );
            if ( $gift_err ) {
                TwellerFlow2_Gift_Cards::guess_failed();
                return new WP_Error( 'gift_card', $gift_err, array( 'status' => 400 ) );
            }
            $gift_code = $gift->code;
            $gift_take = TwellerFlow2_Gift_Cards::redeem( $gift_code, $pricing['total'], 'Booking — ' . sanitize_text_field( $data['client_name'] ) );
            if ( $gift_take <= 0 && $pricing['total'] > 0 ) {
                return new WP_Error( 'gift_card', 'That gift card could not be applied — please try again.', array( 'status' => 400 ) );
            }
        }

        $client_notes = sanitize_textarea_field( $data['notes'] ?? '' );

        $session_data = array(
            'client_name' => sanitize_text_field( $data['client_name'] ),
            'client_email' => $client_email,
            'client_phone' => sanitize_text_field( $data['client_phone'] ?? '' ),
            'package_type' => sanitize_text_field( $data['package_type'] ),
            'session_type' => self::valid_session_type( $data['session_type'] ?? '' ),
            'attribution'  => TwellerFlow2_Booking_Links::sanitize_attribution( $data['attribution'] ?? array() ),
            'session_date' => sanitize_text_field( $data['session_date'] ),
            'session_time' => sanitize_text_field( $data['session_time'] ),
            'location' => $location_text !== '' ? $location_text : sanitize_text_field( $data['location'] ?? '' ),
            'notes' => trim( implode( "\n", array_filter( array( $location_note, $backdrop_note, $coupon_note ) ) ) . ( ( $location_note || $backdrop_note || $coupon_note ) && $client_notes ? "\n\n" : '' ) . $client_notes ),
            'backdrop' => $backdrop_note,
            'payment_status' => 'pending',
            'deposit_amount' => $gift_take, // gift card, if any; bank transfer / card to follow
            'total_amount' => $pricing['total'],
            'members_count' => $package['members'],
            // A gift-card booking sends its own emails below, once the gift
            // is on record — the standard welcome would ask for a deposit
            // the card may already cover.
            'skip_notifications' => $gift_take > 0,
        );

        // Count the coupon use now (it may have run out since the check above).
        if ( $coupon_code !== '' && ! TwellerFlow2_Coupons::redeem( $coupon_code ) ) {
            if ( $gift_take > 0 ) {
                $gc = TwellerFlow2_Gift_Cards::get_by_code( $gift_code );
                if ( $gc ) TwellerFlow2_Gift_Cards::credit( $gc->id, $gift_take, 'Refunded — booking not completed' );
            }
            return new WP_Error( 'coupon', 'That coupon has already been used.', array( 'status' => 400 ) );
        }

        self::$public_booking = true;
        $session_id = TwellerFlow2_Session::create( $session_data );
        self::$public_booking = false;

        if ( ! $session_id && $coupon_code !== '' ) TwellerFlow2_Coupons::release( $coupon_code );

        if ( ! $session_id ) {
            if ( $gift_take > 0 ) {
                $gc = TwellerFlow2_Gift_Cards::get_by_code( $gift_code );
                if ( $gc ) TwellerFlow2_Gift_Cards::credit( $gc->id, $gift_take, 'Refunded — booking could not be created' );
            }
            return new WP_Error( 'create_failed', 'Could not create booking.', array( 'status' => 500 ) );
        }

        if ( $backdrop_plan ) TwellerFlow2_Backdrop_Edits::save_plan( $session_id, $backdrop_plan );

        // The invoice the client sees on the payment step: the same lines the
        // price above was built from.
        if ( class_exists( 'TwellerFlow2_Invoice' ) ) {
            $types = get_option( 'tweller_flow_2_session_types', array() );
            $lines = array( array( 'label' => trim( ( $package['name'] ?? 'Photo' ) . ' session' ), 'sub' => $types[ $session_data['session_type'] ]['name'] ?? '', 'amount' => (float) $package['price'] ) );
            if ( ! empty( $pricing['fee'] ) )      $lines[] = array( 'label' => 'Privacy fee', 'amount' => (float) $pricing['fee'] );
            if ( ! empty( $pricing['discount'] ) ) $lines[] = array( 'label' => 'Thank-you discount', 'sub' => 'For sharing your photos last time (' . ( class_exists( 'TwellerFlow2_Image_Consent' ) ? TwellerFlow2_Image_Consent::next_discount_label() : '' ) . ')', 'amount' => -(float) $pricing['discount'] );
            if ( $coupon_off > 0 ) $lines[] = array( 'label' => $snap_member ? 'Snap It Forward' : 'Coupon ' . $coupon_code, 'sub' => $snap_member ? 'Shared by ' . $snap_member['name'] : '', 'amount' => -$coupon_off );
            if ( $backdrop_plan ) {
                $bd_n = function ( $k ) use ( $backdrop_plan ) { $c = TwellerFlow2_Backdrop_Edits::catalogue( $backdrop_plan['session_type'] ); return $c[ $k ]['name'] ?? $k; };
                if ( $backdrop_plan['free'] !== '' ) $lines[] = array( 'label' => 'Early bird backdrop', 'sub' => $bd_n( $backdrop_plan['free'] ), 'amount' => 0, 'text' => 'Free' );
                if ( $backdrop_plan['paid'] ) $lines[] = array( 'label' => count( $backdrop_plan['paid'] ) === 1 ? 'Extra backdrop' : 'Extra backdrops × ' . count( $backdrop_plan['paid'] ), 'sub' => implode( ', ', array_map( $bd_n, $backdrop_plan['paid'] ) ), 'amount' => (float) $backdrop_plan['fee'] );
                if ( $backdrop_plan['custom'] !== '' ) $lines[] = array( 'label' => 'Custom backdrop', 'sub' => 'We\'ll send you a quote', 'amount' => 0, 'text' => 'To quote' );
            }
            if ( ! empty( $pricing['transport'] ) ) $lines[] = array( 'label' => 'Transport', 'sub' => 'Outside the central area', 'amount' => (float) $pricing['transport'] );
            TwellerFlow2_Invoice::save( $session_id, array( 'lines' => $lines, 'gift' => $gift_take, 'gift_code' => $gift_take > 0 ? $gift_code : '', 'created' => current_time( 'mysql' ) ) );
        }

        if ( $snap_member ) {
            TwellerFlow2_Referrals::record_booking( $snap_member, $session_id, $session_data['client_name'], $client_email, $coupon_off );
            TwellerFlow2_Session::record_stage_history( $session_id, 'booked', 0, 'Snap It Forward: shared by ' . $snap_member['name'] . ' (' . $snap_member['code'] . ') — TT$' . number_format( $coupon_off, 2 ) . ' off' );
        }

        if ( $coupon_code !== '' ) {
            TwellerFlow2_Coupons::record( $coupon_code, $session_id, $session_data['client_name'], $session_data['package_type'], $session_data['session_type'], $coupon_off, $pricing['total'] );
            TwellerFlow2_Session::record_stage_history( $session_id, 'booked', 0, 'Coupon ' . $coupon_code . ' applied — TT$' . number_format( $coupon_off, 2 ) . ' off' );
        }

        if ( $gift_take > 0 ) {
            TwellerFlow2_Gift_Cards::attach_to_session( $gift_code, $gift_take, $session_id );
            $fresh = TwellerFlow2_Session::get( $session_id );
            TwellerFlow2_Session::record_stage_history( $session_id, 'booked', 0, 'Gift card ' . $gift_code . ' applied — TT$' . number_format( $gift_take, 2 ) );
            if ( $gift_take + 0.004 >= (float) $pricing['total'] / 2 ) {
                // The card covers the deposit: confirmed straight away, with
                // the confirmation email + confirmed calendar invite.
                TwellerFlow2_Session::verify_receipt( $session_id, 'approve', 0 );
            } else {
                TwellerFlow2_Notifications::on_stage_change( $session_id, 'booked' );
            }
            TwellerFlow2_Notifications::notify_studio_new_booking( $session_id );
        }

        // Fully covered by a code (a giveaway prize): nothing to pay, so the
        // booking is confirmed straight away (confirmation email + calendar).
        if ( $gift_take <= 0 && (float) $pricing['total'] <= 0 && ( $coupon_code !== '' || $snap_member ) ) {
            TwellerFlow2_Session::verify_receipt( $session_id, 'approve', 0 );
        }

        // Record the consent choice and settle the client-level credits: a
        // redeemed next-session discount is spent now, and choosing "unlimited"
        // banks a fresh discount for the client's next booking.
        if ( class_exists( 'TwellerFlow2_Image_Consent' ) ) {
            TwellerFlow2_Image_Consent::set( $session_id, $consent );
            if ( ! empty( $pricing['discount_applied'] ) ) {
                TwellerFlow2_Image_Consent::redeem_next_session_discount( $client_email );
            }
            if ( $consent === TwellerFlow2_Image_Consent::UNLIMITED ) {
                TwellerFlow2_Image_Consent::grant_next_session_discount( $client_email );
            }
        }

        $session = TwellerFlow2_Session::get( $session_id );

        // Server half of the browser's Lead (same event ID → Meta counts one).
        if ( class_exists( 'TwellerFlow2_Meta_Events' ) && ! empty( $data['event_id'] ) ) {
            TwellerFlow2_Meta_Events::booking_lead( $session_id, $data['event_id'] );
        }

        // The single welcome email (payment details + calendar hold attached)
        // is sent by TwellerFlow2_Session::create() — nothing extra here.

        return rest_ensure_response( array(
            'success' => true,
            'session_id' => $session_id,
            'tracking_code' => $session->tracking_code,
            'gift_applied' => $gift_take,
            'coupon_applied' => $coupon_off,
        ) );
    }

    public static function rest_upload_receipt( $request ) {
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
        }

        $code = sanitize_text_field( $_POST['tracking_code'] ?? '' );
        if ( empty( $code ) ) {
            return new WP_Error( 'missing_code', 'Tracking code is required', array( 'status' => 400 ) );
        }

        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        if ( empty( $_FILES['receipt'] ) ) {
            return new WP_Error( 'no_file', 'No receipt file provided', array( 'status' => 400 ) );
        }

        $file = $_FILES['receipt'];
        $upload_overrides = array( 'test_form' => false );
        
        // Add filter to change upload dir to protected space if preferable, for now standard uploads folder is fine
        // as we don't have sensitive imagery yet, but tracking code is pseudo-secure.
        $movefile = wp_handle_upload( $file, $upload_overrides );

        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $ocr_data  = isset($_POST['ocr_amounts']) ? sanitize_text_field($_POST['ocr_amounts']) : '';
            $ocr_ref   = isset($_POST['ocr_reference']) ? sanitize_text_field($_POST['ocr_reference']) : '';
            $bank_name = isset($_POST['bank_name']) ? sanitize_text_field($_POST['bank_name']) : 'Unknown Bank';
            
            // Save dedicated receipt option
            update_option( 'tf_receipt_' . $session->id, array(
                'url'       => $movefile['url'],
                'ocr'       => $ocr_data,
                'confirmed' => ! empty( $_POST['amount_confirmed'] ),
                'ref'       => $ocr_ref,
                'bank'      => $bank_name,
                'date'      => current_time('mysql')
            ));

            // Set to verifying
            TwellerFlow2_Session::update( $session->id, array(
                'payment_status' => 'verifying'
            ));

            TwellerFlow2_Session::record_stage_history( $session->id, $session->current_stage, $session->current_stage_index, 'Client uploaded bank transfer receipt for verification.' );

            // Send Emails
            $tracker_url = TwellerFlow2_Notifications::get_tracker_url( $session->tracking_code );
            $review_link = admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $session->id );

            // To the studio (site admin + hello@)
            $admin_recipients = array_unique( array_filter( array(
                get_option( 'admin_email' ),
                TwellerFlow2_Notifications::STUDIO_EMAIL,
            ) ) );
            $admin_subj = "Receipt Uploaded — {$session->client_name}";
            $admin_body = "
                <h2 style='color:#101010; font-weight:600;'>A payment receipt just arrived</h2>
                <p style='color:#3D3630; line-height:1.7;'><strong>{$session->client_name}</strong> has uploaded their bank transfer receipt for verification.</p>
                " . TwellerFlow2_Notifications::email_card( 'Payment Details',
                    TwellerFlow2_Notifications::email_detail_row( 'Client', esc_html( $session->client_name ) ) .
                    TwellerFlow2_Notifications::email_detail_row( 'Bank', esc_html( $bank_name ) ) .
                    ( $ocr_data ? TwellerFlow2_Notifications::email_detail_row( 'Detected Amount', esc_html( $ocr_data ) ) : '' ) .
                    ( $ocr_ref ? TwellerFlow2_Notifications::email_detail_row( 'Reference', esc_html( $ocr_ref ) ) : '' ) .
                    TwellerFlow2_Notifications::email_detail_row( 'Shoot Code', $session->tracking_code )
                ) . "
                <div style='text-align:center; margin:28px 0;'>
                    " . TwellerFlow2_Notifications::email_button( $review_link, 'Review in Tweller Bookings WP Dashboard' ) . "
                </div>";
            TwellerFlow2_Notifications::send_raw( $admin_recipients, $admin_subj, $admin_body );

            // To Client
            if ( ! empty($session->client_email) ) {
                $first_name  = trim( explode( ' ', trim( $session->client_name ) )[0] );
                $client_subj = "We've received your receipt, {$first_name} — verifying now";
                $client_body = "
                    <h2 style='color:#101010; font-weight:600;'>Receipt received</h2>
                    <p style='color:#3D3630;'>Hi {$session->client_name},</p>
                    <p style='color:#3D3630; line-height:1.7;'>Thank you — your bank transfer receipt has arrived safely. We'll verify the payment and confirm your booking within <strong>1–2 business days</strong>. You don't need to do anything else for now.</p>
                    <div style='text-align:center; margin:28px 0;'>
                        " . TwellerFlow2_Notifications::email_button( $tracker_url, 'Track My Session' ) . "
                    </div>
                    <p style='color:#3D3630;'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>";
                TwellerFlow2_Notifications::send_email( $session, $client_subj, $client_body );
            }

            return rest_ensure_response( array(
                'success' => true,
                'url' => $movefile['url']
            ));
        } else {
            return new WP_Error( 'upload_error', $movefile['error'], array( 'status' => 500 ) );
        }
    }
}

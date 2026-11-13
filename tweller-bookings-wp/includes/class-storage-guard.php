<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Keeps the server's disk from filling up — and says so when it is.
 *
 * Gallery downloads are built as a temporary .tf2-dl-*.zip next to the
 * photos, the full size of the gallery, then deleted once sent. When the
 * web server kills that request part-way (a host-level timeout ignores
 * set_time_limit), the delete never runs and gigabytes are left behind.
 * Enough of those and the disk fills: photo uploads fail with PHP's
 * "can't write" error (code 7), MySQL can't build the temp tables the admin
 * lists need, and pages start returning 503s.
 *
 *  - Leftover download zips older than two hours are swept away hourly.
 *  - A download zip is refused up front when there isn't room for it.
 *  - Low free space shows as a warning on the plugin's admin screens.
 */
class TwellerFlow2_Storage_Guard {

    const CRON      = 'tweller_storage_sweep';
    const STALE_AGE = 7200;                     // seconds before a temp zip counts as abandoned
    const WARN_BYTES = 3 * 1024 * 1024 * 1024;  // warn under 3 GB free
    const MARGIN     = 512 * 1024 * 1024;       // keep 512 MB spare after any zip

    public static function init() {
        add_action( self::CRON, array( __CLASS__, 'sweep' ) );
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + 120, 'hourly', self::CRON );
        }
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_cleanup_request' ) );
    }

    public static function gallery_root() {
        $u = wp_upload_dir();
        return trailingslashit( $u['basedir'] ) . TwellerFlow2_Gallery::UPLOAD_SUBDIR;
    }

    /** Temp download zips under the gallery folder: [path => [size, mtime]]. */
    public static function temp_zips() {
        $out  = array();
        $root = self::gallery_root();
        if ( ! is_dir( $root ) ) return $out;
        // Galleries are one level deep: tweller-gallery/<code>/.tf2-dl-*.zip
        foreach ( (array) glob( $root . '/*/.tf2-dl-*.zip', GLOB_NOSORT ) as $f ) {
            $out[ $f ] = array( (int) @filesize( $f ), (int) @filemtime( $f ) );
        }
        foreach ( (array) glob( $root . '/.tf2-dl-*.zip', GLOB_NOSORT ) as $f ) {
            $out[ $f ] = array( (int) @filesize( $f ), (int) @filemtime( $f ) );
        }
        return $out;
    }

    /**
     * Delete abandoned download zips. $all also removes recent ones (the
     * admin "clean up now" button — a download in progress at that exact
     * moment would fail and can simply be retried).
     *
     * @return array{count:int, bytes:int}
     */
    public static function sweep( $all = false ) {
        $count = 0; $bytes = 0;
        $cutoff = time() - self::STALE_AGE;
        foreach ( self::temp_zips() as $f => $info ) {
            if ( ! $all && $info[1] > $cutoff ) continue;
            if ( @unlink( $f ) ) { $count++; $bytes += $info[0]; }
        }
        return array( 'count' => $count, 'bytes' => $bytes );
    }

    /** Free bytes on the disk holding the uploads folder, or null if unknown. */
    public static function free_bytes() {
        if ( ! function_exists( 'disk_free_space' ) ) return null;
        $u = wp_upload_dir();
        $f = @disk_free_space( $u['basedir'] );
        return $f === false ? null : (float) $f;
    }

    /**
     * Before building a zip of $needed bytes: WP_Error when the disk can't
     * take it (after first clearing abandoned zips), else true.
     */
    public static function ensure_room( $needed ) {
        $free = self::free_bytes();
        if ( $free === null ) return true;
        if ( $free >= $needed + self::MARGIN ) return true;
        self::sweep();
        $free = self::free_bytes();
        if ( $free === null || $free >= $needed + self::MARGIN ) return true;
        return new WP_Error( 'disk_full', 'The download can\'t be prepared right now — the server is low on space. Please try again later, or download photos one at a time.', array( 'status' => 503 ) );
    }

    /** "Upload failed with error code 7" → what it actually means. */
    public static function upload_error_message( $code ) {
        switch ( (int) $code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:  return 'The photo is larger than the server allows (upload_max_filesize / post_max_size).';
            case UPLOAD_ERR_PARTIAL:    return 'The photo only partly arrived — the connection dropped. Try again.';
            case UPLOAD_ERR_NO_FILE:    return 'No photo was received.';
            case UPLOAD_ERR_NO_TMP_DIR: return 'The server has no temporary folder for uploads (PHP upload_tmp_dir is missing).';
            case UPLOAD_ERR_CANT_WRITE: return 'The server could not save the photo — its disk is full or the temp folder is not writable (error 7).';
            case UPLOAD_ERR_EXTENSION:  return 'A PHP extension on the server blocked the upload.';
        }
        return 'Upload failed (error ' . (int) $code . ').';
    }

    public static function human( $bytes ) {
        $bytes = (float) $bytes;
        if ( $bytes >= 1073741824 ) return number_format( $bytes / 1073741824, 1 ) . ' GB';
        if ( $bytes >= 1048576 )    return number_format( $bytes / 1048576, 0 ) . ' MB';
        return number_format( $bytes / 1024, 0 ) . ' KB';
    }

    // ── Admin ─────────────────────────────────────────

    public static function handle_cleanup_request() {
        if ( empty( $_POST['tf2_storage_cleanup'] ) || ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'tf2_storage_cleanup' );
        $r = self::sweep( true );
        set_transient( 'tf2_storage_cleaned', $r, 60 );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=tweller-flow-2-galleries' ) );
        exit;
    }

    /** Warning on the plugin's screens when space is low or zips are piling up. */
    public static function admin_notice() {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        if ( strpos( $page, 'tweller-flow-2' ) !== 0 || ! current_user_can( 'manage_options' ) ) return;

        $done = get_transient( 'tf2_storage_cleaned' );
        if ( $done ) {
            delete_transient( 'tf2_storage_cleaned' );
            echo '<div class="notice notice-success"><p><strong>Tweller:</strong> removed ' . (int) $done['count'] . ' leftover download file(s), freeing ' . esc_html( self::human( $done['bytes'] ) ) . '.</p></div>';
        }

        $free  = self::free_bytes();
        $zips  = self::temp_zips();
        $zip_b = array_sum( array_map( function ( $i ) { return $i[0]; }, $zips ) );
        $low   = $free !== null && $free < self::WARN_BYTES;
        if ( ! $low && $zip_b < 256 * 1048576 ) return;

        echo '<div class="notice notice-' . ( $low ? 'error' : 'warning' ) . '"><p><strong>Tweller — server storage:</strong> ';
        if ( $free !== null ) echo esc_html( self::human( $free ) ) . ' free on the server. ';
        if ( $low ) echo 'Photo uploads and gallery downloads fail when the disk fills up. ';
        if ( $zips ) echo esc_html( count( $zips ) . ' leftover download file(s) are using ' . self::human( $zip_b ) . '.' );
        echo '</p>';
        if ( $zips ) {
            echo '<form method="post" style="margin:0 0 10px;">';
            wp_nonce_field( 'tf2_storage_cleanup' );
            echo '<input type="hidden" name="tf2_storage_cleanup" value="1"><button class="button button-primary">Clean up leftover downloads now</button></form>';
        }
        echo '</div>';
    }
}

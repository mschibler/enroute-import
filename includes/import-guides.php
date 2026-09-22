<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import guides from the old CMS database — AJAX batch version.
 * All steps independently re-runnable (insert-or-update by _old_cms_id).
 *
 * Steps:
 *   enroute_import_batch_guides()        — Step 1: data (title, quote, description, language)
 *   enroute_import_batch_guide_photos()  — Step 2: sideload photos
 *
 * Source tables:
 *   guide_guide          — main guide record (active flag)
 *   appbase_address      — name (first_name + last_name via person_id)
 *   guide_quote          — quote text, language detection
 *   guide_guidenarration — description blocks (concatenated)
 *   guide_guide_photos   — photo relation
 *   appbase_photo        — photo path
 */

define( 'GUIDE_SITE_BASE_URL', 'https://enroute.ch/media/' );

// ══════════════════════════════════════════════════════════════════════════════
// HELPER — Strip inline styles, spans, fonts; keep only meaningful tags
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_clean_html( string $html ): string {
    // Remove style attributes from all tags
    $html = preg_replace( '/ style="[^"]*"/i', '', $html );
    $html = preg_replace( "/ style='[^']*'/i", '', $html );

    // Unwrap <span>, <font> tags (remove tags but keep inner content)
    $html = preg_replace( '/<span[^>]*>/i', '', $html );
    $html = preg_replace( '/<\/span>/i', '', $html );
    $html = preg_replace( '/<font[^>]*>/i', '', $html );
    $html = preg_replace( '/<\/font>/i', '', $html );

    // Remove class and other non-essential attributes from p tags (keep just <p>)
    $html = preg_replace( '/<p[^>]*>/i', '<p>', $html );

    // Strip any remaining tags not in our allowed list via wp_kses
    $allowed = [
        'p'      => [],
        'b'      => [],
        'strong' => [],
        'i'      => [],
        'em'     => [],
        'br'     => [],
        'a'      => [ 'href' => [], 'target' => [], 'rel' => [] ],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
    ];
    $html = wp_kses( $html, $allowed );

    // Clean up excessive whitespace and empty paragraphs
    $html = preg_replace( '/<p>\s*<\/p>/i', '', $html );
    $html = preg_replace( '/
{3,}/', "

", trim( $html ) );

    return $html;
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 1 — Import guide data
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_guides( int $offset, int $limit = 5 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    @set_time_limit( 120 );

    $count_result = $db->query( "SELECT COUNT(*) AS total FROM guide_guide WHERE active = 1" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    // Get active guides with name from appbase_address
    $result = $db->query( "
        SELECT
            g.id            AS old_id,
            g.person_id     AS person_id,
            a.first_name    AS first_name,
            a.last_name     AS last_name
        FROM guide_guide g
        LEFT JOIN appbase_address a ON a.id = g.person_id
        WHERE g.active = 1
        ORDER BY g.id ASC
        LIMIT $limit OFFSET $offset
    " );

    if ( ! $result ) {
        $db->close();
        return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ];
    }

    $log         = [];
    $batch_count = 0;

    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id    = (int) $row['old_id'];
        $person_id = (int) $row['person_id'];

        // Build title from first name only
        $title = trim( $row['first_name'] ?? '' );
        if ( ! $title ) {
            $title = "Guide #$old_id";
            $log[] = [ 'info', "Guide old_id=$old_id has no name — using placeholder." ];
        }

        // ── Quote ────────────────────────────────────────────────────────────
        // Get active quote for this person; detect language by comparing quote to translations
        $quote      = '';
        $language   = '';
        $q_result   = $db->query(
            "SELECT quote, quote_de, quote_fr, quote_it
             FROM guide_quote
             WHERE author_id = $person_id AND active = 1
             ORDER BY id ASC LIMIT 1"
        );
        if ( $q_result ) {
            $q = $q_result->fetch_assoc();
            $q_result->free();
            if ( $q ) {
                $quote = sanitize_text_field( $q['quote'] ?? '' );
                // Language detection: compare base quote to language variants
                if ( $q['quote_de'] && $q['quote'] === $q['quote_de'] )       $language = 'de';
                elseif ( $q['quote_fr'] && $q['quote'] === $q['quote_fr'] )   $language = 'fr';
                elseif ( $q['quote_it'] && $q['quote'] === $q['quote_it'] )   $language = 'it';
                else $language = 'de'; // default
            }
        }

        // ── Description (narrations) ──────────────────────────────────────────
        // Concatenate all narration blocks ordered by `order`
        $description = '';
        $n_result    = $db->query(
            "SELECT title, description
             FROM guide_guidenarration
             WHERE guide_id = $old_id
             ORDER BY `order` ASC"
        );
        if ( $n_result ) {
            $parts = [];
            while ( $n = $n_result->fetch_assoc() ) {
                $n_title = trim( $n['title']       ?? '' );
                $n_desc  = trim( $n['description'] ?? '' );
                if ( ! $n_title && ! $n_desc ) continue;
                $block  = '<p>';
                if ( $n_title ) $block .= '<b>' . esc_html( $n_title ) . '</b>';
                if ( $n_title && $n_desc ) $block .= '<br>';
                if ( $n_desc )  $block .= enroute_import_clean_html( $n_desc );
                $block .= '</p>';
                $parts[] = $block;
            }
            $description = implode( "\n", $parts );
            $n_result->free();
        }

        // ── Photo path (first photo only) ─────────────────────────────────────
        $photo_path = '';
        $ph_result  = $db->query(
            "SELECT p.photo
             FROM guide_guide_photos gp
             JOIN appbase_photo p ON p.id = gp.photo_id
             WHERE gp.guide_id = $old_id
             ORDER BY gp.id ASC LIMIT 1"
        );
        if ( $ph_result ) {
            $ph = $ph_result->fetch_assoc();
            $ph_result->free();
            if ( $ph ) $photo_path = $ph['photo'] ?? '';
        }

        // ── Insert or update ──────────────────────────────────────────────────
        $existing = get_posts( [
            'post_type'   => 'guide',
            'meta_key'    => '_old_cms_id',
            'meta_value'  => $old_id,
            'numberposts' => 1,
            'fields'      => 'ids',
            'post_status' => 'any',
        ] );

        if ( $existing ) {
            $post_id = $existing[0];
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
            update_post_meta( $post_id, '_guide_quote',       $quote );
            update_post_meta( $post_id, '_guide_description', wp_slash( $description ) );
            update_post_meta( $post_id, '_guide_language',    $language );
            if ( $photo_path ) update_post_meta( $post_id, '_guide_photo_path_old', $photo_path );
            $log[] = [ 'update', "Guide old_id=$old_id updated (WP #$post_id) — $title." ];
        } else {
            $post_id = wp_insert_post( [
                'post_type'   => 'guide',
                'post_title'  => $title,
                'post_status' => 'publish',
            ], true );

            if ( is_wp_error( $post_id ) ) {
                $log[] = [ 'error', "Guide old_id=$old_id: " . $post_id->get_error_message() ];
                continue;
            }

            update_post_meta( $post_id, '_old_cms_id',        $old_id );
            update_post_meta( $post_id, '_guide_quote',       $quote );
            update_post_meta( $post_id, '_guide_description', wp_slash( $description ) );
            update_post_meta( $post_id, '_guide_language',    $language );
            if ( $photo_path ) update_post_meta( $post_id, '_guide_photo_path_old', $photo_path );
            $log[] = [ 'success', "Guide old_id=$old_id imported as WP #$post_id — $title." ];
        }
    }

    $result->free();
    $db->close();

    $next_offset = $offset + $batch_count;
    $done        = ( $batch_count < $limit ) || ( $next_offset >= $total );

    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => $done ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 2 — Sideload guide photos
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_guide_photos( int $offset, int $limit = 3 ): array {
    $all_posts = get_posts( [
        'post_type'   => 'guide',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => '_guide_photo_path_old', 'compare' => 'EXISTS' ],
        ],
    ] );

    // Only posts without a valid photo_id
    $posts = array_values( array_filter( $all_posts, function( $post_id ) {
        $photo_id = get_post_meta( $post_id, '_guide_photo_id', true );
        return empty( $photo_id ) || (int) $photo_id === 0;
    } ) );

    $total = count( $posts );
    $slice = array_slice( $posts, 0, $limit );
    $log   = [];
    $count = 0;

    if ( empty( $slice ) ) {
        return [ 'log' => [ [ 'info', 'No guides need photo sideloading.' ] ], 'batch_count' => 0, 'next_offset' => 0, 'total' => $total, 'done' => true ];
    }

    @set_time_limit( 120 );
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    foreach ( $slice as $post_id ) {
        $count++;
        $photo_path = get_post_meta( $post_id, '_guide_photo_path_old', true );
        $title      = get_the_title( $post_id );
        if ( ! $photo_path ) continue;

        $url = str_starts_with( $photo_path, 'http' )
            ? $photo_path
            : rtrim( GUIDE_SITE_BASE_URL, '/' ) . '/' . ltrim( $photo_path, '/' );

        $timeout_filter = function() { return 30; };
        add_filter( 'http_request_timeout', $timeout_filter );

        try {
            $photo_id = media_sideload_image( $url, $post_id, $title, 'id' );
        } catch ( Throwable $e ) {
            remove_filter( 'http_request_timeout', $timeout_filter );
            $log[] = [ 'error', "WP #$post_id ($title): photo failed — " . $e->getMessage() ];
            continue;
        }

        remove_filter( 'http_request_timeout', $timeout_filter );

        if ( is_wp_error( $photo_id ) ) {
            $log[] = [ 'error', "WP #$post_id ($title): photo failed — " . $photo_id->get_error_message() ];
        } else {
            update_post_meta( $post_id, '_guide_photo_id', (int) $photo_id );
            $log[] = [ 'success', "WP #$post_id ($title): photo sideloaded (attachment #$photo_id)." ];
        }
    }

    $remaining = count( array_filter( $posts, function( $post_id ) {
        $photo_id = get_post_meta( $post_id, '_guide_photo_id', true );
        return empty( $photo_id ) || (int) $photo_id === 0;
    } ) ) - $count;

    return [ 'log' => $log, 'batch_count' => $count, 'next_offset' => 0, 'total' => $total, 'done' => $remaining <= 0 ];
}

function enroute_import_run_guides(): array {
    return enroute_import_batch_guides( 0, 999999 )['log'];
}

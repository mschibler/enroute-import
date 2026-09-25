<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import Points of Interest from the old CMS database.
 *
 * Steps:
 *   enroute_import_batch_poi()        — Step 1: data + language detection
 *   enroute_import_batch_poi_photos() — Step 2: sideload photos
 *
 * Source tables:
 *   points_of_interest_poientry — main POI record
 *   appbase_photo               — photo path via photo_id
 *
 * Language detection: compare description to description_de/fr/it
 */

define( 'POI_SITE_BASE_URL', 'https://enroute.ch/media/' );

// ══════════════════════════════════════════════════════════════════════════════
// STEP 1 — Import POI data
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_poi( int $offset, int $limit = 10 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    @set_time_limit( 120 );

    $count_result = $db->query( "SELECT COUNT(*) AS total FROM points_of_interest_poientry" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $result = $db->query( "
        SELECT
            p.id              AS old_id,
            p.title           AS title,
            p.title_de        AS title_de,
            p.title_fr        AS title_fr,
            p.title_it        AS title_it,
            p.coordinates     AS coordinates,
            p.description     AS description,
            p.description_de  AS description_de,
            p.description_fr  AS description_fr,
            p.description_it  AS description_it,
            p.photo_id        AS photo_id,
            f.photo           AS photo_path
        FROM points_of_interest_poientry p
        LEFT JOIN appbase_photo f ON f.id = p.photo_id
        ORDER BY p.id ASC
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
        $old_id = (int) $row['old_id'];

        // Detect language by comparing base description to language variants
        $desc_base = $row['description'] ?? '';
        if ( $row['description_de'] && $desc_base === $row['description_de'] )      $language = 'de';
        elseif ( $row['description_fr'] && $desc_base === $row['description_fr'] )  $language = 'fr';
        elseif ( $row['description_it'] && $desc_base === $row['description_it'] )  $language = 'it';
        else $language = 'de'; // default

        // Pick title for the detected language
        $title_map = [ 'de' => $row['title_de'], 'fr' => $row['title_fr'], 'it' => $row['title_it'] ];
        $title     = sanitize_text_field( $title_map[ $language ] ?: $row['title'] ?: "POI #$old_id" );

        $description = enroute_import_clean_html( $desc_base );
        $coordinates = sanitize_text_field( $row['coordinates'] ?? '' );
        $photo_path  = $row['photo_path'] ?? '';

        // Insert or update
        $existing = get_posts([
            'post_type'   => 'poi',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => '_old_cms_id', 'value' => $old_id, 'compare' => '=' ],
            ],
        ]);

        if ( $existing ) {
            $post_id = $existing[0];
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
            update_post_meta( $post_id, '_poi_coordinates', $coordinates );
            update_post_meta( $post_id, '_poi_description', wp_slash( $description ) );
            update_post_meta( $post_id, '_poi_language',    $language );
            if ( $photo_path ) update_post_meta( $post_id, '_poi_photo_path_old', $photo_path );
            $log[] = [ 'update', "POI old_id=$old_id updated (WP #$post_id) — $title [$language]." ];
        } else {
            $post_id = wp_insert_post([
                'post_type'   => 'poi',
                'post_title'  => $title,
                'post_status' => 'publish',
            ], true );

            if ( is_wp_error( $post_id ) ) {
                $log[] = [ 'error', "POI old_id=$old_id: " . $post_id->get_error_message() ];
                continue;
            }

            update_post_meta( $post_id, '_old_cms_id',        $old_id );
            update_post_meta( $post_id, '_poi_coordinates',   $coordinates );
            update_post_meta( $post_id, '_poi_description',   wp_slash( $description ) );
            update_post_meta( $post_id, '_poi_language',      $language );
            if ( $photo_path ) update_post_meta( $post_id, '_poi_photo_path_old', $photo_path );
            $log[] = [ 'success', "POI old_id=$old_id imported as WP #$post_id — $title [$language]." ];
        }
    }

    $result->free();
    $db->close();

    $next_offset = $offset + $batch_count;
    $done        = ( $batch_count < $limit ) || ( $next_offset >= $total );

    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => $done ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 2 — Sideload POI photos
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_poi_photos( int $offset, int $limit = 3 ): array {
    $all_posts = get_posts([
        'post_type'   => 'poi',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => '_poi_photo_path_old', 'compare' => 'EXISTS' ],
        ],
    ]);

    $posts = array_values( array_filter( $all_posts, function( $post_id ) {
        $photo_id = get_post_meta( $post_id, '_poi_photo_id', true );
        return empty( $photo_id ) || (int) $photo_id === 0;
    } ) );

    $total = count( $posts );
    $slice = array_slice( $posts, 0, $limit );
    $log   = [];
    $count = 0;

    if ( empty( $slice ) ) {
        return [ 'log' => [ [ 'info', 'No POIs need photo sideloading.' ] ], 'batch_count' => 0, 'next_offset' => 0, 'total' => $total, 'done' => true ];
    }

    @set_time_limit( 120 );
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    foreach ( $slice as $post_id ) {
        $count++;
        $photo_path = get_post_meta( $post_id, '_poi_photo_path_old', true );
        $title      = get_the_title( $post_id );
        if ( ! $photo_path ) continue;

        $url = str_starts_with( $photo_path, 'http' )
            ? $photo_path
            : rtrim( POI_SITE_BASE_URL, '/' ) . '/' . ltrim( $photo_path, '/' );

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
            update_post_meta( $post_id, '_poi_photo_id', (int) $photo_id );
            $log[] = [ 'success', "WP #$post_id ($title): photo sideloaded (attachment #$photo_id)." ];
        }
    }

    $remaining = count( array_filter( $posts, function( $post_id ) {
        $photo_id = get_post_meta( $post_id, '_poi_photo_id', true );
        return empty( $photo_id ) || (int) $photo_id === 0;
    } ) ) - $count;

    return [ 'log' => $log, 'batch_count' => $count, 'next_offset' => 0, 'total' => $total, 'done' => $remaining <= 0 ];
}

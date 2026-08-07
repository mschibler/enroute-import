<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import resources from the old CMS database — AJAX batch version.
 *
 * Steps:
 *   enroute_import_batch_resources()          — Step 1: data only
 *   enroute_import_batch_resource_files()     — Step 2: sideload files
 *   enroute_import_batch_resource_subjects()  — Step 3: assign subjects
 *   enroute_import_batch_resource_types()     — Step 4: assign resource types
 *   enroute_import_batch_resource_religions() — Step 5: resolve religions → subjects
 */

define( 'RESOURCE_SITE_BASE_URL', 'https://enroute.ch/media/' );

define( 'RESOURCE_LANGUAGE_MAP', serialize( [
    1 => 'de', 2 => 'en', 3 => 'fr', 4 => 'it',
] ) );

// ══════════════════════════════════════════════════════════════════════════════
// STEP 1 — Import resource data
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_resources( int $offset, int $limit = 10 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    @set_time_limit( 120 );
    $count_result = $db->query( "SELECT COUNT(*) AS total FROM bibliography_bibliographyentry" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $result = $db->query( "SELECT id, title, file, external_link, language_id FROM bibliography_bibliographyentry ORDER BY id ASC LIMIT $limit OFFSET $offset" );
    if ( ! $result ) {
        $db->close();
        return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ];
    }

    $language_map = unserialize( RESOURCE_LANGUAGE_MAP );
    $log          = [];
    $batch_count  = 0;

    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id = (int) $row['id'];

        $title         = sanitize_text_field( wp_strip_all_tags( $row['title'] ?? '' ) );
        $file_path     = $row['file'] ?? '';
        $external_link = esc_url_raw( $row['external_link'] ?? '' );
        $language      = $language_map[ (int) $row['language_id'] ] ?? 'de';

        if ( ! $title ) {
            $title = "Resource #$old_id";
            $log[] = [ 'info', "Resource old_id=$old_id has no title — using placeholder." ];
        }

        $meta = [
            '_resource_language'      => $language,
            '_resource_external_link' => $external_link,
        ];
        if ( $file_path ) $meta['_resource_file_path_old'] = $file_path;

        $existing = get_posts( [ 'post_type' => 'resource', 'meta_key' => '_old_cms_id', 'meta_value' => $old_id, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ] );

        if ( $existing ) {
            $post_id = $existing[0];
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
            foreach ( $meta as $key => $value ) update_post_meta( $post_id, $key, $value );
            $log[] = [ 'update', "Resource old_id=$old_id updated (WP #$post_id)." ];
        } else {
            $post_id = wp_insert_post( [ 'post_type' => 'resource', 'post_title' => $title, 'post_status' => 'publish' ], true );
            if ( is_wp_error( $post_id ) ) { $log[] = [ 'error', "Resource old_id=$old_id: " . $post_id->get_error_message() ]; continue; }
            update_post_meta( $post_id, '_old_cms_id', $old_id );
            foreach ( $meta as $key => $value ) update_post_meta( $post_id, $key, $value );
            $log[] = [ 'success', "Resource old_id=$old_id imported as WP #$post_id." ];
        }
    }

    $result->free();
    $db->close();
    $next_offset = $offset + $batch_count;
    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => ($batch_count < $limit) || ($next_offset >= $total) ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 2 — Sideload resource files
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_resource_files( int $offset, int $limit = 3 ): array {
    $all_posts = get_posts( [ 'post_type' => 'resource', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_query' => [ [ 'key' => '_resource_file_path_old', 'compare' => 'EXISTS' ] ] ] );

    $posts = array_values( array_filter( $all_posts, function( $post_id ) {
        $file_id = get_post_meta( $post_id, '_resource_file_id', true );
        return empty( $file_id ) || (int) $file_id === 0;
    } ) );

    $total = count( $posts );
    $slice = array_slice( $posts, 0, $limit );
    $log   = [];
    $count = 0;

    if ( empty( $slice ) ) {
        return [ 'log' => [ [ 'info', 'No resources need file sideloading.' ] ], 'batch_count' => 0, 'next_offset' => 0, 'total' => $total, 'done' => true ];
    }

    @set_time_limit( 120 );
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    foreach ( $slice as $post_id ) {
        $count++;
        $file_path = get_post_meta( $post_id, '_resource_file_path_old', true );
        $title     = get_the_title( $post_id );
        if ( ! $file_path ) continue;

        $url = str_starts_with( $file_path, 'http' ) ? $file_path : rtrim( RESOURCE_SITE_BASE_URL, '/' ) . '/' . ltrim( $file_path, '/' );

        $timeout_filter = function() { return 30; };
        add_filter( 'http_request_timeout', $timeout_filter );

        try {
            $tmp = download_url( $url );
            if ( is_wp_error( $tmp ) ) throw new RuntimeException( $tmp->get_error_message() );
            $attachment_id = media_handle_sideload( [ 'name' => basename( $file_path ), 'tmp_name' => $tmp ], $post_id, $title );
            if ( is_wp_error( $attachment_id ) ) { @unlink( $tmp ); throw new RuntimeException( $attachment_id->get_error_message() ); }
            update_post_meta( $post_id, '_resource_file_id', (int) $attachment_id );
            $log[] = [ 'success', "WP #$post_id ($title): file sideloaded (attachment #$attachment_id)." ];
        } catch ( Throwable $e ) {
            $log[] = [ 'error', "WP #$post_id ($title): file failed — " . $e->getMessage() ];
        }

        remove_filter( 'http_request_timeout', $timeout_filter );
    }

    $remaining = count( array_filter( $posts, function( $post_id ) {
        $file_id = get_post_meta( $post_id, '_resource_file_id', true );
        return empty( $file_id ) || (int) $file_id === 0;
    } ) ) - $count;
    $done = $remaining <= 0;
    return [ 'log' => $log, 'batch_count' => $count, 'next_offset' => 0, 'total' => $total, 'done' => $done ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 3 — Assign subjects
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_resource_subjects( int $offset, int $limit = 10 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    $count_result = $db->query( "SELECT COUNT(DISTINCT bibliographyentry_id) AS total FROM bibliography_bibliographyentry_topics" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;
    $result       = $db->query( "SELECT DISTINCT bibliographyentry_id FROM bibliography_bibliographyentry_topics ORDER BY bibliographyentry_id ASC LIMIT $limit OFFSET $offset" );
    if ( ! $result ) { $db->close(); return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ]; }

    $log = []; $batch_count = 0;
    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id = (int) $row['bibliographyentry_id'];
        $res    = get_posts( [ 'post_type' => 'resource', 'meta_key' => '_old_cms_id', 'meta_value' => $old_id, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ] );
        if ( ! $res ) { $log[] = [ 'skip', "Resource old_id=$old_id not found — skipping subjects." ]; continue; }
        $post_id = $res[0];

        $topics   = $db->query( "SELECT t.name_de, t.name_fr, t.name_it, t.slug FROM bibliography_bibliographyentry_topics bt JOIN appbase_topic t ON t.id = bt.topic_id WHERE bt.bibliographyentry_id = $old_id" );
        $term_ids = [];
        while ( $t = $topics->fetch_assoc() ) {
            $tid = enroute_import_get_or_create_term( $t['name_de'] ?? '', $t['name_fr'] ?? '', $t['name_it'] ?? '', $t['slug'] ?? '', 'offer_subject' );
            if ( $tid ) $term_ids[] = $tid;
        }
        $topics->free();
        if ( $term_ids ) { wp_set_object_terms( $post_id, $term_ids, 'offer_subject', true ); $log[] = [ 'success', "Resource old_id=$old_id: " . count($term_ids) . " subject(s) assigned." ]; }
    }

    $result->free(); $db->close();
    $next_offset = $offset + $batch_count;
    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => ($batch_count < $limit) || ($next_offset >= $total) ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 4 — Assign resource types
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_resource_types( int $offset, int $limit = 10 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    $count_result = $db->query( "SELECT COUNT(DISTINCT bibliographyentry_id) AS total FROM bibliography_bibliographyentry_medium" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;
    $result       = $db->query( "SELECT DISTINCT bibliographyentry_id FROM bibliography_bibliographyentry_medium ORDER BY bibliographyentry_id ASC LIMIT $limit OFFSET $offset" );
    if ( ! $result ) { $db->close(); return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ]; }

    $log = []; $batch_count = 0;
    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id = (int) $row['bibliographyentry_id'];
        $res    = get_posts( [ 'post_type' => 'resource', 'meta_key' => '_old_cms_id', 'meta_value' => $old_id, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ] );
        if ( ! $res ) { $log[] = [ 'skip', "Resource old_id=$old_id not found — skipping types." ]; continue; }
        $post_id = $res[0];

        $types    = $db->query( "SELECT m.name_de, m.name_fr, m.name_it, m.name AS name_fallback FROM bibliography_bibliographyentry_medium bm JOIN bibliography_bibliographymedium m ON m.id = bm.bibliographymedium_id WHERE bm.bibliographyentry_id = $old_id" );
        $term_ids = [];
        while ( $m = $types->fetch_assoc() ) {
            $name_de = $m['name_de'] ?: $m['name_fallback'];
            $tid     = enroute_import_get_or_create_term( $name_de, $m['name_fr'] ?? '', $m['name_it'] ?? '', sanitize_title( $name_de ), 'resource_type' );
            if ( $tid ) $term_ids[] = $tid;
        }
        $types->free();
        if ( $term_ids ) { wp_set_object_terms( $post_id, $term_ids, 'resource_type', true ); $log[] = [ 'success', "Resource old_id=$old_id: " . count($term_ids) . " type(s) assigned." ]; }
    }

    $result->free(); $db->close();
    $next_offset = $offset + $batch_count;
    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => ($batch_count < $limit) || ($next_offset >= $total) ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 5 — Resolve religions → subjects via mapping table
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_get_religion_topic_map(): array {
    return [
        1  => 28,   // Alevitentum
        2  => 31,   // Baha'i
        3  => 1,    // Buddhismus
        4  => 10,   // Christentum
        5  => 314,  // Katholizismus
        6  => 315,  // Evangelische Kirchen
        7  => 316,  // Strömungen
        8  => 13,   // Hinduistische Religionen
        9  => 12,   // Islam
        10 => 7,    // Judentum
        11 => 317,  // Neue religiöse Bewegungen
        12 => 37,   // Sikhismus
        13 => 273,  // Taoismus
        14 => 239,  // Antike Religionen
        // 15 => no mapping (Jenische und Sinti)
        // 16 => no mapping (Weitere)
        17 => 74,   // Religion allgemein
        19 => 69,   // Humanismus
    ];
}

function enroute_import_batch_resource_religions( int $offset, int $limit = 10 ): array {
    $map = enroute_import_get_religion_topic_map();
    if ( empty( $map ) ) {
        return [ 'log' => [ [ 'info', 'Religion → topic mapping table is empty.' ] ], 'batch_count' => 0, 'next_offset' => 0, 'total' => 0, 'done' => true ];
    }

    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    $count_result = $db->query( "SELECT COUNT(DISTINCT bibliographyentry_id) AS total FROM bibliography_bibliographyentry_religions" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;
    $result       = $db->query( "SELECT DISTINCT bibliographyentry_id FROM bibliography_bibliographyentry_religions ORDER BY bibliographyentry_id ASC LIMIT $limit OFFSET $offset" );
    if ( ! $result ) { $db->close(); return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ]; }

    $log = []; $batch_count = 0;
    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id = (int) $row['bibliographyentry_id'];
        $res    = get_posts( [ 'post_type' => 'resource', 'meta_key' => '_old_cms_id', 'meta_value' => $old_id, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ] );
        if ( ! $res ) { $log[] = [ 'skip', "Resource old_id=$old_id not found — skipping religion→subject." ]; continue; }
        $post_id = $res[0];

        $religions = $db->query( "SELECT religion_id FROM bibliography_bibliographyentry_religions WHERE bibliographyentry_id = $old_id" );
        $term_ids  = [];
        while ( $r = $religions->fetch_assoc() ) {
            $religion_id = (int) $r['religion_id'];
            $topic_id    = $map[ $religion_id ] ?? null;
            if ( ! $topic_id ) continue;
            $topic = $db->query( "SELECT name_de, name_fr, name_it, slug FROM appbase_topic WHERE id = $topic_id LIMIT 1" );
            if ( ! $topic ) continue;
            $trow = $topic->fetch_assoc();
            $topic->free();
            if ( ! $trow ) continue;
            $tid = enroute_import_get_or_create_term( $trow['name_de'] ?? '', $trow['name_fr'] ?? '', $trow['name_it'] ?? '', $trow['slug'] ?? '', 'offer_subject' );
            if ( $tid ) $term_ids[] = $tid;
        }
        $religions->free();
        if ( $term_ids ) { wp_set_object_terms( $post_id, array_unique( $term_ids ), 'offer_subject', true ); $log[] = [ 'success', "Resource old_id=$old_id: " . count($term_ids) . " subject(s) added via religion mapping." ]; }
        else { $log[] = [ 'skip', "Resource old_id=$old_id: no matching topics in religion map." ]; }
    }

    $result->free(); $db->close();
    $next_offset = $offset + $batch_count;
    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => ($batch_count < $limit) || ($next_offset >= $total) ];
}

function enroute_import_run_resources(): array {
    return enroute_import_batch_resources( 0, 999999 )['log'];
}

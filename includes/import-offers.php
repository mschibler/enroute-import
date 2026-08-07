<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import offers from the old CMS database — AJAX batch version.
 *
 * Steps:
 *   enroute_import_batch_offers()           — Step 1: data only
 *   enroute_import_batch_offer_photos()     — Step 2: sideload images
 *   enroute_import_batch_offer_subjects()   — Step 3: assign subjects (appbase_topic)
 *   enroute_import_batch_offer_targets()    — Step 4: assign target groups (provider_activitycategory)
 *   enroute_import_batch_offer_recurrence() — Step 5: assign weekdays
 */

define( 'OFFER_SITE_BASE_URL', 'https://enroute.ch/media/' );

define( 'OFFER_WEEKDAY_MAP', serialize( [
    1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
    5 => 'friday', 6 => 'saturday', 7 => 'sunday',
] ) );

// ══════════════════════════════════════════════════════════════════════════════
// STEP 1 — Import offer data
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_offers( int $offset, int $limit = 5 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    @set_time_limit( 120 );

    $count_result = $db->query( "SELECT COUNT(*) AS total FROM provider_activity WHERE active_until IS NULL OR active_until >= CURDATE()" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $sql = "
        SELECT
            a.id                        AS old_id,
            a.title                     AS title,
            a.description               AS short_description,
            a.fulltext                  AS description,
            a.slug                      AS slug,
            a.recurrence_description    AS date_info,
            a.pricing_description       AS price,
            a.booking_email             AS contact_email,
            a.can_book                  AS bookable,
            a.provider_id               AS old_station_id,
            ph.photo                    AS photo_path
        FROM provider_activity a
        LEFT JOIN appbase_photo ph ON ph.id = a.event_image_id
        WHERE a.active_until IS NULL OR a.active_until >= CURDATE()
        ORDER BY a.id ASC
        LIMIT $limit OFFSET $offset
    ";

    $result = $db->query( $sql );
    if ( ! $result ) {
        $db->close();
        return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ];
    }

    $log         = [];
    $batch_count = 0;

    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id = (int) $row['old_id'];

        $title         = sanitize_text_field( $row['title']                  ?? '' );
        $short_desc    = sanitize_textarea_field( $row['short_description']   ?? '' );
        $description   = wp_kses_post( $row['description']                   ?? '' );
        $slug          = sanitize_title( $row['slug']                        ?? '' );
        $date_info     = sanitize_text_field( $row['date_info']              ?? '' );
        $price         = sanitize_text_field( $row['price']                  ?? '' );
        $contact_email = sanitize_email( $row['contact_email']               ?? '' );
        $bookable      = empty( $row['bookable'] ) ? '0' : '1';
        $photo_path    = $row['photo_path'] ?? '';

        if ( ! $title ) {
            $title = "Offer #$old_id";
            $log[] = [ 'info', "Offer old_id=$old_id has no title — using placeholder." ];
        }

        // Resolve station
        $wp_station_id = 0;
        if ( ! empty( $row['old_station_id'] ) ) {
            $station_posts = get_posts( [
                'post_type'   => 'station',
                'meta_key'    => '_old_cms_id',
                'meta_value'  => (int) $row['old_station_id'],
                'numberposts' => 1,
                'fields'      => 'ids',
                'post_status' => 'any',
            ] );
            $wp_station_id = $station_posts[0] ?? 0;
        }

        $meta = [
            '_offer_subtitle'      => $short_desc,
            '_offer_description'   => $description,
            '_offer_date_info'     => $date_info,
            '_offer_price'         => $price,
            '_offer_contact_email' => $contact_email,
            '_offer_bookable'      => $bookable,
            '_offer_station'       => $wp_station_id,
        ];

        $existing = get_posts( [
            'post_type'   => 'offer',
            'meta_key'    => '_old_cms_id',
            'meta_value'  => $old_id,
            'numberposts' => 1,
            'fields'      => 'ids',
            'post_status' => 'any',
        ] );

        if ( $existing ) {
            $post_id = $existing[0];
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title, 'post_name' => $slug ] );
            foreach ( $meta as $key => $value ) update_post_meta( $post_id, $key, $value );
            if ( $photo_path ) update_post_meta( $post_id, '_offer_photo_path_old', $photo_path );
            $log[] = [ 'update', "Offer old_id=$old_id updated (WP #$post_id)." ];
        } else {
            $post_id = wp_insert_post( [
                'post_type'   => 'offer',
                'post_title'  => $title,
                'post_name'   => $slug,
                'post_status' => 'publish',
            ], true );

            if ( is_wp_error( $post_id ) ) {
                $log[] = [ 'error', "Offer old_id=$old_id: " . $post_id->get_error_message() ];
                continue;
            }

            update_post_meta( $post_id, '_old_cms_id', $old_id );
            foreach ( $meta as $key => $value ) update_post_meta( $post_id, $key, $value );
            if ( $photo_path ) update_post_meta( $post_id, '_offer_photo_path_old', $photo_path );
            $log[] = [ 'success', "Offer old_id=$old_id imported as WP #$post_id." ];
        }
    }

    $result->free();
    $db->close();

    $next_offset = $offset + $batch_count;
    $done        = ( $batch_count < $limit ) || ( $next_offset >= $total );

    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => $done ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 2 — Sideload offer images (fixed: checks all without valid photo_id)
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_offer_photos( int $offset, int $limit = 3 ): array {
    // Get all offers with a stored photo path
    $all_posts = get_posts( [
        'post_type'   => 'offer',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => '_offer_photo_path_old', 'compare' => 'EXISTS' ],
        ],
    ] );

    // Filter to only those without a valid photo_id
    $posts = array_values( array_filter( $all_posts, function( $post_id ) {
        $photo_id = get_post_meta( $post_id, '_offer_photo_id', true );
        return empty( $photo_id ) || (int) $photo_id === 0;
    } ) );

    $total = count( $posts );
    // Always take from the start of the filtered list — offset is not used here
    // because each successful import removes the post from the filtered list
    $slice = array_slice( $posts, 0, $limit );
    $log   = [];
    $count = 0;

    if ( empty( $slice ) ) {
        return [ 'log' => [ [ 'info', 'No offers need photo sideloading.' ] ], 'batch_count' => 0, 'next_offset' => 0, 'total' => $total, 'done' => true ];
    }

    @set_time_limit( 120 );
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    foreach ( $slice as $post_id ) {
        $count++;
        $photo_path = get_post_meta( $post_id, '_offer_photo_path_old', true );
        $title      = get_the_title( $post_id );
        if ( ! $photo_path ) continue;

        $url = str_starts_with( $photo_path, 'http' )
            ? $photo_path
            : rtrim( OFFER_SITE_BASE_URL, '/' ) . '/' . ltrim( $photo_path, '/' );

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
            update_post_meta( $post_id, '_offer_photo_id', (int) $photo_id );
            set_post_thumbnail( $post_id, (int) $photo_id );
            $log[] = [ 'success', "WP #$post_id ($title): photo sideloaded (attachment #$photo_id)." ];
        }
    }

    // Recalculate remaining after this batch
    $remaining = count( array_filter( $posts, function( $post_id ) {
        $photo_id = get_post_meta( $post_id, '_offer_photo_id', true );
        return empty( $photo_id ) || (int) $photo_id === 0;
    } ) ) - $count;
    $done = $remaining <= 0;
    return [ 'log' => $log, 'batch_count' => $count, 'next_offset' => 0, 'total' => $total, 'done' => $done ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 3 — Assign subjects (provider_activity_topics → appbase_topic)
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_offer_subjects( int $offset, int $limit = 10 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    $count_result = $db->query( "SELECT COUNT(DISTINCT activity_id) AS total FROM provider_activity_topics" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $result = $db->query( "SELECT DISTINCT activity_id FROM provider_activity_topics ORDER BY activity_id ASC LIMIT $limit OFFSET $offset" );
    if ( ! $result ) {
        $db->close();
        return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ];
    }

    $log         = [];
    $batch_count = 0;

    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id = (int) $row['activity_id'];

        $offer = get_posts( [ 'post_type' => 'offer', 'meta_key' => '_old_cms_id', 'meta_value' => $old_id, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ] );
        if ( ! $offer ) { $log[] = [ 'skip', "Offer old_id=$old_id not found — skipping subjects." ]; continue; }
        $post_id = $offer[0];

        $topics = $db->query( "SELECT t.name_de, t.name_fr, t.name_it, t.slug FROM provider_activity_topics at JOIN appbase_topic t ON t.id = at.topic_id WHERE at.activity_id = $old_id" );
        $term_ids = [];
        while ( $t = $topics->fetch_assoc() ) {
            $tid = enroute_import_get_or_create_term( $t['name_de'] ?? '', $t['name_fr'] ?? '', $t['name_it'] ?? '', $t['slug'] ?? '', 'offer_subject' );
            if ( $tid ) $term_ids[] = $tid;
        }
        $topics->free();
        if ( $term_ids ) { wp_set_object_terms( $post_id, $term_ids, 'offer_subject' ); $log[] = [ 'success', "Offer old_id=$old_id: " . count($term_ids) . " subject(s) assigned." ]; }
    }

    $result->free();
    $db->close();
    $next_offset = $offset + $batch_count;
    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => ($batch_count < $limit) || ($next_offset >= $total) ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 4 — Assign target groups (provider_activity_categories → provider_activitycategory)
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_offer_targets( int $offset, int $limit = 10 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    $count_result = $db->query( "SELECT COUNT(DISTINCT activity_id) AS total FROM provider_activity_categories" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $result = $db->query( "SELECT DISTINCT activity_id FROM provider_activity_categories ORDER BY activity_id ASC LIMIT $limit OFFSET $offset" );
    if ( ! $result ) {
        $db->close();
        return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ];
    }

    $log         = [];
    $batch_count = 0;

    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id = (int) $row['activity_id'];

        $offer = get_posts( [ 'post_type' => 'offer', 'meta_key' => '_old_cms_id', 'meta_value' => $old_id, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ] );
        if ( ! $offer ) { $log[] = [ 'skip', "Offer old_id=$old_id not found — skipping target groups." ]; continue; }
        $post_id = $offer[0];

        $targets = $db->query( "SELECT c.name_de, c.name_fr, c.name_it, c.slug FROM provider_activity_categories ac JOIN provider_activitycategory c ON c.id = ac.activitycategory_id WHERE ac.activity_id = $old_id" );
        $term_ids = [];
        while ( $trow = $targets->fetch_assoc() ) {
            $tid = enroute_import_get_or_create_term( $trow['name_de'] ?? '', $trow['name_fr'] ?? '', $trow['name_it'] ?? '', $trow['slug'] ?? '', 'offer_target_group' );
            if ( $tid ) $term_ids[] = $tid;
        }
        $targets->free();
        if ( $term_ids ) { wp_set_object_terms( $post_id, $term_ids, 'offer_target_group' ); $log[] = [ 'success', "Offer old_id=$old_id: " . count($term_ids) . " target group(s) assigned." ]; }
    }

    $result->free();
    $db->close();
    $next_offset = $offset + $batch_count;
    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => ($batch_count < $limit) || ($next_offset >= $total) ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 5 — Assign weekdays
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_offer_recurrence( int $offset, int $limit = 10 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    $weekday_map  = unserialize( OFFER_WEEKDAY_MAP );
    $count_result = $db->query( "SELECT COUNT(DISTINCT activity_id) AS total FROM provider_activity_recurrences" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $result = $db->query( "SELECT DISTINCT activity_id FROM provider_activity_recurrences ORDER BY activity_id ASC LIMIT $limit OFFSET $offset" );
    if ( ! $result ) {
        $db->close();
        return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ];
    }

    $log         = [];
    $batch_count = 0;

    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id = (int) $row['activity_id'];

        $offer = get_posts( [ 'post_type' => 'offer', 'meta_key' => '_old_cms_id', 'meta_value' => $old_id, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ] );
        if ( ! $offer ) { $log[] = [ 'skip', "Offer old_id=$old_id not found — skipping recurrence." ]; continue; }
        $post_id = $offer[0];

        $rec = $db->query( "SELECT ar.recurrence_id FROM provider_activity_recurrences ar WHERE ar.activity_id = $old_id ORDER BY ar.recurrence_id ASC" );
        $weekdays = [];
        while ( $r = $rec->fetch_assoc() ) {
            $day = (int) $r['recurrence_id'];
            if ( isset( $weekday_map[$day] ) ) $weekdays[] = $weekday_map[$day];
        }
        $rec->free();
        if ( $weekdays ) { update_post_meta( $post_id, '_offer_weekdays', $weekdays ); $log[] = [ 'success', "Offer old_id=$old_id: weekdays assigned: " . implode(', ', $weekdays) . "." ]; }
    }

    $result->free();
    $db->close();
    $next_offset = $offset + $batch_count;
    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => ($batch_count < $limit) || ($next_offset >= $total) ];
}

function enroute_import_run_offers(): array {
    return enroute_import_batch_offers( 0, 999999 )['log'];
}

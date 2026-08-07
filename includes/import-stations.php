<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import / update stations from the old CMS database — AJAX batch version.
 *
 * Source tables:
 *   provider_provider  — active, contact_details, photo_main_id, coordinates_location_id
 *   appbase_address    — title (nick_name), contact person, address, zip, city, email, phone, website, coordinates
 *   appbase_photo      — photo path (via photo_main_id)
 *   provider_narration — portrait rows (concatenated, ordered)
 */

define( 'OLD_SITE_BASE_URL', 'https://enroute.ch/media/' );

function enroute_import_batch_stations( int $offset, int $limit = 5 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    @set_time_limit( 120 );

    $count_result = $db->query( "SELECT COUNT(*) AS total FROM provider_provider WHERE active = 1" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $sql = "
        SELECT
            p.id                AS old_id,
            p.active            AS active,
            p.contact_details   AS contact_details,
            a.nick_name         AS title,
            a.first_name        AS first_name,
            a.last_name         AS last_name,
            a.address           AS address,
            a.zip               AS zip,
            a.city              AS city,
            a.email             AS email,
            a.mobile            AS phone,
            a.website           AS website,
            a.coordinates       AS coordinates,
            ph.photo            AS photo_path
        FROM provider_provider p
        LEFT JOIN appbase_address a  ON a.id  = p.coordinates_location_id
        LEFT JOIN appbase_photo   ph ON ph.id = p.photo_main_id
        WHERE p.active = 1
        ORDER BY p.id ASC
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

        $title           = sanitize_text_field( $row['title'] ?? '' );
        $contact_person  = sanitize_text_field( trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) );
        $address         = sanitize_text_field( $row['address']         ?? '' );
        $plz             = sanitize_text_field( $row['zip']             ?? '' );
        $place           = sanitize_text_field( $row['city']            ?? '' );
        $email           = sanitize_email(      $row['email']           ?? '' );
        $phone           = sanitize_text_field( $row['phone']           ?? '' );
        $website         = esc_url_raw(         $row['website']         ?? '' );
        $coordinates     = sanitize_text_field( $row['coordinates']     ?? '' );
        $contact_details = sanitize_textarea_field( $row['contact_details'] ?? '' );
        $active          = empty( $row['active'] ) ? '0' : '1';
        $photo_path      = $row['photo_path'] ?? '';

        if ( ! $title ) {
            $title = "Station #$old_id";
            $log[] = [ 'info', "Station old_id=$old_id has no title — using placeholder \"$title\"." ];
        }

        // Narrations
        $portrait    = '';
        $narr_result = $db->query(
            "SELECT title, description FROM provider_narration
             WHERE provider_id = $old_id AND active = 1
             ORDER BY `order` ASC"
        );
        if ( $narr_result ) {
            $parts = [];
            while ( $n = $narr_result->fetch_assoc() ) {
                $n_title = trim( $n['title']       ?? '' );
                $n_desc  = trim( $n['description'] ?? '' );
                $block   = '<p>';
                if ( $n_title ) $block .= '<strong>' . esc_html( $n_title ) . '</strong><br>';
                if ( $n_desc )  $block .= esc_html( $n_desc );
                $block  .= '</p>';
                if ( $n_title || $n_desc ) $parts[] = $block;
            }
            $portrait = implode( "\n", $parts );
            $narr_result->free();
        }

        $meta = [
            '_station_portrait'        => $portrait,
            '_station_address'         => $address,
            '_station_plz'             => $plz,
            '_station_place'           => $place,
            '_station_email'           => $email,
            '_station_phone'           => $phone,
            '_station_website'         => $website,
            '_station_contact_person'  => $contact_person,
            '_station_contact_details' => $contact_details,
            '_station_coordinates'     => $coordinates,
            '_station_active'          => $active,
        ];

        $existing = get_posts( [
            'post_type'   => 'station',
            'meta_key'    => '_old_cms_id',
            'meta_value'  => $old_id,
            'numberposts' => 1,
            'fields'      => 'ids',
            'post_status' => 'any',
        ] );

        if ( $existing ) {
            $post_id = $existing[0];
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
            foreach ( $meta as $key => $value ) update_post_meta( $post_id, $key, $value );
            if ( $photo_path ) update_post_meta( $post_id, '_station_photo_path_old', $photo_path );
            $log[] = [ 'update', "Station old_id=$old_id updated (WP #$post_id)." ];
        } else {
            $post_id = wp_insert_post( [
                'post_type'   => 'station',
                'post_title'  => $title,
                'post_status' => 'publish',
            ], true );

            if ( is_wp_error( $post_id ) ) {
                $log[] = [ 'error', "Station old_id=$old_id: " . $post_id->get_error_message() ];
                continue;
            }

            update_post_meta( $post_id, '_old_cms_id', $old_id );
            foreach ( $meta as $key => $value ) update_post_meta( $post_id, $key, $value );
            if ( $photo_path ) update_post_meta( $post_id, '_station_photo_path_old', $photo_path );
            $log[] = [ 'success', "Station old_id=$old_id imported as WP #$post_id." ];
        }
    }

    $result->free();
    $db->close();

    $next_offset = $offset + $batch_count;
    $done        = ( $batch_count < $limit ) || ( $next_offset >= $total );

    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => $done ];
}

// ── Photo sideload step ───────────────────────────────────────────────────────

function enroute_import_batch_station_photos( int $offset, int $limit = 3 ): array {
    $all_posts = get_posts( [
        'post_type'   => 'station',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => '_station_photo_path_old', 'compare' => 'EXISTS' ],
        ],
    ] );

    // Only posts without a valid photo_id
    $posts = array_values( array_filter( $all_posts, function( $post_id ) {
        $photo_id = get_post_meta( $post_id, '_station_photo_id', true );
        return empty( $photo_id ) || (int) $photo_id === 0;
    } ) );

    $total = count( $posts );
    $slice = array_slice( $posts, 0, $limit );
    $log   = [];
    $count = 0;

    if ( empty( $slice ) ) {
        return [ 'log' => [ [ 'info', 'No stations need photo sideloading.' ] ], 'batch_count' => 0, 'next_offset' => 0, 'total' => $total, 'done' => true ];
    }

    @set_time_limit( 120 );
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    foreach ( $slice as $post_id ) {
        $count++;
        $photo_path = get_post_meta( $post_id, '_station_photo_path_old', true );
        $title      = get_the_title( $post_id );
        if ( ! $photo_path ) continue;

        $photo_id = enroute_import_sideload_image( $photo_path, $post_id, $title );
        if ( is_wp_error( $photo_id ) ) {
            $log[] = [ 'error', "WP #$post_id ($title): photo failed — " . $photo_id->get_error_message() ];
        } else {
            update_post_meta( $post_id, '_station_photo_id', $photo_id );
            $log[] = [ 'success', "WP #$post_id ($title): photo sideloaded (attachment #$photo_id)." ];
        }
    }

    $remaining = count( array_filter( $posts, function( $post_id ) {
        $photo_id = get_post_meta( $post_id, '_station_photo_id', true );
        return empty( $photo_id ) || (int) $photo_id === 0;
    } ) ) - $count;
    $done = $remaining <= 0;
    return [ 'log' => $log, 'batch_count' => $count, 'next_offset' => 0, 'total' => $total, 'done' => $done ];
}

// ── Shared helpers ────────────────────────────────────────────────────────────

function enroute_import_sideload_image( string $path, int $post_id, string $title ): int|WP_Error {
    $url = str_starts_with( $path, 'http' )
        ? $path
        : rtrim( OLD_SITE_BASE_URL, '/' ) . '/' . ltrim( $path, '/' );

    $timeout_filter = function() { return 30; };
    add_filter( 'http_request_timeout', $timeout_filter );

    try {
        $attachment_id = media_sideload_image( $url, $post_id, $title, 'id' );
    } catch ( Throwable $e ) {
        remove_filter( 'http_request_timeout', $timeout_filter );
        return new WP_Error( 'sideload_exception', $e->getMessage() );
    }

    remove_filter( 'http_request_timeout', $timeout_filter );
    return is_wp_error( $attachment_id ) ? $attachment_id : (int) $attachment_id;
}

function enroute_import_get_or_create_term( string $name_de, string $name_fr, string $name_it, string $slug, string $taxonomy ): ?int {
    if ( ! $name_de ) return null;

    $pll_active = function_exists( 'pll_set_term_language' ) && function_exists( 'pll_save_term_translations' );

    $term_de = get_term_by( 'name', $name_de, $taxonomy );
    if ( ! $term_de ) {
        $result = wp_insert_term( $name_de, $taxonomy, [ 'slug' => sanitize_title( $slug ?: $name_de ) ] );
        if ( is_wp_error( $result ) ) return null;
        $term_de = get_term( $result['term_id'], $taxonomy );
    }
    $term_de_id   = (int) $term_de->term_id;
    $translations = [ 'de' => $term_de_id ];

    if ( $pll_active ) pll_set_term_language( $term_de_id, 'de' );

    foreach ( [ 'fr' => $name_fr, 'it' => $name_it ] as $lang => $name ) {
        if ( ! $name || ! $pll_active ) continue;
        $term = get_term_by( 'name', $name, $taxonomy );
        if ( ! $term ) {
            $result = wp_insert_term( $name, $taxonomy, [ 'slug' => sanitize_title( $slug . '-' . $lang ) ] );
            if ( ! is_wp_error( $result ) ) $term = get_term( $result['term_id'], $taxonomy );
        }
        if ( $term && ! is_wp_error( $term ) ) {
            pll_set_term_language( $term->term_id, $lang );
            $translations[ $lang ] = $term->term_id;
        }
    }

    if ( $pll_active && count( $translations ) > 1 ) {
        pll_save_term_translations( $translations );
    }

    return $term_de_id;
}

function enroute_import_run_stations(): array {
    return enroute_import_batch_stations( 0, 999999 )['log'];
}

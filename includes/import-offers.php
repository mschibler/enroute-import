<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import offers from the old CMS database.
 * Run AFTER stations so station references can be resolved.
 *
 * @return array  Log entries: [ ['success'|'skip'|'error', 'message'], ... ]
 */
function enroute_import_run_offers(): array {
    $log = [];
    $db  = enroute_import_get_db();

    if ( is_wp_error( $db ) ) {
        return [ [ 'error', $db->get_error_message() ] ];
    }

    // TODO: adjust table/column names to match the actual old CMS schema
    $result = $db->query( "SELECT * FROM offers ORDER BY id ASC" );

    if ( ! $result ) {
        $db->close();
        return [ [ 'error', 'Query failed: ' . $db->error ] ];
    }

    while ( $row = $result->fetch_assoc() ) {
        $old_id = (int) $row['id'];

        // Skip if already imported
        $existing = get_posts([
            'post_type'  => 'offer',
            'meta_key'   => '_old_cms_id',
            'meta_value' => $old_id,
            'numberposts'=> 1,
            'fields'     => 'ids',
        ]);
        if ( $existing ) {
            $log[] = [ 'skip', "Offer ID $old_id already imported (WP post #{$existing[0]})" ];
            continue;
        }

        // Resolve station: find WP station by old_cms_id
        $wp_station_id = 0;
        if ( ! empty( $row['station_id'] ) ) {
            $station_posts = get_posts([
                'post_type'  => 'station',
                'meta_key'   => '_old_cms_id',
                'meta_value' => (int) $row['station_id'],
                'numberposts'=> 1,
                'fields'     => 'ids',
            ]);
            $wp_station_id = $station_posts[0] ?? 0;
        }

        // TODO: map $row fields to WP fields
        $post_id = wp_insert_post([
            'post_type'   => 'offer',
            'post_title'  => sanitize_text_field( $row['title'] ?? '' ),
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            $log[] = [ 'error', "Offer ID $old_id: " . $post_id->get_error_message() ];
            continue;
        }

        // TODO: map remaining meta fields
        update_post_meta( $post_id, '_old_cms_id',    $old_id );
        update_post_meta( $post_id, '_offer_station', $wp_station_id );

        $log[] = [ 'success', "Offer ID $old_id imported as WP post #$post_id" ];
    }

    $result->free();
    $db->close();
    return $log;
}

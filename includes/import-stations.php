<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import stations from the old CMS database.
 *
 * @return array  Log entries: [ ['success'|'skip'|'error', 'message'], ... ]
 */
function enroute_import_run_stations(): array {
    $log = [];
    $db  = enroute_import_get_db();

    if ( is_wp_error( $db ) ) {
        return [ [ 'error', $db->get_error_message() ] ];
    }

    // TODO: adjust table/column names to match the actual old CMS schema
    $result = $db->query( "SELECT * FROM stations ORDER BY id ASC" );

    if ( ! $result ) {
        $db->close();
        return [ [ 'error', 'Query failed: ' . $db->error ] ];
    }

    while ( $row = $result->fetch_assoc() ) {
        $old_id = (int) $row['id'];

        // Skip if already imported
        $existing = get_posts([
            'post_type'  => 'station',
            'meta_key'   => '_old_cms_id',
            'meta_value' => $old_id,
            'numberposts'=> 1,
            'fields'     => 'ids',
        ]);
        if ( $existing ) {
            $log[] = [ 'skip', "Station ID $old_id already imported (WP post #{$existing[0]})" ];
            continue;
        }

        // TODO: map $row fields to WP fields
        $post_id = wp_insert_post([
            'post_type'   => 'station',
            'post_title'  => sanitize_text_field( $row['name'] ?? '' ),
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            $log[] = [ 'error', "Station ID $old_id: " . $post_id->get_error_message() ];
            continue;
        }

        // TODO: update_post_meta for address, plz, place, portrait etc.
        update_post_meta( $post_id, '_old_cms_id', $old_id );

        $log[] = [ 'success', "Station ID $old_id imported as WP post #$post_id" ];
    }

    $result->free();
    $db->close();
    return $log;
}

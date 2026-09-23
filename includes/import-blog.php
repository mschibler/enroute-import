<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import blog posts from the old Django CMS database — AJAX batch version.
 * Re-runnable: insert-or-update by _old_cms_id + language.
 *
 * Steps:
 *   enroute_import_batch_blog()        — Step 1: posts + translations
 *   enroute_import_batch_blog_images() — Step 2: sideload images
 *   enroute_import_batch_blog_guides() — Step 3: assign guide authors
 *
 * Source tables:
 *   djangocms_blog_post             — main post (publish, app_config_id, main_image_id)
 *   djangocms_blog_post_translation — title, slug, content per language (de/fr/it)
 *   filer_file                      — image path (via main_image_id)
 *   common_blogpostguideauthor      — guide author relation (Guides-Blog only)
 *   guide_guide + appbase_address   — guide name for lookup
 */

define( 'BLOG_SITE_BASE_URL', 'https://enroute.ch/media/' );

// ══════════════════════════════════════════════════════════════════════════════
// STEP 1 — Import blog posts + translations
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_blog( int $offset, int $limit = 5 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    @set_time_limit( 120 );

    // Only import configured app_config_ids
    $import_configs = get_option( 'enroute_blog_import_configs', [ 1, 3 ] );
    if ( empty( $import_configs ) ) {
        $db->close();
        return [ 'log' => [ [ 'info', 'No blog types configured for import. Set them in Blog Settings.' ] ], 'batch_count' => 0, 'next_offset' => 0, 'total' => 0, 'done' => true ];
    }
    $config_ids = implode( ',', array_map( 'intval', $import_configs ) );
    $cat_map    = get_option( 'enroute_blog_cat_map', [] );

    $count_result = $db->query( "SELECT COUNT(*) AS total FROM djangocms_blog_post WHERE publish = 1 AND app_config_id IN ($config_ids)" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $result = $db->query( "
        SELECT
            p.id            AS old_id,
            p.app_config_id AS config_id,
            p.date_published AS date_published,
            p.main_image_id AS image_id,
            f.file          AS image_path
        FROM djangocms_blog_post p
        LEFT JOIN filer_file f ON f.id = p.main_image_id
        WHERE p.publish = 1 AND p.app_config_id IN ($config_ids)
        ORDER BY p.id ASC
        LIMIT $limit OFFSET $offset
    " );

    if ( ! $result ) {
        $db->close();
        return [ 'log' => [ [ 'error', 'Query failed: ' . $db->error ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => $total, 'done' => true ];
    }

    $log         = [];
    $batch_count = 0;
    $pll_active  = function_exists( 'pll_set_post_language' ) && function_exists( 'pll_save_post_translations' );

    while ( $row = $result->fetch_assoc() ) {
        $batch_count++;
        $old_id    = (int) $row['old_id'];
        $config_id = (int) $row['config_id'];
        $image_path = $row['image_path'] ?? '';
        $pub_date   = $row['date_published'] ?? null;

        // Get all translations for this post
        $trans_result = $db->query( "
            SELECT language_code, title, slug, abstract, post_text
            FROM djangocms_blog_post_translation
            WHERE master_id = $old_id
            ORDER BY language_code ASC
        " );

        if ( ! $trans_result ) continue;

        $translations     = [];
        $pll_translations = [];

        while ( $t = $trans_result->fetch_assoc() ) {
            $lang      = sanitize_key( $t['language_code'] ); // de, fr, it
            $title     = sanitize_text_field( $t['title']     ?? '' );
            $slug      = sanitize_title( $t['slug']           ?? '' );
            $excerpt   = wp_kses_post( $t['abstract']         ?? '' );
            $content   = enroute_import_clean_blog_html( $t['post_text'] ?? '' );

            if ( ! $title ) continue;

            // WP category for this config + language
            $cat_id = $cat_map[ $config_id ][ $lang ] ?? 0;

            // Post date
            $post_date = $pub_date ? date( 'Y-m-d H:i:s', strtotime( $pub_date ) ) : current_time( 'mysql' );

            // Check existing by old_id + lang meta
            $existing = get_posts( [
                'post_type'   => 'post',
                'post_status' => 'any',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_query'  => [
                    [ 'key' => '_old_cms_id',   'value' => $old_id, 'compare' => '=' ],
                    [ 'key' => '_old_cms_lang', 'value' => $lang,   'compare' => '=' ],
                ],
            ] );

            if ( $existing ) {
                $post_id = $existing[0];
                wp_update_post( [
                    'ID'           => $post_id,
                    'post_title'   => $title,
                    'post_name'    => $slug,
                    'post_content' => $content,
                    'post_excerpt' => $excerpt,
                    'post_status'  => 'publish',
                ] );
                $log[] = [ 'update', "Blog post old_id=$old_id ($lang) updated (WP #$post_id)." ];
            } else {
                $post_id = wp_insert_post( [
                    'post_type'    => 'post',
                    'post_title'   => $title,
                    'post_name'    => $slug,
                    'post_content' => $content,
                    'post_excerpt' => $excerpt,
                    'post_status'  => 'publish',
                    'post_date'    => $post_date,
                    'post_date_gmt'=> get_gmt_from_date( $post_date ),
                ], true );

                if ( is_wp_error( $post_id ) ) {
                    $log[] = [ 'error', "Blog post old_id=$old_id ($lang): " . $post_id->get_error_message() ];
                    continue;
                }

                update_post_meta( $post_id, '_old_cms_id',   $old_id );
                update_post_meta( $post_id, '_old_cms_lang', $lang );
                $log[] = [ 'success', "Blog post old_id=$old_id ($lang) imported as WP #$post_id." ];
            }

            // Assign WP category
            if ( $cat_id ) {
                wp_set_post_categories( $post_id, [ $cat_id ] );
            }

            // Set Polylang language
            if ( $pll_active ) {
                pll_set_post_language( $post_id, $lang );
            }

            // Store image path for sideloading
            if ( $image_path ) {
                update_post_meta( $post_id, '_blog_image_path_old', $image_path );
            }

            // Store config_id for guide author step
            update_post_meta( $post_id, '_old_cms_config_id', $config_id );

            $translations[ $lang ] = $post_id;
        }
        $trans_result->free();

        // Link Polylang translations together
        if ( $pll_active && count( $translations ) > 1 ) {
            pll_save_post_translations( $translations );
        }
    }

    $result->free();
    $db->close();

    $next_offset = $offset + $batch_count;
    $done        = ( $batch_count < $limit ) || ( $next_offset >= $total );

    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => $done ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 2 — Sideload blog images
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_blog_images( int $offset, int $limit = 3 ): array {
    $all_posts = get_posts( [
        'post_type'   => 'post',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [
            [ 'key' => '_blog_image_path_old', 'compare' => 'EXISTS' ],
        ],
    ] );

    $posts = array_values( array_filter( $all_posts, function( $post_id ) {
        return ! get_post_thumbnail_id( $post_id );
    } ) );

    $total = count( $posts );
    $slice = array_slice( $posts, 0, $limit );
    $log   = [];
    $count = 0;

    if ( empty( $slice ) ) {
        return [ 'log' => [ [ 'info', 'No blog posts need image sideloading.' ] ], 'batch_count' => 0, 'next_offset' => 0, 'total' => $total, 'done' => true ];
    }

    @set_time_limit( 120 );
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    foreach ( $slice as $post_id ) {
        $count++;
        $image_path = get_post_meta( $post_id, '_blog_image_path_old', true );
        $title      = get_the_title( $post_id );
        if ( ! $image_path ) continue;

        $url = str_starts_with( $image_path, 'http' )
            ? $image_path
            : rtrim( BLOG_SITE_BASE_URL, '/' ) . '/' . ltrim( $image_path, '/' );

        $timeout_filter = function() { return 30; };
        add_filter( 'http_request_timeout', $timeout_filter );

        try {
            $attach_id = media_sideload_image( $url, $post_id, $title, 'id' );
        } catch ( Throwable $e ) {
            remove_filter( 'http_request_timeout', $timeout_filter );
            $log[] = [ 'error', "WP #$post_id ($title): image failed — " . $e->getMessage() ];
            continue;
        }

        remove_filter( 'http_request_timeout', $timeout_filter );

        if ( is_wp_error( $attach_id ) ) {
            $log[] = [ 'error', "WP #$post_id ($title): image failed — " . $attach_id->get_error_message() ];
        } else {
            set_post_thumbnail( $post_id, (int) $attach_id );
            $log[] = [ 'success', "WP #$post_id ($title): image sideloaded (attachment #$attach_id)." ];
        }
    }

    $remaining = count( array_filter( $posts, function( $post_id ) {
        return ! get_post_thumbnail_id( $post_id );
    } ) ) - $count;

    return [ 'log' => $log, 'batch_count' => $count, 'next_offset' => 0, 'total' => $total, 'done' => $remaining <= 0 ];
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 3 — Assign guide authors (Guides-Blog only)
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_batch_blog_guides( int $offset, int $limit = 10 ): array {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) {
        return [ 'log' => [ [ 'error', $db->get_error_message() ] ], 'batch_count' => 0, 'next_offset' => $offset, 'total' => 0, 'done' => true ];
    }

    $count_result = $db->query( "SELECT COUNT(*) AS total FROM common_blogpostguideauthor" );
    $total        = $count_result ? (int) $count_result->fetch_assoc()['total'] : 0;

    $result = $db->query( "
        SELECT
            bg.post_id   AS old_post_id,
            bg.guide_id  AS old_guide_id
        FROM common_blogpostguideauthor bg
        ORDER BY bg.post_id ASC
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
        $old_post_id  = (int) $row['old_post_id'];
        $old_guide_id = (int) $row['old_guide_id'];

        // Find WP guide by _old_cms_id
        $guide_posts = get_posts( [
            'post_type'   => 'guide',
            'meta_key'    => '_old_cms_id',
            'meta_value'  => $old_guide_id,
            'numberposts' => 1,
            'fields'      => 'ids',
            'post_status' => 'any',
        ] );

        if ( ! $guide_posts ) {
            $log[] = [ 'skip', "Guide old_id=$old_guide_id not found in WP — skipping blog post old_id=$old_post_id." ];
            continue;
        }
        $wp_guide_id = $guide_posts[0];

        // Find all WP blog posts with this old_id (all languages)
        $blog_posts = get_posts( [
            'post_type'   => 'post',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                [ 'key' => '_old_cms_id', 'value' => $old_post_id, 'compare' => '=' ],
            ],
        ] );

        if ( ! $blog_posts ) {
            $log[] = [ 'skip', "Blog post old_id=$old_post_id not found in WP." ];
            continue;
        }

        foreach ( $blog_posts as $wp_post_id ) {
            update_post_meta( $wp_post_id, '_blog_guide_author_id', $wp_guide_id );
        }

        $guide_title = get_the_title( $wp_guide_id );
        $log[] = [ 'success', "Blog post old_id=$old_post_id: guide author assigned ($guide_title, WP #$wp_guide_id)." ];
    }

    $result->free();
    $db->close();

    $next_offset = $offset + $batch_count;
    $done        = ( $batch_count < $limit ) || ( $next_offset >= $total );

    return [ 'log' => $log, 'batch_count' => $batch_count, 'next_offset' => $next_offset, 'total' => $total, 'done' => $done ];
}

// ══════════════════════════════════════════════════════════════════════════════
// HELPER — Clean blog HTML (strip inline styles, spans, MS Word cruft)
// ══════════════════════════════════════════════════════════════════════════════

function enroute_import_clean_blog_html( string $html ): string {
    // Remove style attributes
    $html = preg_replace( '/ style="[^"]*"/i', '', $html );
    $html = preg_replace( "/ style='[^']*'/i", '', $html );
    // Remove class attributes
    $html = preg_replace( '/ class="[^"]*"/i', '', $html );
    // Unwrap spans and fonts
    $html = preg_replace( '/<span[^>]*>/i', '', $html );
    $html = preg_replace( '/<\/span>/i', '', $html );
    $html = preg_replace( '/<font[^>]*>/i', '', $html );
    $html = preg_replace( '/<\/font>/i', '', $html );
    // Strip id/lang/dir attributes from block elements
    $html = preg_replace( '/<(p|div|h[1-6]|ul|ol|li|blockquote)[^>]*>/i', '<$1>', $html );

    // Allow a reasonable set of tags
    $allowed = [
        'p'          => [],
        'br'         => [],
        'b'          => [], 'strong' => [],
        'i'          => [], 'em'     => [],
        'u'          => [],
        'h1'         => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'ul'         => [], 'ol'     => [], 'li' => [],
        'blockquote' => [],
        'a'          => [ 'href' => [], 'target' => [], 'rel' => [], 'title' => [] ],
        'img'        => [ 'src' => [], 'alt' => [], 'width' => [], 'height' => [] ],
    ];
    $html = wp_kses( $html, $allowed );

    // Remove empty paragraphs
    $html = preg_replace( '/<p>\s*(<br\s*\/?>\s*)*<\/p>/i', '', $html );
    $html = trim( $html );

    return $html;
}

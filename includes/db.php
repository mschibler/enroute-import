<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Return a mysqli connection to the old CMS database.
 * Credentials are stored in WP options (set on the Settings page).
 *
 * @return mysqli|WP_Error
 */
function enroute_import_get_db() {
    $host   = get_option( 'enroute_import_db_host',   '127.0.0.1' );
    $port   = (int) get_option( 'enroute_import_db_port',   3306 );
    $name   = get_option( 'enroute_import_db_name',   '' );
    $user   = get_option( 'enroute_import_db_user',   '' );
    $pass   = get_option( 'enroute_import_db_pass',   '' );

    if ( ! $name || ! $user ) {
        return new WP_Error( 'no_credentials', __( 'Old CMS database credentials are not configured.', 'enroute_import' ) );
    }

    $mysqli = new mysqli( $host, $user, $pass, $name, $port );

    if ( $mysqli->connect_errno ) {
        return new WP_Error(
            'connect_failed',
            sprintf(
                /* translators: %s: mysqli connect error */
                __( 'Could not connect to old CMS database: %s', 'enroute_import' ),
                $mysqli->connect_error
            )
        );
    }

    $mysqli->set_charset( 'utf8mb4' );
    return $mysqli;
}

/**
 * Test the connection and return true or a WP_Error.
 */
function enroute_import_test_db(): bool|WP_Error {
    $db = enroute_import_get_db();
    if ( is_wp_error( $db ) ) return $db;
    $db->close();
    return true;
}

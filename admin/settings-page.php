<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Menu ─────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function() {
    add_menu_page(
        __( 'Enroute Import', 'enroute_import' ),
        __( 'Enroute Import', 'enroute_import' ),
        'manage_options',
        'enroute-import',
        'enroute_import_page',          // default page = import runner
        'dashicons-database-import',
        30
    );
    add_submenu_page(
        'enroute-import',
        __( 'Run Import', 'enroute_import' ),
        __( 'Run Import', 'enroute_import' ),
        'manage_options',
        'enroute-import',               // same slug = first item
        'enroute_import_page'
    );
    add_submenu_page(
        'enroute-import',
        __( 'Settings', 'enroute_import' ),
        __( 'Settings', 'enroute_import' ),
        'manage_options',
        'enroute-import-settings',
        'enroute_import_settings_page'
    );
});

// ── Settings page ─────────────────────────────────────────────────────────────

function enroute_import_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $notice = '';
    $notice_type = 'success';

    // ── Save ─────────────────────────────────────────────────────────────────
    if (
        isset( $_POST['enroute_import_settings_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['enroute_import_settings_nonce'] ) ), 'enroute_import_settings_save' )
    ) {
        $fields = [
            'enroute_import_db_host' => 'sanitize_text_field',
            'enroute_import_db_port' => 'absint',
            'enroute_import_db_name' => 'sanitize_text_field',
            'enroute_import_db_user' => 'sanitize_text_field',
        ];
        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_option( $key, $sanitizer( wp_unslash( $_POST[ $key ] ) ) );
            }
        }
        // Password: only save if not blank (blank = keep existing)
        if ( isset( $_POST['enroute_import_db_pass'] ) && $_POST['enroute_import_db_pass'] !== '' ) {
            update_option( 'enroute_import_db_pass', sanitize_text_field( wp_unslash( $_POST['enroute_import_db_pass'] ) ) );
        }
        $notice = __( 'Settings saved.', 'enroute_import' );
    }

    // ── Test connection ───────────────────────────────────────────────────────
    if (
        isset( $_POST['enroute_import_test_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['enroute_import_test_nonce'] ) ), 'enroute_import_test_connection' )
    ) {
        $result = enroute_import_test_db();
        if ( is_wp_error( $result ) ) {
            $notice      = $result->get_error_message();
            $notice_type = 'error';
        } else {
            $notice = __( 'Connection successful! The old CMS database is reachable.', 'enroute_import' );
        }
    }

    // ── Current values ────────────────────────────────────────────────────────
    $host = get_option( 'enroute_import_db_host', '127.0.0.1' );
    $port = get_option( 'enroute_import_db_port', '3306' );
    $name = get_option( 'enroute_import_db_name', '' );
    $user = get_option( 'enroute_import_db_user', '' );
    $pass = get_option( 'enroute_import_db_pass', '' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Enroute Import — Settings', 'enroute_import' ); ?></h1>

        <?php if ( $notice ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
            <p><?php echo esc_html( $notice ); ?></p>
        </div>
        <?php endif; ?>

        <p class="description">
            <?php esc_html_e( 'Enter the connection details for the old Django CMS MariaDB database. The password is stored in the WordPress options table — use a read-only database user.', 'enroute_import' ); ?>
        </p>

        <hr>

        <!-- Save form -->
        <form method="post">
            <?php wp_nonce_field( 'enroute_import_settings_save', 'enroute_import_settings_nonce' ); ?>
            <h2><?php esc_html_e( 'Database Connection', 'enroute_import' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="enroute_import_db_host"><?php esc_html_e( 'Host', 'enroute_import' ); ?></label></th>
                    <td>
                        <input type="text" id="enroute_import_db_host" name="enroute_import_db_host"
                               value="<?php echo esc_attr( $host ); ?>" class="regular-text"
                               placeholder="127.0.0.1">
                        <p class="description"><?php esc_html_e( 'Use 127.0.0.1 if the old database is on the same server.', 'enroute_import' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="enroute_import_db_port"><?php esc_html_e( 'Port', 'enroute_import' ); ?></label></th>
                    <td>
                        <input type="number" id="enroute_import_db_port" name="enroute_import_db_port"
                               value="<?php echo esc_attr( $port ); ?>" class="small-text"
                               placeholder="3306">
                    </td>
                </tr>
                <tr>
                    <th><label for="enroute_import_db_name"><?php esc_html_e( 'Database Name', 'enroute_import' ); ?></label></th>
                    <td>
                        <input type="text" id="enroute_import_db_name" name="enroute_import_db_name"
                               value="<?php echo esc_attr( $name ); ?>" class="regular-text"
                               placeholder="old_cms_db">
                    </td>
                </tr>
                <tr>
                    <th><label for="enroute_import_db_user"><?php esc_html_e( 'Username', 'enroute_import' ); ?></label></th>
                    <td>
                        <input type="text" id="enroute_import_db_user" name="enroute_import_db_user"
                               value="<?php echo esc_attr( $user ); ?>" class="regular-text"
                               autocomplete="off">
                        <p class="description"><?php esc_html_e( 'Recommended: a read-only database user.', 'enroute_import' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="enroute_import_db_pass"><?php esc_html_e( 'Password', 'enroute_import' ); ?></label></th>
                    <td>
                        <input type="password" id="enroute_import_db_pass" name="enroute_import_db_pass"
                               value="" class="regular-text"
                               autocomplete="new-password"
                               placeholder="<?php echo $pass ? esc_attr__( '(saved — leave blank to keep)', 'enroute_import' ) : ''; ?>">
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Settings', 'enroute_import' ) ); ?>
        </form>

        <hr>

        <!-- Test connection form -->
        <h2><?php esc_html_e( 'Test Connection', 'enroute_import' ); ?></h2>
        <p><?php esc_html_e( 'Save your settings first, then test the connection.', 'enroute_import' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'enroute_import_test_connection', 'enroute_import_test_nonce' ); ?>
            <?php submit_button( __( 'Test Connection', 'enroute_import' ), 'secondary' ); ?>
        </form>

    </div>
    <?php
}

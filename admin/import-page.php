<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function enroute_import_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $log     = [];
    $ran     = false;
    $action  = isset( $_POST['enroute_import_action'] ) ? sanitize_key( $_POST['enroute_import_action'] ) : '';

    if (
        $action &&
        isset( $_POST['enroute_import_run_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['enroute_import_run_nonce'] ) ), 'enroute_import_run' )
    ) {
        $ran = true;

        // Test DB first
        $db = enroute_import_get_db();
        if ( is_wp_error( $db ) ) {
            $log[] = [ 'error', $db->get_error_message() ];
        } else {
            $db->close();

            switch ( $action ) {
                case 'stations':
                    $log = enroute_import_run_stations();
                    break;
                case 'offers':
                    $log = enroute_import_run_offers();
                    break;
                case 'resources':
                    $log = enroute_import_run_resources();
                    break;
                case 'guides':
                    $log = enroute_import_run_guides();
                    break;
                default:
                    $log[] = [ 'error', __( 'Unknown import action.', 'enroute_import' ) ];
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Enroute Import — Run Import', 'enroute_import' ); ?></h1>

        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Important:', 'enroute_import' ); ?></strong>
                <?php esc_html_e( 'Import stations before offers. Each import skips items that already have a matching old_cms_id to avoid duplicates. You can safely re-run an import.', 'enroute_import' ); ?>
            </p>
        </div>

        <p>
            <?php esc_html_e( 'Configure the database connection on the', 'enroute_import' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=enroute-import-settings' ) ); ?>">
                <?php esc_html_e( 'Settings page', 'enroute_import' ); ?>
            </a>
            <?php esc_html_e( 'before running an import.', 'enroute_import' ); ?>
        </p>

        <hr>

        <!-- Import buttons -->
        <h2><?php esc_html_e( 'Run Import', 'enroute_import' ); ?></h2>
        <p><?php esc_html_e( 'Run each content type separately. Recommended order: Stations → Offers → Resources → Guides.', 'enroute_import' ); ?></p>

        <form method="post" style="display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:2rem;">
            <?php wp_nonce_field( 'enroute_import_run', 'enroute_import_run_nonce' ); ?>

            <?php
            $buttons = [
                'stations'  => __( '1. Import Stations',  'enroute_import' ),
                'offers'    => __( '2. Import Offers',    'enroute_import' ),
                'resources' => __( '3. Import Resources', 'enroute_import' ),
                'guides'    => __( '4. Import Guides',    'enroute_import' ),
            ];
            foreach ( $buttons as $key => $label ) :
            ?>
                <button
                    type="submit"
                    name="enroute_import_action"
                    value="<?php echo esc_attr( $key ); ?>"
                    class="button button-primary"
                    onclick="return confirm('<?php echo esc_js( sprintf( __( 'Run %s import now?', 'enroute_import' ), $label ) ); ?>')"
                >
                    <?php echo esc_html( $label ); ?>
                </button>
            <?php endforeach; ?>
        </form>

        <!-- Log output -->
        <?php if ( $ran ) : ?>
        <hr>
        <h2><?php esc_html_e( 'Import Log', 'enroute_import' ); ?></h2>
        <?php if ( empty( $log ) ) : ?>
            <p><?php esc_html_e( 'Nothing to import or nothing was returned.', 'enroute_import' ); ?></p>
        <?php else : ?>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th style="width:80px;"><?php esc_html_e( 'Status', 'enroute_import' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'enroute_import' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $log as $entry ) :
                    $type    = $entry[0] ?? 'info';
                    $message = $entry[1] ?? '';
                    $colors  = [
                        'success' => '#d4edda',
                        'error'   => '#f8d7da',
                        'skip'    => '#fff3cd',
                        'info'    => '#ffffff',
                    ];
                    $bg = $colors[ $type ] ?? '#ffffff';
                ?>
                <tr style="background:<?php echo esc_attr( $bg ); ?>">
                    <td><strong><?php echo esc_html( strtoupper( $type ) ); ?></strong></td>
                    <td><?php echo esc_html( $message ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $counts = array_count_values( array_column( $log, 0 ) );
        ?>
        <p style="margin-top:1rem;">
            <strong><?php esc_html_e( 'Summary:', 'enroute_import' ); ?></strong>
            <?php foreach ( $counts as $type => $count ) : ?>
                <span style="margin-right:1rem;">
                    <?php echo esc_html( strtoupper( $type ) . ': ' . $count ); ?>
                </span>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>
        <?php endif; ?>

    </div>
    <?php
}

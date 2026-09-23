<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_enroute_import_batch', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    check_ajax_referer( 'enroute_import_batch', 'nonce' );

    $type   = sanitize_key( $_POST['type']   ?? '' );
    $offset = absint(        $_POST['offset'] ?? 0  );
    $batch  = 5;

    switch ( $type ) {
        case 'stations':          $result = enroute_import_batch_stations( $offset, $batch );          break;
        case 'station_photos':         $result = enroute_import_batch_station_photos( $offset, 3 );          break;
        case 'station_activity_photos': $result = enroute_import_batch_station_activity_photos( $offset, 3 ); break;
        case 'offers':            $result = enroute_import_batch_offers( $offset, $batch );            break;
        case 'offer_photos':      $result = enroute_import_batch_offer_photos( $offset, 3 );           break;
        case 'offer_subjects':    $result = enroute_import_batch_offer_subjects( $offset, $batch );    break;
        case 'offer_targets':     $result = enroute_import_batch_offer_targets( $offset, $batch );     break;
        case 'offer_recurrence':  $result = enroute_import_batch_offer_recurrence( $offset, $batch );  break;
        case 'resources':         $result = enroute_import_batch_resources( $offset, $batch );         break;
        case 'resource_files':    $result = enroute_import_batch_resource_files( $offset, 3 );         break;
        case 'resource_subjects': $result = enroute_import_batch_resource_subjects( $offset, $batch ); break;
        case 'resource_types':    $result = enroute_import_batch_resource_types( $offset, $batch );    break;
        case 'resource_religions':$result = enroute_import_batch_resource_religions( $offset, $batch );break;
        case 'guides':            $result = enroute_import_batch_guides( $offset, $batch );            break;
        case 'guide_photos':      $result = enroute_import_batch_guide_photos( $offset, 3 );           break;
        case 'blog':              $result = enroute_import_batch_blog( $offset, $batch );              break;
        case 'blog_images':       $result = enroute_import_batch_blog_images( $offset, 3 );            break;
        case 'blog_guides':       $result = enroute_import_batch_blog_guides( $offset, $batch );       break;
        case 'guide_photos':      $result = enroute_import_batch_guide_photos( $offset, 3 );           break;
        default: wp_send_json_error( [ 'message' => 'Unknown import type.' ] ); return;
    }

    wp_send_json_success( $result );
});

function enroute_import_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Enroute Import — Run Import', 'enroute_import' ); ?></h1>
        <div class="notice notice-warning inline">
            <p><strong><?php esc_html_e( 'Important:', 'enroute_import' ); ?></strong>
            <?php esc_html_e( 'Run steps in order. All steps are re-runnable — existing records are updated, new ones inserted. Images/files are sideloaded separately to avoid timeouts.', 'enroute_import' ); ?></p>
        </div>
        <p><?php esc_html_e( 'Configure the database connection on the', 'enroute_import' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=enroute-import-settings' ) ); ?>"><?php esc_html_e( 'Settings page', 'enroute_import' ); ?></a>.</p>
        <hr>
        <h2><?php esc_html_e( 'Run Import', 'enroute_import' ); ?></h2>
        <?php
        $groups = [
            'Stations' => [
                'stations'           => '1. Import Stations',
                'station_photos'          => '↳ Sideload Station Photos',
                'station_activity_photos' => '↳ Sideload Station Activity Photos',
            ],
            'Offers' => [
                'offers'             => '2. Import Offers',
                'offer_photos'       => '↳ Sideload Offer Images',
                'offer_subjects'     => '↳ Assign Subjects',
                'offer_targets'      => '↳ Assign Target Groups',
                'offer_recurrence'   => '↳ Assign Weekdays',
            ],
            'Resources' => [
                'resources'          => '3. Import Resources',
                'resource_files'     => '↳ Sideload Resource Files',
                'resource_subjects'  => '↳ Assign Subjects',
                'resource_types'     => '↳ Assign Resource Types',
                'resource_religions' => '↳ Assign Subjects via Religions',
            ],
            'Other' => [
                'guides'             => '4. Import Guides',
                'guide_photos'       => '↳ Sideload Guide Photos',
                'blog'               => '5. Import Blog Posts',
                'blog_images'        => '↳ Sideload Blog Images',
                'blog_guides'        => '↳ Assign Guide Authors',
            ],
        ];
        foreach ( $groups as $group_label => $buttons ) :
        ?>
        <h3 style="margin-bottom:0.5rem;"><?php echo esc_html( $group_label ); ?></h3>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem;">
            <?php foreach ( $buttons as $key => $label ) : ?>
            <button class="button <?php echo str_starts_with( $key, 'station_' ) || str_starts_with( $key, 'offer_' ) || str_starts_with( $key, 'resource_' ) ? 'button-secondary' : 'button-primary'; ?> enroute-import-btn"
                data-type="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <button class="button enroute-import-clear" style="margin-bottom:1.5rem;"><?php esc_html_e( 'Clear Log', 'enroute_import' ); ?></button>

        <div id="enroute-import-progress" style="display:none; margin-bottom:1rem;">
            <div style="display:flex; align-items:center; gap:1rem; margin-bottom:0.5rem;">
                <strong id="enroute-import-status"><?php esc_html_e( 'Importing…', 'enroute_import' ); ?></strong>
                <span id="enroute-import-count" style="color:#666;"></span>
                <button class="button button-small enroute-import-stop" style="margin-left:auto; color:#c00;"><?php esc_html_e( 'Stop', 'enroute_import' ); ?></button>
            </div>
            <div style="background:#e0e0e0; border-radius:3px; height:12px; overflow:hidden;">
                <div id="enroute-import-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
            </div>
        </div>

        <div id="enroute-import-summary" style="display:none; margin-bottom:1rem; padding:0.75rem 1rem; background:#f0f0f0; border-left:4px solid #2271b1;">
            <strong><?php esc_html_e( 'Summary:', 'enroute_import' ); ?></strong>
            <span id="enroute-import-summary-text"></span>
        </div>

        <table class="widefat striped" id="enroute-import-log" style="max-width:900px; display:none;">
            <thead><tr>
                <th style="width:80px;"><?php esc_html_e( 'Status', 'enroute_import' ); ?></th>
                <th><?php esc_html_e( 'Message', 'enroute_import' ); ?></th>
            </tr></thead>
            <tbody id="enroute-import-log-body"></tbody>
        </table>
    </div>

    <script>
    (function($) {
        const nonce   = <?php echo wp_json_encode( wp_create_nonce( 'enroute_import_batch' ) ); ?>;
        const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        const colors  = { success:'#d4edda', update:'#cce5ff', error:'#f8d7da', skip:'#fff3cd', info:'#f8f9fa' };
        const counts  = {};
        let stopped = false, totalDone = 0;

        function resetState() {
            stopped = false; totalDone = 0;
            Object.keys(counts).forEach(k => delete counts[k]);
            $('#enroute-import-log-body').empty();
            $('#enroute-import-log').show();
            $('#enroute-import-summary').hide();
            $('#enroute-import-bar').css('width','0%');
            $('#enroute-import-count').text('');
        }

        function appendLog(entries) {
            entries.forEach(function(entry) {
                const type = entry[0]||'info', msg = entry[1]||'', bg = colors[type]||'#fff';
                counts[type] = (counts[type]||0) + 1;
                $('#enroute-import-log-body').append(
                    $('<tr>').css('background',bg).append(
                        $('<td>').html('<strong>'+type.toUpperCase()+'</strong>'),
                        $('<td>').text(msg)
                    )
                );
            });
            const tbody = document.getElementById('enroute-import-log-body');
            if (tbody && tbody.lastElementChild) tbody.lastElementChild.scrollIntoView({block:'end',behavior:'smooth'});
        }

        function updateSummary() {
            const parts = Object.entries(counts).map(([k,v]) => k.toUpperCase()+': '+v);
            $('#enroute-import-summary-text').text(' '+parts.join(' — '));
            $('#enroute-import-summary').show();
        }

        function runBatch(type, offset, total) {
            if (stopped) { finish('Stopped by user.'); return; }
            $('#enroute-import-count').text('Processed: '+totalDone+(total?' / '+total:''));
            if (total > 0) $('#enroute-import-bar').css('width', Math.min(100, Math.round(totalDone/total*100))+'%');

            $.ajax({
                url: ajaxUrl, method: 'POST', timeout: 120000,
                data: { action:'enroute_import_batch', nonce:nonce, type:type, offset:offset }
            })
            .done(function(res) {
                if (!res.success) { appendLog([['error', res.data?.message||'Unknown error']]); finish('Finished with errors.'); return; }
                const data = res.data;
                appendLog(data.log||[]);
                totalDone += data.batch_count||0;
                if (data.done) {
                    $('#enroute-import-bar').css('width','100%');
                    $('#enroute-import-count').text('Processed: '+totalDone+' / '+(data.total||totalDone));
                    finish('Import complete!');
                } else {
                    setTimeout(function() { runBatch(type, data.next_offset, data.total); }, 500);
                }
            })
            .fail(function(xhr, status, error) {
                appendLog([['error','AJAX request failed ('+xhr.status+'): '+error+'. Re-running is safe — already processed records will be skipped or updated.']]);
                finish('Finished with errors.');
            });
        }

        function finish(message) {
            $('#enroute-import-status').text(message);
            $('.enroute-import-btn').prop('disabled', false);
            $('.enroute-import-stop').hide();
            updateSummary();
        }

        $('.enroute-import-btn').on('click', function() {
            const type = $(this).data('type'), label = $(this).text();
            if (!confirm('Run "'+label+'" now?')) return;
            resetState();
            $('.enroute-import-btn').prop('disabled', true);
            $('#enroute-import-progress').show();
            $('#enroute-import-status').text('Importing…');
            $('.enroute-import-stop').show();
            runBatch(type, 0, 0);
        });

        $(document).on('click', '.enroute-import-stop', function() {
            stopped = true; $(this).prop('disabled', true).text('Stopping after current batch…');
        });

        $('.enroute-import-clear').on('click', function() {
            $('#enroute-import-log-body').empty();
            $('#enroute-import-progress').hide();
            $('#enroute-import-summary').hide();
            $('.enroute-import-btn').prop('disabled', false);
        });
    })(jQuery);
    </script>
    <?php
}

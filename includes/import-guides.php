<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Guide import — not needed (already imported manually).
 * Stub kept so the AJAX handler doesn't break.
 */
function enroute_import_batch_guides( int $offset, int $limit = 10 ): array {
    return [
        'log'         => [ [ 'info', 'Guide import not implemented — guides were imported manually.' ] ],
        'batch_count' => 0,
        'next_offset' => 0,
        'total'       => 0,
        'done'        => true,
    ];
}

function enroute_import_run_guides(): array {
    return enroute_import_batch_guides( 0, 10 )['log'];
}

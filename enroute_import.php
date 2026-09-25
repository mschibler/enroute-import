<?php
/**
 * Plugin Name: Enroute Import
 * Description: One-time migration tool: imports content from the old Django/MariaDB CMS into WordPress. Deactivate and delete after migration is complete.
 * Version:     0.1.0
 * Author:      Enroute
 * Text Domain: enroute_import
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ENROUTE_IMPORT_VERSION', '0.1.0' );
define( 'ENROUTE_IMPORT_PATH',    plugin_dir_path( __FILE__ ) );
define( 'ENROUTE_IMPORT_URL',     plugin_dir_url( __FILE__ ) );

require_once ENROUTE_IMPORT_PATH . 'includes/db.php';
require_once ENROUTE_IMPORT_PATH . 'includes/import-stations.php';
require_once ENROUTE_IMPORT_PATH . 'includes/import-offers.php';
require_once ENROUTE_IMPORT_PATH . 'includes/import-resources.php';
require_once ENROUTE_IMPORT_PATH . 'includes/import-guides.php';
require_once ENROUTE_IMPORT_PATH . 'admin/settings-page.php';
require_once ENROUTE_IMPORT_PATH . 'includes/import-blog.php';
require_once ENROUTE_IMPORT_PATH . 'includes/import-poi.php';
require_once ENROUTE_IMPORT_PATH . 'admin/import-page.php';

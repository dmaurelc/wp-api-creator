<?php
define('WP_USE_THEMES', false);
require_once('wp-load.php');

$config = get_option('wp_api_creator_config');
echo json_encode($config, JSON_PRETTY_PRINT);

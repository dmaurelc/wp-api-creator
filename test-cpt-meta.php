<?php
require_once dirname(__DIR__, 3) . '/wp-load.php';

$posts = get_posts(['post_type' => 'property', 'posts_per_page' => 1]);
if (!empty($posts)) {
    $meta = get_post_meta($posts[0]->ID);
    print_r($meta);
} else {
    echo "No property posts found.";
}

<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/inbox.php';

// Live AJAX (threads / thread / send) — handled + exits before any output.
inbox_handle_ajax($CLIENT);

client_header('Inbox', 'inbox', $CLIENT);
page_head('Inbox');
$IB_ENDPOINT = 'inbox.php';
require __DIR__ . '/../includes/inbox_view.php';
layout_footer();

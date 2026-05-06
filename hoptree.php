<?php

// Legacy entry point. Real implementation at /api/v1/hoptree.

require_once dirname(__FILE__) . '/api/handlers/hoptree.php';

$db = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

api_handle_hoptree($db, $_GET);
?>

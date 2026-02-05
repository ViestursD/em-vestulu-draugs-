<?php
require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/text.php';

clear_session_cookie();
header("Location: " . APP_BASE . "/");
exit;

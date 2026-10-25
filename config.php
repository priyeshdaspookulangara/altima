<?php
// Config file for Altima Engine

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'altima_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'mysql'); // 'mysql' or 'sqlite'
define('SQLITE_FILE', __DIR__ . '/altima.sqlite');

// Default Engine Parameters
define('FOUNDATIONAL_ENTRY_FEE', 30.00);
define('DEFAULT_DIRECT_BONUS', 10.00);
define('REBIRTH_TRIGGER_AMOUNT', 30.00);

<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/entities.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/render.php';

date_default_timezone_set('UTC');

start_app_session();

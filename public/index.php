<?php
declare(strict_types=1);

// Public HTTP entrypoint. Application routing and access control live in App\Application.
require_once dirname(__DIR__) . '/bootstrap.php';

(new \App\Application())->run();

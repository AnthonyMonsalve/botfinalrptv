<?php

/** Mensajes de cierre — punto de entrada del módulo. */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/sender.php';

if (is_admin()) {
    require_once __DIR__ . '/admin.php';
}

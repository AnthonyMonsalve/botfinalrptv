<?php

/**
 * Reporte Eléctrico Nacional — punto de entrada del módulo.
 *
 * bot.php solo necesita incluir este archivo; todo lo demás se carga desde aquí.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/install/migrate.php';

require_once __DIR__ . '/data/venezuela.php';
require_once __DIR__ . '/data/geo_lookup.php';

require_once __DIR__ . '/db/report_repository.php';
require_once __DIR__ . '/db/session_repository.php';
require_once __DIR__ . '/db/zone_repository.php';
require_once __DIR__ . '/db/stats_repository.php';

require_once __DIR__ . '/public/rest_api.php';

require_once __DIR__ . '/bot/media.php';
require_once __DIR__ . '/bot/handle_power_report_flow.php';

require_once __DIR__ . '/cron/followup_scheduler.php';

require_once __DIR__ . '/../instructions_validators/is_power_report_instruction.php';

if (is_admin()) {
    require_once __DIR__ . '/admin/admin_menu.php';
}

// El plugin ya está activo en producción, así que la migración no puede colgar de
// register_activation_hook: se evalúa en cada carga y solo actúa si cambió la versión.
add_action('plugins_loaded', 'datav_power_migrate');

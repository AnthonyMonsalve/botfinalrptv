<?php
/*
Plugin Name: DatAvenger — Portal Reporte Eléctrico
Plugin URI: https://latvcalle.com
Description: Portal público en tiempo real de las fallas del servicio eléctrico reportadas por los usuarios de DatAvenger. Se conecta por API al sitio donde vive el bot, así que puede instalarse en cualquier WordPress.
Version: 1.9.1
Author: La TV Calle
Author URI: https://latvcalle.com
*/

/**
 * Este plugin NO tiene base de datos propia ni conoce el esquema del bot.
 * Todo lo que muestra sale de la API pública de DatAvenger, que devuelve datos
 * ya anonimizados. Si el backend cambia de servidor, basta con cambiar la URL
 * en los ajustes.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DVPE_VERSION', '1.9.1');
define('DVPE_PATH', plugin_dir_path(__FILE__));
define('DVPE_URL', plugin_dir_url(__FILE__));

require_once DVPE_PATH . 'includes/config.php';
require_once DVPE_PATH . 'includes/api_client.php';
require_once DVPE_PATH . 'includes/route.php';
require_once DVPE_PATH . 'includes/seo.php';
require_once DVPE_PATH . 'includes/informes.php';
require_once DVPE_PATH . 'includes/shortcode.php';

if (is_admin()) {
    require_once DVPE_PATH . 'includes/settings.php';
}

register_activation_hook(__FILE__, 'dvpe_activate');
register_deactivation_hook(__FILE__, 'dvpe_deactivate');

function dvpe_activate()
{
    dvpe_register_portal_route();
    flush_rewrite_rules();
}

function dvpe_deactivate()
{
    flush_rewrite_rules();
}

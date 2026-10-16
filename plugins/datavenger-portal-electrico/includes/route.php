<?php

/**
 * Ruta propia del portal.
 *
 * Se sirve una página completa desde el plugin en vez de usar el tema: así el
 * portal se ve igual en cualquier sitio donde se instale y, sobre todo, no pasa
 * por el pipeline de caché de página del tema ni de los plugins de optimización.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'dvpe_register_portal_route');

function dvpe_register_portal_route()
{
    $slug = trim(dvpe_setting('dvpe_slug'), '/');

    if ($slug === '') {
        return;
    }

    // El listado de informes cuelga del portal para que las dos direcciones se
    // lean como una sola sección: /reporte-electrico/ y /reporte-electrico/informes/
    add_rewrite_rule('^' . preg_quote($slug, '#') . '/informes/?$', 'index.php?dvpe_portal=informes', 'top');
    add_rewrite_rule('^' . preg_quote($slug, '#') . '/?$', 'index.php?dvpe_portal=1', 'top');

    // Cambiar el slug en los ajustes deja la regla vieja en la base de datos.
    // Y una versión nueva del plugin puede traer rutas nuevas —como la de los
    // informes— que no existen hasta que se regeneran las reglas.
    if (get_option('dvpe_flush_rewrite') || get_option('dvpe_rules_version') !== DVPE_VERSION) {
        delete_option('dvpe_flush_rewrite');
        update_option('dvpe_rules_version', DVPE_VERSION);
        flush_rewrite_rules();
    }
}

add_filter('query_vars', 'dvpe_query_vars');

function dvpe_query_vars($vars)
{
    $vars[] = 'dvpe_portal';

    return $vars;
}

add_action('template_redirect', 'dvpe_render_portal');

function dvpe_render_portal()
{
    $vista = get_query_var('dvpe_portal');

    if (!$vista) {
        return;
    }

    status_header(200);

    if ($vista === 'informes') {
        // El listado sí puede cachearse: cambia cuando el equipo publica, no
        // cada veinte segundos como el mapa.
        include DVPE_PATH . 'includes/view/informes.php';

        exit;
    }

    dvpe_send_no_cache_headers();

    include DVPE_PATH . 'includes/view/portal.php';

    exit;
}

/**
 * Anti-caché en todos los niveles.
 *
 * Las constantes DONOTCACHEPAGE / DONOTCACHEOBJECT las respetan WP Rocket,
 * LiteSpeed Cache, W3 Total Cache y WP Super Cache. Las cabeceras cubren al
 * navegador y a los CDN que estén delante.
 */
function dvpe_send_no_cache_headers()
{
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    if (!defined('DONOTCACHEOBJECT')) {
        define('DONOTCACHEOBJECT', true);
    }

    if (!defined('DONOTMINIFY')) {
        define('DONOTMINIFY', true);
    }

    nocache_headers();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/** URL pública del portal, para enlazarla desde el admin. */
function dvpe_portal_url()
{
    return home_url('/' . trim(dvpe_setting('dvpe_slug'), '/') . '/');
}

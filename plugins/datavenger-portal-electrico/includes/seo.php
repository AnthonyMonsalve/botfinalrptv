<?php

/**
 * Metadatos del portal para buscadores y redes sociales.
 *
 * El portal se sirve fuera del tema, así que no hereda nada de los plugins de
 * SEO del sitio: título, descripción, canonical, tarjeta para compartir y datos
 * estructurados se arman aquí.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Todo lo que necesita la cabecera, resuelto en una sola llamada.
 *
 * @return array title, description, url, site, image, icon, preconnect, schema
 */
function dvpe_seo_data()
{
    $title = trim((string) dvpe_setting('dvpe_title'));
    $description = trim((string) dvpe_setting('dvpe_subtitle'));
    $site = get_bloginfo('name');
    $url = dvpe_portal_url();
    $icon = DVPE_URL . 'assets/datavenger.png';

    $image = trim((string) dvpe_setting('dvpe_share_image'));
    $image = $image === '' ? $icon : $image;

    // El título de la pestaña lleva el nombre del sitio detrás; el de la tarjeta
    // al compartir no, porque ahí ya se muestra el dominio aparte.
    $page_title = $site && stripos($title, $site) === false
        ? $title . ' · ' . $site
        : $title;

    $datos = [
        'title' => $page_title,
        'description' => $description,
        'url' => $url,
        'site' => $site,
        'image' => $image,
        'icon' => $icon,
        'preconnect' => dvpe_seo_preconnect(),
        'schema' => dvpe_seo_schema($title, $description, $url, $site, $image),
    ];

    return dvpe_seo_for_report($datos);
}

/**
 * Cuando se comparte el enlace de un reporte concreto (?report=412), la vista
 * previa habla de ESE reporte.
 *
 * Facebook y X no ejecutan JavaScript: si no se resuelve aquí, en el servidor,
 * un enlace compartido muestra siempre la misma tarjeta genérica del portal.
 */
function dvpe_seo_for_report($datos)
{
    $id = isset($_GET['report']) ? (int) $_GET['report'] : 0;

    if ($id <= 0) {
        return $datos;
    }

    list($reporte, $error) = dvpe_api_get('power/report/' . $id, []);

    if ($error !== '' || empty($reporte['zona'])) {
        return $datos;
    }

    $estados = [
        'restored' => 'Servicio restablecido',
        'undetermined' => 'Pendiente de confirmación',
    ];

    $estado = isset($estados[$reporte['status']]) ? $estados[$reporte['status']] : 'Falla activa';

    $datos['title'] = $estado . ' en ' . $reporte['zona'] . ' · ' . $datos['site'];
    $datos['description'] = $estado . ' en ' . $reporte['zona'] . '. Sin servicio desde '
        . $reporte['outage_since'] . ' · ' . $reporte['duration_label']
        . '. Reporte ciudadano recogido por DatAvenger vía WhatsApp.';
    $datos['url'] = add_query_arg('report', $id, $datos['url']);

    if (!empty($reporte['photo'])) {
        $datos['image'] = $reporte['photo'];
    }

    return $datos;
}

/** Orígenes a los que el portal pedirá datos nada más cargar. */
function dvpe_seo_preconnect()
{
    $origins = ['https://server.arcgisonline.com', 'https://unpkg.com'];

    // En modo directo las consultas van al sitio del bot, que suele ser otro
    // dominio; en modo proxy salen del propio sitio y no hay nada que adelantar.
    $endpoint = dvpe_client_endpoint();

    if ($endpoint['mode'] === 'direct' && $endpoint['base'] !== '') {
        $parts = wp_parse_url($endpoint['base']);

        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $origins[] = $parts['scheme'] . '://' . $parts['host'];
        }
    }

    return array_values(array_unique($origins));
}

/**
 * Datos estructurados schema.org.
 *
 * Se declara como Dataset y no como página corriente a propósito: esto es un
 * conjunto de datos abierto sobre el servicio eléctrico, y así puede aparecer
 * en Google Dataset Search además de en la búsqueda normal.
 */
function dvpe_seo_schema($title, $description, $url, $site, $image)
{
    // La dirección que se anuncia tiene que responder de verdad: la API del bot
    // vive en otro sitio, así que en modo proxy se publica la de este dominio.
    $endpoint = dvpe_client_endpoint();

    $api = $endpoint['mode'] === 'proxy'
        ? add_query_arg('path', 'power/live', $endpoint['base'])
        : rtrim($endpoint['base'], '/') . '/power/live';

    return [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Dataset',
                '@id' => $url . '#dataset',
                'name' => $title,
                'description' => $description,
                'url' => $url,
                'image' => $image,
                'inLanguage' => 'es',
                'isAccessibleForFree' => true,
                'license' => 'https://creativecommons.org/licenses/by/4.0/',
                'keywords' => [
                    'apagones Venezuela',
                    'fallas eléctricas',
                    'servicio eléctrico',
                    'cortes de luz',
                    'mapa de apagones',
                    'reporte ciudadano',
                ],
                'spatialCoverage' => [
                    '@type' => 'Place',
                    'name' => 'Venezuela',
                    'geo' => [
                        '@type' => 'GeoShape',
                        // Recuadro del país: sur-oeste y norte-este.
                        'box' => '0.6 -73.4 12.5 -59.8',
                    ],
                ],
                'creator' => [
                    '@type' => 'Organization',
                    'name' => $site,
                    'url' => home_url('/'),
                ],
                'distribution' => [
                    [
                        '@type' => 'DataDownload',
                        'encodingFormat' => 'application/json',
                        'contentUrl' => $api,
                    ],
                ],
                'variableMeasured' => [
                    'Fallas eléctricas activas',
                    'Tiempo sin servicio por zona',
                    'Reportes ciudadanos por hora',
                ],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $url . '#website',
                'name' => $title,
                'description' => $description,
                'url' => $url,
                'inLanguage' => 'es',
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $site,
                    'url' => home_url('/'),
                    'logo' => DVPE_URL . 'assets/datavenger.png',
                ],
            ],
        ],
    ];
}

/* -------------------------------------------------------------------------
 * Descubrimiento
 * ---------------------------------------------------------------------- */

add_action('init', 'dvpe_add_sitemap', 20);

/**
 * El portal vive en una ruta propia y no entra en ningún sitemap del sitio.
 * Sin esto, Google solo llega si alguien lo enlaza desde fuera.
 */
function dvpe_add_sitemap()
{
    if (!function_exists('wp_register_sitemap_provider') || !class_exists('WP_Sitemaps_Provider')) {
        return;
    }

    require_once DVPE_PATH . 'includes/sitemap_provider.php';

    wp_register_sitemap_provider('dvpe-portal', new DVPE_Sitemap_Provider());
}

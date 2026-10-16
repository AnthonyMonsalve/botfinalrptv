<?php

/** Ajustes del portal. Todos con prefijo dvpe_ para no chocar con otros plugins. */

if (!defined('ABSPATH')) {
    exit;
}

function dvpe_defaults()
{
    return [
        'dvpe_api_url' => '',
        'dvpe_mode' => 'direct',
        'dvpe_slug' => 'reporte-electrico',
        'dvpe_title' => 'Reporte Eléctrico Nacional',
        'dvpe_subtitle' => 'Fallas del servicio eléctrico reportadas por la ciudadanía, en tiempo real.',
        'dvpe_accent' => '#f5a623',
        'dvpe_poll_seconds' => '20',
        'dvpe_credit' => 'Reportes recogidos por DatAvenger vía WhatsApp',
        // Imagen de la tarjeta al compartir el enlace. Vacío = la mascota.
        'dvpe_share_image' => '',
        // Aviso del último informe que sube desde abajo en el portal.
        'dvpe_nudge_enabled' => '1',
        'dvpe_nudge_delay' => '45',
        'dvpe_basemap' => 'esri_dark',
        'dvpe_carto_key' => '',
    ];
}

/**
 * Capas del mapa base.
 *
 * CARTO dejó de servir sus basemaps sin registro: sin clave las teselas siguen
 * llegando, pero estampadas con "API KEY REQUIRED". Por eso el valor por defecto
 * es Esri Dark Gray, que no pide clave y mantiene el mapa oscuro. CARTO sigue
 * disponible para quien tenga su clave.
 *
 * Devuelve una lista porque el mapa oscuro de Esri viene en dos capas: el fondo
 * y, encima, los nombres de estados y ciudades.
 */
function dvpe_basemap_layers()
{
    $carto_key = trim((string) dvpe_setting('dvpe_carto_key'));
    $basemap = dvpe_setting('dvpe_basemap');

    if ($basemap === 'carto_dark' && $carto_key !== '') {
        return [[
            'url' => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png?api_key=' . rawurlencode($carto_key),
            'attribution' => '&copy; OpenStreetMap · CARTO',
            'maxZoom' => 18,
        ]];
    }

    if ($basemap === 'osm') {
        return [[
            'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution' => '&copy; OpenStreetMap',
            'maxZoom' => 19,
        ]];
    }

    return [
        [
            'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}',
            'attribution' => 'Teselas &copy; Esri &middot; &copy; OpenStreetMap',
            'maxZoom' => 16,
        ],
        [
            // Los nombres van en una capa aparte y deben quedar por encima.
            'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}',
            'attribution' => '',
            'maxZoom' => 16,
        ],
    ];
}

function dvpe_setting($key)
{
    $defaults = dvpe_defaults();
    $default = isset($defaults[$key]) ? $defaults[$key] : '';

    $value = get_option($key, $default);

    return $value === '' ? $default : $value;
}

function dvpe_poll_seconds()
{
    return max(5, (int) dvpe_setting('dvpe_poll_seconds'));
}

/**
 * Normaliza lo que el administrador pegue en el campo de la API.
 *
 * Se acepta tanto la URL del sitio del bot como la de la API completa, porque
 * es un error muy fácil de cometer y el mensaje de "no conecta" no ayudaría a
 * descubrir que solo faltaba el sufijo.
 *
 *   https://sitio.com                      -> https://sitio.com/wp-json/datav/v1
 *   https://sitio.com/wp-json              -> https://sitio.com/wp-json/datav/v1
 *   https://sitio.com/wp-json/datav/v1     -> igual
 */
function dvpe_normalize_api_url($url)
{
    $url = untrailingslashit(trim((string) $url));

    if ($url === '') {
        return '';
    }

    if (substr($url, -strlen('/datav/v1')) === '/datav/v1') {
        return $url;
    }

    if (substr($url, -strlen('/wp-json')) === '/wp-json') {
        return $url . '/datav/v1';
    }

    return $url . '/wp-json/datav/v1';
}

/** URL base ya normalizada, o '' si no está configurada. */
function dvpe_api_base()
{
    return dvpe_normalize_api_url(get_option('dvpe_api_url', ''));
}

function dvpe_is_configured()
{
    return dvpe_api_base() !== '';
}

/**
 * De dónde saca los datos el navegador.
 *
 * 'direct' — el navegador llama a la API del bot. Es lo más ligero y lo más
 *            en vivo, pero exige que el backend permita CORS para este dominio.
 * 'proxy'  — el navegador llama a este WordPress y él reenvía la petición. Sirve
 *            cuando un CDN o un firewall se come las cabeceras CORS.
 */
function dvpe_mode()
{
    return dvpe_setting('dvpe_mode') === 'proxy' ? 'proxy' : 'direct';
}

<?php

/**
 * Cliente de la API de DatAvenger y proxy opcional.
 *
 * El proxy existe para los casos en que el navegador no puede hablar
 * directamente con el backend (CORS bloqueado por un CDN, backend en una red
 * interna, dominios con políticas estrictas). Reenvía desde el servidor y
 * devuelve el JSON tal cual, sin cachear nada en el camino.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DVPE_PROXY_NAMESPACE', 'datavenger-portal/v1');

/** Rutas de la API del bot que este plugin puede pedir. */
function dvpe_allowed_paths()
{
    return ['power/meta', 'power/live', 'power/stats', 'power/zones', 'power/points', 'power/timeline'];
}

/**
 * Valida el path que llega al proxy.
 *
 * Es una lista blanca y no una comprobación de prefijo: sin esto, un `path` con
 * `../` o con una URL absoluta convertiría el proxy en un SSRF contra la red
 * interna del servidor.
 */
function dvpe_is_allowed_path($path)
{
    $path = ltrim((string) $path, '/');

    if (in_array($path, dvpe_allowed_paths(), true)) {
        return true;
    }

    return (bool) preg_match('#^power/report/\d+$#', $path);
}

/** Llamada desde el servidor a la API del bot. Devuelve [datos, error]. */
function dvpe_api_get($path, $query = [])
{
    $base = dvpe_api_base();

    if ($base === '') {
        return [null, 'El portal no tiene configurada la dirección de la API.'];
    }

    if (!dvpe_is_allowed_path($path)) {
        return [null, 'Ruta no permitida.'];
    }

    $url = $base . '/' . ltrim($path, '/');

    // Marca de tiempo para saltar cachés intermedios y CDN.
    $query['_'] = time();
    $url = add_query_arg($query, $url);

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($response)) {
        return [null, 'No se pudo conectar: ' . $response->get_error_message()];
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code !== 200) {
        return [null, 'La API respondió HTTP ' . $code . '.'];
    }

    $data = json_decode($body, true);

    if ($data === null) {
        return [null, 'La API devolvió una respuesta que no es JSON válido.'];
    }

    return [$data, ''];
}

/* -------------------------------------------------------------------------
 * Proxy
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', 'dvpe_register_proxy_route');

function dvpe_register_proxy_route()
{
    register_rest_route(DVPE_PROXY_NAMESPACE, '/proxy', [
        'methods' => 'GET',
        'callback' => 'dvpe_proxy_request',
        'permission_callback' => '__return_true',
    ]);
}

function dvpe_proxy_request($request)
{
    $path = (string) $request->get_param('path');

    if (!dvpe_is_allowed_path($path)) {
        return new WP_Error('bad_path', 'Ruta no permitida.', ['status' => 400]);
    }

    $query = [];

    foreach (['range', 'from', 'to', 'bucket', 'level', 'estado_code', 'municipio_code', 'parroquia_code', 'limit'] as $key) {
        $value = $request->get_param($key);

        if ($value !== null && $value !== '') {
            $query[$key] = sanitize_text_field($value);
        }
    }

    list($data, $error) = dvpe_api_get($path, $query);

    if ($error !== '') {
        return new WP_Error('upstream', $error, ['status' => 502]);
    }

    return new WP_REST_Response($data, 200);
}

add_action('rest_api_init', 'dvpe_register_photo_route');

function dvpe_register_photo_route()
{
    register_rest_route(DVPE_PROXY_NAMESPACE, '/photo', [
        'methods' => 'GET',
        'callback' => 'dvpe_photo_proxy',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Sirve la foto de un reporte desde este mismo dominio.
 *
 * No es un capricho: la tarjeta para compartir se compone en un <canvas>, y una
 * imagen de otro dominio sin cabeceras CORS lo "mancha" e impide descargarla.
 * Pasándola por aquí, para el navegador es del mismo origen.
 *
 * Solo se acepta el id del reporte, nunca una dirección: la URL de la foto la
 * decide la API del bot, no quien llama. Así esto no puede usarse para que el
 * servidor descargue cualquier cosa de la red interna.
 */
function dvpe_photo_proxy($request)
{
    $id = (int) $request->get_param('id');

    if ($id <= 0) {
        return new WP_Error('bad_id', 'Falta el reporte.', ['status' => 400]);
    }

    list($data, $error) = dvpe_api_get('power/report/' . $id, []);

    if ($error !== '') {
        return new WP_Error('upstream', $error, ['status' => 502]);
    }

    $url = isset($data['photo']) ? (string) $data['photo'] : '';

    if ($url === '') {
        return new WP_Error('sin_foto', 'Ese reporte no tiene foto.', ['status' => 404]);
    }

    $respuesta = wp_remote_get($url, ['timeout' => 12]);

    if (is_wp_error($respuesta) || wp_remote_retrieve_response_code($respuesta) !== 200) {
        return new WP_Error('foto', 'No se pudo recuperar la foto.', ['status' => 502]);
    }

    $tipo = (string) wp_remote_retrieve_header($respuesta, 'content-type');

    if (strpos($tipo, 'image/') !== 0) {
        return new WP_Error('foto', 'El archivo no es una imagen.', ['status' => 415]);
    }

    $cuerpo = wp_remote_retrieve_body($respuesta);

    // Se sirve a mano y se corta la ejecución: una imagen no pasa por el
    // serializador JSON de la API REST.
    header('Content-Type: ' . $tipo);
    header('Content-Length: ' . strlen($cuerpo));
    header('Access-Control-Allow-Origin: *');
    // La foto de un reporte ya enviado no cambia nunca.
    header('Cache-Control: public, max-age=86400, immutable');

    echo $cuerpo;
    exit;
}

add_filter('rest_post_dispatch', 'dvpe_proxy_headers', 10, 3);

/** El proxy no puede cachear: el portal es en tiempo real. */
function dvpe_proxy_headers($response, $server, $request)
{
    if (strpos($request->get_route(), '/' . DVPE_PROXY_NAMESPACE) !== 0) {
        return $response;
    }

    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');
    $response->header('Expires', '0');

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    return $response;
}

/** Base que usará el JavaScript del portal, según el modo configurado. */
function dvpe_client_endpoint()
{
    if (dvpe_mode() === 'proxy') {
        return [
            'mode' => 'proxy',
            'base' => rest_url(DVPE_PROXY_NAMESPACE . '/proxy'),
        ];
    }

    return [
        'mode' => 'direct',
        'base' => dvpe_api_base(),
    ];
}

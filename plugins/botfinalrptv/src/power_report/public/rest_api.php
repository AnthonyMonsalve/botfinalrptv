<?php

/**
 * API REST pública del Reporte Eléctrico.
 *
 * La consume el plugin del portal, que puede vivir en OTRO sitio WordPress. Por
 * eso los endpoints son abiertos, van con CORS y jamás devuelven datos personales:
 * el teléfono y el nombre del reportante se quedan en el admin de este sitio.
 *
 * El filtrado de datos personales se hace aquí, construyendo la respuesta campo a
 * campo con una lista blanca. Nunca se serializa la fila de la base de datos tal
 * cual, para que agregar una columna nueva no la publique por accidente.
 */

if (!defined('ABSPATH')) {
    exit;
}

const DATAV_POWER_API_NAMESPACE = 'datav/v1';
const DATAV_POWER_API_VERSION = '1.4.0';

add_action('rest_api_init', 'datav_power_register_rest_routes');

function datav_power_register_rest_routes()
{
    $public = ['methods' => 'GET', 'permission_callback' => '__return_true'];

    register_rest_route(DATAV_POWER_API_NAMESPACE, '/power/meta', $public + [
        'callback' => 'datav_power_rest_meta',
    ]);

    register_rest_route(DATAV_POWER_API_NAMESPACE, '/power/live', $public + [
        'callback' => 'datav_power_rest_live',
    ]);

    register_rest_route(DATAV_POWER_API_NAMESPACE, '/power/stats', $public + [
        'callback' => 'datav_power_rest_stats',
    ]);

    register_rest_route(DATAV_POWER_API_NAMESPACE, '/power/zones', $public + [
        'callback' => 'datav_power_rest_zones',
    ]);

    register_rest_route(DATAV_POWER_API_NAMESPACE, '/power/report/(?P<id>\d+)', $public + [
        'callback' => 'datav_power_rest_report',
    ]);

    register_rest_route(DATAV_POWER_API_NAMESPACE, '/power/comments', $public + [
        'callback' => 'datav_power_rest_comments',
    ]);

    register_rest_route(DATAV_POWER_API_NAMESPACE, '/power/points', $public + [
        'callback' => 'datav_power_rest_points',
    ]);

    register_rest_route(DATAV_POWER_API_NAMESPACE, '/power/timeline', $public + [
        'callback' => 'datav_power_rest_timeline',
    ]);
}

/** Ventana consultada, común a stats, points y timeline. */
function datav_power_rest_bounds($request)
{
    return datav_power_resolve_bounds(
        (string) $request->get_param('range'),
        (string) $request->get_param('from'),
        (string) $request->get_param('to')
    );
}

/* -------------------------------------------------------------------------
 * Endpoints
 * ---------------------------------------------------------------------- */

/** Identidad de la API: sirve para que el portal compruebe la conexión. */
function datav_power_rest_meta()
{
    return datav_power_rest_response([
        'name' => 'DatAvenger — Reporte Eléctrico Nacional',
        'version' => DATAV_POWER_API_VERSION,
        'site' => get_bloginfo('name'),
        'enabled' => datav_power_is_enabled(),
        'ranges' => datav_power_stats_ranges(),
        'timezone' => wp_timezone_string(),
        'active_count' => datav_power_active_count(),
        // Para que el portal sepa si debe pintar la sección de comentarios.
        'comments_enabled' => datav_power_comments_public(),
        'comments_count' => datav_power_comments_public() ? datav_power_comments_count() : 0,
    ]);
}

/** Fallas en monitoreo ahora mismo. */
function datav_power_rest_live($request)
{
    $filters = datav_power_rest_filters($request);
    $limit = min(1000, max(1, (int) $request->get_param('limit') ?: 500));

    $reports = [];

    foreach (datav_power_reports_live($filters, $limit) as $row) {
        $reports[] = datav_power_public_report($row);
    }

    return datav_power_rest_response([
        'generated_at' => current_time('mysql'),
        'active_count' => count($reports),
        'reports' => $reports,
    ]);
}

/**
 * Totales y rankings por zona para un periodo.
 *
 * Acepta un rango con nombre (range=7d) o uno a medida (from/to). Se siguen
 * devolviendo 'range', 'from' y 'to' sueltos porque el portal antiguo los lee así.
 */
function datav_power_rest_stats($request)
{
    $filters = datav_power_rest_filters($request);
    $bounds = datav_power_rest_bounds($request);
    $totals = datav_power_stats_totals($bounds, $filters);

    // Una sola pasada de intervalos sirve para el total y para los tres niveles.
    $coverage = datav_power_coverage($bounds, $filters);
    $span = max(0, strtotime($bounds['to']) - strtotime($bounds['from']));

    return datav_power_rest_response([
        'generated_at' => current_time('mysql'),
        'range' => $bounds['range'],
        'from' => $bounds['from'],
        'to' => $bounds['to'],
        'label' => $bounds['label'],
        // Cuánto dura la ventana consultada: da contexto a la cobertura
        // ("18 h de 24 h") sin que el portal tenga que restar fechas.
        'range_seconds' => $span,
        'range_label' => datav_power_format_duration($span),
        'totals' => $totals + [
            'outage_hours' => round($totals['outage_seconds'] / 3600, 1),
            'outage_label' => datav_power_format_duration($totals['outage_seconds']),
            'avg_label' => datav_power_format_duration($totals['avg_seconds']),
            // Tiempo real con al menos una falla activa, sin contar dos veces los
            // reportes que se solapan. Es la cifra que se puede leer como duración.
            'coverage_seconds' => $coverage['total'],
            'coverage_hours' => round($coverage['total'] / 3600, 1),
            'coverage_label' => datav_power_format_duration($coverage['total']),
            'coverage_ratio' => $span > 0 ? round($coverage['total'] / $span, 3) : 0,
        ],
        'by_estado' => datav_power_stats_by_zone($bounds, 'estado', $filters, 25, $coverage),
        'by_municipio' => datav_power_stats_by_zone($bounds, 'municipio', $filters, 25, $coverage),
        'by_parroquia' => datav_power_stats_by_zone($bounds, 'parroquia', $filters, 25, $coverage),
    ]);
}

/**
 * Focos del mapa de calor para un periodo.
 *
 * Por defecto agrega por zona (una fila por municipio), que es lo que hace que
 * el mapa y el ranking cuenten lo mismo. Con level=point vuelve a la agregación
 * por coordenada, más fina pero incomparable con la tabla.
 *
 * En ningún caso se devuelven reportes: ni identificadores ni direcciones.
 */
function datav_power_rest_points($request)
{
    $bounds = datav_power_rest_bounds($request);
    $filters = datav_power_rest_filters($request);
    $limit = min(5000, max(1, (int) $request->get_param('limit') ?: 5000));

    $level = (string) $request->get_param('level');
    $level = in_array($level, ['estado', 'municipio', 'parroquia', 'point'], true) ? $level : 'municipio';

    if ($level === 'point') {
        $points = datav_power_points_in_range($bounds, $filters, $limit);
    } else {
        // La unión de intervalos es la misma que alimenta el ranking.
        $points = datav_power_zone_points($bounds, $level, $filters, datav_power_coverage($bounds, $filters), $limit);
    }

    $max = ['c' => 0, 's' => 0, 'u' => 0];

    foreach ($points as $point) {
        $max['c'] = max($max['c'], $point['c']);
        $max['s'] = max($max['s'], $point['s']);
        $max['u'] = max($max['u'], isset($point['u']) ? $point['u'] : $point['s']);
    }

    return datav_power_rest_response([
        'generated_at' => current_time('mysql'),
        'range' => $bounds['range'],
        'from' => $bounds['from'],
        'to' => $bounds['to'],
        'label' => $bounds['label'],
        'level' => $level,
        'count' => count($points),
        // El portal normaliza la intensidad con estos máximos.
        'max' => $max,
        'points' => $points,
    ]);
}

/** Reportes por hora o por día dentro del periodo. */
function datav_power_rest_timeline($request)
{
    $bounds = datav_power_rest_bounds($request);
    $filters = datav_power_rest_filters($request);

    $timeline = datav_power_timeline($bounds, (string) $request->get_param('bucket'), $filters);

    return datav_power_rest_response([
        'generated_at' => current_time('mysql'),
        'range' => $bounds['range'],
        'from' => $bounds['from'],
        'to' => $bounds['to'],
        'label' => $bounds['label'],
    ] + $timeline);
}

/** División territorial activa, para los filtros del portal. */
function datav_power_rest_zones()
{
    $estados = [];

    foreach (datav_power_geo_tree() as $code => $estado) {
        $municipios = [];

        foreach ($estado['municipios'] as $m_code => $municipio) {
            $municipios[] = [
                'code' => $m_code,
                'name' => $municipio['name'],
                'lat' => $municipio['lat'],
                'lng' => $municipio['lng'],
            ];
        }

        $estados[] = [
            'code' => $code,
            'name' => $estado['name'],
            'lat' => $estado['lat'],
            'lng' => $estado['lng'],
            'municipios' => $municipios,
        ];
    }

    return datav_power_rest_response(['estados' => $estados]);
}

/** Detalle público de un reporte. */
function datav_power_rest_report($request)
{
    $report = datav_power_report_get((int) $request['id']);

    if (!$report || $report->status === 'draft') {
        return new WP_Error('not_found', 'Reporte no encontrado.', ['status' => 404]);
    }

    return datav_power_rest_response(datav_power_public_report($report, true));
}

/**
 * Mensajes que la gente deja al cerrarse su caso, para la sección de comentarios
 * del portal. Van sin teléfono ni nombre, igual que el resto de la API.
 *
 * Si el admin apaga la publicación, el endpoint sigue existiendo pero devuelve
 * la lista vacía: así el portal no se rompe, simplemente no muestra nada.
 */
function datav_power_rest_comments($request)
{
    $limit = min(100, max(1, (int) $request->get_param('limit') ?: 20));
    $offset = max(0, (int) $request->get_param('offset'));
    $filters = datav_power_rest_filters($request);

    if (!datav_power_comments_public()) {
        return datav_power_rest_response([
            'generated_at' => current_time('mysql'),
            'total' => 0,
            'comments' => [],
        ]);
    }

    $comments = [];

    foreach (datav_power_reports_with_comments($limit, $offset, $filters) as $row) {
        $comments[] = datav_power_public_comment($row);
    }

    return datav_power_rest_response([
        'generated_at' => current_time('mysql'),
        'total' => datav_power_comments_count($filters),
        'limit' => $limit,
        'offset' => $offset,
        'comments' => $comments,
    ]);
}

/* -------------------------------------------------------------------------
 * Serialización pública
 * ---------------------------------------------------------------------- */

/**
 * Un comentario publicable.
 *
 * 'text' es texto escrito por una persona por WhatsApp: el portal debe escaparlo
 * antes de pintarlo. Aquí se guarda ya pasado por sanitize_textarea_field().
 */
function datav_power_public_comment($row)
{
    $seconds = datav_power_report_duration_seconds($row);

    return [
        'report_id' => (int) $row->id,
        'text' => $row->comment,
        'created_at' => $row->comment_at,
        'zona' => datav_power_report_zone_label($row),
        'estado' => $row->estado,
        'estado_code' => $row->estado_code,
        'municipio' => $row->municipio,
        'municipio_code' => $row->municipio_code,
        'parroquia' => $row->parroquia,
        'parroquia_code' => $row->parroquia_code,
        'status' => $row->status,
        'outage_since' => $row->outage_since,
        'restored_at' => $row->restored_at,
        'duration_seconds' => $seconds,
        'duration_label' => datav_power_format_duration($seconds),
    ];
}

/**
 * Lista blanca de campos publicables.
 *
 * phone_number y profile_name NO están y no deben añadirse: el portal es una
 * página abierta y quien reporta una falla no consintió aparecer identificado.
 */
function datav_power_public_report($row, $detailed = false)
{
    $seconds = datav_power_report_duration_seconds($row);

    $data = [
        'id' => (int) $row->id,
        'estado' => $row->estado,
        'estado_code' => $row->estado_code,
        'municipio' => $row->municipio,
        'municipio_code' => $row->municipio_code,
        'parroquia' => $row->parroquia,
        'parroquia_code' => $row->parroquia_code,
        'zona' => datav_power_report_zone_label($row),
        'direccion' => $row->direccion,
        'lat' => $row->lat === null ? null : (float) $row->lat,
        'lng' => $row->lng === null ? null : (float) $row->lng,
        'geo_precision' => $row->geo_precision,
        'status' => $row->status,
        'outage_since' => $row->outage_since,
        'restored_at' => $row->restored_at,
        'duration_seconds' => $seconds,
        'duration_label' => datav_power_format_duration($seconds),
        'has_photo' => !empty($row->media_url),
    ];

    // El comentario sí es publicable: la persona lo escribió sabiendo que va al
    // portal. Aun así respeta el interruptor del admin, por si hay que apagarlo.
    $data['has_comment'] = datav_power_comments_public() && !empty($row->comment);
    $data['comment'] = $data['has_comment'] ? $row->comment : null;
    $data['comment_at'] = $data['has_comment'] ? $row->comment_at : null;

    if ($detailed) {
        $data['photo'] = $row->media_url;
        $data['checks_sent'] = (int) $row->checks_sent;
        $data['last_confirmed_at'] = $row->last_confirmed_at;
    }

    return $data;
}

function datav_power_active_count()
{
    global $wpdb;

    $table = datav_power_reports_table();

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'active'");
}

function datav_power_rest_filters($request)
{
    return [
        'estado_code' => sanitize_text_field((string) $request->get_param('estado_code')),
        'municipio_code' => sanitize_text_field((string) $request->get_param('municipio_code')),
        'parroquia_code' => sanitize_text_field((string) $request->get_param('parroquia_code')),
    ];
}

function datav_power_rest_response($data)
{
    return new WP_REST_Response($data, 200);
}

/* -------------------------------------------------------------------------
 * CORS y anti-caché
 * ---------------------------------------------------------------------- */

add_filter('rest_post_dispatch', 'datav_power_rest_headers', 10, 3);

/**
 * Cabeceras solo para las rutas de este módulo.
 *
 * Se usa rest_post_dispatch y no se toca el filtro global rest_send_cors_headers
 * de WordPress: cambiar ese afectaría a TODAS las rutas REST del sitio, incluida
 * la del webhook de Twilio y las del propio WordPress.
 */
function datav_power_rest_headers($response, $server, $request)
{
    if (strpos($request->get_route(), '/' . DATAV_POWER_API_NAMESPACE . '/power') !== 0) {
        return $response;
    }

    // El portal vive en otro dominio y necesita leer esto desde el navegador.
    $origin = datav_power_allowed_origin(get_http_origin());

    if ($origin !== '') {
        $response->header('Access-Control-Allow-Origin', $origin);
        $response->header('Vary', 'Origin');
        $response->header('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type');
        $response->header('Access-Control-Max-Age', '600');
    }

    // Tiempo real: nada de caché en ningún nivel.
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');
    $response->header('Expires', '0');

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    if (!defined('DONOTCACHEOBJECT')) {
        define('DONOTCACHEOBJECT', true);
    }

    return $response;
}

/**
 * Origen permitido. Sin lista configurada se permite cualquiera, que es lo
 * razonable para datos públicos y anonimizados; con lista, solo los que estén.
 */
function datav_power_allowed_origin($origin)
{
    $raw = trim((string) get_option('datAv_power_api_allowed_origins', ''));

    if ($raw === '') {
        return '*';
    }

    $allowed = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw)));

    if ($origin && in_array(untrailingslashit($origin), $allowed, true)) {
        return $origin;
    }

    return '';
}

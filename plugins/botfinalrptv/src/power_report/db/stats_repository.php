<?php

/**
 * Agregaciones para el portal público: horas sin luz por zona y por periodo.
 *
 * SQL compatible con MySQL 5.7 (el docker-compose fija esa versión): nada de
 * CTEs ni funciones de ventana, solo GROUP BY y TIMESTAMPDIFF.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Momento en que un caso deja de sumar horas, en SQL.
 *
 * Refleja exactamente datav_power_report_effective_end(): si no, el portal y el
 * bot reportarían duraciones distintas para el mismo caso. No se puede usar un
 * simple COALESCE(restored_at, last_confirmed_at, ...) porque los casos ACTIVOS
 * también tienen last_confirmed_at y se quedarían congelados en el pasado.
 *
 * %s se sustituye por la hora actual de WordPress, no por NOW(): las fechas se
 * guardan con current_time('mysql') y el servidor MySQL puede estar en otra zona.
 */
function datav_power_sql_effective_end()
{
    return "(CASE
        WHEN r.restored_at IS NOT NULL THEN r.restored_at
        WHEN r.status = 'undetermined' THEN COALESCE(r.last_confirmed_at, r.outage_since)
        ELSE %s
    END)";
}

/** Rangos con nombre que ofrece el portal. Además se admite uno a medida. */
function datav_power_stats_ranges()
{
    return ['today', 'yesterday', '24h', '7d', '30d', 'month'];
}

/** Ventana mínima y máxima de un rango a medida. */
const DATAV_POWER_MIN_RANGE_SECONDS = 900;
const DATAV_POWER_MAX_RANGE_DAYS = 366;

/**
 * Resuelve la ventana a consultar, venga de un rango con nombre o de un
 * from/to escritos por el usuario.
 *
 * Todo lo que llega de fuera se normaliza aquí: se aceptan varios formatos de
 * fecha (incluido el 'Y-m-d\TH:i' que mandan los campos datetime-local), se
 * ordenan si vienen al revés, no se deja consultar el futuro y se acota el
 * tamaño de la ventana. Así ninguna consulta de abajo tiene que desconfiar.
 *
 * @return array ['from','to','range','label']
 */
function datav_power_resolve_bounds($range = '', $from = '', $to = '')
{
    $now = current_time('mysql');
    $now_ts = strtotime($now);

    $from_ts = datav_power_parse_input_datetime($from);
    $to_ts = datav_power_parse_input_datetime($to);

    // Sin fechas escritas, manda el rango con nombre.
    if ($from_ts === null && $to_ts === null) {
        $bounds = datav_power_range_bounds($range);
        $bounds['range'] = in_array($range, datav_power_stats_ranges(), true) ? $range : '7d';
        $bounds['label'] = datav_power_range_label($bounds['from'], $bounds['to']);

        return $bounds;
    }

    // Con una sola fecha se completa la otra: hasta ahora, o los 7 días previos.
    if ($from_ts === null) {
        $from_ts = $to_ts - 7 * DAY_IN_SECONDS;
    }

    if ($to_ts === null) {
        $to_ts = $now_ts;
    }

    if ($from_ts > $to_ts) {
        $swap = $from_ts;
        $from_ts = $to_ts;
        $to_ts = $swap;
    }

    // Consultar el futuro no devuelve nada útil y ensucia las etiquetas.
    $to_ts = min($to_ts, $now_ts);

    if ($to_ts - $from_ts < DATAV_POWER_MIN_RANGE_SECONDS) {
        $from_ts = $to_ts - DATAV_POWER_MIN_RANGE_SECONDS;
    }

    if ($to_ts - $from_ts > DATAV_POWER_MAX_RANGE_DAYS * DAY_IN_SECONDS) {
        $from_ts = $to_ts - DATAV_POWER_MAX_RANGE_DAYS * DAY_IN_SECONDS;
    }

    $bounds = [
        'from' => date('Y-m-d H:i:s', $from_ts),
        'to' => date('Y-m-d H:i:s', $to_ts),
        'range' => 'custom',
    ];

    $bounds['label'] = datav_power_range_label($bounds['from'], $bounds['to']);

    return $bounds;
}

/**
 * Fecha escrita por el usuario -> marca de tiempo, o null si no se entiende.
 * Se admite 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' y 'Y-m-d\TH:i'.
 */
function datav_power_parse_input_datetime($value)
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    // 'T' del datetime-local del navegador; el resto ya es formato de MySQL.
    $value = str_replace('T', ' ', $value);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value)) {
        return null;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : $timestamp;
}

/** '28 ago 6:00 am — 29 ago 6:00 pm' / '28 ago — 29 ago' cuando son días enteros. */
function datav_power_range_label($from, $to)
{
    $from_ts = strtotime($from);
    $to_ts = strtotime($to);

    $meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    // El año solo se escribe cuando no es el actual: en un rango de esta semana
    // sobra, pero en uno que cruza de diciembre a enero es imprescindible.
    $current_year = date('Y', strtotime(current_time('mysql')));

    $day = function ($ts) use ($meses, $current_year) {
        $label = (int) date('j', $ts) . ' ' . $meses[(int) date('n', $ts)];

        return date('Y', $ts) === $current_year ? $label : $label . ' ' . date('Y', $ts);
    };

    $time = function ($ts) {
        return strtolower(date('g:i a', $ts));
    };

    // Un rango que cubre días completos se lee mejor sin horas.
    $whole_days = date('H:i:s', $from_ts) === '00:00:00' && date('H:i', $to_ts) === '23:59';

    if ($whole_days) {
        return $day($from_ts) === $day($to_ts)
            ? $day($from_ts)
            : $day($from_ts) . ' — ' . $day($to_ts);
    }

    return $day($from_ts) . ' ' . $time($from_ts) . ' — ' . $day($to_ts) . ' ' . $time($to_ts);
}

/** ['from' => 'Y-m-d H:i:s', 'to' => 'Y-m-d H:i:s'] para un rango con nombre. */
function datav_power_range_bounds($range)
{
    $now = current_time('mysql');
    $now_ts = strtotime($now);

    switch ($range) {
        case 'today':
            $from = date('Y-m-d 00:00:00', $now_ts);
            break;

        // Ayer es el único preset que no termina "ahora": es un día cerrado.
        case 'yesterday':
            $yesterday = $now_ts - DAY_IN_SECONDS;

            return [
                'from' => date('Y-m-d 00:00:00', $yesterday),
                'to' => date('Y-m-d 23:59:59', $yesterday),
            ];

        case '24h':
            $from = date('Y-m-d H:i:s', $now_ts - DAY_IN_SECONDS);
            break;

        case 'month':
            $from = date('Y-m-01 00:00:00', $now_ts);
            break;

        case '30d':
            $from = date('Y-m-d H:i:s', $now_ts - 30 * DAY_IN_SECONDS);
            break;

        case '7d':
        default:
            $from = date('Y-m-d H:i:s', $now_ts - 7 * DAY_IN_SECONDS);
            break;
    }

    return ['from' => $from, 'to' => $now];
}

/**
 * Segundos sin servicio dentro de la ventana consultada.
 *
 * Se recorta el caso al rango (GREATEST/LEAST) para que una falla que empezó
 * hace 20 días no vuelque todas sus horas dentro del filtro "hoy".
 */
function datav_power_sql_seconds_in_range()
{
    $end = datav_power_sql_effective_end();

    return "GREATEST(0, TIMESTAMPDIFF(
        SECOND,
        GREATEST(r.outage_since, %s),
        LEAST($end, %s)
    ))";
}

/** Filtro común: casos con horas computables que solapan el rango. */
function datav_power_sql_range_where()
{
    $end = datav_power_sql_effective_end();

    return "r.status IN ('active', 'restored', 'undetermined')
        AND r.outage_since IS NOT NULL
        AND r.outage_since <= %s
        AND $end >= %s";
}

/**
 * Argumentos de una consulta que usa SUM(seconds_in_range) ... WHERE range_where.
 *
 * El orden importa y no es intuitivo, porque los marcadores de effective_end
 * quedan intercalados dentro de los otros dos fragmentos. Siguiendo el orden en
 * que aparecen en el texto del SQL:
 *   seconds_in_range -> from, now, to
 *   range_where      -> to,   now, from
 */
function datav_power_range_args($bounds, $now)
{
    return [
        $bounds['from'],
        $now,
        $bounds['to'],
        $bounds['to'],
        $now,
        $bounds['from'],
    ];
}

/* -------------------------------------------------------------------------
 * Cobertura: cuánto tiempo REAL hubo fallas
 * ---------------------------------------------------------------------- */

/**
 * Tope de casos que se traen para calcular la unión de intervalos.
 * Con más de esto la cifra ya no cambia de forma apreciable y sí lo hace el coste.
 */
const DATAV_POWER_INTERVAL_LIMIT = 20000;

/**
 * Segundos que cubre un conjunto de intervalos, contando una sola vez los que
 * se solapan.
 *
 * Es la diferencia entre "sumar horas" y "medir tiempo": si veinte personas del
 * mismo sector reportan el mismo apagón de 3 h, la suma dice 60 h y la realidad
 * son 3. Sumar mide cuánta gente reportó; esto mide cuánto duró la falla.
 *
 * @param array $intervals [[inicio, fin], ...] en marcas de tiempo.
 */
function datav_power_union_seconds($intervals)
{
    if (empty($intervals)) {
        return 0;
    }

    usort($intervals, function ($a, $b) {
        return $a[0] - $b[0];
    });

    $total = 0;
    $start = null;
    $end = null;

    foreach ($intervals as $interval) {
        if ($start === null) {
            $start = $interval[0];
            $end = $interval[1];

            continue;
        }

        // Empieza antes de que acabe el tramo abierto: se estira, no se suma.
        if ($interval[0] <= $end) {
            $end = max($end, $interval[1]);

            continue;
        }

        $total += max(0, $end - $start);
        $start = $interval[0];
        $end = $interval[1];
    }

    return $total + max(0, $end - $start);
}

/**
 * Cobertura del periodo: total nacional y por cada nivel territorial.
 *
 * Se resuelve con UNA consulta y el resto en PHP porque MySQL 5.7 no tiene
 * funciones de ventana; fusionar intervalos aquí es directo y basta de sobra
 * con los volúmenes de esta base.
 *
 * @return array ['total' => seg, 'estado' => [code => seg], 'municipio' => …, 'parroquia' => …]
 */
function datav_power_coverage($bounds, $filters = [])
{
    global $wpdb;

    $table = datav_power_reports_table();
    $now = current_time('mysql');

    $end_expr = datav_power_sql_effective_end();
    $where = datav_power_sql_range_where();

    $sql = "SELECT
            r.estado_code, r.municipio_code, r.parroquia_code,
            GREATEST(r.outage_since, %s) AS s,
            LEAST($end_expr, %s) AS e
        FROM $table r
        WHERE $where";

    $args = datav_power_range_args($bounds, $now);

    list($sql, $args) = datav_power_apply_zone_filters($sql, $args, $filters);

    $sql .= ' LIMIT ' . DATAV_POWER_INTERVAL_LIMIT;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

    $all = [];
    $levels = ['estado' => [], 'municipio' => [], 'parroquia' => []];

    foreach ((array) $rows as $row) {
        $interval = [strtotime($row->s), strtotime($row->e)];

        if ($interval[1] <= $interval[0]) {
            continue;
        }

        $all[] = $interval;

        foreach (['estado', 'municipio', 'parroquia'] as $level) {
            $code = $row->{$level . '_code'};

            if ($code !== '') {
                $levels[$level][$code][] = $interval;
            }
        }
    }

    $coverage = ['total' => datav_power_union_seconds($all)];

    foreach ($levels as $level => $zones) {
        $coverage[$level] = [];

        foreach ($zones as $code => $intervals) {
            $coverage[$level][$code] = datav_power_union_seconds($intervals);
        }
    }

    return $coverage;
}

/**
 * Totales del periodo.
 *
 * @return array reports, active, restored, undetermined, outage_seconds, avg_seconds
 */
function datav_power_stats_totals($bounds, $filters = [])
{
    global $wpdb;

    $table = datav_power_reports_table();
    $now = current_time('mysql');

    $seconds = datav_power_sql_seconds_in_range();
    $where = datav_power_sql_range_where();

    $sql = "SELECT
            COUNT(*) AS reports,
            SUM(CASE WHEN r.status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN r.status = 'restored' THEN 1 ELSE 0 END) AS restored,
            SUM(CASE WHEN r.status = 'undetermined' THEN 1 ELSE 0 END) AS undetermined,
            COALESCE(SUM($seconds), 0) AS outage_seconds
        FROM $table r
        WHERE $where";

    $args = datav_power_range_args($bounds, $now);

    list($sql, $args) = datav_power_apply_zone_filters($sql, $args, $filters);

    $row = $wpdb->get_row($wpdb->prepare($sql, $args));

    $reports = $row ? (int) $row->reports : 0;
    $seconds_total = $row ? (int) $row->outage_seconds : 0;

    return [
        'reports' => $reports,
        'active' => $row ? (int) $row->active : 0,
        'restored' => $row ? (int) $row->restored : 0,
        'undetermined' => $row ? (int) $row->undetermined : 0,
        'outage_seconds' => $seconds_total,
        'avg_seconds' => $reports > 0 ? (int) round($seconds_total / $reports) : 0,
    ];
}

/**
 * Ranking por zona.
 *
 * @param string $level 'estado' | 'municipio' | 'parroquia'
 */
function datav_power_stats_by_zone($bounds, $level = 'estado', $filters = [], $limit = 25, $coverage = null)
{
    global $wpdb;

    $table = datav_power_reports_table();
    $now = current_time('mysql');

    $columns = [
        'estado' => ['r.estado_code', 'r.estado', "''"],
        'municipio' => ['r.municipio_code', 'r.municipio', 'r.estado'],
        'parroquia' => ['r.parroquia_code', 'r.parroquia', 'r.municipio'],
    ];

    if (!isset($columns[$level])) {
        $level = 'estado';
    }

    list($code_col, $name_col, $parent_col) = $columns[$level];

    // Se piden más filas de las que se devuelven porque el orden final lo decide
    // la cobertura, que se calcula en PHP: quedarse antes con el top por suma
    // podría dejar fuera a una zona que sí encabeza el ranking por tiempo real.
    $fetch = $coverage === null ? $limit : max($limit, 200);

    $seconds = datav_power_sql_seconds_in_range();
    $where = datav_power_sql_range_where();

    $sql = "SELECT
            $code_col AS code,
            $name_col AS name,
            $parent_col AS parent,
            COUNT(*) AS reports,
            SUM(CASE WHEN r.status = 'active' THEN 1 ELSE 0 END) AS active,
            COALESCE(SUM($seconds), 0) AS outage_seconds
        FROM $table r
        WHERE $where AND $code_col <> ''";

    $args = datav_power_range_args($bounds, $now);

    list($sql, $args) = datav_power_apply_zone_filters($sql, $args, $filters);

    $sql .= " GROUP BY $code_col, $name_col, $parent_col
              ORDER BY outage_seconds DESC, reports DESC
              LIMIT %d";
    $args[] = (int) $fetch;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

    $out = [];

    foreach ((array) $rows as $row) {
        // 'coverage' es el tiempo real con fallas en la zona (intervalos unidos)
        // y 'outage' la suma de todos los reportes. Se publican los dos: el
        // primero se lee como duración, el segundo como volumen de reportes.
        $covered = ($coverage !== null && isset($coverage[$level][$row->code]))
            ? (int) $coverage[$level][$row->code]
            : null;

        $out[] = [
            'code' => $row->code,
            'name' => $row->name,
            'parent' => $row->parent,
            'reports' => (int) $row->reports,
            'active' => (int) $row->active,
            'outage_seconds' => (int) $row->outage_seconds,
            'outage_hours' => round($row->outage_seconds / 3600, 1),
            'outage_label' => datav_power_format_duration((int) $row->outage_seconds),
            'coverage_seconds' => $covered,
            'coverage_label' => $covered === null ? '' : datav_power_format_duration($covered),
        ];
    }

    if ($coverage !== null) {
        usort($out, function ($a, $b) {
            if ($a['coverage_seconds'] === $b['coverage_seconds']) {
                // A igual tiempo sin luz, primero donde más gente lo reportó.
                return $b['reports'] - $a['reports'];
            }

            return $b['coverage_seconds'] - $a['coverage_seconds'];
        });

        $out = array_slice($out, 0, $limit);
    }

    return $out;
}

/** Fallas en monitoreo ahora mismo, ordenadas por la más larga. */
function datav_power_reports_live($filters = [], $limit = 500)
{
    global $wpdb;

    $table = datav_power_reports_table();

    $sql = "SELECT * FROM $table r WHERE r.status = 'active' AND r.outage_since IS NOT NULL";
    $args = [];

    list($sql, $args) = datav_power_apply_zone_filters($sql, $args, $filters);

    $sql .= ' ORDER BY r.outage_since ASC LIMIT %d';
    $args[] = (int) $limit;

    return $wpdb->get_results($wpdb->prepare($sql, $args));
}

/**
 * Focos para el mapa de calor: los puntos del periodo con sus dos pesos.
 *
 * Se agrupa por coordenada redondeada a 3 decimales (~100 m) por dos motivos:
 * los reportes sin GPS caen todos en el punto conocido de su parroquia o
 * municipio y se apilarían en el mismo píxel, y así la respuesta no crece un
 * elemento por cada reporte.
 *
 * Cada punto lleva 'c' (reportes) y 's' (segundos sin luz dentro del rango):
 * son los dos pesos entre los que el portal alterna sin volver a pedir datos.
 */
function datav_power_points_in_range($bounds, $filters = [], $limit = 5000)
{
    global $wpdb;

    $table = datav_power_reports_table();
    $now = current_time('mysql');

    $seconds = datav_power_sql_seconds_in_range();
    $where = datav_power_sql_range_where();

    $sql = "SELECT
            ROUND(r.lat, 3) AS lat,
            ROUND(r.lng, 3) AS lng,
            COUNT(*) AS c,
            COALESCE(SUM($seconds), 0) AS s
        FROM $table r
        WHERE $where AND r.lat IS NOT NULL AND r.lng IS NOT NULL";

    $args = datav_power_range_args($bounds, $now);

    list($sql, $args) = datav_power_apply_zone_filters($sql, $args, $filters);

    $sql .= ' GROUP BY ROUND(r.lat, 3), ROUND(r.lng, 3)
              ORDER BY s DESC
              LIMIT %d';
    $args[] = (int) $limit;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

    $points = [];

    foreach ((array) $rows as $row) {
        $points[] = [
            'lat' => (float) $row->lat,
            'lng' => (float) $row->lng,
            'c' => (int) $row->c,
            's' => (int) $row->s,
        ];
    }

    return $points;
}

/**
 * Focos agregados por zona: una fila por estado, municipio o parroquia.
 *
 * Es lo que hace que el mapa y el ranking hablen de lo mismo. Agrupando por
 * coordenada, un estado con reportes repartidos en varios municipios se dibuja
 * como varias manchas débiles aunque encabece la tabla; agrupando por zona, el
 * foco más caliente es exactamente el número uno de la lista.
 *
 * El punto se sitúa en el promedio de las coordenadas de sus propios reportes,
 * no en el centro del municipio: así cae donde de verdad está reportando la
 * gente. AVG() ignora los nulos, de modo que un reporte sin coordenadas sigue
 * contando en 'c' aunque no mueva el punto — que es como lo cuenta el ranking.
 */
function datav_power_zone_points($bounds, $level = 'municipio', $filters = [], $coverage = null, $limit = 1000)
{
    global $wpdb;

    $table = datav_power_reports_table();
    $now = current_time('mysql');

    $columns = [
        'estado' => ['r.estado_code', 'r.estado', "''"],
        'municipio' => ['r.municipio_code', 'r.municipio', 'r.estado'],
        'parroquia' => ['r.parroquia_code', 'r.parroquia', 'r.municipio'],
    ];

    if (!isset($columns[$level])) {
        $level = 'municipio';
    }

    list($code_col, $name_col, $parent_col) = $columns[$level];

    $seconds = datav_power_sql_seconds_in_range();
    $where = datav_power_sql_range_where();

    $sql = "SELECT
            $code_col AS code,
            $name_col AS name,
            $parent_col AS parent,
            AVG(r.lat) AS lat,
            AVG(r.lng) AS lng,
            COUNT(*) AS c,
            COALESCE(SUM($seconds), 0) AS s
        FROM $table r
        WHERE $where AND $code_col <> ''";

    $args = datav_power_range_args($bounds, $now);

    list($sql, $args) = datav_power_apply_zone_filters($sql, $args, $filters);

    $sql .= " GROUP BY $code_col, $name_col, $parent_col
              ORDER BY s DESC
              LIMIT %d";
    $args[] = (int) $limit;

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

    $points = [];

    foreach ((array) $rows as $row) {
        // Una zona cuyos reportes no tienen ni una coordenada no se puede pintar.
        if ($row->lat === null || $row->lng === null) {
            continue;
        }

        $covered = ($coverage !== null && isset($coverage[$level][$row->code]))
            ? (int) $coverage[$level][$row->code]
            : (int) $row->s;

        $points[] = [
            'code' => $row->code,
            'name' => $row->name,
            'parent' => $row->parent,
            'lat' => round((float) $row->lat, 4),
            'lng' => round((float) $row->lng, 4),
            'c' => (int) $row->c,
            's' => (int) $row->s,
            // 'u' es el tiempo real con fallas de la zona: el mismo número que
            // muestra el ranking, para que las dos lecturas no se contradigan.
            'u' => $covered,
        ];
    }

    return $points;
}

/** 'hour' o 'day', respetando lo pedido salvo que produzca un gráfico ilegible. */
function datav_power_timeline_bucket($bounds, $bucket = '')
{
    $span = strtotime($bounds['to']) - strtotime($bounds['from']);

    if ($bucket !== 'hour' && $bucket !== 'day') {
        return $span <= 3 * DAY_IN_SECONDS ? 'hour' : 'day';
    }

    // Un año por horas serían miles de barras de un píxel.
    if ($bucket === 'hour' && $span > 14 * DAY_IN_SECONDS) {
        return 'day';
    }

    return $bucket;
}

/**
 * Reportes por hora o por día dentro del rango.
 *
 * Se cuenta por el momento en que EMPEZÓ la falla, no por solapamiento: lo que
 * interesa leer aquí es a qué hora empezó a irse la luz, que es el pico de un
 * apagón grande. Los tramos sin reportes se rellenan con cero para que el
 * gráfico tenga el eje completo y no una sucesión de barras sueltas.
 */
function datav_power_timeline($bounds, $bucket = '', $filters = [])
{
    global $wpdb;

    $table = datav_power_reports_table();
    $bucket = datav_power_timeline_bucket($bounds, $bucket);

    // %% escapa el porcentaje ante $wpdb->prepare(), que si no lo tomaría por
    // un marcador suyo y rompería la consulta.
    $format = $bucket === 'hour' ? '%%Y-%%m-%%d %%H:00:00' : '%%Y-%%m-%%d 00:00:00';

    $sql = "SELECT
            DATE_FORMAT(r.outage_since, '$format') AS t,
            COUNT(*) AS reports
        FROM $table r
        WHERE r.status IN ('active', 'restored', 'undetermined')
            AND r.outage_since IS NOT NULL
            AND r.outage_since >= %s
            AND r.outage_since <= %s";

    $args = [$bounds['from'], $bounds['to']];

    list($sql, $args) = datav_power_apply_zone_filters($sql, $args, $filters);

    $sql .= ' GROUP BY t ORDER BY t ASC';

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

    $counts = [];

    foreach ((array) $rows as $row) {
        $counts[$row->t] = (int) $row->reports;
    }

    return datav_power_fill_timeline($bounds, $bucket, $counts);
}

/** Serie completa desde 'from' hasta 'to', con ceros donde no hubo reportes. */
function datav_power_fill_timeline($bounds, $bucket, $counts)
{
    $step = $bucket === 'hour' ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
    $pattern = $bucket === 'hour' ? 'Y-m-d H:00:00' : 'Y-m-d 00:00:00';

    $cursor = strtotime(date($pattern, strtotime($bounds['from'])));
    $end = strtotime($bounds['to']);

    $buckets = [];
    $peak = ['t' => '', 'reports' => 0];
    $guard = 0;

    while ($cursor <= $end && $guard++ < 500) {
        $key = date($pattern, $cursor);
        $reports = isset($counts[$key]) ? $counts[$key] : 0;

        $buckets[] = ['t' => $key, 'reports' => $reports];

        if ($reports > $peak['reports']) {
            $peak = ['t' => $key, 'reports' => $reports];
        }

        $cursor += $step;
    }

    return [
        'bucket' => $bucket,
        'buckets' => $buckets,
        'peak' => $peak,
    ];
}

/** Añade los filtros opcionales de zona a una consulta ya empezada. */
function datav_power_apply_zone_filters($sql, $args, $filters)
{
    if (!empty($filters['estado_code'])) {
        $sql .= ' AND r.estado_code = %s';
        $args[] = $filters['estado_code'];
    }

    if (!empty($filters['municipio_code'])) {
        $sql .= ' AND r.municipio_code = %s';
        $args[] = $filters['municipio_code'];
    }

    if (!empty($filters['parroquia_code'])) {
        $sql .= ' AND r.parroquia_code = %s';
        $args[] = $filters['parroquia_code'];
    }

    return [$sql, $args];
}

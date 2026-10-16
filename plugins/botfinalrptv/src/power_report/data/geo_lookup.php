<?php

/**
 * Consulta y normalización de la división político-territorial.
 *
 * La fuente de verdad es la tabla {prefix}datav_power_zones, editable desde la
 * pestaña "Zonas" del admin. venezuela.php solo se usa para dos cosas: sembrar
 * esa tabla la primera vez y servir de red de seguridad si la tabla no existe
 * o está vacía, para que el bot nunca se quede sin listas que ofrecer.
 *
 * Códigos: estado = 'ZUL', municipio = 'ZUL-14', parroquia = 'ZUL-14-07'.
 * Un código nunca se reutiliza para otra zona: los reportes ya guardados lo
 * referencian.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/venezuela.php';

function datav_power_zones_table()
{
    global $wpdb;

    return $wpdb->prefix . 'datav_power_zones';
}

/**
 * Árbol completo de zonas activas, cacheado por petición.
 *
 * [ 'ZUL' => [ code, name, capital, lat, lng,
 *              'municipios' => [ 'ZUL-14' => [ ..., 'parroquias' => [ 'ZUL-14-07' => [...] ] ] ] ] ]
 */
function datav_power_geo_tree()
{
    // Se cachea en una global y no en una estática porque el admin guarda y
    // vuelve a pintar la lista dentro de la misma petición: hay que poder
    // invalidarla desde fuera con datav_power_geo_flush_cache().
    if (isset($GLOBALS['datav_power_geo_tree']) && $GLOBALS['datav_power_geo_tree'] !== null) {
        return $GLOBALS['datav_power_geo_tree'];
    }

    $tree = datav_power_geo_tree_from_db();

    if (empty($tree)) {
        $tree = datav_power_geo_tree_from_file();
    }

    $GLOBALS['datav_power_geo_tree'] = $tree;

    return $tree;
}

/** Fuerza a releer el árbol: lo usa el admin tras guardar cambios. */
function datav_power_geo_flush_cache()
{
    $GLOBALS['datav_power_geo_tree'] = null;
}

function datav_power_geo_tree_from_db()
{
    global $wpdb;

    $table = datav_power_zones_table();

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return [];
    }

    $rows = $wpdb->get_results(
        "SELECT code, parent_code, level, name, capital, lat, lng
         FROM $table
         WHERE is_active = 1
         ORDER BY sort_order ASC, name ASC"
    );

    if (empty($rows)) {
        return [];
    }

    $tree = [];

    foreach ($rows as $row) {
        if ($row->level === 'estado') {
            $tree[$row->code] = datav_power_zone_node($row) + ['municipios' => []];
        }
    }

    foreach ($rows as $row) {
        if ($row->level === 'municipio' && isset($tree[$row->parent_code])) {
            $tree[$row->parent_code]['municipios'][$row->code] = datav_power_zone_node($row) + ['parroquias' => []];
        }
    }

    foreach ($rows as $row) {
        if ($row->level !== 'parroquia') {
            continue;
        }

        // El código de un municipio es el prefijo del de sus parroquias.
        $estado_code = strtok($row->parent_code, '-');

        if (isset($tree[$estado_code]['municipios'][$row->parent_code])) {
            $tree[$estado_code]['municipios'][$row->parent_code]['parroquias'][$row->code] = datav_power_zone_node($row);
        }
    }

    return $tree;
}

function datav_power_zone_node($row)
{
    return [
        'code' => $row->code,
        'name' => $row->name,
        'capital' => $row->capital,
        'lat' => $row->lat === null ? null : (float) $row->lat,
        'lng' => $row->lng === null ? null : (float) $row->lng,
    ];
}

/** Red de seguridad: mismo árbol construido desde el archivo semilla. */
function datav_power_geo_tree_from_file()
{
    $data = datav_power_venezuela_data();

    uasort($data, function ($a, $b) {
        return strcmp(datav_power_normalize_text($a['name']), datav_power_normalize_text($b['name']));
    });

    $tree = [];

    foreach ($data as $estado_code => $estado) {
        $node = [
            'code' => $estado_code,
            'name' => $estado['name'],
            'capital' => isset($estado['capital']) ? $estado['capital'] : '',
            'lat' => isset($estado['lat']) ? (float) $estado['lat'] : null,
            'lng' => isset($estado['lng']) ? (float) $estado['lng'] : null,
            'municipios' => [],
        ];

        foreach ($estado['municipios'] as $index => $municipio) {
            $municipio_code = datav_power_build_code($estado_code, $index);

            $municipio_node = [
                'code' => $municipio_code,
                'name' => $municipio['name'],
                'capital' => isset($municipio['capital']) ? $municipio['capital'] : '',
                'lat' => isset($municipio['lat']) ? (float) $municipio['lat'] : null,
                'lng' => isset($municipio['lng']) ? (float) $municipio['lng'] : null,
                'parroquias' => [],
            ];

            if (!empty($municipio['parroquias'])) {
                foreach ($municipio['parroquias'] as $p_index => $parroquia_name) {
                    $parroquia_code = datav_power_build_code($municipio_code, $p_index);

                    $municipio_node['parroquias'][$parroquia_code] = [
                        'code' => $parroquia_code,
                        'name' => $parroquia_name,
                        'capital' => '',
                        'lat' => null,
                        'lng' => null,
                    ];
                }
            }

            $node['municipios'][$municipio_code] = $municipio_node;
        }

        $tree[$estado_code] = $node;
    }

    return $tree;
}

/* -------------------------------------------------------------------------
 * Consultas que usa el flujo del bot
 * ---------------------------------------------------------------------- */

/** Lista de estados: [ ['code'=>'ZUL','name'=>'Zulia','capital'=>'Maracaibo'], ... ] */
function datav_power_estados()
{
    $list = [];

    foreach (datav_power_geo_tree() as $code => $estado) {
        $list[] = [
            'code' => $code,
            'name' => $estado['name'],
            'capital' => $estado['capital'],
        ];
    }

    return $list;
}

/** Datos de un estado, o null. */
function datav_power_estado($estado_code)
{
    $tree = datav_power_geo_tree();

    return isset($tree[$estado_code]) ? $tree[$estado_code] : null;
}

/** Municipios de un estado: [ ['code','name','capital'], ... ] */
function datav_power_municipios($estado_code)
{
    $estado = datav_power_estado($estado_code);

    if (!$estado) {
        return [];
    }

    $list = [];

    foreach ($estado['municipios'] as $code => $municipio) {
        $list[] = [
            'code' => $code,
            'name' => $municipio['name'],
            'capital' => $municipio['capital'],
        ];
    }

    return $list;
}

/** Datos de un municipio, o null. */
function datav_power_municipio($municipio_code)
{
    $estado_code = strtok((string) $municipio_code, '-');
    $estado = datav_power_estado($estado_code);

    if (!$estado || !isset($estado['municipios'][$municipio_code])) {
        return null;
    }

    return $estado['municipios'][$municipio_code];
}

/** Parroquias de un municipio, o [] si no tiene ninguna cargada. */
function datav_power_parroquias($municipio_code)
{
    $municipio = datav_power_municipio($municipio_code);

    if (!$municipio || empty($municipio['parroquias'])) {
        return [];
    }

    $list = [];

    foreach ($municipio['parroquias'] as $code => $parroquia) {
        $list[] = [
            'code' => $code,
            'name' => $parroquia['name'],
        ];
    }

    return $list;
}

/** Nombre de una parroquia por su código, o null. */
function datav_power_parroquia_name($parroquia_code)
{
    $parts = explode('-', (string) $parroquia_code);

    if (count($parts) < 3) {
        return null;
    }

    $municipio = datav_power_municipio($parts[0] . '-' . $parts[1]);

    if (!$municipio || !isset($municipio['parroquias'][$parroquia_code])) {
        return null;
    }

    return $municipio['parroquias'][$parroquia_code]['name'];
}

/**
 * Punto del mapa para un reporte sin GPS: la coordenada más específica que haya.
 * Devuelve ['lat','lng','precision'] o null.
 */
function datav_power_coords_for($estado_code, $municipio_code = '', $parroquia_code = '')
{
    if ($parroquia_code) {
        $municipio = datav_power_municipio($municipio_code);

        if ($municipio && isset($municipio['parroquias'][$parroquia_code])) {
            $parroquia = $municipio['parroquias'][$parroquia_code];

            if ($parroquia['lat'] !== null && $parroquia['lng'] !== null) {
                return [
                    'lat' => $parroquia['lat'],
                    'lng' => $parroquia['lng'],
                    'precision' => 'parroquia',
                ];
            }
        }
    }

    if ($municipio_code) {
        $municipio = datav_power_municipio($municipio_code);

        if ($municipio && $municipio['lat'] !== null && $municipio['lng'] !== null) {
            return [
                'lat' => $municipio['lat'],
                'lng' => $municipio['lng'],
                'precision' => 'municipio',
            ];
        }
    }

    $estado = datav_power_estado($estado_code);

    if ($estado && $estado['lat'] !== null && $estado['lng'] !== null) {
        return [
            'lat' => $estado['lat'],
            'lng' => $estado['lng'],
            'precision' => 'estado',
        ];
    }

    return null;
}

/**
 * Sugerencia a partir de una ubicación GPS compartida por WhatsApp.
 *
 * No hace point-in-polygon (haría falta el GeoJSON del país): busca el municipio
 * con coordenadas conocidas más cercano. Es una SUGERENCIA que el usuario
 * confirma en el flujo; nunca se guarda sin confirmar.
 */
function datav_power_suggest_from_coords($lat, $lng)
{
    $best = null;

    foreach (datav_power_geo_tree() as $estado_code => $estado) {
        foreach ($estado['municipios'] as $municipio_code => $municipio) {
            if ($municipio['lat'] === null || $municipio['lng'] === null) {
                continue;
            }

            $distance = datav_power_haversine_km($lat, $lng, $municipio['lat'], $municipio['lng']);

            if ($best === null || $distance < $best['distance_km']) {
                $best = [
                    'estado_code' => $estado_code,
                    'municipio_code' => $municipio_code,
                    'distance_km' => $distance,
                ];
            }
        }
    }

    // Si el punto más cercano queda absurdamente lejos no se sugiere nada:
    // probablemente la ubicación esté fuera de Venezuela o mal formada.
    if ($best === null || $best['distance_km'] > 150) {
        return null;
    }

    return $best;
}

/** Distancia en kilómetros entre dos coordenadas. */
function datav_power_haversine_km($lat1, $lng1, $lat2, $lng2)
{
    $earth_radius = 6371;

    $d_lat = deg2rad($lat2 - $lat1);
    $d_lng = deg2rad($lng2 - $lng1);

    $a = sin($d_lat / 2) * sin($d_lat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lng / 2) * sin($d_lng / 2);

    return $earth_radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** 'ZUL' + índice 13 => 'ZUL-14' */
function datav_power_build_code($prefix, $index)
{
    return $prefix . '-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
}

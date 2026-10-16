<?php

/**
 * CRUD de {prefix}datav_power_zones.
 *
 * geo_lookup.php solo lee las zonas activas y las cachea en árbol para el bot.
 * Aquí se trabaja fila a fila e incluyendo las inactivas, que es lo que necesita
 * el admin para poder reactivarlas.
 */

if (!defined('ABSPATH')) {
    exit;
}

function datav_power_zone_levels()
{
    return [
        'estado' => 'Estado',
        'municipio' => 'Municipio',
        'parroquia' => 'Parroquia / Ciudad',
    ];
}

/** Nivel que le corresponde a los hijos de una zona. '' => estados. */
function datav_power_child_level($parent_code)
{
    if ($parent_code === '') {
        return 'estado';
    }

    return substr_count($parent_code, '-') === 0 ? 'municipio' : 'parroquia';
}

/** Hijos directos de una zona, activos e inactivos, en orden de presentación. */
function datav_power_zone_children($parent_code)
{
    global $wpdb;

    $table = datav_power_zones_table();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE parent_code = %s ORDER BY sort_order ASC, name ASC",
        $parent_code
    ));
}

function datav_power_zone_find($code)
{
    global $wpdb;

    $table = datav_power_zones_table();

    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE code = %s", $code));
}

/** Cuántos hijos tiene una zona, para avisar antes de borrarla. */
function datav_power_zone_children_count($code)
{
    global $wpdb;

    $table = datav_power_zones_table();

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE parent_code = %s OR parent_code LIKE %s",
        $code,
        $wpdb->esc_like($code . '-') . '%'
    ));
}

function datav_power_zone_update($code, $data)
{
    global $wpdb;

    return $wpdb->update(datav_power_zones_table(), $data, ['code' => $code]);
}

/**
 * Borra una zona y todo lo que cuelga de ella.
 *
 * Los reportes ya registrados no se ven afectados: guardan el nombre de la zona
 * copiado, no una referencia, justamente para que el histórico no se rompa al
 * reorganizar la división territorial.
 */
function datav_power_zone_delete($code)
{
    global $wpdb;

    $table = datav_power_zones_table();

    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE code = %s OR parent_code = %s OR parent_code LIKE %s",
        $code,
        $code,
        $wpdb->esc_like($code . '-') . '%'
    ));
}

/** Inserta una zona nueva bajo un padre y devuelve su código, o '' si falla. */
function datav_power_zone_create($parent_code, $name, $capital = '', $lat = null, $lng = null)
{
    global $wpdb;

    $name = trim($name);

    if ($name === '') {
        return '';
    }

    $code = $parent_code === ''
        ? datav_power_next_estado_code($name)
        : datav_power_next_child_code($parent_code);

    if ($code === '') {
        return '';
    }

    $inserted = $wpdb->insert(datav_power_zones_table(), [
        'code' => $code,
        'parent_code' => $parent_code,
        'level' => datav_power_child_level($parent_code),
        'name' => $name,
        'capital' => trim($capital),
        'lat' => $lat,
        'lng' => $lng,
        'is_active' => 1,
        'sort_order' => datav_power_next_sort_order($parent_code),
    ]);

    return $inserted ? $code : '';
}

/**
 * Siguiente código libre bajo un padre: 'ZUL-14' -> 'ZUL-14-19'.
 *
 * Se toma el máximo sufijo ya usado y se suma uno en vez de contar filas, para
 * no reutilizar el código de una zona borrada: podría seguir referenciado por
 * reportes viejos y confundiría las estadísticas por zona.
 */
function datav_power_next_child_code($parent_code)
{
    global $wpdb;

    $table = datav_power_zones_table();

    $children = $wpdb->get_col($wpdb->prepare(
        "SELECT code FROM $table WHERE parent_code = %s",
        $parent_code
    ));

    $max = 0;

    foreach ($children as $child_code) {
        $suffix = (int) substr($child_code, strlen($parent_code) + 1);

        if ($suffix > $max) {
            $max = $suffix;
        }
    }

    return $parent_code . '-' . str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
}

/** Código de 3 letras para un estado nuevo, evitando colisiones. */
function datav_power_next_estado_code($name)
{
    global $wpdb;

    $table = datav_power_zones_table();

    $base = strtoupper(substr(preg_replace('/[^a-z]/', '', datav_power_normalize_text($name)), 0, 3));

    if (strlen($base) < 3) {
        $base = str_pad($base === '' ? 'ZON' : $base, 3, 'X');
    }

    $candidate = $base;

    for ($i = 1; $i <= 99; $i++) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE code = %s", $candidate));

        if (!$exists) {
            return $candidate;
        }

        $candidate = substr($base, 0, 2) . $i;
    }

    return '';
}

function datav_power_next_sort_order($parent_code)
{
    global $wpdb;

    $table = datav_power_zones_table();

    $max = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(sort_order) FROM $table WHERE parent_code = %s",
        $parent_code
    ));

    return $max + 1;
}

/** 'Zulia / Maracaibo' — ruta de una zona para la migaja de pan del admin. */
function datav_power_zone_breadcrumb($code)
{
    $trail = [];

    while ($code !== '') {
        $zone = datav_power_zone_find($code);

        if (!$zone) {
            break;
        }

        array_unshift($trail, $zone);
        $code = $zone->parent_code;
    }

    return $trail;
}

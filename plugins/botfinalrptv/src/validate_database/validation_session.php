<?php

/**
 * Vigencia de la sesión "enviar un reporte a los corresponsales".
 *
 * Hasta ahora esa sesión no caducaba nunca: quien enviaba un reporte y no
 * escribía "salir" se quedaba dentro para siempre, y semanas después cualquier
 * mensaje suyo —un "hola", una opción del menú— llegaba a los corresponsales
 * como si fuera un reporte nuevo en vez de abrirle el menú.
 *
 * Con una fecha de caducidad la conversación se cierra sola: mientras la
 * persona siga enviando información se renueva, y en cuanto deja de escribir
 * el flujo se suelta al cabo de un rato.
 */

if (!defined('ABSPATH')) {
    exit;
}

const DATAV_VALIDATE_SESSION_VERSION = '1.0.0';

/** Cuánto sigue abierta la conversación sin que la persona escriba. */
function datav_validate_minutes()
{
    $minutes = (int) get_option('datAv_validate_minutes', 60);

    return min(1440, max(5, $minutes));
}

add_action('plugins_loaded', 'datav_validate_add_columns');

/**
 * Columna de caducidad en la tabla que ya usa el bot.
 *
 * ALTER TABLE y no dbDelta a propósito: la definición completa de {prefix}twilio
 * vive en create_database.php y las dos definiciones se pisarían entre sí en
 * cada actualización.
 */
function datav_validate_add_columns()
{
    global $wpdb;

    if (get_option('datAv_validate_session_version') === DATAV_VALIDATE_SESSION_VERSION) {
        return;
    }

    $table = $wpdb->prefix . 'twilio';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return;
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");

    if (!in_array('validate_expires', $columns, true)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN validate_expires datetime DEFAULT NULL");
    }

    update_option('datAv_validate_session_version', DATAV_VALIDATE_SESSION_VERSION);
}

/** Renueva la vigencia. Se llama al abrir el flujo y con cada envío. */
function datav_validate_touch($phone_number)
{
    global $wpdb;

    $expires = date(
        'Y-m-d H:i:s',
        strtotime(current_time('mysql')) + datav_validate_minutes() * MINUTE_IN_SECONDS
    );

    $wpdb->update(
        $wpdb->prefix . 'twilio',
        ['validate_expires' => $expires],
        ['phone_number' => $phone_number]
    );
}

/** Cierra la conversación con los corresponsales y suelta la caducidad. */
function datav_validate_close($phone_number)
{
    global $wpdb;

    $wpdb->update(
        $wpdb->prefix . 'twilio',
        ['validate_info' => 'not_started', 'validate_expires' => null],
        ['phone_number' => $phone_number]
    );
}

/** ¿La sesión guardada ya venció? */
function datav_validate_is_expired($row)
{
    // Sin columna todavía (migración a medias) la sesión se comporta como antes:
    // más vale no cerrarle el flujo a nadie por una actualización a medio hacer.
    if (!isset($row->validate_expires) || empty($row->validate_expires)) {
        return false;
    }

    return strtotime($row->validate_expires) < strtotime(current_time('mysql'));
}

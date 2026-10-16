<?php

/**
 * Acceso a {prefix}datav_power_reports.
 *
 * Estados de un reporte:
 *   draft         borrador a medio llenar, todavía en la conversación
 *   active        falla confirmada, en monitoreo
 *   restored      el usuario confirmó que volvió el servicio
 *   undetermined  se agotaron las confirmaciones sin respuesta
 */

if (!defined('ABSPATH')) {
    exit;
}

function datav_power_reports_table()
{
    global $wpdb;

    return $wpdb->prefix . 'datav_power_reports';
}

/** Crea el borrador con el que arranca la conversación. Devuelve su id. */
function datav_power_report_create_draft($phone_number, $profile_name = '')
{
    global $wpdb;

    // Un usuario que abandona la conversación a medias deja el borrador colgado.
    // Se descartan al empezar de nuevo para que la tabla no crezca sin control.
    $wpdb->delete(datav_power_reports_table(), [
        'phone_number' => $phone_number,
        'status' => 'draft',
    ]);

    $now = current_time('mysql');

    $wpdb->insert(datav_power_reports_table(), [
        'phone_number' => $phone_number,
        'profile_name' => $profile_name,
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $wpdb->insert_id;
}

function datav_power_report_get($id)
{
    global $wpdb;

    $table = datav_power_reports_table();

    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

function datav_power_report_update($id, $data)
{
    global $wpdb;

    $data['updated_at'] = current_time('mysql');

    return $wpdb->update(datav_power_reports_table(), $data, ['id' => (int) $id]);
}

function datav_power_report_delete($id)
{
    global $wpdb;

    return $wpdb->delete(datav_power_reports_table(), ['id' => (int) $id]);
}

/**
 * Cuántos reportes reales ha hecho un número, sin contar borradores.
 *
 * Es lo que permite saber si un reporte es el primero de esa persona sin añadir
 * ninguna columna: el histórico ya está en la tabla.
 */
function datav_power_report_count_for_phone($phone_number, $exclude_id = 0)
{
    global $wpdb;

    $table = datav_power_reports_table();

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE phone_number = %s AND status <> 'draft' AND id <> %d",
        $phone_number,
        (int) $exclude_id
    ));
}

/** Reporte en monitoreo de un usuario, o null. */
function datav_power_report_active_for_phone($phone_number)
{
    global $wpdb;

    $table = datav_power_reports_table();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE phone_number = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
        $phone_number
    ));
}

/** Pasa el borrador a monitoreo y programa la primera confirmación. */
function datav_power_report_activate($id)
{
    $now = current_time('mysql');

    datav_power_report_update($id, [
        'status' => 'active',
        'last_confirmed_at' => $now,
        'next_check_at' => datav_power_next_check_time(0),
        'checks_sent' => 0,
        'unanswered_checks' => 0,
    ]);
}

/**
 * El usuario confirmó que volvió la luz.
 *
 * $restored_at lo dice él ("volvió a las 3"), no el momento en que contestó: la
 * respuesta puede llegar horas después y esa diferencia es justo lo que se mide.
 */
function datav_power_report_mark_restored($id, $restored_at = null)
{
    datav_power_report_update($id, [
        'status' => 'restored',
        'restored_at' => $restored_at ? $restored_at : current_time('mysql'),
        'next_check_at' => null,
    ]);
}

/** El usuario confirmó que sigue sin servicio: reinicia el contador de silencios. */
function datav_power_report_mark_still_out($id)
{
    $report = datav_power_report_get($id);

    datav_power_report_update($id, [
        'last_confirmed_at' => current_time('mysql'),
        'next_check_at' => datav_power_next_check_time($report ? (int) $report->checks_sent : 0),
        'unanswered_checks' => 0,
    ]);
}

/* -------------------------------------------------------------------------
 * Caja de comentarios
 * ---------------------------------------------------------------------- */

/** Guarda el mensaje que la persona dejó al cerrarse su caso. */
function datav_power_report_save_comment($id, $comment)
{
    $comment = sanitize_textarea_field((string) $comment);
    $comment = function_exists('mb_substr') ? mb_substr($comment, 0, 1000) : substr($comment, 0, 1000);

    return datav_power_report_update($id, [
        'comment' => $comment,
        'comment_at' => current_time('mysql'),
    ]);
}

/** Borra un comentario sin tocar el reporte: es la herramienta de moderación. */
function datav_power_report_delete_comment($id)
{
    return datav_power_report_update($id, [
        'comment' => '',
        'comment_at' => null,
    ]);
}

/**
 * Comentarios más recientes, para el admin y para el portal.
 *
 * Devuelve la fila completa: cada consumidor decide qué publica. El de la API
 * pasa por la lista blanca de datav_power_public_comment().
 */
function datav_power_reports_with_comments($limit = 50, $offset = 0, $filters = [])
{
    global $wpdb;

    $table = datav_power_reports_table();

    $where = ["comment IS NOT NULL", "comment <> ''"];
    $values = [];

    foreach (['estado_code', 'municipio_code', 'parroquia_code'] as $field) {
        if (!empty($filters[$field])) {
            $where[] = "$field = %s";
            $values[] = $filters[$field];
        }
    }

    $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where)
        . ' ORDER BY comment_at DESC LIMIT %d OFFSET %d';

    $values[] = max(1, (int) $limit);
    $values[] = max(0, (int) $offset);

    return $wpdb->get_results($wpdb->prepare($sql, $values));
}

function datav_power_comments_count($filters = [])
{
    global $wpdb;

    $table = datav_power_reports_table();

    $where = ["comment IS NOT NULL", "comment <> ''"];
    $values = [];

    foreach (['estado_code', 'municipio_code', 'parroquia_code'] as $field) {
        if (!empty($filters[$field])) {
            $where[] = "$field = %s";
            $values[] = $filters[$field];
        }
    }

    $sql = "SELECT COUNT(*) FROM $table WHERE " . implode(' AND ', $where);

    return (int) ($values ? $wpdb->get_var($wpdb->prepare($sql, $values)) : $wpdb->get_var($sql));
}

/**
 * Se agotaron las confirmaciones sin respuesta.
 *
 * No se toca 'restored_at' a propósito: la duración del caso se congela en
 * last_confirmed_at, de modo que un reporte abandonado no siga sumando horas
 * en las estadísticas del portal.
 */
function datav_power_report_mark_undetermined($id)
{
    datav_power_report_update($id, [
        'status' => 'undetermined',
        'next_check_at' => null,
    ]);
}

/**
 * Se envió una confirmación y todavía no hay respuesta.
 *
 * 'unanswered_checks' cuenta mensajes SEGUIDOS sin contestar: cualquier respuesta
 * del usuario lo devuelve a cero, así que una falla larga que el usuario va
 * confirmando nunca se cierra sola.
 */
function datav_power_report_mark_check_sent($report)
{
    $sent = (int) $report->checks_sent + 1;

    datav_power_report_update($report->id, [
        'checks_sent' => $sent,
        'unanswered_checks' => (int) $report->unanswered_checks + 1,
        'next_check_at' => datav_power_next_check_time($sent),
    ]);
}

/** Reportes que ya toca preguntar, en lotes para no colgar la petición del cron. */
function datav_power_reports_due($limit = 50)
{
    global $wpdb;

    $table = datav_power_reports_table();

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table
         WHERE status = 'active' AND next_check_at IS NOT NULL AND next_check_at <= %s
         ORDER BY next_check_at ASC
         LIMIT %d",
        current_time('mysql'),
        $limit
    ));
}

/**
 * Cuándo toca la próxima confirmación, con el intervalo creciendo.
 *
 * Preguntar cada 45 minutos durante una falla de 15 horas son veinte mensajes y
 * un bot silenciado. Se pregunta pronto la primera vez —cuando la información
 * vale más— y el intervalo se dobla en cada intento, con tope de 12 horas:
 *
 *   45 min · 1 h 30 · 3 h · 6 h · 12 h · 12 h …
 *
 * El primer intervalo sigue siendo el del panel; lo que cambia es el ritmo.
 */
function datav_power_next_check_time($checks_sent = 0)
{
    $minutes = datav_power_interval_minutes() * pow(2, max(0, (int) $checks_sent));
    $minutes = min($minutes, 12 * 60);

    return date('Y-m-d H:i:s', strtotime(current_time('mysql')) + $minutes * 60);
}

/**
 * Momento en que dejamos de contar la falla:
 * restablecida -> cuando volvió; indeterminada -> última confirmación; activa -> ahora.
 */
function datav_power_report_effective_end($report)
{
    if (!empty($report->restored_at)) {
        return $report->restored_at;
    }

    if ($report->status === 'undetermined') {
        return !empty($report->last_confirmed_at) ? $report->last_confirmed_at : $report->outage_since;
    }

    return current_time('mysql');
}

/** Duración de la falla en segundos. */
function datav_power_report_duration_seconds($report)
{
    if (empty($report->outage_since)) {
        return 0;
    }

    $seconds = strtotime(datav_power_report_effective_end($report)) - strtotime($report->outage_since);

    return max(0, $seconds);
}

/** '4 h 12 min' / '12 min' / '2 d 3 h' */
function datav_power_format_duration($seconds)
{
    $seconds = max(0, (int) $seconds);

    $days = (int) floor($seconds / 86400);
    $hours = (int) floor(($seconds % 86400) / 3600);
    $minutes = (int) floor(($seconds % 3600) / 60);

    if ($days > 0) {
        return $hours > 0 ? "{$days} d {$hours} h" : "{$days} d";
    }

    if ($hours > 0) {
        return $minutes > 0 ? "{$hours} h {$minutes} min" : "{$hours} h";
    }

    return "{$minutes} min";
}

/** 'Coquivacoa, Maracaibo, Zulia' — la parte de la zona que sí es pública. */
function datav_power_report_zone_label($report)
{
    $parts = array_filter([
        !empty($report->parroquia) ? $report->parroquia : '',
        !empty($report->municipio) ? $report->municipio : '',
        !empty($report->estado) ? $report->estado : '',
    ]);

    return implode(', ', $parts);
}

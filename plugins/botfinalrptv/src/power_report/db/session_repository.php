<?php

/**
 * Estado conversacional del flujo, guardado en la tabla {prefix}twilio que ya usa
 * el resto del bot. Solo el paso actual y el borrador asociado: los datos del
 * reporte viven en {prefix}datav_power_reports.
 */

if (!defined('ABSPATH')) {
    exit;
}

function datav_power_session_table()
{
    global $wpdb;

    return $wpdb->prefix . 'twilio';
}

/** Devuelve ['status','step','report_id','expires_at'] aunque el usuario no exista. */
function datav_power_session_get($phone_number)
{
    global $wpdb;

    $table = datav_power_session_table();

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT power_status, power_step, power_report_id, power_step_expires FROM $table WHERE phone_number = %s",
        $phone_number
    ));

    if (!$row) {
        return ['status' => 'not_started', 'step' => '', 'report_id' => 0, 'expires_at' => null];
    }

    return [
        'status' => $row->power_status,
        'step' => $row->power_step,
        'report_id' => (int) $row->power_report_id,
        'expires_at' => $row->power_step_expires,
    ];
}

/**
 * ¿El usuario está dentro del flujo de reporte eléctrico?
 *
 * Una sesión con vencimiento cumplido (la caja de comentarios) se cierra aquí
 * mismo: el mensaje que acaba de llegar ya no pertenece al flujo y tiene que
 * seguir el camino normal del bot, que es mostrar el menú.
 */
function hasUserStartedPowerReport($phone_number)
{
    $session = datav_power_session_get($phone_number);

    if ($session['status'] !== 'started') {
        return false;
    }

    if (datav_power_session_is_expired($session)) {
        datav_power_session_end($phone_number);

        return false;
    }

    return true;
}

function datav_power_session_is_expired($session)
{
    if (empty($session['expires_at'])) {
        return false;
    }

    return strtotime($session['expires_at']) < strtotime(current_time('mysql'));
}

/**
 * Abre la sesión en un paso concreto, creando la fila del usuario si hace falta.
 *
 * $expires_at solo lo usan los pasos que no pueden quedar abiertos para siempre;
 * el resto del flujo espera al usuario indefinidamente, como hasta ahora.
 */
function datav_power_session_start($phone_number, $step, $report_id, $expires_at = null)
{
    datav_power_session_write($phone_number, [
        'power_status' => 'started',
        'power_step' => $step,
        'power_report_id' => (int) $report_id,
        'power_step_expires' => $expires_at,
    ]);
}

/** Al cambiar de paso se suelta el vencimiento: era de la pregunta anterior. */
function datav_power_session_set_step($phone_number, $step)
{
    datav_power_session_write($phone_number, [
        'power_step' => $step,
        'power_step_expires' => null,
    ]);
}

/** Cierra la sesión y suelta el borrador. */
function datav_power_session_end($phone_number)
{
    datav_power_session_write($phone_number, [
        'power_status' => 'not_started',
        'power_step' => '',
        'power_report_id' => null,
        'power_step_expires' => null,
    ]);
}

function datav_power_session_write($phone_number, $data)
{
    global $wpdb;

    $table = datav_power_session_table();

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE phone_number = %s",
        $phone_number
    ));

    if ($exists) {
        $wpdb->update($table, $data, ['phone_number' => $phone_number]);

        return;
    }

    $wpdb->insert($table, array_merge($data, ['phone_number' => $phone_number]));
}

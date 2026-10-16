<?php

/**
 * Envío del mensaje de cierre.
 *
 * Único punto por el que pasan todos los flujos. Cada uno solo dice "terminé
 * esto"; aquí se decide si toca enviar, cuánto esperar y si ya se envió
 * demasiado pronto.
 *
 * Convención de argumentos heredada del resto del bot: $from es el usuario y
 * $to el número de Twilio.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param string $event Clave de datav_followup_events().
 * @return bool Si se llegó a enviar.
 */
function datav_followup_send($event, $from, $to, $client)
{
    if (!datav_followup_is_enabled()) {
        datav_followup_log($event, $from, 'omitido: los mensajes de cierre están desactivados');

        return false;
    }

    if (!datav_followup_event_is_enabled($event)) {
        datav_followup_log($event, $from, 'omitido: este evento está desactivado');

        return false;
    }

    if (datav_followup_in_cooldown($from)) {
        datav_followup_log($event, $from, 'omitido: ya se le envió hace menos de ' . datav_followup_cooldown_days() . ' días');

        return false;
    }

    $message = datav_followup_message_text();

    if ($message === '') {
        datav_followup_log($event, $from, 'omitido: el mensaje está vacío y no se encontró social_media.txt');

        return false;
    }

    // Separado del mensaje anterior para que se lea como algo aparte y no como
    // la coletilla de lo que el usuario acaba de hacer.
    datav_safe_sleep(datav_message_delay());

    $sent = send_twilio_message($to, $from, $message, $client);

    if ($sent === true) {
        datav_followup_log_sent($from, $event);
        datav_followup_log($event, $from, 'enviado');
    } else {
        // No se registra en la tabla: si Twilio falló, el tope no debe activarse
        // y bloquear el siguiente intento.
        datav_followup_log($event, $from, 'ERROR: Twilio rechazó el envío');
    }

    return $sent === true;
}

/**
 * Deja rastro de cada decisión, incluidas las de no enviar.
 *
 * Sin esto, "no me llegó el mensaje" no tiene respuesta posible: puede ser el
 * tope de frecuencia, el evento desactivado o un fallo de Twilio, y desde fuera
 * los tres se ven igual.
 */
function datav_followup_log($event, $phone_number, $outcome)
{
    $log_file = plugin_dir_path(__FILE__) . 'followup_log.txt';

    file_put_contents(
        $log_file,
        '[' . current_time('mysql') . '] ' . $event . ' | ' . $phone_number . ' | ' . $outcome . "\n",
        FILE_APPEND
    );
}

/**
 * ¿Se le envió el mensaje hace poco?
 *
 * El tope es por PERSONA y no por evento: alguien que en la misma tarde reporta
 * una falla, termina un curso y manda un reporte dispararía tres eventos
 * distintos y recibiría el mismo texto tres veces, que es justo el spam que se
 * quiere evitar.
 */
function datav_followup_in_cooldown($phone_number)
{
    $days = datav_followup_cooldown_days();

    if ($days <= 0) {
        return false;
    }

    global $wpdb;

    $table = datav_followup_table();

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return false;
    }

    $limit = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - $days * DAY_IN_SECONDS);

    $recent = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE phone_number = %s AND sent_at >= %s LIMIT 1",
        $phone_number,
        $limit
    ));

    return (bool) $recent;
}

function datav_followup_log_sent($phone_number, $event)
{
    global $wpdb;

    $wpdb->insert(datav_followup_table(), [
        'phone_number' => $phone_number,
        'event' => $event,
        'sent_at' => current_time('mysql'),
    ]);
}

/**
 * Pausa que se salta sola si el webhook va justo de tiempo.
 *
 * Twilio espera como mucho 15 s la respuesta de este webhook; pasado ese punto
 * da la petición por fallida (error 11200). Cada sleep() congela el proceso PHP
 * entero, así que se come ese presupuesto. El curso de infomemes ya gasta hasta
 * 10 s durmiendo entre mensajes, y añadirle la pausa del mensaje de cierre
 * podría pasarse. Ante la duda se prefiere enviar el mensaje sin pausa antes
 * que perder la respuesta completa.
 */
function datav_safe_sleep($seconds)
{
    $seconds = (int) $seconds;

    if ($seconds <= 0) {
        return;
    }

    if ($seconds + datav_request_elapsed_seconds() > DATAV_REQUEST_TIME_BUDGET) {
        return;
    }

    sleep($seconds);
}

/** Margen sobre los 15 s de Twilio, dejando aire para las llamadas a su API. */
define('DATAV_REQUEST_TIME_BUDGET', 9);

function datav_request_elapsed_seconds()
{
    if (empty($_SERVER['REQUEST_TIME_FLOAT'])) {
        return 0;
    }

    return microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT'];
}

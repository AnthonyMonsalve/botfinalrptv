<?php

/**
 * Seguimiento automático de las fallas en monitoreo.
 *
 * Un evento de WP-Cron corre cada 5 minutos y procesa los reportes cuyo
 * next_check_at ya venció. El intervalo real entre preguntas al usuario lo fija
 * el ajuste 'datAv_power_interval_minutes' (30 por defecto); el tick es más
 * frecuente que el intervalo solo para que la pregunta no se retrase mucho.
 *
 * OJO: WP-Cron no es un cron real, solo se dispara con visitas al sitio. Para que
 * esto sea puntual hace falta DISABLE_WP_CRON + un cron del sistema llamando a
 * wp-cron.php. La pestaña "Seguimiento" del admin lo advierte y muestra la última
 * ejecución para detectar si no está configurado.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Twilio\Rest\Client;

const DATAV_POWER_CRON_HOOK = 'datav_power_followup_tick';
const DATAV_POWER_CRON_SCHEDULE = 'datav_power_every_5_minutes';

add_filter('cron_schedules', 'datav_power_register_cron_schedule');

function datav_power_register_cron_schedule($schedules)
{
    $schedules[DATAV_POWER_CRON_SCHEDULE] = [
        'interval' => 300,
        'display' => 'Cada 5 minutos (Reporte Eléctrico)',
    ];

    return $schedules;
}

add_action('init', 'datav_power_schedule_followups');

function datav_power_schedule_followups()
{
    if (!wp_next_scheduled(DATAV_POWER_CRON_HOOK)) {
        wp_schedule_event(time() + 60, DATAV_POWER_CRON_SCHEDULE, DATAV_POWER_CRON_HOOK);
    }
}

add_action(DATAV_POWER_CRON_HOOK, 'datav_power_followup_tick');

/**
 * Un ciclo de seguimiento. Devuelve un resumen para poder llamarlo también a
 * mano desde el admin y mostrar qué hizo.
 */
function datav_power_followup_tick()
{
    $summary = ['checked' => 0, 'closed' => 0, 'skipped' => 0, 'failed' => 0];

    update_option('datAv_power_cron_last_run', current_time('mysql'), false);

    if (!datav_power_is_enabled()) {
        return $summary;
    }

    $sid = get_option('datAv_twilio_sid', '');
    $token = get_option('datAv_twilio_token', '');

    if (empty($sid) || empty($token)) {
        datav_power_log('Cron: faltan credenciales de Twilio, no se envían confirmaciones.');

        return $summary;
    }

    $client = new Client($sid, $token);
    $max_checks = datav_power_max_checks();

    // En lotes: si hay un apagón nacional puede haber miles de reportes vencidos
    // a la vez y una petición de WP-Cron no puede quedarse colgada procesándolos.
    foreach (datav_power_reports_due(50) as $report) {
        if ((int) $report->unanswered_checks >= $max_checks) {
            datav_power_close_undetermined($report, $client);
            $summary['closed']++;

            continue;
        }

        if (datav_power_send_followup($report, $client)) {
            datav_power_report_mark_check_sent($report);
            datav_power_open_followup_session($report);
            $summary['checked']++;
        } else {
            // No se marca como enviada: se reintenta en el siguiente tick en vez
            // de gastar una de las confirmaciones en un mensaje que no salió.
            $summary['failed']++;
        }
    }

    return $summary;
}

/**
 * Envía la pregunta "¿ya volvió la luz?".
 *
 * Con Content SID configurado se manda como plantilla aprobada, que es lo único
 * que WhatsApp acepta pasadas 24 h desde el último mensaje del usuario. Sin SID
 * se cae a texto libre, que solo funciona dentro de esa ventana.
 */
function datav_power_send_followup($report, $client)
{
    $from = get_option('datAv_twilio_phone', '');

    if (empty($from)) {
        datav_power_log('Cron: falta el número de Twilio (datAv_twilio_phone).');

        return false;
    }

    $zona = datav_power_report_zone_label($report);
    $duracion = datav_power_format_duration(datav_power_report_duration_seconds($report));

    $content_sid = datav_power_setting('datAv_power_followup_content_sid');

    if (!empty($content_sid)) {
        return datav_power_send_content_template($from, $report->phone_number, $content_sid, [
            '1' => $zona,
            '2' => $duracion,
        ], $client);
    }

    $body = datav_power_message('followup', ['zona' => $zona, 'duracion' => $duracion]);

    return send_twilio_message($from, $report->phone_number, $body, $client) === true;
}

/** Envío de una plantilla de Twilio Content con sus variables. */
function datav_power_send_content_template($from, $to, $content_sid, $variables, $client)
{
    try {
        $client->messages->create($to, [
            'from' => $from,
            'contentSid' => $content_sid,
            'contentVariables' => json_encode($variables),
        ]);

        return true;
    } catch (Exception $e) {
        datav_power_log('Cron: error enviando la plantilla a ' . $to . ': ' . $e->getMessage());

        return false;
    }
}

/**
 * Deja al usuario en el paso 'followup' para que su "1" o "2" se interprete
 * como respuesta al seguimiento.
 *
 * Si está a mitad de otro flujo (curso, infomemes, validación) no se toca su
 * sesión: en bot.php esos flujos tienen prioridad y sobrescribirla lo dejaría
 * atascado entre dos conversaciones.
 */
function datav_power_open_followup_session($report)
{
    if (datav_power_user_busy_elsewhere($report->phone_number)) {
        return;
    }

    datav_power_session_start($report->phone_number, DATAV_POWER_STEP_FOLLOWUP, $report->id);
}

function datav_power_user_busy_elsewhere($phone_number)
{
    $guards = [
        'hasUserStartedCourse',
        'hasUserStartedInfomemes',
        'hasUserStartedValidation',
        'hasUserStartedFake',
    ];

    foreach ($guards as $guard) {
        if (function_exists($guard) && call_user_func($guard, $phone_number)) {
            return true;
        }
    }

    return false;
}

/** Se agotaron las confirmaciones sin respuesta: el caso queda indeterminado. */
function datav_power_close_undetermined($report, $client)
{
    datav_power_report_mark_undetermined($report->id);

    $session = datav_power_session_get($report->phone_number);

    // Solo se cierra la sesión si seguía esperando respuesta de ESTE reporte.
    if ($session['status'] === 'started' && (int) $session['report_id'] === (int) $report->id) {
        datav_power_session_end($report->phone_number);
    }

    $from = get_option('datAv_twilio_phone', '');

    if (empty($from)) {
        return;
    }

    send_twilio_message($from, $report->phone_number, datav_power_message('undetermined', [
        'zona' => datav_power_report_zone_label($report),
    ]), $client);

    datav_power_invite_comment($report, $from, $client);
}

/**
 * Misma caja de comentarios que al cerrar el caso a mano.
 *
 * La sesión se abre con vencimiento: si la persona no escribe dentro del plazo,
 * su próximo mensaje al bot vuelve a ser uno normal y le sale el menú, en vez de
 * quedar guardado como comentario de un caso que ya cerró hace rato.
 */
function datav_power_invite_comment($report, $from, $client)
{
    if (!datav_power_comments_enabled() || datav_power_user_busy_elsewhere($report->phone_number)) {
        return;
    }

    $minutes = datav_power_comment_minutes();

    datav_power_session_start(
        $report->phone_number,
        DATAV_POWER_STEP_COMMENT,
        $report->id,
        datav_power_comment_deadline($minutes)
    );

    send_twilio_message($from, $report->phone_number, datav_power_message('ask_comment', [
        'minutos' => $minutes,
    ]), $client);
}

/* -------------------------------------------------------------------------
 * Estado del cron para el admin
 * ---------------------------------------------------------------------- */

function datav_power_cron_status()
{
    $last_run = get_option('datAv_power_cron_last_run', '');
    $next = wp_next_scheduled(DATAV_POWER_CRON_HOOK);

    $minutes_since = $last_run ? (strtotime(current_time('mysql')) - strtotime($last_run)) / 60 : null;

    return [
        'last_run' => $last_run,
        'next_run' => $next ? date('Y-m-d H:i:s', $next + (get_option('gmt_offset') * HOUR_IN_SECONDS)) : '',
        'scheduled' => (bool) $next,
        // Con un cron real no deberían pasar más de ~10 min entre ejecuciones.
        'healthy' => $minutes_since !== null && $minutes_since < 15,
        'minutes_since' => $minutes_since,
    ];
}

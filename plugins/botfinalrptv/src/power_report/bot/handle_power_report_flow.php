<?php

/**
 * Máquina de pasos del flujo Reporte Eléctrico.
 *
 *   estado -> [gps_confirm] -> municipio -> [parroquia] -> direccion -> since -> photo -> activo
 *
 * Los pasos entre corchetes se saltan solos: gps_confirm solo aparece si el usuario
 * compartió su ubicación, y parroquia solo si el municipio las tiene cargadas en
 * venezuela.php. Una vez activo, el reporte vuelve al paso 'followup' cada vez que
 * el cron pregunta si ya volvió la luz.
 *
 * Convención de argumentos heredada del resto del bot: $from es el usuario y $to el
 * número de Twilio, por eso los envíos son send_twilio_message($to, $from, ...).
 */

if (!defined('ABSPATH')) {
    exit;
}

const DATAV_POWER_STEP_ESTADO = 'estado';
const DATAV_POWER_STEP_GPS_CONFIRM = 'gps_confirm';
const DATAV_POWER_STEP_MUNICIPIO = 'municipio';
const DATAV_POWER_STEP_PARROQUIA = 'parroquia';
const DATAV_POWER_STEP_DIRECCION = 'direccion';
const DATAV_POWER_STEP_SINCE = 'since';
const DATAV_POWER_STEP_SINCE_RANGE = 'since_range';
const DATAV_POWER_STEP_PHOTO = 'photo';
const DATAV_POWER_STEP_FOLLOWUP = 'followup';
const DATAV_POWER_STEP_CURRENT_STATE = 'current_state';
const DATAV_POWER_STEP_RESTORED_WHEN = 'restored_when';
const DATAV_POWER_STEP_RESTORED_AT = 'restored_at';
const DATAV_POWER_STEP_COMMENT = 'comment';

/** Hasta cuándo hacia atrás se acepta una hora: más allá es un error de tipeo. */
const DATAV_POWER_MAX_PAST_DAYS = 30;

/**
 * Respaldo del paso 'since' para quien no recuerda la hora exacta.
 * 'seconds' es el punto medio del rango, salvo el último, que es su cota inferior.
 */
function datav_power_since_options()
{
    return [
        1 => ['label' => 'Menos de 1 hora', 'seconds' => 1800],
        2 => ['label' => 'De 1 a 3 horas', 'seconds' => 7200],
        3 => ['label' => 'De 3 a 6 horas', 'seconds' => 16200],
        4 => ['label' => 'De 6 a 12 horas', 'seconds' => 32400],
        5 => ['label' => 'De 12 a 24 horas', 'seconds' => 64800],
        6 => ['label' => 'Más de 1 día', 'seconds' => 86400],
    ];
}

/**
 * Entrada al flujo desde el menú principal (el usuario aún no tiene sesión abierta).
 */
function datav_power_start_flow($from, $to, $client, $params = [])
{
    $active = datav_power_report_active_for_phone($from);

    // Con un reporte ya en monitoreo no se abre otro: se le pregunta por ese.
    if ($active) {
        datav_power_session_start($from, DATAV_POWER_STEP_FOLLOWUP, $active->id);

        return send_twilio_message($to, $from, datav_power_message('already_active', [
            'zona' => datav_power_report_zone_label($active),
            'duracion' => datav_power_format_duration(datav_power_report_duration_seconds($active)),
        ]), $client);
    }

    $profile_name = isset($params['ProfileName']) ? $params['ProfileName'] : '';
    $report_id = datav_power_report_create_draft($from, $profile_name);

    datav_power_session_start($from, DATAV_POWER_STEP_ESTADO, $report_id);

    send_twilio_message($to, $from, datav_power_message('welcome'), $client);

    return datav_power_ask_estado($from, $to, $client);
}

/**
 * Punto de entrada cuando el usuario ya está dentro del flujo.
 */
function handle_power_report_flow($messageReceived, $from, $to, $client, $params = [])
{
    $session = datav_power_session_get($from);
    $step = $session['step'];
    $report = $session['report_id'] ? datav_power_report_get($session['report_id']) : null;

    // Sesión huérfana (borrador borrado a mano, migración a medias): se reinicia.
    if (!$report) {
        datav_power_session_end($from);

        return datav_power_start_flow($from, $to, $client, $params);
    }

    if (datav_power_is_exit_instruction($messageReceived)) {
        return datav_power_exit_flow($report, $from, $to, $client);
    }

    // Ubicación compartida: solo es útil mientras no se haya fijado la zona.
    $coords = datav_power_extract_coords($params);

    if ($coords && in_array($step, [DATAV_POWER_STEP_ESTADO, DATAV_POWER_STEP_GPS_CONFIRM, DATAV_POWER_STEP_MUNICIPIO], true)) {
        return datav_power_handle_shared_location($report, $coords, $from, $to, $client);
    }

    switch ($step) {
        case DATAV_POWER_STEP_ESTADO:
            return datav_power_step_estado($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_GPS_CONFIRM:
            return datav_power_step_gps_confirm($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_MUNICIPIO:
            return datav_power_step_municipio($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_PARROQUIA:
            return datav_power_step_parroquia($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_DIRECCION:
            return datav_power_step_direccion($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_SINCE:
            return datav_power_step_since($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_SINCE_RANGE:
            return datav_power_step_since_range($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_PHOTO:
            return datav_power_step_photo($messageReceived, $report, $from, $to, $client, $params);

        case DATAV_POWER_STEP_FOLLOWUP:
            return datav_power_step_followup($messageReceived, $report, $from, $to, $client, $params);

        case DATAV_POWER_STEP_CURRENT_STATE:
            return datav_power_step_current_state($messageReceived, $report, $from, $to, $client, $params);

        case DATAV_POWER_STEP_RESTORED_WHEN:
            return datav_power_step_restored_when($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_RESTORED_AT:
            return datav_power_step_restored_at($messageReceived, $report, $from, $to, $client);

        case DATAV_POWER_STEP_COMMENT:
            return datav_power_step_comment($messageReceived, $report, $from, $to, $client);
    }

    // Paso desconocido: se vuelve al principio en vez de dejar al usuario atascado.
    datav_power_session_set_step($from, DATAV_POWER_STEP_ESTADO);

    return datav_power_ask_estado($from, $to, $client);
}

/* -------------------------------------------------------------------------
 * Pasos
 * ---------------------------------------------------------------------- */

function datav_power_ask_estado($from, $to, $client)
{
    $estados = datav_power_estados();

    $body = datav_power_message('ask_estado') . "\n\n" . datav_power_numbered_list(wp_list_pluck($estados, 'name'));

    return send_twilio_message($to, $from, $body, $client);
}

function datav_power_step_estado($messageReceived, $report, $from, $to, $client)
{
    $estados = datav_power_estados();
    $choice = datav_power_pick_option($messageReceived, wp_list_pluck($estados, 'name'));

    if ($choice === null) {
        send_twilio_message($to, $from, datav_power_message('invalid_option'), $client);

        return datav_power_ask_estado($from, $to, $client);
    }

    $estado = $estados[$choice];

    datav_power_report_update($report->id, [
        'estado_code' => $estado['code'],
        'estado' => $estado['name'],
        'municipio_code' => '',
        'municipio' => '',
        'parroquia_code' => '',
        'parroquia' => '',
    ]);

    return datav_power_ask_municipio($estado['code'], $estado['name'], $from, $to, $client);
}

function datav_power_ask_municipio($estado_code, $estado_name, $from, $to, $client)
{
    datav_power_session_set_step($from, DATAV_POWER_STEP_MUNICIPIO);

    $municipios = datav_power_municipios($estado_code);

    $body = datav_power_message('ask_municipio', ['estado' => $estado_name])
        . "\n\n" . datav_power_numbered_list(datav_power_municipio_labels($municipios));

    return send_twilio_message($to, $from, $body, $client);
}

function datav_power_step_municipio($messageReceived, $report, $from, $to, $client)
{
    $municipios = datav_power_municipios($report->estado_code);
    $choice = datav_power_pick_option($messageReceived, wp_list_pluck($municipios, 'name'));

    if ($choice === null) {
        send_twilio_message($to, $from, datav_power_message('invalid_option'), $client);

        return datav_power_ask_municipio($report->estado_code, $report->estado, $from, $to, $client);
    }

    $municipio = $municipios[$choice];

    datav_power_report_update($report->id, [
        'municipio_code' => $municipio['code'],
        'municipio' => $municipio['name'],
        'parroquia_code' => '',
        'parroquia' => '',
    ]);

    return datav_power_ask_parroquia_or_skip($municipio['code'], $municipio['name'], $from, $to, $client);
}

/** El paso de parroquia solo existe si el municipio las tiene cargadas. */
function datav_power_ask_parroquia_or_skip($municipio_code, $municipio_name, $from, $to, $client)
{
    $parroquias = datav_power_parroquias($municipio_code);

    if (empty($parroquias)) {
        return datav_power_ask_direccion($from, $to, $client);
    }

    datav_power_session_set_step($from, DATAV_POWER_STEP_PARROQUIA);

    $body = datav_power_message('ask_parroquia', ['municipio' => $municipio_name])
        . "\n\n" . datav_power_numbered_list(wp_list_pluck($parroquias, 'name'));

    return send_twilio_message($to, $from, $body, $client);
}

function datav_power_step_parroquia($messageReceived, $report, $from, $to, $client)
{
    $parroquias = datav_power_parroquias($report->municipio_code);
    $choice = datav_power_pick_option($messageReceived, wp_list_pluck($parroquias, 'name'));

    if ($choice === null) {
        send_twilio_message($to, $from, datav_power_message('invalid_option'), $client);

        return datav_power_ask_parroquia_or_skip($report->municipio_code, $report->municipio, $from, $to, $client);
    }

    $parroquia = $parroquias[$choice];

    datav_power_report_update($report->id, [
        'parroquia_code' => $parroquia['code'],
        'parroquia' => $parroquia['name'],
    ]);

    return datav_power_ask_direccion($from, $to, $client);
}

function datav_power_ask_direccion($from, $to, $client)
{
    datav_power_session_set_step($from, DATAV_POWER_STEP_DIRECCION);

    return send_twilio_message($to, $from, datav_power_message('ask_direccion'), $client);
}

function datav_power_step_direccion($messageReceived, $report, $from, $to, $client)
{
    $direccion = trim((string) $messageReceived);

    if ($direccion !== '0') {
        $direccion = sanitize_text_field($direccion);
        $direccion = function_exists('mb_substr')
            ? mb_substr($direccion, 0, 200)
            : substr($direccion, 0, 200);

        datav_power_report_update($report->id, ['direccion' => $direccion]);
    }

    return datav_power_ask_since($from, $to, $client);
}

function datav_power_ask_since($from, $to, $client)
{
    datav_power_session_set_step($from, DATAV_POWER_STEP_SINCE);

    return send_twilio_message($to, $from, datav_power_message('ask_since'), $client);
}

/**
 * Se pide la hora concreta en que se fue la luz, no un rango: "se fue a las 3" es
 * lo que la gente sabe de verdad, y da un dato exacto en vez de una estimación.
 * Quien no la recuerde responde '0' y pasa a la lista de rangos.
 */
function datav_power_step_since($messageReceived, $report, $from, $to, $client)
{
    if (datav_power_wants_since_range($messageReceived)) {
        return datav_power_ask_since_range($from, $to, $client);
    }

    $timestamp = datav_power_parse_moment($messageReceived);

    if ($timestamp === null) {
        send_twilio_message($to, $from, datav_power_message('invalid_time'), $client);

        return datav_power_ask_since($from, $to, $client);
    }

    return datav_power_save_outage_start($timestamp, $report, $from, $to, $client);
}

function datav_power_ask_since_range($from, $to, $client)
{
    datav_power_session_set_step($from, DATAV_POWER_STEP_SINCE_RANGE);

    $body = datav_power_message('ask_since_range')
        . "\n\n" . datav_power_numbered_list(wp_list_pluck(datav_power_since_options(), 'label'));

    return send_twilio_message($to, $from, $body, $client);
}

function datav_power_step_since_range($messageReceived, $report, $from, $to, $client)
{
    $options = datav_power_since_options();
    $choice = datav_power_pick_option($messageReceived, wp_list_pluck($options, 'label'));

    // Todavía puede escribir la hora exacta aquí: si se entiende, se prefiere.
    // Un número suelto no cuenta: aquí significa "opción de la lista", y quien
    // teclea un 7 espera una séptima opción, no las siete de la tarde.
    if ($choice === null) {
        $timestamp = ctype_digit(datav_power_compare_key($messageReceived))
            ? null
            : datav_power_parse_moment($messageReceived);

        if ($timestamp !== null) {
            return datav_power_save_outage_start($timestamp, $report, $from, $to, $client);
        }

        send_twilio_message($to, $from, datav_power_message('invalid_option'), $client);

        return datav_power_ask_since_range($from, $to, $client);
    }

    // pick_option devuelve el índice 0-based sobre la lista de etiquetas.
    $option = array_values($options)[$choice];

    return datav_power_save_outage_start(strtotime(current_time('mysql')) - $option['seconds'], $report, $from, $to, $client);
}

/** Guarda el inicio de la falla y pasa a la foto, venga de la hora o del rango. */
function datav_power_save_outage_start($timestamp, $report, $from, $to, $client)
{
    datav_power_report_update($report->id, [
        'outage_since' => date('Y-m-d H:i:s', $timestamp),
    ]);

    datav_power_session_set_step($from, DATAV_POWER_STEP_PHOTO);

    // Se repite la hora entendida junto a la siguiente pregunta: así queda claro
    // qué se registró sin gastar un mensaje extra.
    $body = datav_power_message('time_saved', [
        'hora' => datav_power_format_moment($timestamp),
        'duracion' => datav_power_format_duration(strtotime(current_time('mysql')) - $timestamp),
    ]) . "\n\n" . datav_power_message('ask_photo');

    return send_twilio_message($to, $from, $body, $client);
}

/** '0' o "no recuerdo": quiere la lista de rangos en vez de la hora exacta. */
function datav_power_wants_since_range($messageReceived)
{
    $answer = datav_power_compare_key($messageReceived);

    if ($answer === '0') {
        return true;
    }

    foreach (['no recuerdo', 'no se', 'no lo se', 'no me acuerdo', 'ni idea', 'no recuerdo la hora'] as $phrase) {
        if ($answer === $phrase) {
            return true;
        }
    }

    return false;
}

function datav_power_step_photo($messageReceived, $report, $from, $to, $client, $params)
{
    $media_url = isset($params['MediaUrl0']) ? $params['MediaUrl0'] : '';

    if ($media_url) {
        $stored_url = datav_power_download_media($media_url);

        if ($stored_url) {
            datav_power_report_update($report->id, ['media_url' => $stored_url]);
        }
    }

    return datav_power_ask_current_state($report, $from, $to, $client);
}

/* -------------------------------------------------------------------------
 * Estado del servicio al cerrar el formulario
 * ---------------------------------------------------------------------- */

/**
 * Última pregunta del formulario: ¿la falla sigue o ya pasó?
 *
 * Antes se daba por hecho que seguía activa, y quien reportaba un apagón de
 * ayer entraba al seguimiento con el contador corriendo sobre una falla que ya
 * había terminado. El estado tiene que ser explícito, no supuesto.
 */
function datav_power_ask_current_state($report, $from, $to, $client)
{
    datav_power_session_set_step($from, DATAV_POWER_STEP_CURRENT_STATE);

    return send_twilio_message($to, $from, datav_power_message('ask_current_state', [
        'inicio' => empty($report->outage_since) ? '' : datav_power_format_moment(strtotime($report->outage_since)),
    ]), $client);
}

function datav_power_step_current_state($messageReceived, $report, $from, $to, $client, $params = [])
{
    $choice = datav_power_read_followup_answer($messageReceived, $params);

    if ($choice === null) {
        send_twilio_message($to, $from, datav_power_message('invalid_option'), $client);

        return datav_power_ask_current_state($report, $from, $to, $client);
    }

    // Sigue sin luz: el reporte queda activo y entra en seguimiento.
    if ($choice === 1) {
        return datav_power_finalize_report($report->id, $from, $to, $client);
    }

    // "Sí, ya regresó, a las 6": si la hora viene en la misma frase, se cierra ya.
    $timestamp = datav_power_time_inside_answer($messageReceived, $report);

    if ($timestamp !== null) {
        datav_power_report_activate($report->id);

        return datav_power_close_restored($timestamp, $report, $from, $to, $client);
    }

    return datav_power_ask_restored_when($report, $from, $to, $client);
}

/**
 * "¿Cuándo regresó?" en dos opciones antes de pedir la hora.
 *
 * La mayoría contesta justo cuando le acaba de llegar la luz, y a esa mayoría
 * le basta con pulsar 1. Solo a quien responde más tarde se le pide la hora.
 */
function datav_power_ask_restored_when($report, $from, $to, $client)
{
    datav_power_session_set_step($from, DATAV_POWER_STEP_RESTORED_WHEN);

    return send_twilio_message($to, $from, datav_power_message('ask_restored_when'), $client);
}

function datav_power_step_restored_when($messageReceived, $report, $from, $to, $client)
{
    $choice = datav_power_pick_option($messageReceived, ['Acaba de regresar', 'Regreso a otra hora']);

    // Si en vez de elegir escribe directamente la hora, se acepta igual.
    if ($choice === null) {
        $timestamp = datav_power_time_inside_answer($messageReceived, $report);

        if ($timestamp !== null) {
            return datav_power_finish_restored($timestamp, $report, $from, $to, $client);
        }

        if (datav_power_means_just_now($messageReceived)) {
            return datav_power_finish_restored(strtotime(current_time('mysql')), $report, $from, $to, $client);
        }

        return send_twilio_message($to, $from, datav_power_message('invalid_option'), $client);
    }

    if ($choice === 0) {
        return datav_power_finish_restored(strtotime(current_time('mysql')), $report, $from, $to, $client);
    }

    datav_power_session_set_step($from, DATAV_POWER_STEP_RESTORED_AT);

    return send_twilio_message($to, $from, datav_power_message('ask_restored_at'), $client);
}

/**
 * Cierra el caso como restablecido.
 *
 * Un borrador que nunca llegó a estar activo se activa primero: así pasa por el
 * mismo sitio que cualquier otro reporte y cuenta en las estadísticas, aunque la
 * falla ya hubiera terminado cuando la persona la reportó.
 */
function datav_power_finish_restored($timestamp, $report, $from, $to, $client)
{
    if ($report->status === 'draft') {
        datav_power_report_activate($report->id);
    }

    return datav_power_close_restored($timestamp, $report, $from, $to, $client);
}

/** Respuesta a "¿ya volvió la luz?", venga del seguimiento o del reporte duplicado. */
function datav_power_step_followup($messageReceived, $report, $from, $to, $client, $params = [])
{
    $choice = datav_power_read_followup_answer($messageReceived, $params);

    if ($choice === null) {
        return send_twilio_message($to, $from, datav_power_message('invalid_option'), $client);
    }

    if ($choice === 0) {
        // Si la respuesta ya trae la hora ("sí, volvió a las 6"), se cierra con ella.
        $timestamp = datav_power_time_inside_answer($messageReceived, $report);

        if ($timestamp !== null) {
            return datav_power_close_restored($timestamp, $report, $from, $to, $client);
        }

        // El caso no se cierra todavía: la luz pudo volver horas antes de que la
        // persona conteste, y darlo por ahora mismo inflaría todas las duraciones.
        return datav_power_ask_restored_when($report, $from, $to, $client);
    }

    datav_power_report_mark_still_out($report->id);
    datav_power_session_end($from);

    $current = datav_power_report_get($report->id);

    // Aquí no va el enlace del portal: el seguimiento se repite cada intervalo y
    // mandarlo cada vez sería spam. Se envía al registrar y al cerrar el reporte.
    return send_twilio_message($to, $from, datav_power_message('still_out', [
        'zona' => datav_power_report_zone_label($current),
        'duracion' => datav_power_format_duration(datav_power_report_duration_seconds($current)),
    ]), $client);
}

/**
 * "¿A qué hora volvió la luz?" — el dato que cierra el caso.
 *
 * Se acepta cualquier hora entre el inicio de la falla y ahora. Una hora anterior
 * al apagón no puede ser: casi siempre es un error de tipeo, así que se avisa y
 * se vuelve a preguntar en vez de guardar una duración negativa.
 */
function datav_power_step_restored_at($messageReceived, $report, $from, $to, $client)
{
    $now = strtotime(current_time('mysql'));

    if (datav_power_means_just_now($messageReceived)) {
        return datav_power_close_restored($now, $report, $from, $to, $client);
    }

    $timestamp = datav_power_parse_moment($messageReceived);

    if ($timestamp === null) {
        return send_twilio_message($to, $from, datav_power_message('invalid_restored_time'), $client);
    }

    if (!empty($report->outage_since) && $timestamp < strtotime($report->outage_since)) {
        return send_twilio_message($to, $from, datav_power_message('restored_too_early', [
            'inicio' => datav_power_format_moment(strtotime($report->outage_since)),
        ]), $client);
    }

    return datav_power_close_restored($timestamp, $report, $from, $to, $client);
}

/** Cierra el caso con la hora en que volvió el servicio. */
function datav_power_close_restored($timestamp, $report, $from, $to, $client)
{
    datav_power_report_mark_restored($report->id, date('Y-m-d H:i:s', $timestamp));

    $restored = datav_power_report_get($report->id);

    send_twilio_message($to, $from, datav_power_message('restored', [
        'zona' => datav_power_report_zone_label($restored),
        'inicio' => empty($restored->outage_since) ? '—' : datav_power_format_moment(strtotime($restored->outage_since)),
        'hora' => datav_power_format_moment($timestamp),
        'duracion' => datav_power_format_duration(datav_power_report_duration_seconds($restored)),
    ]), $client);

    datav_power_send_portal_link($from, $to, $client);

    return datav_power_ask_comment($report, $from, $to, $client);
}

/* -------------------------------------------------------------------------
 * Caja de comentarios
 * ---------------------------------------------------------------------- */

/**
 * Invita a dejar un mensaje sobre la falla, ya cerrada.
 *
 * La sesión queda abierta con vencimiento, no para siempre: si la persona no
 * escribe dentro del plazo, su siguiente mensaje es uno cualquiera y le sale el
 * menú, en vez de quedar guardado como comentario de un reporte que ya olvidó.
 *
 * Aquí no se manda el menú a propósito: al lado de la pregunta confundiría, y de
 * pulsar una opción del menú esa respuesta acabaría guardada como comentario.
 */
function datav_power_ask_comment($report, $from, $to, $client)
{
    if (!datav_power_comments_enabled()) {
        datav_power_session_end($from);

        return send_twilio_menu_message($to, $from, $client);
    }

    $minutes = datav_power_comment_minutes();

    datav_power_session_start($from, DATAV_POWER_STEP_COMMENT, $report->id, datav_power_comment_deadline($minutes));

    return send_twilio_message($to, $from, datav_power_message('ask_comment', ['minutos' => $minutes]), $client);
}

/** Hasta cuándo se acepta el comentario, en formato de la base de datos. */
function datav_power_comment_deadline($minutes)
{
    return date('Y-m-d H:i:s', strtotime(current_time('mysql')) + $minutes * MINUTE_IN_SECONDS);
}

function datav_power_step_comment($messageReceived, $report, $from, $to, $client)
{
    // Escribir "luz" dentro del plazo es querer abrir OTRO reporte, no comentar:
    // se le atiende como tal en vez de guardar la palabra como comentario.
    if (function_exists('isPowerReportInstruction') && isPowerReportInstruction($messageReceived)) {
        datav_power_session_end($from);

        return datav_power_start_flow($from, $to, $client);
    }

    if (datav_power_declines_comment($messageReceived)) {
        datav_power_session_end($from);

        send_twilio_message($to, $from, datav_power_message('comment_skipped'), $client);

        return send_twilio_menu_message($to, $from, $client);
    }

    // Un audio o una foto llegan con el cuerpo vacío: se pide por escrito en vez
    // de guardar un comentario en blanco.
    if (trim((string) $messageReceived) === '') {
        return send_twilio_message($to, $from, datav_power_message('comment_empty'), $client);
    }

    datav_power_report_save_comment($report->id, $messageReceived);
    datav_power_session_end($from);

    send_twilio_message($to, $from, datav_power_message('comment_saved'), $client);

    return send_twilio_menu_message($to, $from, $client);
}

/** '0' o un "no" suelto: no quiere dejar mensaje. */
function datav_power_declines_comment($messageReceived)
{
    $answer = datav_power_compare_key($messageReceived);

    return in_array($answer, [
        '0',
        'no',
        'no gracias',
        'nada',
        'ninguno',
        'ninguna',
        'listo',
        'ya',
    ], true);
}

/** "acaba de llegar": la hora es ahora mismo. */
function datav_power_means_just_now($messageReceived)
{
    $answer = datav_power_compare_key($messageReceived);

    return in_array($answer, [
        '0',
        'ahora',
        'ahorita',
        'ahora mismo',
        'justo ahora',
        'ya',
        'recien',
        'recien llego',
        'acaba de llegar',
        'acaba de venir',
        'apenas llego',
        'hace un momento',
        'en este momento',
    ], true);
}

/* -------------------------------------------------------------------------
 * Ubicación compartida
 * ---------------------------------------------------------------------- */

function datav_power_extract_coords($params)
{
    if (!isset($params['Latitude'], $params['Longitude'])) {
        return null;
    }

    $lat = (float) $params['Latitude'];
    $lng = (float) $params['Longitude'];

    if ($lat === 0.0 && $lng === 0.0) {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
}

/**
 * El GPS se guarda siempre (da el pin exacto del mapa) pero la zona administrativa
 * es solo una sugerencia por cercanía: nunca se da por buena sin que el usuario
 * la confirme.
 */
function datav_power_handle_shared_location($report, $coords, $from, $to, $client)
{
    datav_power_report_update($report->id, [
        'lat' => $coords['lat'],
        'lng' => $coords['lng'],
        'geo_precision' => 'gps',
    ]);

    $suggestion = datav_power_suggest_from_coords($coords['lat'], $coords['lng']);

    if (!$suggestion) {
        datav_power_session_set_step($from, DATAV_POWER_STEP_ESTADO);

        return datav_power_ask_estado($from, $to, $client);
    }

    $estado = datav_power_estado($suggestion['estado_code']);
    $municipio = datav_power_municipio($suggestion['municipio_code']);

    datav_power_report_update($report->id, [
        'estado_code' => $suggestion['estado_code'],
        'estado' => $estado['name'],
        'municipio_code' => $suggestion['municipio_code'],
        'municipio' => $municipio['name'],
    ]);

    datav_power_session_set_step($from, DATAV_POWER_STEP_GPS_CONFIRM);

    return send_twilio_message($to, $from, datav_power_message('gps_suggest', [
        'estado' => $estado['name'],
        'municipio' => $municipio['name'],
    ]), $client);
}

function datav_power_step_gps_confirm($messageReceived, $report, $from, $to, $client)
{
    $choice = datav_power_pick_option($messageReceived, ['Si, es correcto', 'No, quiero elegirlo yo']);

    if ($choice === null) {
        return send_twilio_message($to, $from, datav_power_message('invalid_option'), $client);
    }

    if ($choice === 1) {
        datav_power_report_update($report->id, [
            'estado_code' => '',
            'estado' => '',
            'municipio_code' => '',
            'municipio' => '',
        ]);

        datav_power_session_set_step($from, DATAV_POWER_STEP_ESTADO);

        return datav_power_ask_estado($from, $to, $client);
    }

    return datav_power_ask_parroquia_or_skip($report->municipio_code, $report->municipio, $from, $to, $client);
}

/* -------------------------------------------------------------------------
 * Cierre del flujo
 * ---------------------------------------------------------------------- */

function datav_power_finalize_report($report_id, $from, $to, $client)
{
    $report = datav_power_report_get($report_id);

    // Sin GPS, el pin del mapa sale del punto conocido más específico de la zona.
    if ($report->lat === null || $report->lng === null) {
        $coords = datav_power_coords_for($report->estado_code, $report->municipio_code, $report->parroquia_code);

        if ($coords) {
            datav_power_report_update($report_id, [
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
                'geo_precision' => $coords['precision'],
            ]);
        }
    }

    // Se cuenta ANTES de activar para no incluir este mismo reporte.
    $is_first = datav_power_report_count_for_phone($from, $report_id) === 0;

    datav_power_report_activate($report_id);
    datav_power_session_end($from);

    $report = datav_power_report_get($report_id);

    $inicio = empty($report->outage_since) ? 'sin definir' : datav_power_format_moment(strtotime($report->outage_since));
    $transcurrido = datav_power_format_duration(datav_power_report_duration_seconds($report));

    send_twilio_message($to, $from, datav_power_message('saved', [
        'zona' => datav_power_report_zone_label($report),
        'inicio' => $inicio,
        // 'transcurrido' no es una duración cerrada: el caso sigue abierto y el
        // contador sigue corriendo. Por eso no se llama 'duración'.
        'transcurrido' => $transcurrido,
        'hora' => $inicio,
        'desde' => $transcurrido . ' atrás',
        'intervalo' => datav_power_interval_minutes(),
    ]), $client);

    // El evento de "primer reporte" tiene prioridad; si no está activado, se
    // intenta el genérico, para que marcar solo uno de los dos siempre funcione.
    if (!($is_first && datav_followup_send('power_first_report', $from, $to, $client))) {
        datav_followup_send('power_report', $from, $to, $client);
    }

    // Aquí no va el menú: el reporte queda en monitoreo, no terminó nada. El menú
    // reaparece al cerrar el caso, cuando la persona dice que ya volvió la luz.
    return datav_power_send_portal_link($from, $to, $client);
}

/**
 * Cierre del flujo: enlace al portal donde se ven todos los reportes.
 * Si el administrador deja la URL vacía, no se envía nada y el flujo sigue igual.
 */
function datav_power_send_portal_link($from, $to, $client)
{
    $url = datav_power_portal_url();

    if ($url === '') {
        return true;
    }

    return send_twilio_message($to, $from, datav_power_message('portal', ['url' => $url]), $client);
}

/**
 * Salida del flujo.
 *
 * No se reutiliza isExitCourseInstruction() a propósito: esa lista incluye '0' y
 * 'no', que aquí significan otra cosa ('0' omite un campo opcional y 'no' responde
 * que sigue sin servicio). Usarla haría que omitir la foto cerrara el reporte.
 */
function datav_power_is_exit_instruction($messageReceived)
{
    $answer = datav_power_normalize_text($messageReceived);

    return in_array($answer, [
        'salir',
        'salirme',
        'cancelar',
        'menu',
        'menu principal',
        'terminar',
        'fin',
    ], true);
}

/** 'salir': descarta el borrador, pero nunca cierra un reporte ya en monitoreo. */
function datav_power_exit_flow($report, $from, $to, $client)
{
    if ($report->status === 'draft') {
        datav_power_report_delete($report->id);
    }

    datav_power_session_end($from);

    send_twilio_message($to, $from, datav_power_message('exit'), $client);

    return send_twilio_menu_message($to, $from, $client);
}

/* -------------------------------------------------------------------------
 * Interpretación de la hora en que se fue la luz
 * ---------------------------------------------------------------------- */

/**
 * Convierte lo que escribió el usuario en un momento del pasado reciente.
 * Sirve igual para cuándo se fue la luz y para cuándo volvió.
 *
 * Entiende "3pm", "15:30", "3 y media", "8 de la mañana", "a las tres de la tarde",
 * "ayer 9pm", "anoche a las 10" y también "hace 2 horas". Devuelve una marca de
 * tiempo local, o null si no se entendió.
 */
function datav_power_parse_moment($text)
{
    $now = strtotime(current_time('mysql'));
    $answer = datav_power_normalize_time_text($text);

    if ($answer === '') {
        return null;
    }

    $relative = datav_power_parse_relative_ago($answer, $now);

    if ($relative !== null) {
        return datav_power_validate_moment($relative, $now);
    }

    $day_offset = 0;

    if (preg_match('/\b(anteayer|antier|antes de ayer)\b/', $answer)) {
        $day_offset = -2;
    } elseif (preg_match('/\b(ayer|anoche)\b/', $answer)) {
        $day_offset = -1;
    }

    $clock = datav_power_parse_clock($answer);

    if ($clock === null) {
        return null;
    }

    list($hour, $minute, $explicit_hour) = $clock;

    $meridiem = datav_power_parse_meridiem($answer);

    if ($meridiem === 'pm' && $hour < 12) {
        $hour += 12;
    } elseif ($meridiem === 'am' && $hour === 12) {
        $hour = 0;
    }

    $midnight = strtotime(date('Y-m-d', $now) . ' 00:00:00') + $day_offset * DAY_IN_SECONDS;
    $candidates = [$midnight + $hour * HOUR_IN_SECONDS + $minute * MINUTE_IN_SECONDS];

    // "se fue a las 3" no dice si fue am o pm, así que se consideran las dos
    // lecturas. La hora 0 se deja fuera: "00:30" no es ambiguo en ninguna notación.
    if ($meridiem === null && $explicit_hour) {
        if ($hour > 0 && $hour < 12) {
            $candidates[] = $candidates[0] + 12 * HOUR_IN_SECONDS;
        } elseif ($hour === 12) {
            // "12" de madrugada suele ser la medianoche recién pasada.
            $candidates[] = $candidates[0] - 12 * HOUR_IN_SECONDS;
        }
    }

    // Una hora que todavía no ha llegado hoy solo puede ser del día anterior, y de
    // todas las lecturas posibles se toma la más reciente ya ocurrida.
    foreach ($candidates as $index => $candidate) {
        if ($candidate > $now) {
            $candidates[$index] = $candidate - DAY_IN_SECONDS;
        }
    }

    return datav_power_validate_moment(max($candidates), $now);
}

/** Deja el texto comparable sin romper "15:30" ni "2,5": solo normaliza a. m. / p. m. */
function datav_power_normalize_time_text($text)
{
    $text = datav_power_normalize_text($text);

    return str_replace(
        ['a. m.', 'a.m.', 'a. m', 'p. m.', 'p.m.', 'p. m'],
        ['am', 'am', 'am', 'pm', 'pm', 'pm'],
        $text
    );
}

/** "hace 2 horas", "hace media hora", "hace un dia". null si no es de esta forma. */
function datav_power_parse_relative_ago($answer, $now)
{
    if (!preg_match('/^hace\s+(.+)$/', $answer, $matches)) {
        return null;
    }

    $rest = $matches[1];

    if (preg_match('/^media\s+hora/', $rest)) {
        return $now - 1800;
    }

    $number = '\d+(?:[.,]\d+)?|un|una|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce';

    if (!preg_match('/^(' . $number . ')\s*(minutos?|mins?|horas?|hrs?|h|dias?|d)\b/', $rest, $matches)) {
        return null;
    }

    $quantity = datav_power_to_number($matches[1]);

    if ($quantity === null || $quantity <= 0) {
        return null;
    }

    if (strpos($matches[2], 'min') === 0) {
        $unit = MINUTE_IN_SECONDS;
    } elseif (strpos($matches[2], 'd') === 0) {
        $unit = DAY_IN_SECONDS;
    } else {
        $unit = HOUR_IN_SECONDS;
    }

    $seconds = $quantity * $unit;

    // "hace 2 horas y media" no debe perder la media hora.
    if ($unit === HOUR_IN_SECONDS && preg_match('/\by\s*media\b/', $rest)) {
        $seconds += 1800;
    }

    return $now - (int) round($seconds);
}

/**
 * Hora y minutos del texto: [hora, minutos, hora_explicita].
 * 'hora_explicita' es false en "mediodia"/"medianoche", donde no hay ambigüedad
 * am/pm que resolver. Devuelve null si no hay ninguna hora reconocible.
 */
function datav_power_parse_clock($answer)
{
    if (preg_match('/\bmedianoche\b/', $answer)) {
        return [0, 0, false];
    }

    if (preg_match('/\bmediodia\b/', $answer)) {
        return [12, 0, false];
    }

    $hour = null;
    $minute = 0;

    // Se usa (?!\d) y no \b porque en "9pm" no hay frontera entre el 9 y la p.
    if (preg_match('/(\d{1,2})\s*[:.h]\s*(\d{2})(?!\d)/', $answer, $matches)) {
        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
    } elseif (preg_match('/(\d{1,2})\s*y\s*media\b/', $answer, $matches)) {
        $hour = (int) $matches[1];
        $minute = 30;
    } elseif (preg_match('/(\d{1,2})(?!\d)/', $answer, $matches)) {
        $hour = (int) $matches[1];
    } elseif (preg_match('/\b(una|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)\b/', $answer, $matches)) {
        $hour = (int) datav_power_to_number($matches[1]);

        if (preg_match('/\by\s*media\b/', $answer)) {
            $minute = 30;
        }
    }

    if ($hour === null || $hour > 23 || $minute > 59) {
        return null;
    }

    return [$hour, $minute, true];
}

/** 'am', 'pm' o null si el usuario no lo dijo. */
function datav_power_parse_meridiem($answer)
{
    if (preg_match('/(?:^|[\s\d])am(?![a-z])/', $answer)
        || preg_match('/(?:de|en) la (manana|madrugada)\b/', $answer)) {
        return 'am';
    }

    if (preg_match('/(?:^|[\s\d])pm(?![a-z])/', $answer)
        || preg_match('/(?:de|en) la (tarde|noche)\b/', $answer)
        || preg_match('/\banoche\b/', $answer)) {
        return 'pm';
    }

    return null;
}

/** Números escritos con letra, para "a las tres de la tarde". */
function datav_power_to_number($word)
{
    $words = [
        'un' => 1, 'una' => 1, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4,
        'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9,
        'diez' => 10, 'once' => 11, 'doce' => 12,
    ];

    if (isset($words[$word])) {
        return $words[$word];
    }

    return is_numeric(str_replace(',', '.', $word)) ? (float) str_replace(',', '.', $word) : null;
}

/** Descarta lo imposible: el futuro y las horas absurdamente viejas. */
function datav_power_validate_moment($timestamp, $now)
{
    if ($timestamp > $now) {
        return null;
    }

    if ($timestamp < $now - DATAV_POWER_MAX_PAST_DAYS * DAY_IN_SECONDS) {
        return null;
    }

    return $timestamp;
}

/** 'hoy 3:00 pm' / 'ayer 9:30 pm' / '12/08 a las 7:00 am' */
function datav_power_format_moment($timestamp)
{
    $now = strtotime(current_time('mysql'));

    $today = strtotime(date('Y-m-d', $now) . ' 00:00:00');
    $day = strtotime(date('Y-m-d', $timestamp) . ' 00:00:00');

    $hour = date('g:i a', $timestamp);

    if ($day === $today) {
        return 'hoy ' . $hour;
    }

    if ($day === $today - DAY_IN_SECONDS) {
        return 'ayer ' . $hour;
    }

    return date('d/m', $timestamp) . ' a las ' . $hour;
}

/* -------------------------------------------------------------------------
 * Utilidades de listas
 * ---------------------------------------------------------------------- */

function datav_power_numbered_list($labels)
{
    $lines = [];

    foreach (array_values($labels) as $index => $label) {
        $lines[] = ($index + 1) . '. ' . $label;
    }

    return implode("\n", $lines);
}

/** 'Maracaibo' / 'Sucre (Petare)' cuando el municipio y su capital no coinciden. */
function datav_power_municipio_labels($municipios)
{
    $labels = [];

    foreach ($municipios as $municipio) {
        $labels[] = $municipio['name'] === $municipio['capital']
            ? $municipio['name']
            : $municipio['name'] . ' (' . $municipio['capital'] . ')';
    }

    return $labels;
}

/**
 * Clave de comparación: sin acentos, sin mayúsculas y sin puntuación.
 * Hace que "Sí, ya llegó" y "si ya llego" se consideren la misma respuesta.
 */
function datav_power_compare_key($text)
{
    $text = datav_power_normalize_text($text);
    $text = preg_replace('/[^a-z0-9 ]/', '', $text);

    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Interpreta la respuesta del usuario contra una lista de opciones.
 * Acepta el número o el nombre escrito. Devuelve el índice 0-based, o null.
 */
function datav_power_pick_option($messageReceived, $labels)
{
    $labels = array_values($labels);
    $answer = datav_power_compare_key($messageReceived);

    if ($answer === '') {
        return null;
    }

    if (ctype_digit($answer)) {
        $index = (int) $answer - 1;

        return isset($labels[$index]) ? $index : null;
    }

    foreach ($labels as $index => $label) {
        if (datav_power_compare_key($label) === $answer) {
            return $index;
        }
    }

    return null;
}

/**
 * Respuesta al seguimiento: 0 = ya volvió la luz, 1 = sigue sin servicio, null = no se entendió.
 *
 * Llega por tres vías distintas y hay que aceptar las tres: el payload del botón
 * cuando se usa la plantilla aprobada, el título del botón (que WhatsApp manda
 * también como cuerpo del mensaje) y el número o el texto que el usuario escriba.
 */
function datav_power_read_followup_answer($messageReceived, $params = [])
{
    $payload = '';

    foreach (['ButtonPayload', 'ListId', 'ButtonText'] as $key) {
        if (!empty($params[$key])) {
            $payload = datav_power_compare_key($params[$key]);
            break;
        }
    }

    $restored = ['power restored', '1', 'si', 'si ya llego', 'ya llego', 'ya volvio', 'ya hay luz', 'llego la luz', 'si ya volvio'];
    $still_out = ['power still out', '2', 'no', 'no sigue sin servicio', 'no sigue sin luz', 'sigue sin luz', 'sigue sin servicio', 'no ha llegado', 'todavia no'];

    foreach ([$payload, datav_power_compare_key($messageReceived)] as $answer) {
        if ($answer === '') {
            continue;
        }

        if (in_array($answer, $restored, true)) {
            return 0;
        }

        if (in_array($answer, $still_out, true)) {
            return 1;
        }
    }

    // "si, ya llego a las 6" no está en ninguna lista y sigue siendo un sí: basta
    // con cómo empieza la frase. Va después de las listas para no pisarlas.
    $written = datav_power_compare_key($messageReceived);

    if (preg_match('/^(si|sii+|claro|correcto|afirmativo|ya llego|ya volvio|ya hay luz)\b/', $written)) {
        return 0;
    }

    if (preg_match('/^(no|nop|nel|todavia no|aun no|sigue)\b/', $written)) {
        return 1;
    }

    return null;
}

/**
 * Hora escrita dentro de la propia respuesta afirmativa ("sí, volvió a las 6").
 *
 * Evita repreguntar cuando la persona ya dio el dato. Un "1" o un "sí" pelados no
 * llevan hora: solo se intenta cuando el mensaje tiene algo más que el número de
 * la opción, porque interpretar ese "1" como la una de la tarde sería un disparate.
 */
function datav_power_time_inside_answer($messageReceived, $report)
{
    $answer = datav_power_compare_key($messageReceived);

    if ($answer === '' || ctype_digit($answer)) {
        return null;
    }

    if (!preg_match('/\d|\b(una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|mediodia|medianoche)\b/', $answer)) {
        return null;
    }

    $timestamp = datav_power_parse_moment($messageReceived);

    if ($timestamp === null) {
        return null;
    }

    // Una hora anterior al apagón no es la hora del regreso: se pregunta aparte.
    if (!empty($report->outage_since) && $timestamp < strtotime($report->outage_since)) {
        return null;
    }

    return $timestamp;
}

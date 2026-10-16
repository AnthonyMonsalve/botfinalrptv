<?php

/**
 * Creador de la plantilla de seguimiento en Twilio Content.
 *
 * Sigue el mismo patrón que el botón "Crear Template de Menú" de
 * dashboard_config.php: llamada directa por cURL a la API de Content, porque el
 * SDK de PHP no serializa bien las claves con barra ("twilio/quick-reply").
 *
 * La plantilla es de tipo quick-reply con dos botones. Cuando el usuario pulsa
 * uno, Twilio manda su 'id' en ButtonPayload, que es lo que lee
 * datav_power_read_followup_answer().
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_datav_power_create_followup_template', 'datav_power_create_followup_template');

function datav_power_create_followup_template()
{
    check_ajax_referer('datav_power_nonce_action', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sin permisos.']);
    }

    $sid = get_option('datAv_twilio_sid', '');
    $token = get_option('datAv_twilio_token', '');

    if (empty($sid) || empty($token)) {
        wp_send_json_error(['message' => 'Configura primero el Twilio SID y Token en DatAv Config.']);
    }

    $template = [
        // El nombre lleva versión porque Twilio no permite editar una plantilla
        // ya aprobada: para cambiar el texto hay que crear otra.
        'friendly_name' => 'datav_power_seguimiento_v1',
        'language' => 'es',
        'variables' => ['1' => 'Maracaibo, Zulia', '2' => '4 h 12 min'],
        'types' => [
            'twilio/quick-reply' => [
                'body' => "\xE2\x9A\xA1 Seguimos monitoreando tu reporte en {{1}} ({{2}} sin servicio). \xC2\xBFYa volvi\xC3\xB3 la luz?",
                'actions' => [
                    ['title' => "S\xC3\xAD, ya lleg\xC3\xB3", 'id' => 'power_restored'],
                    ['title' => 'No, sigue sin luz', 'id' => 'power_still_out'],
                ],
            ],
        ],
    ];

    $json_body = json_encode($template);

    $ch = curl_init('https://content.twilio.com/v1/Content');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $sid . ':' . $token,
        CURLOPT_POSTFIELDS => $json_body,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        wp_send_json_error(['message' => 'Error cURL: ' . $curl_error]);
    }

    $data = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300 && isset($data['sid'])) {
        update_option('datAv_power_followup_content_sid', $data['sid']);

        wp_send_json_success([
            'sid' => $data['sid'],
            'message' => 'Plantilla creada. Falta enviarla a aprobación de WhatsApp desde la consola de Twilio '
                . '(Messaging > Content Template Builder), categoría "Utility". Hasta que WhatsApp la apruebe, '
                . 'el seguimiento solo llegará dentro de las 24 h desde el último mensaje del usuario.',
        ]);
    }

    wp_send_json_error([
        'message' => 'Error de la API de Twilio: ' . (isset($data['message']) ? $data['message'] : 'desconocido'),
        'code' => isset($data['code']) ? $data['code'] : $http_code,
    ]);
}

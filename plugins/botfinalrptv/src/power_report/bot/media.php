<?php

/**
 * Descarga de la foto que el usuario adjunta a su reporte.
 *
 * A diferencia de download_image() en overlay_fake_news.php, aquí se envían las
 * credenciales de Twilio: las URLs de media de WhatsApp están protegidas y sin
 * autenticación devuelven 401 en las cuentas con "Media Access Protection".
 */

if (!defined('ABSPATH')) {
    exit;
}

function datav_power_log($message)
{
    $log_file = plugin_dir_path(__FILE__) . '../power_report_log.txt';
    file_put_contents($log_file, '[' . current_time('mysql') . '] ' . $message . "\n", FILE_APPEND);
}

/**
 * Guarda la imagen en uploads/reportes-electricos/ y devuelve su URL pública,
 * o '' si no se pudo descargar.
 */
function datav_power_download_media($media_url)
{
    if (empty($media_url)) {
        return '';
    }

    $upload_dir = wp_upload_dir();
    $folder_path = $upload_dir['basedir'] . '/reportes-electricos';
    $folder_url = $upload_dir['baseurl'] . '/reportes-electricos';

    if (!file_exists($folder_path) && !wp_mkdir_p($folder_path)) {
        datav_power_log('No se pudo crear el directorio ' . $folder_path);

        return '';
    }

    $sid = get_option('datAv_twilio_sid', '');
    $token = get_option('datAv_twilio_token', '');

    $args = ['timeout' => 30];

    if ($sid && $token) {
        $args['headers'] = ['Authorization' => 'Basic ' . base64_encode($sid . ':' . $token)];
    }

    $response = wp_remote_get($media_url, $args);

    if (is_wp_error($response)) {
        datav_power_log('Error descargando media: ' . $response->get_error_message());

        return '';
    }

    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code != 200) {
        datav_power_log('Media respondió HTTP ' . $status_code . ' para ' . $media_url);

        return '';
    }

    $body = wp_remote_retrieve_body($response);

    if ($body === '') {
        datav_power_log('Media vacía para ' . $media_url);

        return '';
    }

    $extension = datav_power_extension_from_content_type(wp_remote_retrieve_header($response, 'content-type'));

    if ($extension === '') {
        datav_power_log('Tipo de contenido no soportado para ' . $media_url);

        return '';
    }

    $filename = 'reporte-' . date('Ymd-His') . '-' . wp_generate_password(6, false) . '.' . $extension;

    if (file_put_contents($folder_path . '/' . $filename, $body) === false) {
        datav_power_log('No se pudo escribir ' . $folder_path . '/' . $filename);

        return '';
    }

    return $folder_url . '/' . $filename;
}

function datav_power_extension_from_content_type($content_type)
{
    $content_type = strtolower(trim(strtok((string) $content_type, ';')));

    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    return isset($map[$content_type]) ? $map[$content_type] : '';
}

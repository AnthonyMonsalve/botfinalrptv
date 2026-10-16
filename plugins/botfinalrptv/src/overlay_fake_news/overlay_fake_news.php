<?php
if (!defined('ABSPATH')) {
    exit;
}

include(plugin_dir_path(__FILE__) . 'admin/image-overlay-admin.php');

use Intervention\Image\ImageManagerStatic as Image;

// Registrar el menú de administración
function image_overlay_plugin_menu() {
    add_menu_page('Image Overlay', 'Image Overlay', 'manage_options', 'image-overlay-plugin', 'image_overlay_plugin_admin_page');
}
add_action('admin_menu', 'image_overlay_plugin_menu');

// Función para superponer imágenes usando Intervention Image
function image_overlay($baseImagePath, $outputImagePath) {
    $log_file = plugin_dir_path(__FILE__) . 'validate_info_log.txt';

    try {
        $overlayImagePath = plugin_dir_path(__FILE__) . 'overlay.png'; // Ruta de la imagen de superposición predeterminada
        file_put_contents($log_file, "overlayImagePath $overlayImagePath\n", FILE_APPEND);
        $baseImage = Image::make($baseImagePath);
        $overlay = Image::make($overlayImagePath);
        $baseImage->insert($overlay, 'center');
        $baseImage->save($outputImagePath);
        file_put_contents($log_file, "outputImagePath $outputImagePath\n", FILE_APPEND);

    } catch (Exception $e) {
        error_log('Error superponiendo la imagen: ' . $e->getMessage());
        file_put_contents($log_file, "error\n", FILE_APPEND);
    }
}

// Función para manejar mensajes recibidos de Twilio
function handle_twilio_fake($request) {
    try {
        $data = $request->get_params();

        if (isset($data['Body']) && (strtolower($data['Body']) === 'fake' || strtolower($data['Body']) === 'falso')) {
            $from = $data['From'];
            $mediaUrl = $data['MediaUrl0']; // URL de la imagen enviada por el usuario

            $baseImagePath = download_image($mediaUrl);
            if (!$baseImagePath) {
                return new WP_Error('download_failed', 'No se pudo descargar la imagen.');
            }

            $outputImagePath = wp_upload_dir()['path'] . '/result_image.jpg';
            image_overlay($baseImagePath, $outputImagePath);
            send_image_via_twilio($from, $outputImagePath);
        }

        return new WP_REST_Response('Mensaje procesado.', 200);
    } catch (Exception $e) {
        error_log('Error manejando el mensaje de Twilio: ' . $e->getMessage());
        return new WP_Error('processing_failed', 'Error procesando el mensaje.');
    }
}

// Función para descargar la imagen enviada por el usuario
function download_image($url) {
    try {
        $imageContents = file_get_contents($url);
        $baseImagePath = wp_upload_dir()['path'] . '/base_image.jpg';
        file_put_contents($baseImagePath, $imageContents);
        return $baseImagePath;
    } catch (Exception $e) {
        error_log('Error descargando la imagen: ' . $e->getMessage());
        return null;
    }
}

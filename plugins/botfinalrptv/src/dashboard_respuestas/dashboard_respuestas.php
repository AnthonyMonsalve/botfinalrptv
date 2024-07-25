<?php
use Twilio\Rest\Client;

// Asegúrate de que el código no se ejecute directamente
if (!defined('ABSPATH')) {
    exit;
}

// Hook para agregar un elemento de menú al administrador
add_action('admin_menu', 'datAvenger_admin_menu');

function datAvenger_admin_menu()
{
    add_menu_page(
        'DatAvenger Messaging', // Título de la página
        'DatAvenger Messaging', // Título del menú
        'manage_options', // Capacidad
        'datAvenger-messaging', // Slug del menú
        'datAvenger_messaging_page', // Función que muestra la página
        'dashicons-email-alt', // Icono del menú (Dashicons)
        5.1 // Posición del menú
    );
}

function datAvenger_messaging_page()
{

    ?>
    <div class="wrap">
        <h1>Enviar Mensaje con DatAvenger</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Número de WhatsApp</th>
                    <td><input type="text" name="twilio_to_number" value="" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Mensaje</th>
                    <td><textarea name="twilio_message" rows="5" cols="50" required></textarea></td>
                </tr>
            </table>
            <?php submit_button('Enviar Mensaje'); ?>
        </form>
    </div>
    <?php

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Ruta del archivo de log en el mismo directorio
        $log_file = plugin_dir_path(__FILE__) . 'twilio_debug.txt';

        // Registro para verificar que la solicitud POST se ha recibido
        file_put_contents($log_file, "Solicitud POST recibida.\n", FILE_APPEND);

        $sid = get_option('datAv_twilio_sid', '');
        $token = get_option('datAv_twilio_token', '');
        $twilio_phone = get_option('datAv_twilio_phone', '');

		// Registro para verificar que las credenciales de Twilio se han establecido correctamente
		file_put_contents($log_file, "SID de Twilio: " . $sid . "\n", FILE_APPEND);
		file_put_contents($log_file, "Token de Twilio: " . $token . "\n", FILE_APPEND);

		try {
			$client_tw = new Client($sid, $token);
			file_put_contents($log_file, "Cliente de Twilio creado exitosamente.\n", FILE_APPEND);
		} catch (Exception $e) {
			file_put_contents($log_file, "Error al crear el cliente de Twilio: " . $e->getMessage() . "\n", FILE_APPEND);
		}
        
        $to_number = 'whatsapp:' . sanitize_text_field($_POST['twilio_to_number']);
        $message = sanitize_textarea_field($_POST['twilio_message']);

        // Registro para verificar que los datos del formulario se han recibido correctamente
        file_put_contents($log_file, "Número de WhatsApp de destino: " . $to_number . "\n", FILE_APPEND);
        file_put_contents($log_file, "Mensaje: " . $message . "\n", FILE_APPEND);

        // Intenta enviar el mensaje y registra si hubo éxito o error
        try {
            send_twilio_message($twilio_phone, $to_number, $message, $client_tw);
            file_put_contents($log_file, "Mensaje enviado con éxito.\n", FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents($log_file, "Error al enviar el mensaje: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

}

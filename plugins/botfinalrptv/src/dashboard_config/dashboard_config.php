<?php

use Twilio\Rest\Client;

// Asegúrate de que el código no se ejecute directamente
if (!defined('ABSPATH')) {
    exit;
}

// Hook para agregar un elemento de menú al administrador
add_action('admin_menu', 'datAvenger_config_menu');

function datAvenger_config_menu()
{
    add_menu_page(
        'DatAv Config', // Título de la página
        'DatAv Config', // Título del menú
        'manage_options', // Capacidad
        'datAv-config', // Slug del menú
        'datAv_config_page', // Función que muestra la página
        'dashicons-email-alt', // Icono del menú (Dashicons)
        200.1 // Posición del menú
    );
}

// AJAX handler para crear el Content Template del menú
add_action('wp_ajax_datAv_create_menu_template', 'datAv_create_menu_template');

function datAv_create_menu_template()
{
    check_ajax_referer('datAv_nonce_action', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sin permisos.']);
    }

    $sid = get_option('datAv_twilio_sid', '');
    $token = get_option('datAv_twilio_token', '');

    if (empty($sid) || empty($token)) {
        wp_send_json_error(['message' => 'Configura el Twilio SID y Token primero.']);
    }

    // El nombre de la opción de Reporte Eléctrico es configurable desde su propia
    // pestaña. Va siempre de primera y con el emoji de electricidad delante, salvo
    // que el administrador ya lo haya puesto en el nombre.
    // WhatsApp corta los títulos de fila del list-picker en 24 caracteres.
    $powerLabel = function_exists('datav_power_setting')
        ? datav_power_setting('datAv_power_menu_label')
        : 'Reporte Eléctrico';
    $powerLabel = trim($powerLabel);

    if (mb_strpos($powerLabel, "\xE2\x9A\xA1") !== 0) {
        $powerLabel = "\xE2\x9A\xA1 " . $powerLabel;
    }

    $powerLabel = mb_substr($powerLabel, 0, 24);

    // Construir el JSON con las keys correctas que espera la API (twilio/list-picker)
    // El SDK PHP serializa como "twilioListPicker" pero la API requiere "twilio/list-picker"
    $templateData = [
        'friendly_name' => 'datav_menu_principal_v7',
        'language' => 'es',
        'variables' => new \stdClass(),
        'types' => [
            'twilio/list-picker' => [
                'body' => "\xC2\xA1Hola! bienvenido a DatAvenger, selecciona una opci\xC3\xB3n de men\xC3\xBA",
                'button' => 'Ver opciones',
                'items' => [
                    [
                        'id' => '8',
                        'item' => $powerLabel,
                        'description' => 'Reporta una falla del servicio electrico',
                    ],
                    [
                        'id' => '7',
                        'item' => "\xF0\x9F\x87\xBB\xF0\x9F\x87\xAA Quiero Elegir",
                        'description' => 'Exige las condiciones para volver a elegir',
                    ],
                    [
                        'id' => '1',
                        'item' => 'Enviar un reporte',
                        'description' => 'Texto, audio, video o consultar veracidad',
                    ],
                    [
                        'id' => '2',
                        'item' => 'Noticias Venezuela',
                        'description' => '#VzlaTieneVoz Acontecer nacional',
                    ],
                    [
                        'id' => '3',
                        'item' => 'Curso desinformacion',
                        'description' => 'Curso interactivo sobre desinformacion',
                    ],
                    [
                        'id' => '6',
                        'item' => 'Memegenerator',
                        'description' => '¡Crea tu propio meme con nosotros!',
                    ],
                ],
            ],
        ],
    ];

    $jsonBody = json_encode($templateData);

    // Llamada directa a la API de Twilio Content (el SDK no serializa bien las keys de types)
    $ch = curl_init('https://content.twilio.com/v1/Content');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_USERPWD => $sid . ':' . $token,
        CURLOPT_POSTFIELDS => $jsonBody,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        wp_send_json_error([
            'message' => 'Error cURL: ' . $curlError,
        ]);
    }

    $responseData = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($responseData['sid'])) {
        update_option('datAv_twilio_menu_content_sid', $responseData['sid']);

        wp_send_json_success([
            'message' => 'Template creado exitosamente.',
            'sid' => $responseData['sid'],
            'friendly_name' => $responseData['friendly_name'] ?? 'datav_menu_principal_v7',
            'json_sent' => $jsonBody,
        ]);
    } else {
        wp_send_json_error([
            'message' => 'Error Twilio API: ' . ($responseData['message'] ?? 'Error desconocido'),
            'code' => $responseData['code'] ?? $httpCode,
            'details' => $responseData,
            'json_sent' => $jsonBody,
        ]);
    }
}

function datAv_config_page()
{
    // Comprobar si el usuario tiene permisos para guardar
    if (!current_user_can('manage_options')) {
        return;
    }

    // Verificar el nonce
    if (isset($_POST['datAv_save_config']) && check_admin_referer('datAv_nonce_action', 'datAv_nonce_name')) {
        update_option('datAv_twilio_sid', sanitize_text_field($_POST['datAv_twilio_sid']));
        update_option('datAv_twilio_token', sanitize_text_field($_POST['datAv_twilio_token']));
        update_option('datAv_twilio_phone', sanitize_text_field($_POST['datAv_twilio_phone']));
        update_option('datAv_twilio_admins', sanitize_text_field($_POST['datAv_twilio_admins']));
        update_option('datAv_twilio_menu_content_sid', sanitize_text_field($_POST['datAv_twilio_menu_content_sid']));

        // Mostrar mensaje de éxito
        add_settings_error('datAv_messages', 'datAv_message', 'Configuraciones guardadas.', 'updated');
    }

    settings_errors('datAv_messages');

    $twilio_sid = get_option('datAv_twilio_sid', '');
    $twilio_token = get_option('datAv_twilio_token', '');
    $twilio_phone = get_option('datAv_twilio_phone', '');
    $twilio_admins = get_option('datAv_twilio_admins', '');
    $menu_content_sid = get_option('datAv_twilio_menu_content_sid', '');
?>
    <div class="wrap">
        <h1>Configuración de DatAv</h1>
        <form method="post" action="">
            <?php wp_nonce_field('datAv_nonce_action', 'datAv_nonce_name'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Twilio SID</th>
                    <td><input type="text" name="datAv_twilio_sid" value="<?php echo esc_attr($twilio_sid); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Twilio Token</th>
                    <td><input type="password" name="datAv_twilio_token" value="<?php echo esc_attr($twilio_token); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Twilio Phone</th>
                    <td><input type="text" name="datAv_twilio_phone" value="<?php echo esc_attr($twilio_phone); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Twilio Admins</th>
                    <td><input type="text" name="datAv_twilio_admins" value="<?php echo esc_attr($twilio_admins); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button('Guardar Configuraciones', 'primary', 'datAv_save_config'); ?>

            <hr />
            <h2>Content Template Builder - Menú Principal</h2>
            <p>Crea un template de menú interactivo (List Picker) para WhatsApp usando la API de Twilio Content.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Menu Content SID</th>
                    <td>
                        <input type="text" name="datAv_twilio_menu_content_sid" id="datAv_menu_content_sid" value="<?php echo esc_attr($menu_content_sid); ?>" class="regular-text" />
                        <p class="description">SID del Content Template del menú. Se genera automáticamente al crear el template.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Crear Template</th>
                    <td>
                        <button type="button" id="datAv_create_template_btn" class="button button-secondary">Crear Template de Menú</button>
                        <span id="datAv_template_spinner" class="spinner" style="float:none;"></span>
                        <div id="datAv-ajax-result" style="margin-top: 10px; padding: 10px; display: none;"></div>
                    </td>
                </tr>
            </table>
        </form>
    </div>

    <script>
    (function() {
        var btn = document.getElementById('datAv_create_template_btn');
        var spinner = document.getElementById('datAv_template_spinner');
        var resultDiv = document.getElementById('datAv-ajax-result');
        var sidInput = document.getElementById('datAv_menu_content_sid');

        btn.addEventListener('click', function() {
            btn.disabled = true;
            spinner.classList.add('is-active');
            resultDiv.style.display = 'none';

            var formData = new FormData();
            formData.append('action', 'datAv_create_menu_template');
            formData.append('nonce', '<?php echo wp_create_nonce('datAv_nonce_action'); ?>');

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                spinner.classList.remove('is-active');
                btn.disabled = false;
                resultDiv.style.display = 'block';

                console.log('Twilio Content Template response:', data);

                if (data.success) {
                    resultDiv.style.background = '#d4edda';
                    resultDiv.style.border = '1px solid #c3e6cb';
                    resultDiv.style.color = '#155724';
                    resultDiv.innerHTML = '<strong>Template creado!</strong><br>SID: <code>' + data.data.sid + '</code><br>Name: ' + data.data.friendly_name;
                    sidInput.value = data.data.sid;
                } else {
                    resultDiv.style.background = '#f8d7da';
                    resultDiv.style.border = '1px solid #f5c6cb';
                    resultDiv.style.color = '#721c24';
                    resultDiv.innerHTML = '<strong>Error:</strong> ' + data.data.message;
                    if (data.data.code) {
                        resultDiv.innerHTML += '<br>Code: ' + data.data.code;
                    }
                }
            })
            .catch(function(err) {
                spinner.classList.remove('is-active');
                btn.disabled = false;
                resultDiv.style.display = 'block';
                resultDiv.style.background = '#f8d7da';
                resultDiv.style.border = '1px solid #f5c6cb';
                resultDiv.style.color = '#721c24';
                resultDiv.innerHTML = '<strong>Error de red:</strong> ' + err.message;
                console.error('AJAX error:', err);
            });
        });
    })();
    </script>
<?php
}

?>

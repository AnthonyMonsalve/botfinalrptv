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

function datAv_config_page()
{
    // Comprobar si el usuario tiene permisos para guardar
    if (!current_user_can('manage_options')) {
        return;
    }

    // Guardar la configuración
    if (isset($_POST['datAv_save_config'])) {
        update_option('datAv_twilio_sid', sanitize_text_field($_POST['datAv_twilio_sid']));
        update_option('datAv_twilio_token', sanitize_text_field($_POST['datAv_twilio_token']));
        update_option('datAv_twilio_phone', sanitize_text_field($_POST['datAv_twilio_phone']));
        update_option('datAv_twilio_admins', sanitize_text_field($_POST['datAv_twilio_admins']));
        
        // Mostrar mensaje de éxito
        echo '<div class="updated"><p>Configuraciones guardadas.</p></div>';
    }

    // Obtener los valores guardados
    $twilio_sid = get_option('datAv_twilio_sid', '');
    $twilio_token = get_option('datAv_twilio_token', '');
    $twilio_phone = get_option('datAv_twilio_phone', '');
    $twilio_admins = get_option('datAv_twilio_admins', '');
    ?>
    <div class="wrap">
        <h1>Configuración de DatAv</h1>
        <form method="post" action="">
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
        </form>
    </div>
    <?php
}
?>

<?php

use Twilio\Rest\Client;

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'datAvenger_admin_menu');

function datAvenger_admin_menu()
{
    add_menu_page(
        'DatAvenger Messaging',
        'DatAvenger Messaging',
        'manage_options',
        'datAvenger-messaging',
        'datAvenger_messaging_page',
        'dashicons-email-alt',
        5.1
    );
}

function datAvenger_messaging_page()
{
?>
    <div class="wrap">
        <h1>Enviar Mensaje con DatAvenger</h1>
        <form method="post" action="">
            <?php wp_nonce_field('datavenger_send_message_nonce', 'datavenger_message_nonce_field'); ?>
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

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && check_admin_referer('datavenger_send_message_nonce', 'datavenger_message_nonce_field')) {
        $sid = get_option('datAv_twilio_sid', '');
        $token = get_option('datAv_twilio_token', '');
        $twilio_phone = get_option('datAv_twilio_phone', '');

        try {
            $client_tw = new Client($sid, $token);
            $to_number = 'whatsapp:' . sanitize_text_field($_POST['twilio_to_number']);
            $message = sanitize_textarea_field($_POST['twilio_message']);

            $message_sent = $client_tw->messages->create(
                $to_number,
                array(
                    'from' => $twilio_phone,
                    'body' => $message
                )
            );

            if ($message_sent) {
                echo '<div class="updated"><p>Mensaje enviado con éxito.</p></div>';
            }
        } catch (Exception $e) {
            echo '<div class="error"><p>Error al enviar el mensaje: ' . $e->getMessage() . '</p></div>';
        }
    }
}

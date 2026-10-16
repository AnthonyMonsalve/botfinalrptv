<?php

/**
 * Pestaña "Mensajes": permite reescribir cualquier texto del flujo.
 *
 * Un campo vacío no borra el mensaje: hace que vuelva a usarse el .txt por
 * defecto de src/messages/power_report/. Así siempre hay algo que enviar.
 */

if (!defined('ABSPATH')) {
    exit;
}

function datav_power_tab_messages_save()
{
    foreach (array_keys(datav_power_message_registry()) as $key) {
        $field = 'datAv_power_msg_' . $key;

        if (isset($_POST[$field])) {
            update_option($field, sanitize_textarea_field(wp_unslash($_POST[$field])));
        }
    }
}

function datav_power_tab_messages_render()
{
    ?>
    <p class="description" style="margin: 15px 0;">
        Deja un campo en blanco para volver al texto por defecto. Los <code>{marcadores}</code> se sustituyen
        al enviar el mensaje: si los borras, ese dato no aparecerá.
    </p>

    <table class="form-table">
        <?php foreach (datav_power_message_registry() as $key => $entry) :
            $field = 'datAv_power_msg_' . $key;
            $saved = get_option($field, '');
            $placeholders = datav_power_message_placeholders($entry['fallback']);
            ?>
            <tr valign="top">
                <th scope="row">
                    <?php echo esc_html($entry['label']); ?>
                    <?php if ($placeholders) : ?>
                        <p class="description" style="font-weight: normal;">
                            <?php echo esc_html(implode(' ', $placeholders)); ?>
                        </p>
                    <?php endif; ?>
                </th>
                <td>
                    <textarea name="<?php echo esc_attr($field); ?>" rows="4" class="large-text"
                              placeholder="<?php echo esc_attr($entry['fallback']); ?>"><?php
                        echo esc_textarea($saved);
                    ?></textarea>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php
}

/** Marcadores {como_este} presentes en un texto, para mostrarlos como ayuda. */
function datav_power_message_placeholders($text)
{
    preg_match_all('/\{[a-z_]+\}/', $text, $matches);

    return array_values(array_unique($matches[0]));
}

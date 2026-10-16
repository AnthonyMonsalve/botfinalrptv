<?php

/** Página de ajustes, colgada del menú "DatAv Config" que ya existe. */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'datav_followup_admin_menu', 20);

function datav_followup_admin_menu()
{
    add_submenu_page(
        'datAv-config',
        'Mensajes de cierre',
        'Mensajes de cierre',
        'manage_options',
        'datav-followup',
        'datav_followup_admin_page'
    );
}

function datav_followup_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['datav_followup_save']) && check_admin_referer('datav_followup_nonce_action', 'datav_followup_nonce')) {
        datav_followup_save_settings();
        add_settings_error('datav_followup_messages', 'saved', 'Ajustes guardados.', 'updated');
    }

    settings_errors('datav_followup_messages');

    $events = datav_followup_events();
    ?>
    <div class="wrap">
        <h1>Mensajes de cierre</h1>
        <p class="description" style="max-width: 75ch;">
            En vez de ocupar una opción del menú, este mensaje se envía por separado
            <strong>después</strong> de que la persona completa una acción. Se manda una sola vez
            aunque se disparen varios eventos seguidos.
        </p>

        <form method="post" action="">
            <?php wp_nonce_field('datav_followup_nonce_action', 'datav_followup_nonce'); ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Activo</th>
                    <td>
                        <label>
                            <input type="checkbox" name="datav_followup_enabled" value="1"
                                <?php checked(datav_followup_is_enabled()); ?> />
                            Enviar mensajes de cierre
                        </label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Mensaje</th>
                    <td>
                        <textarea name="datav_followup_message" rows="7" class="large-text"
                                  placeholder="<?php echo esc_attr(datav_followup_message_text()); ?>"><?php
                            echo esc_textarea(get_option('datav_followup_message', ''));
                        ?></textarea>
                        <p class="description">
                            Déjalo vacío para usar el texto de <code>src/messages/social_media.txt</code>,
                            que es el que se muestra de fondo.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">No repetir antes de</th>
                    <td>
                        <input type="number" name="datav_followup_cooldown_days" min="0" class="small-text"
                               value="<?php echo esc_attr(datav_followup_cooldown_days()); ?>" /> días
                        <p class="description">
                            El contador es <strong>de cada número de teléfono por separado</strong>: que una
                            persona ya lo haya recibido no impide que lo reciban las demás.<br />
                            Dentro de una misma persona, en cambio, <strong>todos los eventos comparten el
                            contador</strong>: si termina un curso y luego reporta una falla, lo recibe
                            una sola vez.<br />
                            Pon <code>0</code> para no limitarlo (no recomendado: es la vía rápida a que
                            lo marquen como spam).
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Pausa antes de enviarlo</th>
                    <td>
                        <input type="number" name="datav_followup_delay_seconds" min="0" max="5" class="small-text"
                               value="<?php echo esc_attr(datav_message_delay()); ?>" /> segundos
                        <p class="description">
                            Para que llegue como un mensaje aparte y no pegado al anterior.
                            La pausa se omite sola si la respuesta al webhook ya va justa de tiempo,
                            porque Twilio la corta a los 15&nbsp;segundos.
                        </p>
                    </td>
                </tr>
            </table>

            <h2>¿Cuándo se envía?</h2>
            <table class="wp-list-table widefat striped" style="max-width: 780px;">
                <thead>
                    <tr>
                        <th style="width: 60px;">Activo</th>
                        <th>Momento</th>
                        <th>Envíos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $key => $event) : ?>
                        <tr>
                            <td style="text-align:center;">
                                <input type="checkbox" name="datav_followup_event_<?php echo esc_attr($key); ?>" value="1"
                                    <?php checked(datav_followup_event_is_enabled($key)); ?> />
                            </td>
                            <td>
                                <strong><?php echo esc_html($event['label']); ?></strong>
                                <p class="description"><?php echo esc_html($event['hint']); ?></p>
                            </td>
                            <td><?php echo esc_html(number_format_i18n(datav_followup_event_count($key))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Diagnóstico</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Reiniciar el tope</th>
                    <td>
                        <input type="text" name="datav_followup_reset_phone" class="regular-text"
                               placeholder="+584143385226" />
                        <p class="description">
                            Borra el historial de ese número para que vuelva a recibir el mensaje de inmediato.
                            Sirve para probar sin esperar los <?php echo esc_html(datav_followup_cooldown_days()); ?> días.
                            Déjalo vacío si no quieres tocar nada.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Últimos envíos</th>
                    <td>
                        <?php $recent = datav_followup_recent_sends(10); ?>
                        <?php if (empty($recent)) : ?>
                            <p class="description">
                                Todavía no se ha enviado ningún mensaje de cierre. Si esperabas alguno,
                                revisa <code>src/followup_messages/followup_log.txt</code>: ahí queda
                                anotado también <strong>por qué</strong> se omitió cada envío.
                            </p>
                        <?php else : ?>
                            <table class="wp-list-table widefat striped" style="max-width: 620px;">
                                <thead><tr><th>Cuándo</th><th>Número</th><th>Evento</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recent as $row) : ?>
                                        <tr>
                                            <td><?php echo esc_html($row->sent_at); ?></td>
                                            <td><?php echo esc_html($row->phone_number); ?></td>
                                            <td><?php echo esc_html($row->event); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button('Guardar cambios', 'primary', 'datav_followup_save'); ?>
        </form>
    </div>
    <?php
}

/** Últimos mensajes de cierre efectivamente enviados. */
function datav_followup_recent_sends($limit = 10)
{
    global $wpdb;

    $table = datav_followup_table();

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return [];
    }

    return $wpdb->get_results($wpdb->prepare(
        "SELECT phone_number, event, sent_at FROM $table ORDER BY sent_at DESC, id DESC LIMIT %d",
        $limit
    ));
}

/**
 * Borra el historial de un número para que su tope vuelva a cero.
 *
 * Se busca por coincidencia parcial porque el bot guarda el número con el
 * prefijo 'whatsapp:' y nadie lo escribe así a mano en este formulario.
 */
function datav_followup_reset_phone($phone_number)
{
    global $wpdb;

    $table = datav_followup_table();
    $digits = preg_replace('/\D+/', '', $phone_number);

    if ($digits === '') {
        return 0;
    }

    return (int) $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE REPLACE(REPLACE(phone_number, '+', ''), 'whatsapp:', '') LIKE %s",
        '%' . $wpdb->esc_like($digits) . '%'
    ));
}

function datav_followup_save_settings()
{
    update_option('datav_followup_enabled', isset($_POST['datav_followup_enabled']) ? '1' : '0');

    if (isset($_POST['datav_followup_message'])) {
        update_option('datav_followup_message', sanitize_textarea_field(wp_unslash($_POST['datav_followup_message'])));
    }

    if (isset($_POST['datav_followup_cooldown_days'])) {
        update_option('datav_followup_cooldown_days', (string) max(0, (int) $_POST['datav_followup_cooldown_days']));
    }

    if (isset($_POST['datav_followup_delay_seconds'])) {
        $delay = min(5, max(0, (int) $_POST['datav_followup_delay_seconds']));
        update_option('datav_followup_delay_seconds', (string) $delay);
    }

    foreach (array_keys(datav_followup_events()) as $key) {
        update_option('datav_followup_event_' . $key, isset($_POST['datav_followup_event_' . $key]) ? '1' : '0');
    }

    if (!empty($_POST['datav_followup_reset_phone'])) {
        $phone = sanitize_text_field(wp_unslash($_POST['datav_followup_reset_phone']));
        $deleted = datav_followup_reset_phone($phone);

        add_settings_error(
            'datav_followup_messages',
            'reset',
            sprintf('Tope reiniciado para %s (%d registro/s borrado/s).', esc_html($phone), $deleted),
            'updated'
        );
    }
}

/** Cuántas veces se ha enviado por ese evento, para ver si está funcionando. */
function datav_followup_event_count($event)
{
    global $wpdb;

    $table = datav_followup_table();

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE event = %s",
        $event
    ));
}

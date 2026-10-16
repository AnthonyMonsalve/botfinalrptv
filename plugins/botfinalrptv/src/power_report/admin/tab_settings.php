<?php

/** Pestaña "Ajustes": activación del flujo, cómo se llama y cómo se dispara. */

if (!defined('ABSPATH')) {
    exit;
}

function datav_power_tab_settings_save()
{
    update_option('datAv_power_enabled', isset($_POST['datAv_power_enabled']) ? '1' : '0');

    datav_power_save_text_option('datAv_power_menu_label');
    // Se permite vacío a propósito: así el admin puede desactivar el enlace final.
    if (isset($_POST['datAv_power_portal_url'])) {
        update_option('datAv_power_portal_url', esc_url_raw(trim(wp_unslash($_POST['datAv_power_portal_url']))));
    }

    datav_power_save_textarea_option('datAv_power_keywords');
    datav_power_save_textarea_option('datAv_power_api_allowed_origins');
}

function datav_power_tab_settings_render()
{
    $enabled = datav_power_setting('datAv_power_enabled') === '1';
    ?>
    <table class="form-table">
        <tr valign="top">
            <th scope="row">Flujo activo</th>
            <td>
                <label>
                    <input type="checkbox" name="datAv_power_enabled" value="1" <?php checked($enabled); ?> />
                    Permitir que los usuarios reporten fallas eléctricas por WhatsApp
                </label>
                <p class="description">Al desactivarlo el bot deja de reconocer las palabras clave. Los reportes ya registrados se conservan.</p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Nombre en el menú del bot</th>
            <td>
                <input type="text" name="datAv_power_menu_label" class="regular-text"
                       value="<?php echo esc_attr(datav_power_setting('datAv_power_menu_label')); ?>" />
                <p class="description">Cómo aparece la opción en el menú principal de WhatsApp. El menú la muestra de primera, con el emoji ⚡.</p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Enlace al portal de reportes</th>
            <td>
                <input type="text" name="datAv_power_portal_url" class="large-text code"
                       value="<?php echo esc_attr(datav_power_setting('datAv_power_portal_url')); ?>" />
                <p class="description">
                    Al terminar el flujo (reporte registrado o servicio restablecido) el bot manda este enlace
                    para que la persona vea todos los reportes. <strong>Déjalo vacío para no enviarlo.</strong>
                    El texto del mensaje se edita en la pestaña <em>Mensajes</em>.
                </p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Palabras clave</th>
            <td>
                <textarea name="datAv_power_keywords" rows="8" class="large-text code"><?php
                    echo esc_textarea(datav_power_setting('datAv_power_keywords'));
                ?></textarea>
                <p class="description">
                    Una por línea. El bot ignora mayúsculas y acentos, así que <code>Apagón</code> y <code>apagon</code>
                    son lo mismo. Debe coincidir el mensaje completo, no una palabra suelta dentro de una frase.
                </p>
            </td>
        </tr>
    </table>

    <h2>API pública</h2>
    <p class="description" style="margin-bottom: 15px;">
        El portal en tiempo real es un <strong>plugin aparte</strong> que puede estar instalado en este sitio
        o en cualquier otro WordPress. Se conecta a esta dirección; pégala en su configuración.
    </p>

    <table class="form-table">
        <tr valign="top">
            <th scope="row">Dirección de la API</th>
            <td>
                <input type="text" class="large-text code" readonly onclick="this.select();"
                       value="<?php echo esc_attr(rest_url(DATAV_POWER_API_NAMESPACE)); ?>" />
                <p class="description">
                    Comprueba que responde abriendo
                    <a href="<?php echo esc_url(rest_url(DATAV_POWER_API_NAMESPACE . '/power/meta')); ?>" target="_blank">
                        <?php echo esc_html(rest_url(DATAV_POWER_API_NAMESPACE . '/power/meta')); ?>
                    </a>.
                    Las respuestas van siempre sin caché y <strong>nunca incluyen el teléfono ni el nombre</strong>
                    de quien reporta.
                </p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Dominios autorizados</th>
            <td>
                <textarea name="datAv_power_api_allowed_origins" rows="4" class="large-text code"
                          placeholder="https://otrositio.com&#10;https://www.otrositio.com"><?php
                    echo esc_textarea(datav_power_setting('datAv_power_api_allowed_origins'));
                ?></textarea>
                <p class="description">
                    Uno por línea, con <code>https://</code> y sin barra final.
                    <strong>Déjalo vacío para permitir cualquier dominio</strong>, que es lo habitual tratándose de
                    datos públicos y anónimos. Si lo llenas, solo esos sitios podrán consumir la API desde el navegador
                    (el modo proxy del portal sigue funcionando igual, porque consulta desde el servidor).
                </p>
            </td>
        </tr>
    </table>
    <?php
}

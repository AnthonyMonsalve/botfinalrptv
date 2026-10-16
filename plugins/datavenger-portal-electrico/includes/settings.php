<?php

/** Página de ajustes del portal, bajo Ajustes de WordPress. */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dvpe_admin_menu');

function dvpe_admin_menu()
{
    add_options_page(
        'Portal Reporte Eléctrico',
        'Portal Reporte Eléctrico',
        'manage_options',
        'dvpe-settings',
        'dvpe_settings_page'
    );
}

function dvpe_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['dvpe_save']) && check_admin_referer('dvpe_nonce_action', 'dvpe_nonce')) {
        dvpe_save_settings();
        add_settings_error('dvpe_messages', 'dvpe_saved', 'Ajustes guardados.', 'updated');
    }

    settings_errors('dvpe_messages');

    $api_base = dvpe_api_base();
    ?>
    <div class="wrap">
        <h1>Portal Reporte Eléctrico</h1>
        <p class="description" style="max-width: 70ch;">
            Este plugin solo muestra los datos: los reportes viven en el sitio donde está instalado
            DatAvenger. Indica abajo la dirección de su API y el portal quedará publicado.
        </p>

        <form method="post" action="">
            <?php wp_nonce_field('dvpe_nonce_action', 'dvpe_nonce'); ?>

            <h2>Conexión</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Dirección de la API</th>
                    <td>
                        <input type="url" name="dvpe_api_url" class="large-text code"
                               placeholder="https://sitio-del-bot.com"
                               value="<?php echo esc_attr(get_option('dvpe_api_url', '')); ?>" />
                        <p class="description">
                            <?php if ($api_base) : ?>
                                Se consultará: <code><?php echo esc_html($api_base); ?></code>
                            <?php else : ?>
                                Puedes pegar la URL del sitio del bot; el sufijo <code>/wp-json/datav/v1</code>
                                se añade solo si falta.
                            <?php endif; ?>
                        </p>
                        <p>
                            <button type="button" class="button" id="dvpe-test">Probar conexión</button>
                            <span class="spinner" id="dvpe-test-spinner" style="float:none;"></span>
                        </p>
                        <div id="dvpe-test-result" style="display:none; margin-top:8px; padding:10px;"></div>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Modo de consulta</th>
                    <td>
                        <?php $mode = dvpe_mode(); ?>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="radio" name="dvpe_mode" value="direct" <?php checked($mode, 'direct'); ?> />
                            <strong>Directo</strong> — el navegador del visitante consulta la API del bot.
                            Más ligero para este servidor. Requiere que el bot permita este dominio.
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="dvpe_mode" value="proxy" <?php checked($mode, 'proxy'); ?> />
                            <strong>Proxy</strong> — este WordPress consulta la API y reenvía la respuesta.
                            Úsalo si un CDN o un firewall bloquea las peticiones entre dominios.
                        </label>
                    </td>
                </tr>
            </table>

            <h2>Página pública</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Dirección</th>
                    <td>
                        <code><?php echo esc_html(trailingslashit(home_url())); ?></code>
                        <input type="text" name="dvpe_slug"
                               value="<?php echo esc_attr(dvpe_setting('dvpe_slug')); ?>" />
                        <p class="description">
                            <a href="<?php echo esc_url(dvpe_portal_url()); ?>" target="_blank">Ver el portal</a>
                            &nbsp;·&nbsp; La página se sirve siempre sin caché.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Título</th>
                    <td>
                        <input type="text" name="dvpe_title" class="regular-text"
                               value="<?php echo esc_attr(dvpe_setting('dvpe_title')); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Descripción</th>
                    <td>
                        <textarea name="dvpe_subtitle" rows="2" class="large-text"><?php
                            echo esc_textarea(dvpe_setting('dvpe_subtitle'));
                        ?></textarea>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Aviso del último informe</th>
                    <td>
                        <label>
                            <input type="checkbox" name="dvpe_nudge_enabled" value="1"
                                <?php checked(dvpe_setting('dvpe_nudge_enabled') === '1'); ?> />
                            Mostrar en el portal un aviso deslizante con el informe más reciente
                        </label>
                        <p style="margin:8px 0 0;">
                            Aparece a los
                            <input type="number" name="dvpe_nudge_delay" min="3" max="600" step="1" class="small-text"
                                   value="<?php echo esc_attr(dvpe_setting('dvpe_nudge_delay')); ?>" />
                            segundos.
                        </p>
                        <p class="description">
                            Si la persona lo cierra, no vuelve a verlo en todo el día.
                            Para invitar desde otras páginas del sitio, usa el shortcode
                            <code>[datavenger_informes]</code>.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Imagen al compartir</th>
                    <td>
                        <input type="text" name="dvpe_share_image" class="large-text code"
                               value="<?php echo esc_attr(dvpe_setting('dvpe_share_image')); ?>" />
                        <p class="description">
                            La que se ve al pegar el enlace en WhatsApp, X o Facebook. Lo ideal son
                            1200 × 630 px; puedes usar una de las imágenes que genera el propio portal.
                            <strong>Vacío = la mascota de DatAvenger.</strong>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Mapa base</th>
                    <td>
                        <?php $basemap = dvpe_setting('dvpe_basemap'); ?>
                        <select name="dvpe_basemap">
                            <option value="esri_dark" <?php selected($basemap, 'esri_dark'); ?>>
                                Oscuro (Esri) — sin clave
                            </option>
                            <option value="osm" <?php selected($basemap, 'osm'); ?>>
                                Claro (OpenStreetMap) — sin clave
                            </option>
                            <option value="carto_dark" <?php selected($basemap, 'carto_dark'); ?>>
                                Oscuro (CARTO) — requiere clave
                            </option>
                        </select>
                        <p class="description">
                            CARTO dejó de servir sus mapas sin registro: al usarlo sin clave, las teselas
                            llegan marcadas con <em>API KEY REQUIRED</em>. Las otras dos opciones no piden nada.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Clave de CARTO</th>
                    <td>
                        <input type="text" name="dvpe_carto_key" class="regular-text code"
                               value="<?php echo esc_attr(dvpe_setting('dvpe_carto_key')); ?>" />
                        <p class="description">
                            Solo se usa con la opción CARTO. Se obtiene gratis registrándose en
                            <code>carto.com/basemaps</code>. Si la dejas vacía, el portal usa el mapa de Esri.
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Color de acento</th>
                    <td>
                        <input type="color" name="dvpe_accent"
                               value="<?php echo esc_attr(dvpe_setting('dvpe_accent')); ?>" />
                        <p class="description">Para adaptar el portal a la identidad del sitio.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Crédito del pie</th>
                    <td>
                        <input type="text" name="dvpe_credit" class="large-text"
                               value="<?php echo esc_attr(dvpe_setting('dvpe_credit')); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Frecuencia de actualización</th>
                    <td>
                        <input type="number" name="dvpe_poll_seconds" min="5" class="small-text"
                               value="<?php echo esc_attr(dvpe_poll_seconds()); ?>" /> segundos
                        <p class="description">
                            Cada cuánto el portal vuelve a pedir los datos. Bajarlo mucho multiplica
                            las peticiones al sitio del bot sin ganar gran cosa.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Guardar cambios', 'primary', 'dvpe_save'); ?>
        </form>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('dvpe-test');
        var spinner = document.getElementById('dvpe-test-spinner');
        var result = document.getElementById('dvpe-test-result');

        btn.addEventListener('click', function () {
            btn.disabled = true;
            spinner.classList.add('is-active');
            result.style.display = 'none';

            var data = new FormData();
            data.append('action', 'dvpe_test_connection');
            data.append('nonce', '<?php echo esc_js(wp_create_nonce('dvpe_nonce_action')); ?>');
            data.append('url', document.querySelector('[name="dvpe_api_url"]').value);

            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST', body: data, credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                spinner.classList.remove('is-active');
                btn.disabled = false;
                result.style.display = 'block';
                if (res.success) {
                    result.style.cssText = 'display:block;margin-top:8px;padding:10px;background:#d4edda;border:1px solid #c3e6cb;color:#155724;';
                    result.innerHTML = res.data.message;
                } else {
                    result.style.cssText = 'display:block;margin-top:8px;padding:10px;background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;';
                    result.innerHTML = '<strong>Sin conexión:</strong> ' + res.data.message;
                }
            })
            .catch(function (err) {
                spinner.classList.remove('is-active');
                btn.disabled = false;
                result.style.cssText = 'display:block;margin-top:8px;padding:10px;background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;';
                result.innerHTML = '<strong>Error:</strong> ' + err.message;
            });
        });
    })();
    </script>
    <?php
}

function dvpe_save_settings()
{
    if (isset($_POST['dvpe_api_url'])) {
        update_option('dvpe_api_url', esc_url_raw(trim(wp_unslash($_POST['dvpe_api_url']))));
    }

    update_option('dvpe_mode', isset($_POST['dvpe_mode']) && $_POST['dvpe_mode'] === 'proxy' ? 'proxy' : 'direct');

    if (isset($_POST['dvpe_slug'])) {
        $slug = sanitize_title(wp_unslash($_POST['dvpe_slug']));
        $defaults = dvpe_defaults();
        $slug = $slug === '' ? $defaults['dvpe_slug'] : $slug;

        if ($slug !== get_option('dvpe_slug')) {
            update_option('dvpe_slug', $slug);
            // La regla de reescritura vieja sigue en la base de datos hasta que
            // se regeneran; se hace en el próximo 'init', no aquí.
            update_option('dvpe_flush_rewrite', 1);
        }
    }

    foreach (['dvpe_title', 'dvpe_credit', 'dvpe_accent', 'dvpe_carto_key'] as $key) {
        if (isset($_POST[$key])) {
            update_option($key, sanitize_text_field(wp_unslash($_POST[$key])));
        }
    }

    update_option('dvpe_nudge_enabled', isset($_POST['dvpe_nudge_enabled']) ? '1' : '0');

    if (isset($_POST['dvpe_nudge_delay'])) {
        $segundos = min(600, max(3, (int) $_POST['dvpe_nudge_delay']));
        update_option('dvpe_nudge_delay', (string) $segundos);
    }

    // Puede ir vacía: entonces se usa la mascota.
    if (isset($_POST['dvpe_share_image'])) {
        update_option('dvpe_share_image', esc_url_raw(trim(wp_unslash($_POST['dvpe_share_image']))));
    }

    if (isset($_POST['dvpe_basemap'])) {
        $basemap = sanitize_key(wp_unslash($_POST['dvpe_basemap']));
        $allowed = ['esri_dark', 'osm', 'carto_dark'];

        update_option('dvpe_basemap', in_array($basemap, $allowed, true) ? $basemap : 'esri_dark');
    }

    if (isset($_POST['dvpe_subtitle'])) {
        update_option('dvpe_subtitle', sanitize_textarea_field(wp_unslash($_POST['dvpe_subtitle'])));
    }

    if (isset($_POST['dvpe_poll_seconds'])) {
        update_option('dvpe_poll_seconds', (string) max(5, (int) $_POST['dvpe_poll_seconds']));
    }
}

add_action('wp_ajax_dvpe_test_connection', 'dvpe_test_connection');

/**
 * Prueba la URL que hay escrita en el formulario, no la guardada: así se puede
 * comprobar antes de guardar.
 */
function dvpe_test_connection()
{
    check_ajax_referer('dvpe_nonce_action', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sin permisos.']);
    }

    $base = dvpe_normalize_api_url(isset($_POST['url']) ? wp_unslash($_POST['url']) : '');

    if ($base === '') {
        wp_send_json_error(['message' => 'Escribe primero la dirección de la API.']);
    }

    $response = wp_remote_get($base . '/power/meta?_=' . time(), [
        'timeout' => 15,
        'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) {
        wp_send_json_error([
            'message' => 'La dirección respondió HTTP ' . $code . '. Comprueba que sea el sitio donde está DatAvenger.',
        ]);
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data) || !isset($data['version'])) {
        wp_send_json_error(['message' => 'Responde, pero no es la API de DatAvenger.']);
    }

    wp_send_json_success([
        'message' => sprintf(
            'Conectado con <strong>%s</strong> (API %s). Fallas activas ahora: <strong>%d</strong>.%s',
            esc_html(isset($data['site']) ? $data['site'] : 'el sitio del bot'),
            esc_html($data['version']),
            isset($data['active_count']) ? (int) $data['active_count'] : 0,
            empty($data['enabled']) ? ' <em>Aviso: el flujo de reportes está desactivado en el bot.</em>' : ''
        ),
    ]);
}

<?php

/**
 * Pestaña "Seguimiento": cada cuánto se pregunta si volvió la luz, cuándo se da
 * un caso por indeterminado, y el estado real del cron que lo ejecuta.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/followup_template.php';

function datav_power_tab_followup_save()
{
    if (isset($_POST['datAv_power_interval_minutes'])) {
        $minutes = max(5, (int) $_POST['datAv_power_interval_minutes']);
        update_option('datAv_power_interval_minutes', (string) $minutes);
    }

    if (isset($_POST['datAv_power_max_checks'])) {
        $checks = max(1, (int) $_POST['datAv_power_max_checks']);
        update_option('datAv_power_max_checks', (string) $checks);
    }

    datav_power_save_text_option('datAv_power_followup_content_sid', true);

    // Ejecutar el ciclo a mano envía mensajes reales de WhatsApp, así que va
    // detrás de su propio botón y de una confirmación en el navegador.
    if (!empty($_POST['datav_power_run_now'])) {
        $summary = datav_power_followup_tick();

        add_settings_error(
            'datav_power_messages',
            'datav_power_tick',
            sprintf(
                'Ciclo ejecutado: %d confirmaciones enviadas, %d casos cerrados como indeterminados, %d envíos fallidos.',
                $summary['checked'],
                $summary['closed'],
                $summary['failed']
            ),
            'updated'
        );
    }
}

function datav_power_tab_followup_render()
{
    $interval = datav_power_interval_minutes();
    $max_checks = datav_power_max_checks();
    $status = datav_power_cron_status();
    ?>
    <table class="form-table">
        <tr valign="top">
            <th scope="row">Intervalo entre confirmaciones</th>
            <td>
                <input type="number" name="datAv_power_interval_minutes" min="5" step="5" class="small-text"
                       value="<?php echo esc_attr($interval); ?>" /> minutos
                <p class="description">Cada cuánto se le pregunta al usuario si ya volvió el servicio.</p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Confirmaciones sin respuesta</th>
            <td>
                <input type="number" name="datAv_power_max_checks" min="1" class="small-text"
                       value="<?php echo esc_attr($max_checks); ?>" /> mensajes
                <p class="description">
                    Tras este número de mensajes <em>seguidos</em> sin respuesta el caso se cierra como
                    <strong>indeterminado</strong>, aproximadamente
                    <strong><?php echo esc_html(datav_power_format_duration($interval * ($max_checks + 1) * 60)); ?></strong>
                    después del último contacto. Cualquier respuesta del usuario reinicia la cuenta, así que
                    una falla larga que él va confirmando nunca se cierra sola.
                    La duración se congela en la última confirmación, no en el cierre, para no inflar las estadísticas.
                </p>
            </td>
        </tr>
    </table>

    <h2>Plantilla de WhatsApp</h2>
    <table class="form-table">
        <tr valign="top">
            <th scope="row">Content SID</th>
            <td>
                <input type="text" name="datAv_power_followup_content_sid" id="datav_power_followup_sid" class="regular-text"
                       value="<?php echo esc_attr(datav_power_setting('datAv_power_followup_content_sid')); ?>" />
                <p class="description">
                    Fuera de las 24&nbsp;h desde el último mensaje del usuario, Twilio rechaza el texto libre
                    (error&nbsp;63016). Con este SID el seguimiento se envía como plantilla aprobada con botones
                    <em>Sí, ya llegó</em> / <em>No, sigue sin luz</em>. Sin SID se manda como texto normal, que
                    solo funciona dentro de esa ventana.
                </p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Crear plantilla</th>
            <td>
                <button type="button" id="datav_power_create_template" class="button button-secondary">
                    Crear plantilla de seguimiento
                </button>
                <span id="datav_power_template_spinner" class="spinner" style="float:none;"></span>
                <div id="datav_power_template_result" style="margin-top:10px; padding:10px; display:none;"></div>
                <p class="description">
                    Crea la plantilla en Twilio y guarda su SID. <strong>Después hay que enviarla a aprobación
                    de WhatsApp</strong> desde la consola de Twilio (Messaging &rsaquo; Content Template Builder),
                    categoría <em>Utility</em>. La aprobación no es inmediata.
                </p>
            </td>
        </tr>
    </table>

    <h2>Estado del cron</h2>
    <table class="form-table">
        <tr valign="top">
            <th scope="row">Ejecución automática</th>
            <td>
                <?php if (!$status['scheduled']) : ?>
                    <span style="color:#b32d2e;">&#10007; El evento no está programado.</span>
                    Se reprograma solo al cargar cualquier página del sitio.
                <?php elseif ($status['healthy']) : ?>
                    <span style="color:#00a32a;">&#10003; Funcionando.</span>
                    Última ejecución: <?php echo esc_html($status['last_run']); ?>
                    (hace <?php echo esc_html(round($status['minutes_since'])); ?> min).
                <?php elseif ($status['last_run']) : ?>
                    <span style="color:#dba617;">&#9888; Retrasado.</span>
                    Última ejecución: <?php echo esc_html($status['last_run']); ?>
                    (hace <?php echo esc_html(round($status['minutes_since'])); ?> min).
                    Debería correr cada 5 minutos.
                <?php else : ?>
                    <span style="color:#dba617;">&#9888; Todavía no se ha ejecutado nunca.</span>
                <?php endif; ?>
                <p class="description">Próxima ejecución prevista: <?php echo esc_html($status['next_run'] ?: 'sin programar'); ?></p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Ejecutar ahora</th>
            <td>
                <label>
                    <input type="checkbox" name="datav_power_run_now" value="1"
                           onclick="if(this.checked &amp;&amp; !confirm('Esto enviará mensajes de WhatsApp reales a los usuarios con reportes vencidos. ¿Continuar?')) this.checked = false;" />
                    Ejecutar un ciclo de seguimiento al guardar
                </label>
                <p class="description">Envía mensajes reales. Útil para probar el flujo sin esperar al cron.</p>
            </td>
        </tr>
    </table>

    <div class="notice notice-warning inline" style="margin-top: 20px;">
        <p>
            <strong>WP-Cron no es un cron real:</strong> solo se dispara cuando alguien visita el sitio, así que
            en horas de poco tráfico las confirmaciones se retrasan. Para que salgan puntuales, añade
            <code>define('DISABLE_WP_CRON', true);</code> en <code>wp-config.php</code> y programa en el servidor:
        </p>
        <p><code>*/5 * * * * curl -s <?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?> &gt;/dev/null</code></p>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('datav_power_create_template');
        var spinner = document.getElementById('datav_power_template_spinner');
        var result = document.getElementById('datav_power_template_result');
        var sidInput = document.getElementById('datav_power_followup_sid');

        btn.addEventListener('click', function () {
            btn.disabled = true;
            spinner.classList.add('is-active');
            result.style.display = 'none';

            var data = new FormData();
            data.append('action', 'datav_power_create_followup_template');
            data.append('nonce', '<?php echo esc_js(wp_create_nonce('datav_power_nonce_action')); ?>');

            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST', body: data, credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                spinner.classList.remove('is-active');
                btn.disabled = false;
                result.style.display = 'block';

                if (res.success) {
                    result.style.cssText = 'display:block;margin-top:10px;padding:10px;background:#d4edda;border:1px solid #c3e6cb;color:#155724;';
                    result.innerHTML = '<strong>SID:</strong> <code>' + res.data.sid + '</code><br>' + res.data.message;
                    sidInput.value = res.data.sid;
                } else {
                    result.style.cssText = 'display:block;margin-top:10px;padding:10px;background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;';
                    result.innerHTML = '<strong>Error:</strong> ' + res.data.message;
                }
            })
            .catch(function (err) {
                spinner.classList.remove('is-active');
                btn.disabled = false;
                result.style.cssText = 'display:block;margin-top:10px;padding:10px;background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;';
                result.innerHTML = '<strong>Error de red:</strong> ' + err.message;
            });
        });
    })();
    </script>
    <?php
}

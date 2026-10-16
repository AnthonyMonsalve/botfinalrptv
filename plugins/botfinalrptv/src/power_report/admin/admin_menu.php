<?php

/**
 * Menú "Reporte Eléctrico" en el admin de WordPress.
 *
 * Una sola página con pestañas. Cada pestaña vive en su propio archivo y expone
 * dos funciones: datav_power_tab_<slug>_save() y datav_power_tab_<slug>_render().
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/tab_settings.php';
require_once __DIR__ . '/tab_messages.php';
require_once __DIR__ . '/tab_zones.php';
require_once __DIR__ . '/tab_followup.php';
require_once __DIR__ . '/tab_comments.php';

add_action('admin_menu', 'datav_power_admin_menu');

function datav_power_admin_menu()
{
    add_menu_page(
        'Reporte Eléctrico',
        'Reporte Eléctrico',
        'manage_options',
        'datav-power',
        'datav_power_admin_page',
        'dashicons-lightbulb',
        200.2
    );
}

function datav_power_admin_tabs()
{
    return [
        'settings' => 'Ajustes',
        'zones' => 'Zonas',
        'messages' => 'Mensajes',
        'followup' => 'Seguimiento',
        'comments' => 'Comentarios',
    ];
}

function datav_power_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $tabs = datav_power_admin_tabs();

    $current = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';

    if (!isset($tabs[$current])) {
        $current = 'settings';
    }

    if (isset($_POST['datav_power_save']) && check_admin_referer('datav_power_nonce_action', 'datav_power_nonce')) {
        call_user_func('datav_power_tab_' . $current . '_save');

        add_settings_error('datav_power_messages', 'datav_power_saved', 'Cambios guardados.', 'updated');
    }

    settings_errors('datav_power_messages');
    ?>
    <div class="wrap">
        <h1>Reporte Eléctrico Nacional</h1>

        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $slug => $label) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=datav-power&tab=' . $slug)); ?>"
                   class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </h2>

        <form method="post" action="">
            <?php wp_nonce_field('datav_power_nonce_action', 'datav_power_nonce'); ?>
            <?php call_user_func('datav_power_tab_' . $current . '_render'); ?>
            <?php submit_button('Guardar cambios', 'primary', 'datav_power_save'); ?>
        </form>
    </div>
    <?php
}

/** Lee un campo de texto del POST con su valor por defecto si viene vacío. */
function datav_power_save_text_option($key, $allow_empty = false)
{
    if (!isset($_POST[$key])) {
        return;
    }

    $value = sanitize_text_field(wp_unslash($_POST[$key]));

    if ($value === '' && !$allow_empty) {
        $defaults = datav_power_settings_defaults();
        $value = isset($defaults[$key]) ? $defaults[$key] : '';
    }

    update_option($key, $value);
}

function datav_power_save_textarea_option($key)
{
    if (!isset($_POST[$key])) {
        return;
    }

    update_option($key, sanitize_textarea_field(wp_unslash($_POST[$key])));
}

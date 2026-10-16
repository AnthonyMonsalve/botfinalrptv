<?php

/**
 * Registro de envíos, que es lo que permite el tope de frecuencia.
 *
 * Sin esta tabla no habría forma de saber que a alguien ya se le mandó el
 * mensaje hace dos horas: recibiría el mismo texto tras reportar una falla,
 * tras terminar un curso y tras enviar un reporte, todo el mismo día.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DATAV_FOLLOWUP_DB_VERSION', '1.0.0');

add_action('plugins_loaded', 'datav_followup_migrate');

function datav_followup_migrate()
{
    if (get_option('datav_followup_db_version') === DATAV_FOLLOWUP_DB_VERSION) {
        return;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'datav_followup_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        phone_number varchar(50) NOT NULL,
        event varchar(40) NOT NULL,
        sent_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_recent (phone_number, sent_at),
        KEY idx_event (phone_number, event)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('datav_followup_db_version', DATAV_FOLLOWUP_DB_VERSION);
}

function datav_followup_table()
{
    global $wpdb;

    return $wpdb->prefix . 'datav_followup_log';
}

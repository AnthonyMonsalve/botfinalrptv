<?php

/**
 * Migración versionada del módulo Reporte Eléctrico.
 *
 * No se puede depender de register_activation_hook: el plugin ya está activo en
 * producción y ese hook no volverá a dispararse. Se corre en 'plugins_loaded'
 * y solo hace trabajo cuando la versión guardada difiere de la constante.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DATAV_POWER_DB_VERSION', '1.2.0');

function datav_power_migrate()
{
    if (get_option('datAv_power_db_version') === DATAV_POWER_DB_VERSION) {
        return;
    }

    datav_power_create_reports_table();
    datav_power_create_zones_table();
    datav_power_add_session_columns();

    // Solo siembra si la tabla está vacía: nunca pisa lo que el admin haya editado.
    datav_power_seed_zones();

    update_option('datAv_power_db_version', DATAV_POWER_DB_VERSION);
}

/** Tabla principal: un registro por caso de falla eléctrica. */
function datav_power_create_reports_table()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'datav_power_reports';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        phone_number varchar(50) NOT NULL,
        profile_name varchar(120) DEFAULT '' NOT NULL,
        estado_code varchar(8) DEFAULT '' NOT NULL,
        estado varchar(80) DEFAULT '' NOT NULL,
        municipio_code varchar(12) DEFAULT '' NOT NULL,
        municipio varchar(120) DEFAULT '' NOT NULL,
        parroquia_code varchar(16) DEFAULT '' NOT NULL,
        parroquia varchar(120) DEFAULT '' NOT NULL,
        direccion varchar(255) DEFAULT '' NOT NULL,
        lat decimal(10,7) DEFAULT NULL,
        lng decimal(10,7) DEFAULT NULL,
        geo_precision varchar(20) DEFAULT '' NOT NULL,
        media_url varchar(255) DEFAULT '' NOT NULL,
        comment text,
        comment_at datetime DEFAULT NULL,
        outage_since datetime DEFAULT NULL,
        restored_at datetime DEFAULT NULL,
        status varchar(20) DEFAULT 'draft' NOT NULL,
        last_confirmed_at datetime DEFAULT NULL,
        next_check_at datetime DEFAULT NULL,
        checks_sent int(11) DEFAULT 0 NOT NULL,
        unanswered_checks int(11) DEFAULT 0 NOT NULL,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_due (status, next_check_at),
        KEY idx_zone (estado_code, municipio_code, parroquia_code),
        KEY idx_outage (outage_since),
        KEY idx_phone (phone_number, status)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * División político-territorial editable desde el admin.
 *
 * Los tres niveles viven en la misma tabla enlazados por 'code'/'parent_code',
 * no por id: así el código que se guardó en un reporte sigue significando lo
 * mismo aunque la fila se borre y se vuelva a crear.
 */
function datav_power_create_zones_table()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'datav_power_zones';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        code varchar(16) NOT NULL,
        parent_code varchar(12) DEFAULT '' NOT NULL,
        level varchar(12) NOT NULL,
        name varchar(120) NOT NULL,
        capital varchar(120) DEFAULT '' NOT NULL,
        lat decimal(10,7) DEFAULT NULL,
        lng decimal(10,7) DEFAULT NULL,
        is_active tinyint(1) DEFAULT 1 NOT NULL,
        sort_order int(11) DEFAULT 0 NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_code (code),
        KEY idx_parent (parent_code, is_active, sort_order),
        KEY idx_level (level, is_active, sort_order)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Carga la división político-territorial de venezuela.php en la tabla.
 *
 * @param bool $force true reemplaza todo (botón "restaurar valores por defecto"),
 *                    false solo siembra si la tabla está vacía.
 */
function datav_power_seed_zones($force = false)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'datav_power_zones';

    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

    if ($count > 0 && !$force) {
        return 0;
    }

    if ($force) {
        $wpdb->query("TRUNCATE TABLE $table_name");
    }

    // Trae también datav_power_build_code() y datav_power_normalize_text().
    require_once __DIR__ . '/../data/geo_lookup.php';

    $inserted = 0;

    // Los estados se siembran en orden alfabético para que la lista que ve el
    // usuario en WhatsApp salga ordenada sin depender del orden del archivo.
    $estados = datav_power_venezuela_data();

    uasort($estados, function ($a, $b) {
        return strcmp(datav_power_normalize_text($a['name']), datav_power_normalize_text($b['name']));
    });

    $estado_order = 0;

    foreach ($estados as $estado_code => $estado) {
        $wpdb->insert($table_name, [
            'code' => $estado_code,
            'parent_code' => '',
            'level' => 'estado',
            'name' => $estado['name'],
            'capital' => isset($estado['capital']) ? $estado['capital'] : '',
            'lat' => isset($estado['lat']) ? $estado['lat'] : null,
            'lng' => isset($estado['lng']) ? $estado['lng'] : null,
            'is_active' => 1,
            'sort_order' => ++$estado_order,
        ]);
        $inserted++;

        foreach ($estado['municipios'] as $index => $municipio) {
            $municipio_code = datav_power_build_code($estado_code, $index);

            $wpdb->insert($table_name, [
                'code' => $municipio_code,
                'parent_code' => $estado_code,
                'level' => 'municipio',
                'name' => $municipio['name'],
                'capital' => isset($municipio['capital']) ? $municipio['capital'] : '',
                'lat' => isset($municipio['lat']) ? $municipio['lat'] : null,
                'lng' => isset($municipio['lng']) ? $municipio['lng'] : null,
                'is_active' => 1,
                'sort_order' => $index + 1,
            ]);
            $inserted++;

            if (empty($municipio['parroquias'])) {
                continue;
            }

            foreach ($municipio['parroquias'] as $p_index => $parroquia_name) {
                $wpdb->insert($table_name, [
                    'code' => datav_power_build_code($municipio_code, $p_index),
                    'parent_code' => $municipio_code,
                    'level' => 'parroquia',
                    'name' => $parroquia_name,
                    'capital' => '',
                    'lat' => null,
                    'lng' => null,
                    'is_active' => 1,
                    'sort_order' => $p_index + 1,
                ]);
                $inserted++;
            }
        }
    }

    return $inserted;
}

/**
 * Columnas de sesión conversacional en la tabla que ya usa el bot.
 *
 * Se hace con ALTER TABLE y no con dbDelta a propósito: dbDelta necesitaría la
 * definición completa de {prefix}twilio, que vive en create_database.php, y las
 * dos definiciones se pisarían entre sí en cada actualización.
 */
function datav_power_add_session_columns()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'twilio';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
        return;
    }

    $columns = [
        'power_status' => "varchar(60) DEFAULT 'not_started' NOT NULL",
        'power_step' => "varchar(40) DEFAULT '' NOT NULL",
        'power_report_id' => 'bigint(20) unsigned DEFAULT NULL',
        // Solo lo usa la caja de comentarios: pasado ese momento la sesión ya no
        // vale y el siguiente mensaje del usuario vuelve a ser un mensaje normal.
        'power_step_expires' => 'datetime DEFAULT NULL',
    ];

    $existing = $wpdb->get_col("SHOW COLUMNS FROM $table_name");

    foreach ($columns as $column => $definition) {
        if (!in_array($column, $existing, true)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
        }
    }
}

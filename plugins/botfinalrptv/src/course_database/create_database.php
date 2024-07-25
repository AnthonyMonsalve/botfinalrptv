<?php

function create_course_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'twilio';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        phone_number varchar(50) NOT NULL,
        validate_info varchar(60) DEFAULT 'not_started' NOT NULL,
        course_status varchar(60) DEFAULT 'not_started' NOT NULL,
        test1 int(1) DEFAULT 0 NOT NULL,
        test2 int(1) DEFAULT 0 NOT NULL,
        test3 int(1) DEFAULT 0 NOT NULL,
        test4 int(1) DEFAULT 0 NOT NULL,
        test5 int(1) DEFAULT 0 NOT NULL,
        test6 int(1) DEFAULT 0 NOT NULL,
        test7 int(1) DEFAULT 0 NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

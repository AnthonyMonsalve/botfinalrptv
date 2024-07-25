<?php

function get_validate_info_status($phone_number)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'twilio';
  $log_file = plugin_dir_path(__FILE__) . 'course_status_log.txt';

  // Obtener el estado actual del test
  $current_status = $wpdb->get_var($wpdb->prepare("SELECT validate_info FROM $table_name WHERE phone_number = %s", $phone_number));

  if ($current_status === null) {
    file_put_contents($log_file, "Error: No se encontró el número: $phone_number en la base de datos\n", FILE_APPEND);
    return null;
  }

  // Registrar el estado obtenido
  file_put_contents($log_file, "Estado de validate_info para el número: $phone_number es: $current_status\n", FILE_APPEND);

  return $current_status;
}

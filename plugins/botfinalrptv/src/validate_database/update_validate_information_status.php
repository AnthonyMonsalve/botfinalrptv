<?php

function update_validate_information_status($phone_number, $new_status)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'twilio_courses';
  $log_file = plugin_dir_path(__FILE__) . 'course_status_log.txt';

  // Actualizar el estado del test en la base de datos
  $updated = $wpdb->update(
    $table_name,
    array('validate_info' => $new_status),
    array('phone_number' => $phone_number)
  );

  if ($updated === false) {
    $wpdb_last_error = $wpdb->last_error;
    file_put_contents($log_file, "Error actualizando validate_info para el número: $phone_number - Error: $wpdb_last_error\n", FILE_APPEND);
    return false;
  } else {
    file_put_contents($log_file, "Estado de validate_info actualizado para el número: $phone_number a estado: $new_status\n", FILE_APPEND);
    return true;
  }
}

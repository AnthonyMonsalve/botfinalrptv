<?php

function update_test_status($phone_number, $test_name, $new_status)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'twilio';
  $log_file = plugin_dir_path(__FILE__) . 'course_status_log.txt';

  // Verificar si el test_name es válido
  $valid_tests = ['test1', 'test2', 'test3', 'test4', 'test5', 'test6', 'test7'];
  if (!in_array($test_name, $valid_tests)) {
    file_put_contents($log_file, "Error: $test_name no es un test válido\n", FILE_APPEND);
    return false;
  }

  // Actualizar el estado del test en la base de datos
  $updated = $wpdb->update(
    $table_name,
    array($test_name => $new_status),
    array('phone_number' => $phone_number)
  );

  if ($updated === false) {
    $wpdb_last_error = $wpdb->last_error;
    file_put_contents($log_file, "Error actualizando $test_name para el número: $phone_number - Error: $wpdb_last_error\n", FILE_APPEND);
    return false;
  } else {
    file_put_contents($log_file, "Estado de $test_name actualizado para el número: $phone_number a estado: $new_status\n", FILE_APPEND);
    return true;
  }
}

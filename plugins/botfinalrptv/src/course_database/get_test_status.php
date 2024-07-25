<?php

function get_test_status($phone_number, $test_name)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'twilio';
  $log_file = plugin_dir_path(__FILE__) . 'course_status_log.txt';

  // Verificar si el test_name es válido
  $valid_tests = ['test1', 'test2', 'test3', 'test4', 'test5', 'test6', 'test7'];
  if (!in_array($test_name, $valid_tests)) {
    file_put_contents($log_file, "Error: $test_name no es un test válido\n", FILE_APPEND);
    return null;
  }

  // Obtener el estado actual del test
  $current_status = $wpdb->get_var($wpdb->prepare("SELECT $test_name FROM $table_name WHERE phone_number = %s", $phone_number));

  if ($current_status === null) {
    file_put_contents($log_file, "Error: No se encontró el número: $phone_number en la base de datos\n", FILE_APPEND);
    return null;
  }

  // Registrar el estado obtenido
  file_put_contents($log_file, "Estado de $test_name para el número: $phone_number es: $current_status\n", FILE_APPEND);

  return $current_status;
}

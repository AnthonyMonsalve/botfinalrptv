<?php



function get_infomemes_progression_status($phone_number, $test_name)

{

  global $wpdb;

  $table_name = $wpdb->prefix . 'twilio';

  $log_file = plugin_dir_path(__FILE__) . 'infomemes_status_log.txt';

  // Verificar si el test_name es válido

  $valid_tests = ['infomemes_intro', 'infomemes_mod1', 'infomemes_mod1_respuesta1', 'infomemes_mod2', 'infomemes_mod2_respuesta1', 'infomemes_mod3', 'infomemes_mod3_respuesta1', 'infomemes_mod4', 'infomemes_mod4_respuesta1', 'infomemes_mod4_respuesta2', 'infomemes_mod4_cierre_modulo', 'infomemes_mod5', 'infomemes_mod5_respuesta1', 'infomemes_mod6', 'infomemes_mod6_certificado', 'infomemes_mod6_cierre', 'infomemes_mod6_cierre_2'];

  if (!in_array($test_name, $valid_tests)) {

    file_put_contents($log_file, "Error_: $test_name no es un test válido\n", FILE_APPEND);

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

<?php



// Verificar y actualizar el estado del validate

function update_status_fake($phone_number, $status)

{

  global $wpdb;

  $table_name = $wpdb->prefix . 'twilio';

  $log_file = plugin_dir_path(__FILE__) . 'fake_status_log.txt';

  // Registrar el intento de actualizar/iniciar el validate
  file_put_contents($log_file, "Intentando actualizar/iniciar el fake para el número: $phone_number con estado: $status\n", FILE_APPEND);


  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE phone_number = %s", $phone_number));



  if ($row) {

    $updated = $wpdb->update(

      $table_name,

      array('fake_status' => $status),

      array('phone_number' => $phone_number)

    );

    if ($updated === false) {

      $wpdb_last_error = $wpdb->last_error;

      file_put_contents($log_file, "Error actualizando el estado del validate para el número: $phone_number - Error: $wpdb_last_error\n", FILE_APPEND);

    } else {

      file_put_contents($log_file, "Estado del validate actualizado para el número: $phone_number a estado: $status\n", FILE_APPEND);

    }

  } else {

    $inserted = $wpdb->insert(

      $table_name,

      array(

        'phone_number' => $phone_number,

        'fake_status' => $status

      )

    );

    if ($inserted === false) {

      $wpdb_last_error = $wpdb->last_error;

      file_put_contents($log_file, "Error insertando el estado del validate para el número: $phone_number - Error: $wpdb_last_error\n", FILE_APPEND);

    } else {

      file_put_contents($log_file, "Estado del validate insertado para el número: $phone_number con estado: $status\n", FILE_APPEND);

    }

  }

}


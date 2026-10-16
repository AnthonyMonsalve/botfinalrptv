<?php

/**
 * ¿El usuario está dentro del flujo de corresponsales?
 *
 * Una sesión vencida se cierra aquí mismo y se responde que no: así el mensaje
 * que acaba de llegar sigue el camino normal del bot —que es mostrar el menú—
 * en vez de acabar reenviado a los corresponsales semanas después.
 */
function hasUserStartedValidation($phone_number)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'twilio';

  // SELECT * y no la lista de columnas: si la migración de 'validate_expires'
  // todavía no ha corrido, pedirla por nombre haría fallar la consulta entera
  // y el flujo dejaría de funcionar para todo el mundo.
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE phone_number = %s", $phone_number));

  if (!$row || $row->validate_info != 'started') {
    return false;
  }

  if (function_exists('datav_validate_is_expired') && datav_validate_is_expired($row)) {
    datav_validate_close($phone_number);

    return false;
  }

  return true;
}

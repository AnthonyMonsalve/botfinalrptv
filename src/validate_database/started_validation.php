<?php

// Verificar si el usuario ha empezado el curso
function hasUserStartedValidation($phone_number)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'twilio';

  $row = $wpdb->get_row($wpdb->prepare("SELECT validate_info FROM $table_name WHERE phone_number = %s", $phone_number));

  if ($row && $row->validate_info == 'started') {
    return true;
  } else {
    return false;
  }
}

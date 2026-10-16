<?php



// Verificar si el usuario ha empezado el curso

function hasUserStartedInfomemes($phone_number)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'twilio';
  $row = $wpdb->get_row($wpdb->prepare("SELECT infomemes_status FROM $table_name WHERE phone_number = %s", $phone_number));

  if ($row && $row->infomemes_status == 'started') {
    return true;
  } else {
    return false;
  }
}


<?php

// Función para limpiar el texto eliminando espacios, números y caracteres especiales
function sanitize_filename($text)
{
  // Convertir el texto a minúsculas
  $text = strtolower($text);
  // Eliminar números y caracteres especiales, dejando solo letras y espacios
  $text = preg_replace('/[^a-z\s]/', '', $text);
  // Eliminar espacios en blanco adicionales
  $text = trim($text);
  // Reemplazar los espacios por guiones
  $text = str_replace(' ', '-', $text);

  return $text;
}

// Función para agregar texto a una imagen
function generate_certificate_image($text)
{
  // Limpiar el texto para el nombre del archivo
  $sanitized_text = sanitize_filename($text);

  // Obtener la ruta de la carpeta de uploads
  $upload_dir = wp_upload_dir();
  $uploads_path = $upload_dir['basedir'] . '/certificates';

  // Crear el directorio de certificados si no existe
  if (!file_exists($uploads_path)) {
    mkdir($uploads_path, 0755, true);
  }

  // Ruta de la imagen precargada
  $base_image_path = plugin_dir_path(__FILE__) . 'images/base-image.jpg';

  // Crear imagen desde el archivo base
  $image = imagecreatefromjpeg($base_image_path);

  // Obtener dimensiones de la imagen
  $image_width = imagesx($image);
  $image_height = imagesy($image);

  // Definir color y fuente
  $color = imagecolorallocate($image, 0, 0, 0); // Negro
  $font_path = plugin_dir_path(__FILE__) . 'fonts/arial.ttf';
  $font_size = 90;

  // Obtener las dimensiones del texto
  $bbox = imagettfbbox($font_size, 0, $font_path, $text);
  $text_width = $bbox[2] - $bbox[0]; // Ancho del texto
  $text_height = $bbox[1] - $bbox[7]; // Alto del texto

  // Calcular las coordenadas para centrar el texto
  $x = ($image_width / 2) - ($text_width / 2);
  $y = ($image_height / 2) - ($text_height / 2);

  // Ajuste para la altura, ya que el texto empieza desde la línea base, no desde la parte superior
  $y += $text_height;

  // Agregar el texto a la imagen
  imagettftext($image, $font_size, 0, $x, $y, $color, $font_path, $text);

  // Guardar la nueva imagen en la carpeta de certificados dentro de uploads
  $output_image_path = $uploads_path . '/certificate-' . $sanitized_text . '-' . time() . '.jpg';
  imagejpeg($image, $output_image_path);

  // Liberar memoria
  imagedestroy($image);

  return $output_image_path; // Devuelve la ruta de la imagen generada
}

// Función para otros plugins que llamen a este generador
function create_certificate($text)
{
  return generate_certificate_image($text);
}

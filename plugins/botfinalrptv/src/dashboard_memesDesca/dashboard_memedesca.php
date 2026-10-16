<?php
// Función para agregar el menú de administración
function meme_generator_menu()
{
  add_menu_page(
    'Memes Generados', // Título de la página
    'Memes Infomemes', // Texto del menú
    'manage_options', // Capacidad
    'meme-generator', // Slug de la página
    'meme_generator_page', // Función que muestra el contenido de la página
    'dashicons-welcome-write-blog', // Icono del menú
    150 // Posición en el menú
  );
}
add_action('admin_menu', 'meme_generator_menu');

function meme_generator_page()
{
  echo '<div class="wrap">';
  echo '<h1>Memes Generados</h1>';
  echo '<table class="wp-list-table widefat fixed striped">';
  echo '<thead><tr><th>Nombre del Meme</th><th>Enlace</th></tr></thead>';
  echo '<tbody>';

  // Obtener la ruta del directorio de uploads
  $upload_dir = wp_upload_dir();
  $memes_dir = $upload_dir['basedir'] . '/memesDesca/';

  // Verificar que el directorio de Memes exista
  if (!is_dir($memes_dir)) {
    echo '<tr><td colspan="2">No se han generado Memes aún.</td></tr>';
  } else {
    // Obtener todos los archivos de Memes en el directorio
    $memes = glob($memes_dir . '*.jpg');

    // Listar todos los Memes generados
    if ($memes) {
      foreach ($memes as $meme) {
        $meme_name = basename($meme);
        $meme_url = $upload_dir['baseurl'] . '/memesDesca/' . $meme_name;
        echo '<tr>';
        echo '<td>' . esc_html($meme_name) . '</td>';
        echo '<td><a href="' . esc_url($meme_url) . '" target="_blank">Ver Meme</a></td>';
        echo '</tr>';
      }
    } else {
      echo '<tr><td colspan="2">No se han generado Memes aún.</td></tr>';
    }
  }

  echo '</tbody>';
  echo '</table>';
  echo '</div>';
}

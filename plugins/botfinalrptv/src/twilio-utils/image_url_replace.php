<?php 

function convert_path_to_url($file_path) {
    $upload_dir = wp_upload_dir();
    $base_url = $upload_dir['baseurl'];
    $base_dir = $upload_dir['basedir'];

    // Reemplazar la ruta base del sistema de archivos con la URL base de las subidas
    $url = str_replace($base_dir, $base_url, $file_path);

    return $url;
}

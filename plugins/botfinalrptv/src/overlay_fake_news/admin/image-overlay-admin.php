<?php
// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Función para mostrar la página de administración
function image_overlay_plugin_admin_page() {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (isset($_FILES['base_image']) && isset($_FILES['overlay_image'])) {
            $baseImagePath = $_FILES['base_image']['tmp_name'];
            $overlayImagePath = $_FILES['overlay_image']['tmp_name'];
            // Obtener la fecha y hora actuales
            $fecha = date('Y-m-d H:i:s');

            // Reemplazar los espacios y los dos puntos por guiones
            $fecha_formateada = str_replace([' ', ':'], '-', $fecha);
            $outputImagePath = wp_upload_dir()['path'] . '/result_image'.$fecha_formateada.'.jpg';

            image_overlay($baseImagePath, $overlayImagePath, $outputImagePath);

            echo '<div class="updated"><p>Imagen superpuesta guardada en: ' . $outputImagePath . '</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h2>Image Overlay Plugin</h2>
        <form method="post" enctype="multipart/form-data">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Imagen Base (cualquier formato)</th>
                    <td><input type="file" name="base_image" accept="image/*" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Imagen de Superposición (PNG)</th>
                    <td><input type="file" name="overlay_image" accept="image/png" required /></td>
                </tr>
            </table>
            <?php submit_button('Superponer Imagen'); ?>
        </form>
    </div>
    <?php
}
?>

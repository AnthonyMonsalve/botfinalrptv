<?php

/**
 * Informes de DatAvenger: los documentos que publica el equipo sobre el
 * servicio eléctrico.
 *
 * Se guardan como un tipo de contenido propio de WordPress y no en una tabla
 * aparte: así el panel de carga, los borradores, las fechas, el buscador del
 * admin y las URLs para compartir ya vienen hechos, y el equipo trabaja con la
 * pantalla de siempre en vez de con un formulario nuevo que haya que aprender.
 */

if (!defined('ABSPATH')) {
    exit;
}

const DVPE_INFORME_CPT = 'dvpe_informe';
const DVPE_INFORME_PDF = '_dvpe_pdf_url';
const DVPE_INFORME_PDF_ID = '_dvpe_pdf_id';

add_action('init', 'dvpe_register_informes');

function dvpe_register_informes()
{
    register_post_type(DVPE_INFORME_CPT, [
        'labels' => [
            'name' => 'Informes',
            'singular_name' => 'Informe',
            'add_new' => 'Añadir informe',
            'add_new_item' => 'Añadir nuevo informe',
            'edit_item' => 'Editar informe',
            'new_item' => 'Nuevo informe',
            'view_item' => 'Ver informe',
            'search_items' => 'Buscar informes',
            'not_found' => 'Todavía no hay informes publicados.',
            'not_found_in_trash' => 'No hay informes en la papelera.',
            'featured_image' => 'Imagen previa',
            'set_featured_image' => 'Elegir imagen previa',
            'menu_name' => 'Informes DatAvenger',
        ],
        'public' => true,
        'has_archive' => false,
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-media-document',
        'menu_position' => 200.3,
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
        'rewrite' => ['slug' => 'informe', 'with_front' => false],
    ]);
}

/* -------------------------------------------------------------------------
 * Panel de carga: el PDF
 * ---------------------------------------------------------------------- */

add_action('add_meta_boxes', 'dvpe_informe_meta_box');

function dvpe_informe_meta_box()
{
    add_meta_box(
        'dvpe_informe_pdf',
        'Documento del informe (PDF)',
        'dvpe_informe_meta_box_render',
        DVPE_INFORME_CPT,
        'side',
        'high'
    );
}

function dvpe_informe_meta_box_render($post)
{
    wp_nonce_field('dvpe_informe_save', 'dvpe_informe_nonce');

    $url = get_post_meta($post->ID, DVPE_INFORME_PDF, true);
    $id = (int) get_post_meta($post->ID, DVPE_INFORME_PDF_ID, true);
    $nombre = $id ? basename(get_attached_file($id)) : basename((string) $url);
    ?>
    <p>
        <input type="url" id="dvpe-pdf-url" name="dvpe_pdf_url" class="widefat code"
               placeholder="https://…/informe.pdf" value="<?php echo esc_attr($url); ?>" />
        <input type="hidden" id="dvpe-pdf-id" name="dvpe_pdf_id" value="<?php echo esc_attr($id); ?>" />
    </p>
    <p>
        <button type="button" class="button" id="dvpe-pdf-pick">Subir o elegir PDF</button>
        <button type="button" class="button-link" id="dvpe-pdf-clear"
                style="<?php echo $url ? '' : 'display:none;'; ?>">Quitar</button>
    </p>
    <p class="description" id="dvpe-pdf-name"><?php echo $nombre ? esc_html($nombre) : 'Ningún documento seleccionado.'; ?></p>
    <p class="description">
        También puedes pegar la dirección de un PDF alojado fuera de este sitio.
        La <strong>imagen previa</strong> es la imagen destacada, y el
        <strong>texto descriptivo</strong>, el contenido del editor.
    </p>
    <?php
}

add_action('admin_enqueue_scripts', 'dvpe_informe_admin_assets');

function dvpe_informe_admin_assets($hook)
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }

    if (get_post_type() !== DVPE_INFORME_CPT) {
        return;
    }

    // La biblioteca de medios de WordPress: el mismo selector de siempre.
    wp_enqueue_media();

    $script = <<<'JS'
jQuery(function ($) {
    var marco;

    $('#dvpe-pdf-pick').on('click', function (e) {
        e.preventDefault();

        if (marco) { marco.open(); return; }

        marco = wp.media({
            title: 'Elige el PDF del informe',
            button: { text: 'Usar este documento' },
            library: { type: 'application/pdf' },
            multiple: false
        });

        marco.on('select', function () {
            var archivo = marco.state().get('selection').first().toJSON();

            $('#dvpe-pdf-url').val(archivo.url);
            $('#dvpe-pdf-id').val(archivo.id);
            $('#dvpe-pdf-name').text(archivo.filename || archivo.url);
            $('#dvpe-pdf-clear').show();
        });

        marco.open();
    });

    $('#dvpe-pdf-clear').on('click', function (e) {
        e.preventDefault();
        $('#dvpe-pdf-url').val('');
        $('#dvpe-pdf-id').val('');
        $('#dvpe-pdf-name').text('Ningún documento seleccionado.');
        $(this).hide();
    });
});
JS;

    wp_add_inline_script('jquery', $script);
}

add_action('save_post_' . DVPE_INFORME_CPT, 'dvpe_informe_save', 10, 2);

function dvpe_informe_save($post_id, $post)
{
    if (!isset($_POST['dvpe_informe_nonce']) || !wp_verify_nonce($_POST['dvpe_informe_nonce'], 'dvpe_informe_save')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['dvpe_pdf_url'])) {
        update_post_meta($post_id, DVPE_INFORME_PDF, esc_url_raw(trim(wp_unslash($_POST['dvpe_pdf_url']))));
    }

    if (isset($_POST['dvpe_pdf_id'])) {
        update_post_meta($post_id, DVPE_INFORME_PDF_ID, (int) $_POST['dvpe_pdf_id']);
    }
}

/* -------------------------------------------------------------------------
 * Listado en el admin
 * ---------------------------------------------------------------------- */

add_filter('manage_' . DVPE_INFORME_CPT . '_posts_columns', 'dvpe_informe_columns');

function dvpe_informe_columns($columns)
{
    $nuevas = [];

    foreach ($columns as $clave => $etiqueta) {
        if ($clave === 'title') {
            $nuevas['dvpe_thumb'] = 'Previa';
        }

        $nuevas[$clave] = $etiqueta;

        if ($clave === 'title') {
            $nuevas['dvpe_pdf'] = 'PDF';
        }
    }

    return $nuevas;
}

add_action('manage_' . DVPE_INFORME_CPT . '_posts_custom_column', 'dvpe_informe_column_content', 10, 2);

function dvpe_informe_column_content($column, $post_id)
{
    if ($column === 'dvpe_thumb') {
        echo has_post_thumbnail($post_id)
            ? get_the_post_thumbnail($post_id, [54, 54], ['style' => 'border-radius:6px;object-fit:cover;'])
            : '—';
    }

    if ($column === 'dvpe_pdf') {
        $url = get_post_meta($post_id, DVPE_INFORME_PDF, true);

        echo $url
            ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Ver PDF</a>'
            : '<span style="color:#b32d2e;">Falta el PDF</span>';
    }
}

/* -------------------------------------------------------------------------
 * Consulta
 * ---------------------------------------------------------------------- */

/**
 * Informes publicados, del más reciente al más antiguo.
 *
 * @return array [ ['id','titulo','texto','fecha','fecha_iso','imagen','pdf','enlace'], … ]
 */
function dvpe_get_informes($limit = 20)
{
    $posts = get_posts([
        'post_type' => DVPE_INFORME_CPT,
        'post_status' => 'publish',
        'numberposts' => (int) $limit,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    $informes = [];

    foreach ($posts as $post) {
        $pdf = get_post_meta($post->ID, DVPE_INFORME_PDF, true);

        $texto = has_excerpt($post) ? get_the_excerpt($post) : wp_strip_all_tags($post->post_content);
        $texto = trim(preg_replace('/\s+/', ' ', $texto));

        $informes[] = [
            'id' => $post->ID,
            'titulo' => get_the_title($post),
            'texto' => $texto,
            'resumen' => wp_trim_words($texto, 32, '…'),
            'fecha' => date_i18n('j \d\e F \d\e Y', get_post_time('U', false, $post)),
            'fecha_iso' => get_post_time('c', true, $post),
            'imagen' => get_the_post_thumbnail_url($post, 'large'),
            'pdf' => $pdf,
            // Sin PDF, el enlace lleva a la ficha del informe en el sitio.
            'enlace' => $pdf ? $pdf : get_permalink($post),
        ];
    }

    return $informes;
}

/** El más reciente, o null si todavía no hay ninguno. */
function dvpe_latest_informe()
{
    $informes = dvpe_get_informes(1);

    return empty($informes) ? null : $informes[0];
}

/** Dirección del listado público. */
function dvpe_informes_url()
{
    return trailingslashit(dvpe_portal_url()) . 'informes/';
}

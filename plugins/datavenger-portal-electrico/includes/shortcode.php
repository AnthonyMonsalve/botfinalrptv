<?php

/**
 * Banda horizontal para invitar a leer los informes desde cualquier página.
 *
 *   [datavenger_informes]
 *   [datavenger_informes titulo="Lee el informe" cta="Descargar" ultimo="no"]
 *
 * Por defecto apunta al último informe publicado; con ultimo="no" lleva al
 * listado completo. Los estilos se cargan solo en las páginas que usan el
 * shortcode, no en todo el sitio.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('datavenger_informes', 'dvpe_informes_shortcode');

function dvpe_informes_shortcode($atts)
{
    $atts = shortcode_atts([
        'titulo' => '',
        'texto' => '',
        'cta' => 'Leer el informe',
        'kicker' => '⚡ Informes DatAvenger',
        'ultimo' => 'si',
    ], $atts, 'datavenger_informes');

    $ultimo = $atts['ultimo'] !== 'no' ? dvpe_latest_informe() : null;

    // Sin informes publicados no se pinta nada: mejor un hueco que una banda
    // que lleva a una página vacía.
    if ($atts['ultimo'] !== 'no' && !$ultimo) {
        return '';
    }

    $titulo = $atts['titulo'] !== ''
        ? $atts['titulo']
        : ($ultimo ? $ultimo['titulo'] : 'Informes sobre el servicio eléctrico');

    $texto = $atts['texto'] !== ''
        ? $atts['texto']
        : ($ultimo
            ? $ultimo['resumen']
            : 'Análisis de DatAvenger elaborados con los reportes que envía la ciudadanía por WhatsApp.');

    $enlace = $ultimo ? $ultimo['enlace'] : dvpe_informes_url();
    $externo = $ultimo && !empty($ultimo['pdf']);

    wp_enqueue_style('dvpe-informes', DVPE_URL . 'assets/informes.css', [], DVPE_VERSION);

    ob_start();
    ?>
    <div class="dvpe-strip" style="--dvpe-strip-accent: <?php echo esc_attr(dvpe_setting('dvpe_accent')); ?>;">
        <img class="dvpe-strip__mascot" src="<?php echo esc_url(DVPE_URL . 'assets/datavenger.png'); ?>" alt="" />

        <div class="dvpe-strip__body">
            <span class="dvpe-strip__kicker"><?php echo esc_html($atts['kicker']); ?></span>
            <span class="dvpe-strip__title"><?php echo esc_html($titulo); ?></span>
            <span class="dvpe-strip__text"><?php echo esc_html($texto); ?></span>
        </div>

        <a class="dvpe-strip__cta" href="<?php echo esc_url($enlace); ?>"
           <?php echo $externo ? 'target="_blank" rel="noopener"' : ''; ?>>
            <?php echo esc_html($atts['cta']); ?>
        </a>
    </div>
    <?php

    return ob_get_clean();
}

/**
 * Marcado del aviso que sube desde abajo en el portal.
 * Devuelve '' si no hay informes o si el aviso está desactivado.
 */
function dvpe_nudge_markup()
{
    if (dvpe_setting('dvpe_nudge_enabled') !== '1') {
        return '';
    }

    $informe = dvpe_latest_informe();

    if (!$informe) {
        return '';
    }

    $externo = !empty($informe['pdf']);

    ob_start();
    ?>
    <aside class="dvpe-nudge" id="dvpe-nudge" hidden>
        <button type="button" class="dvpe-nudge__close" id="dvpe-nudge-close"
                aria-label="Cerrar el aviso">&times;</button>

        <img class="dvpe-nudge__mascot" src="<?php echo esc_url(DVPE_URL . 'assets/datavenger.png'); ?>" alt="" />

        <div class="dvpe-nudge__body">
            <span class="dvpe-nudge__kicker">Último informe</span>
            <span class="dvpe-nudge__title"><?php echo esc_html($informe['titulo']); ?></span>
        </div>

        <a class="dvpe-btn" href="<?php echo esc_url($informe['enlace']); ?>"
           <?php echo $externo ? 'target="_blank" rel="noopener"' : ''; ?>>Leerlo</a>
    </aside>
    <?php

    return ob_get_clean();
}

<?php

/**
 * Proveedor de sitemap para la ruta del portal.
 *
 * Se carga tarde y solo si WordPress trae su sistema de sitemaps (5.5+), por eso
 * vive en su propio archivo: la clase base no existe hasta que WordPress está
 * completamente cargado.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DVPE_Sitemap_Provider extends WP_Sitemaps_Provider
{
    public function __construct()
    {
        $this->name = 'dvpe-portal';
        $this->object_type = 'dvpe-portal';
    }

    /**
     * Una sola URL: la del portal.
     *
     * No se añade 'lastmod' a propósito. Los datos cambian cada pocos minutos y
     * anunciar eso solo conseguiría que el rastreador vuelva sin encontrar nada
     * nuevo en el HTML, que es siempre el mismo.
     */
    public function get_url_list($page_num, $object_subtype = '')
    {
        return [
            ['loc' => dvpe_portal_url()],
        ];
    }

    public function get_max_num_pages($object_subtype = '')
    {
        return 1;
    }
}

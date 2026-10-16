<?php

/**
 * Pestaña "Comentarios": los mensajes que la gente deja cuando su caso se cierra.
 *
 * Aquí se leen, se moderan (borrar uno concreto) y se decide si el portal puede
 * publicarlos. El texto lo escribió una persona por WhatsApp, así que se pinta
 * siempre escapado.
 */

if (!defined('ABSPATH')) {
    exit;
}

const DATAV_POWER_COMMENTS_PER_PAGE = 30;

function datav_power_tab_comments_save()
{
    update_option('datAv_power_comments_enabled', isset($_POST['datAv_power_comments_enabled']) ? '1' : '0');
    update_option('datAv_power_comments_public', isset($_POST['datAv_power_comments_public']) ? '1' : '0');

    if (isset($_POST['datAv_power_comment_minutes'])) {
        $minutes = min(1440, max(5, (int) $_POST['datAv_power_comment_minutes']));
        update_option('datAv_power_comment_minutes', (string) $minutes);
    }

    // Borrar es la única acción destructiva de la pestaña y va con su propio
    // campo, no con el botón de guardar: así no se borra nada sin querer.
    if (!empty($_POST['datav_power_delete_comment'])) {
        datav_power_report_delete_comment((int) $_POST['datav_power_delete_comment']);

        add_settings_error('datav_power_messages', 'datav_power_comment_deleted', 'Comentario eliminado.', 'updated');
    }
}

function datav_power_tab_comments_render()
{
    $page = isset($_GET['cpage']) ? max(1, (int) $_GET['cpage']) : 1;
    $offset = ($page - 1) * DATAV_POWER_COMMENTS_PER_PAGE;

    $total = datav_power_comments_count();
    $comments = datav_power_reports_with_comments(DATAV_POWER_COMMENTS_PER_PAGE, $offset);
    ?>
    <table class="form-table">
        <tr valign="top">
            <th scope="row">Pedir un mensaje al cerrar el caso</th>
            <td>
                <label>
                    <input type="checkbox" name="datAv_power_comments_enabled" value="1"
                        <?php checked(datav_power_comments_enabled()); ?> />
                    Invitar a dejar un comentario cuando vuelve la luz o cuando el caso se cierra solo
                </label>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Plazo para responder</th>
            <td>
                <input type="number" name="datAv_power_comment_minutes" min="5" max="1440" step="5"
                       value="<?php echo esc_attr(datav_power_comment_minutes()); ?>" class="small-text" /> minutos
                <p class="description">
                    Pasado el plazo, lo que escriba esa persona vuelve a ser un mensaje normal del bot
                    y le responde con el menú, en vez de guardarse como comentario.
                </p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Publicar en el portal</th>
            <td>
                <label>
                    <input type="checkbox" name="datAv_power_comments_public" value="1"
                        <?php checked(datav_power_comments_public()); ?> />
                    Servir los comentarios en la API pública para que el portal los muestre
                </label>
                <p class="description">
                    El portal los lee de
                    <code><?php echo esc_html(rest_url(DATAV_POWER_API_NAMESPACE . '/power/comments')); ?></code>.
                    Nunca se publica el teléfono ni el nombre de quien escribe.
                </p>
            </td>
        </tr>
    </table>

    <h2>Comentarios recibidos (<?php echo esc_html($total); ?>)</h2>

    <?php // Lo llena el botón "Eliminar" de cada fila justo antes de enviar. ?>
    <input type="hidden" id="datav_power_delete_comment" name="datav_power_delete_comment" value="" />

    <?php if (empty($comments)) : ?>
        <p class="description">Todavía no hay comentarios.</p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Fecha</th>
                    <th style="width: 220px;">Zona</th>
                    <th>Mensaje</th>
                    <th style="width: 130px;">Falla</th>
                    <th style="width: 90px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comments as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->comment_at); ?></td>
                        <td><?php echo esc_html(datav_power_report_zone_label($row)); ?></td>
                        <td><?php echo esc_html($row->comment); ?></td>
                        <td>
                            <?php echo esc_html(datav_power_format_duration(datav_power_report_duration_seconds($row))); ?>
                            <br /><span class="description"><?php echo esc_html($row->status); ?></span>
                        </td>
                        <td>
                            <?php // Se envía con el mismo name del botón de guardar, que es lo que
                            // dispara el save de la pestaña; el id viaja en el campo oculto. ?>
                            <button type="submit" class="button button-link-delete"
                                    name="datav_power_save" value="1"
                                    onclick="if (!confirm('¿Eliminar este comentario? No se puede deshacer.')) { return false; } document.getElementById('datav_power_delete_comment').value = '<?php echo esc_js($row->id); ?>';">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $pages = (int) ceil($total / DATAV_POWER_COMMENTS_PER_PAGE);

        if ($pages > 1) :
            ?>
            <p style="margin-top: 12px;">
                <?php
                echo paginate_links([
                    'base' => admin_url('admin.php?page=datav-power&tab=comments&cpage=%#%'),
                    'format' => '',
                    'current' => $page,
                    'total' => $pages,
                ]);
                ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}

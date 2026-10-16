<?php

/**
 * Pestaña "Zonas": edición de la división político-territorial que el bot ofrece.
 *
 * Se navega un nivel a la vez (estados -> municipios -> parroquias) porque son
 * más de 500 filas y una sola tabla plana sería inmanejable. El nivel visible se
 * pasa en ?parent=<code>.
 */

if (!defined('ABSPATH')) {
    exit;
}

function datav_power_zones_current_parent()
{
    return isset($_GET['parent']) ? sanitize_text_field(wp_unslash($_GET['parent'])) : '';
}

function datav_power_tab_zones_save()
{
    $parent = datav_power_zones_current_parent();

    // Restaurar los valores de fábrica descarta cualquier edición: se pide
    // confirmación explícita en el navegador antes de llegar aquí.
    if (!empty($_POST['datav_power_restore_zones'])) {
        $count = datav_power_seed_zones(true);
        datav_power_geo_flush_cache();

        add_settings_error(
            'datav_power_messages',
            'datav_power_zones_restored',
            "Se restauró la división territorial por defecto ($count zonas).",
            'updated'
        );

        return;
    }

    if (!empty($_POST['zone']) && is_array($_POST['zone'])) {
        foreach (wp_unslash($_POST['zone']) as $code => $fields) {
            $code = sanitize_text_field($code);

            if (!datav_power_zone_find($code)) {
                continue;
            }

            if (!empty($fields['delete'])) {
                datav_power_zone_delete($code);

                continue;
            }

            $name = sanitize_text_field($fields['name']);

            // Un nombre vacío borraría la opción de la lista del bot sin que se
            // note; se ignora el cambio y se conserva el nombre anterior.
            if ($name === '') {
                continue;
            }

            datav_power_zone_update($code, [
                'name' => $name,
                'capital' => sanitize_text_field($fields['capital']),
                'lat' => datav_power_parse_coord($fields['lat']),
                'lng' => datav_power_parse_coord($fields['lng']),
                'is_active' => empty($fields['is_active']) ? 0 : 1,
                'sort_order' => (int) $fields['sort_order'],
            ]);
        }
    }

    if (!empty($_POST['new_zone']) && is_array($_POST['new_zone'])) {
        foreach (wp_unslash($_POST['new_zone']) as $fields) {
            if (trim($fields['name']) === '') {
                continue;
            }

            datav_power_zone_create(
                $parent,
                sanitize_text_field($fields['name']),
                sanitize_text_field($fields['capital']),
                datav_power_parse_coord($fields['lat']),
                datav_power_parse_coord($fields['lng'])
            );
        }
    }

    datav_power_geo_flush_cache();
}

/** '' o basura -> null, para no guardar 0,0 (que es un punto en el Atlántico). */
function datav_power_parse_coord($value)
{
    $value = trim((string) $value);

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function datav_power_tab_zones_render()
{
    $parent = datav_power_zones_current_parent();
    $level = datav_power_child_level($parent);
    $levels = datav_power_zone_levels();
    $rows = datav_power_zone_children($parent);
    $trail = $parent === '' ? [] : datav_power_zone_breadcrumb($parent);

    $base_url = admin_url('admin.php?page=datav-power&tab=zones');
    $shows_capital = $level !== 'parroquia';
    ?>
    <p style="margin: 15px 0;">
        <a href="<?php echo esc_url($base_url); ?>">Estados</a>
        <?php foreach ($trail as $index => $zone) : ?>
            &rsaquo;
            <?php if ($index === count($trail) - 1) : ?>
                <strong><?php echo esc_html($zone->name); ?></strong>
            <?php else : ?>
                <a href="<?php echo esc_url($base_url . '&parent=' . rawurlencode($zone->code)); ?>">
                    <?php echo esc_html($zone->name); ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </p>

    <p class="description">
        Editando <strong><?php echo esc_html(strtolower($levels[$level])); ?></strong>.
        Una zona <em>inactiva</em> deja de ofrecerse en WhatsApp pero conserva su histórico.
        Las coordenadas ubican el pin en el mapa cuando el usuario no comparte su GPS;
        déjalas en blanco y se usará el punto del nivel superior.
    </p>

    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th style="width: 70px;">Orden</th>
                <th><?php echo esc_html($levels[$level]); ?></th>
                <?php if ($shows_capital) : ?><th>Capital</th><?php endif; ?>
                <th style="width: 110px;">Latitud</th>
                <th style="width: 110px;">Longitud</th>
                <th style="width: 70px;">Activa</th>
                <th style="width: 90px;">Contenido</th>
                <th style="width: 70px;">Borrar</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="8">Todavía no hay zonas en este nivel. Agrega una abajo.</td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $row) :
                $field = 'zone[' . $row->code . ']';
                $children = $level === 'parroquia' ? 0 : datav_power_zone_children_count($row->code);
                ?>
                <tr>
                    <td>
                        <input type="number" name="<?php echo esc_attr($field); ?>[sort_order]"
                               value="<?php echo esc_attr($row->sort_order); ?>" style="width: 60px;" />
                    </td>
                    <td>
                        <input type="text" name="<?php echo esc_attr($field); ?>[name]"
                               value="<?php echo esc_attr($row->name); ?>" class="regular-text" />
                        <br /><code style="font-size: 11px;"><?php echo esc_html($row->code); ?></code>
                    </td>
                    <?php if ($shows_capital) : ?>
                        <td>
                            <input type="text" name="<?php echo esc_attr($field); ?>[capital]"
                                   value="<?php echo esc_attr($row->capital); ?>" />
                        </td>
                    <?php else : ?>
                        <input type="hidden" name="<?php echo esc_attr($field); ?>[capital]"
                               value="<?php echo esc_attr($row->capital); ?>" />
                    <?php endif; ?>
                    <td>
                        <input type="text" name="<?php echo esc_attr($field); ?>[lat]"
                               value="<?php echo esc_attr($row->lat); ?>" style="width: 100px;" />
                    </td>
                    <td>
                        <input type="text" name="<?php echo esc_attr($field); ?>[lng]"
                               value="<?php echo esc_attr($row->lng); ?>" style="width: 100px;" />
                    </td>
                    <td style="text-align: center;">
                        <input type="checkbox" name="<?php echo esc_attr($field); ?>[is_active]" value="1"
                            <?php checked((int) $row->is_active, 1); ?> />
                    </td>
                    <td>
                        <?php if ($level === 'parroquia') : ?>
                            &mdash;
                        <?php else : ?>
                            <a href="<?php echo esc_url($base_url . '&parent=' . rawurlencode($row->code)); ?>">
                                <?php echo esc_html($children); ?> zona<?php echo $children === 1 ? '' : 's'; ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <input type="checkbox" name="<?php echo esc_attr($field); ?>[delete]" value="1"
                               title="Al guardar se borra esta zona y todo lo que contiene." />
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < 3; $i++) : ?>
                <tr style="background: #f6f7f7;">
                    <td>&mdash;</td>
                    <td>
                        <input type="text" name="new_zone[<?php echo (int) $i; ?>][name]"
                               placeholder="Agregar <?php echo esc_attr(strtolower($levels[$level])); ?>…"
                               class="regular-text" />
                    </td>
                    <?php if ($shows_capital) : ?>
                        <td><input type="text" name="new_zone[<?php echo (int) $i; ?>][capital]" placeholder="Capital" /></td>
                    <?php else : ?>
                        <input type="hidden" name="new_zone[<?php echo (int) $i; ?>][capital]" value="" />
                    <?php endif; ?>
                    <td><input type="text" name="new_zone[<?php echo (int) $i; ?>][lat]" placeholder="10.6427" style="width: 100px;" /></td>
                    <td><input type="text" name="new_zone[<?php echo (int) $i; ?>][lng]" placeholder="-71.6125" style="width: 100px;" /></td>
                    <td colspan="3">&mdash;</td>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <p style="margin-top: 20px;">
        <label>
            <input type="checkbox" name="datav_power_restore_zones" value="1"
                   onclick="if(this.checked &amp;&amp; !confirm('Esto borra TODAS las zonas y vuelve a cargar la división territorial de fábrica. Se perderán las que hayas agregado o editado. ¿Continuar?')) this.checked = false;" />
            Restaurar la división territorial por defecto al guardar
        </label>
    </p>
    <?php
}

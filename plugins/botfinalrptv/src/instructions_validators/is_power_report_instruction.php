<?php

/**
 * Activación del flujo Reporte Eléctrico.
 *
 * A diferencia del resto de validadores, las palabras clave no están escritas
 * aquí: se leen de la opción datAv_power_keywords para que el administrador
 * pueda cambiarlas desde WordPress sin tocar código.
 */

function isPowerReportInstruction($instruction)
{
    if (!function_exists('datav_power_is_enabled') || !datav_power_is_enabled()) {
        return false;
    }

    $normalized = datav_power_normalize_text($instruction);

    if ($normalized === '') {
        return false;
    }

    return in_array($normalized, datav_power_keywords(), true);
}

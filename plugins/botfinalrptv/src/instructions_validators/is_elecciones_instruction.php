<?php

function isEleccionesInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $eleccionesInstructions = [
    '1', 'elecciones', 'elecciones 2024', 'maria corina', 'maría corina', 'lo más reciente', 'presidenciales'
  ];

  foreach ($eleccionesInstructions as $eleccion) {
    if (strpos($instruction, $eleccion) !== false) {
      return true;
    }
  }

  return false; // Retorna false explícitamente si no hay coincidencias
}

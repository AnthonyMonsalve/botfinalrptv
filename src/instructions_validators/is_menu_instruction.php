<?php

function isMenuInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $menuInstructions = [
    'republica',
    'república',
    'República',
    'Republica',
    'REPÚBLICA',
    'rEPÚBLICA',
    'rEPUBLICA',
    'REPUBLICA',
    'menú',
    'menu',
    'menu principal',
    'ver menú principal',
    'mostrar menú principal',
    'quiero ver el menú principal',
    'menú principal',
    'regresar al menú principal',
    'volver al menú principal',
    'mostrar el menú principal',
    'quiero ver menú principal',
    'mostrar menú',
    'ver menú',
    'ir al menú principal',
    'menú inicial',
    'inicio',
    'menú de inicio',
    'inicio del menú',
    'ir al inicio',
    'principal',
    'regresar al inicio',
    'volver al inicio',
    'dame el menú principal',
    'mostrar opciones principales',
    'ver opciones principales',
    'menú de opciones',
    'opciones principales',
    'mostrar menú de opciones'
  ];

  if (in_array($instruction, $menuInstructions)) {
    return true;
  }
}

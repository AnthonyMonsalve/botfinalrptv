
<?php

function isEleccionesInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $EleccionesInstruction = [
    '1', 'elecciones', 'elecciones 2024', 'maria corina', 'maría corina', 'lo más reciente', 'presidenciales'
  ];

  if (in_array($instruction, $EleccionesInstruction)) {
    return true;
  }
}

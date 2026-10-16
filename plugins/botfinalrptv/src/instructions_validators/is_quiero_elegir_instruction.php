<?php

function isQuieroElegirInstruction($instruction)
{
  $instruction = strtolower(trim($instruction));

  $quieroElegirInstructions = [
    '7',
    'quiero elegir',
    'quieroelegir',
    'quiero votar',
    'volver a elegir',
    'quiero decidir',
    'elegir',
    'condiciones para elegir',
    'exigencias',
    'exigir condiciones'
  ];

  if (in_array($instruction, $quieroElegirInstructions)) {
    return true;
  }
}

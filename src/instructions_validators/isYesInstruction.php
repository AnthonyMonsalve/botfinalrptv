<?php

function isYesInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $ExitCourseInstruction = [
    'si', 'sí', 'yes', 'y', '1', 'acepto', 'aceptar', 'continuar', 'continuar con el curso', 'continuar con el entrenamiento', 'continuar con la lección', 'continuar con el módulo', 'continuar con el curso interactivo', 'continuar con el entrenamiento interactivo', 'continuar con la lección interactiva', 'continuar con el módulo interactivo', 'continuar con el curso interactivo', 'continuar con el entrenamiento interactivo', 'continuar con la lección interactiva', 'continuar con el módulo interactivo', 'continuar con el curso interactivo', 'continuar con el entrenamiento interactivo', 'continuar con la lección interactiva', 'continuar con el módulo interactivo', 'continuar con el curso interactivo', 'continuar con el entrenamiento interactivo', 'continuar con la lección interactiva', 'continuar con el módulo interactivo'
  ];

  if (in_array($instruction, $ExitCourseInstruction)) {
    return true;
  }
}

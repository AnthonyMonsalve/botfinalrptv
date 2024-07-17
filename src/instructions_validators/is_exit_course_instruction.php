<?php

function isExitCourseInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $ExitCourseInstruction = [
    'salir', 'terminar curso', 'fin', '0', 'salir del curso', '2', 'no', 'terminar', 'terminar validacion'
  ];

  if (in_array($instruction, $ExitCourseInstruction)) {
    return true;
  }
}

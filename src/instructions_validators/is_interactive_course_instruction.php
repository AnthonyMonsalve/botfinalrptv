<?php

function isInteractiveCourseInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $interactiveCourseInstructions = [
    '3',
    'empezar curso interactivo',
    'quiero empezar el curso interactivo',
    'iniciar curso interactivo',
    'comenzar curso interactivo',
    'empezar curso',
    'iniciar curso',
    'comenzar curso',
    'quiero empezar el curso',
    'quiero iniciar el curso',
    'quiero comenzar el curso',
    'curso interactivo',
    'curso',
    'empezar entrenamiento interactivo',
    'iniciar entrenamiento interactivo',
    'comenzar entrenamiento interactivo',
    'empezar entrenamiento',
    'iniciar entrenamiento',
    'comenzar entrenamiento',
    'quiero empezar el entrenamiento',
    'quiero iniciar el entrenamiento',
    'quiero comenzar el entrenamiento',
    'interactivo',
    'empezar lección interactiva',
    'iniciar lección interactiva',
    'comenzar lección interactiva',
    'empezar lección',
    'iniciar lección',
    'comenzar lección',
    'quiero empezar la lección',
    'quiero iniciar la lección',
    'quiero comenzar la lección',
    'lección interactiva',
    'lección',
    'empezar módulo interactivo',
    'iniciar módulo interactivo',
    'comenzar módulo interactivo',
    'empezar módulo',
    'iniciar módulo',
    'comenzar módulo',
    'quiero empezar el módulo',
    'quiero iniciar el módulo',
    'quiero comenzar el módulo',
    'módulo interactivo',
    'módulo'
  ];

  if (in_array($instruction, $interactiveCourseInstructions)) {
    return true;
  }
}

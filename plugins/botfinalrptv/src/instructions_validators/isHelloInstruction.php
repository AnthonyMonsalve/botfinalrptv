<?php

function isHelloInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $HelloInstructions = [
    'datavenger', 'Datavenger', 'DATAVENGER', 'DatAvenger', 'DataAvenger', 'dataavenger', 'Dataavenger', 'dataAvenger', 'hola', 'HOLA', 'Hola', 'hOLA', 'Hola', 'Holaa', 'hi', 'hello', 'Hello', 'helloo', 'Helloo', '.', 'DatAvenger 🦸🏻‍♂🦸🏽 me gustaría unirme a la liga de #HeroesXLaInformacion',
    'hola',
    'buenos días',
    'buenas tardes',
    'buenas noches',
    'qué tal',
    'cómo estás',
    'saludos',
    'hey',
    'buen día',
    'hi',
    'hello',
    'holi',
    'saludo',
    'qué onda',
    'qué hay',
    'qué pasa',
    'qué hubo',
    'hola, qué tal',
    'hola, cómo estás',
    'buenas',
    'muy buenas',
    'buenas a todos',
    'holaaa',
    'holaaaa',
    'hola hola'
  ];

  if (in_array($instruction, $HelloInstructions)) {
    return true;
  }
}

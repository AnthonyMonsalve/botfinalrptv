<?php



function isFakenewsGeneratorInstruction($instruction)

{

  $instruction = strtolower($instruction);



  $eleccionesInstructions = [

    '#fakegenerator'

  ];



  foreach ($eleccionesInstructions as $eleccion) {

    if (strpos($instruction, $eleccion) !== false) {

      return true;

    }

  }



  return false; // Retorna false explícitamente si no hay coincidencias

}


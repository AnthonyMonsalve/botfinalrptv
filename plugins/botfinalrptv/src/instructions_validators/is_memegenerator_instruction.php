<?php

function isMemegeneratorInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $memegeneratorInstructions = [
    '6',
    'memegenerator',
    'meme generator',
    'crear meme',
    'crea un meme',
    'quiero crear un meme',
    'hacer meme',
    'meme'
  ];

  if (in_array($instruction, $memegeneratorInstructions)) {
    return true;
  }
}

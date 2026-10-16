<?php

function isInteractiveInfomemesInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $interactiveCourseInstructions = [
    'infomemes'
  ];

  if (in_array($instruction, $interactiveCourseInstructions)) {
    return true;
  }
}

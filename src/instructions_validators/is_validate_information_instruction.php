<?php

function isValidateInformationInstruction($instruction)
{
    $instruction = strtolower($instruction);

    $interactiveCourseInstructions = [
        '3',
        'validar informacion',
        'validar información',
        'validar information',
        'validar info',
    ];

    if (in_array($instruction, $interactiveCourseInstructions)) {
        return true;
    }
}

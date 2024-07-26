<?php

function isValidateInformationInstruction($instruction)
{
    $instruction = strtolower($instruction);

    $interactiveCourseInstructions = [
        '2',
        'validar informacion',
        'validar información',
        'validar information',
        'validar info',
        'denunciar',
        'denuncia',
        'denunciar'
    ];

    if (in_array($instruction, $interactiveCourseInstructions)) {
        return true;
    }
}

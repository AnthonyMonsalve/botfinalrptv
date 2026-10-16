<?php

function isValidateInformationInstruction($instruction)
{
    $instruction = strtolower($instruction);

    $interactiveCourseInstructions = [
        '1',
        'validar informacion',
        'validar información',
        'validar information',
        'validar info',
        'denunciar',
        'denuncia',
        'denunciar',
		'fact checking',
        'enviar un reporte',
    ];

    if (in_array($instruction, $interactiveCourseInstructions)) {
        return true;
    }
}

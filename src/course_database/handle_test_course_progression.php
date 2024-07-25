<?php

function handle_test_course_progression($messageReceived, $from, $to, $client)
{
    $tests = [
        'test1' => ['response' => 'test2.txt', 'correct' => '', 'incorrect' => ''],
        'test2' => ['response' => 'test3.txt', 'correct' => '', 'incorrect' => ''],
        'test3' => [
            'response' => 'test4.txt',
            'correct' => '✅ *¡Correcto!* Toda información falsa presentada como verdadera es *desinformación.*',
            'incorrect' => '❌ *Incorrecto.* La opción *B* corresponde a _información errónea_, y la opción *C* se trata de _información maliciosa_',
            'correct_option' => 'a'
        ],
        'test4' => [
            'response' => 'test5.txt',
            'correct' => '✅ *¡Correcto!* Recuerda que es importante verificar la *fuente* para asegurarnos de que _la información es fiable._',
            'incorrect' => '❌ *Incorrecto.* Recuerda que buscar la noticia en varias fuentes confiables puede ayudarte a asegurar que una información es veraz.',
            'correct_option' => 'b'
        ],
        'test5' => [
            'response' => 'test6.txt',
            'correct' => '✅ *¡Correcto!* Los memes son *formatos creativos para presentar hechos*, pero no olvides que es fundamental investigar por tu cuenta para contrastar las afirmaciones.',
            'incorrect' => '❌ *Incorrecto.* Algunos memes _pueden ayudar a informar_. Ya sabes lo que dicen: la gente *aprende mientras se ríe* :).',
            'correct_option' => 'c'
        ],
        'test6' => [
            'response' => 'test7.txt',
            'correct' => '✅ *¡Correcto!* Todos debemos verificar las informaciones que nos llegan, independientemente del tema o nuestra edad.',
            'incorrect' => '❌ *Incorrecto.* La respuesta correcta es A: los titulares o contenidos sensacionalistas y capaces de llamar nuestra atención pueden ser una distorsión de la realidad. ¡Estemos *atentos*! 🔋',
            'correct_option' => 'a'
        ],
        'test7' => [
            'response' => 'testFinal.txt',
            'correct' => '✅ *¡Correcto!* Puedes verificar la autenticidad de una imagen o video en Google Imágenes y TinEye *(al final te compartiremos el link)*',
            'incorrect' => '❌ *Incorrecto.* Te recomendamos usar Google Imágenes y TinyEye para verificar imágenes y videos.',
            'correct_option' => 'b',
            'final_message' => 'certificado.txt'
        ]
    ];

    $certificado = 'https://latvcalle.com/wp-content/uploads/2024/07/Certificado_Heroe1.png';

    foreach ($tests as $test => $data) {
        if (!get_test_status($from, $test)) {
            if ($test == 'test1' || $test == 'test2' || is_valid_option_test($messageReceived)) {
                update_test_status($from, $test, 1);

                if (isset($data['correct_option']) && strtolower($messageReceived) == $data['correct_option']) {
                    $dontHaveError = send_twilio_message($to, $from, $data['correct'], $client);
                } elseif (isset($data['correct_option'])) {
                    $dontHaveError = send_twilio_message($to, $from, $data['incorrect'], $client);
                }

                if (isset($data['final_message'])) {
                    $dontHaveError = send_twilio_message($to, $from, get_message($data['final_message']), $client, $certificado);
                    logout_course($from);
                }

                $dontHaveError = send_twilio_message($to, $from, get_message($data['response']), $client);

                return;
            }
        }
    }

    $dontHaveError = send_twilio_message($to, $from, get_message('respuesta_incorrecta_curso.txt'), $client);

    return $dontHaveError;
}

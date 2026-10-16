<?php

require_once __DIR__ . '/../../certificate_infomemes/generate_certificate.php';


function handle_test_infomemes_progression($messageReceived, $from, $to, $client, $MediaUrl = null)
{
    $infomemes_progression = [
        'infomemes_intro' => [
            'response' => [],
            'next' => true,
            'alternative' => [
                'response' => ['infomemes/introduccion/mas-info-1.txt', 'infomemes/introduccion/mas-info-2.txt', 'infomemes/introduccion/mas-info-3.txt'],
                'next' => true,
                'correct_option' => ['1'],
            ],
            'correct_option' => ['1'],
            'alternative_option' => ['2']
        ],
        'infomemes_mod1' => [
            'response' => ['infomemes/modulo1/modulo.txt', 'infomemes/modulo1/info-1.txt', 'infomemes/modulo1/pregunta-1.txt'],
            'next' => true,
            'alternative' => true,
        ],
        'infomemes_mod1_respuesta1' => [
            'response' => [],
            'next' => [
                'response' => ['infomemes/modulo1/respuesta-pregunta-1.txt'],
                'next' => true
            ],
            'alternative' => [
                'response' => ['infomemes/modulo1/respuesta-pregunta-1.txt'],
                'next' => true
            ],
            'continue_after_next' => true,
            'continue_after_alternative' => true,
            'correct_option' => ['1'],
            'alternative_option' => ['2'],
            'alternative_option_is_correct_too' => true,
        ],
        'infomemes_mod2' => [
            'response' => ['infomemes/modulo2/modulo.txt', 'infomemes/modulo2/info-1.txt', 'infomemes/modulo2/info-2.txt', 'infomemes/modulo2/info-3.txt', 'infomemes/modulo2/pregunta-1.txt'],
            'next' => true,
            'alternative' => true,
        ],
        'infomemes_mod2_respuesta1' => [
            'response' => [],
            'next' => [
                'response' => ['infomemes/modulo2/respuesta-salud-pregunta-1.txt', 'infomemes/modulo2/respuesta-educacion-pregunta-1.txt', 'infomemes/modulo2/respuesta-ambiente-pregunta-1.txt', 'infomemes/modulo2/respuesta-trabajo-pregunta-1.txt'],
                'next' => true
            ],
            'correct_option' => ['1', '2', '3', '4'],
            'continue_after_next' => true,
        ],
        'infomemes_mod3' => [
            'response' => ['infomemes/modulo3/modulo.txt', 'infomemes/modulo3/info-1.txt', 'infomemes/modulo3/info-2.txt', 'infomemes/modulo3/info-3.txt', 'infomemes/modulo3/pregunta-1.txt'],
            'next' => true,
            'alternative' => true,
        ],
        'infomemes_mod3_respuesta1' => [
            'response' => [],
            'next' => [
                'response' => ['infomemes/modulo3/respuesta-ejemplo-pregunta-1.txt'],
                'next' => true
            ],
            'alternative' => [
                'response' => ['infomemes/modulo3/respuesta-sin-ejemplo-pregunta-1.txt'],
                'next' => true
            ],
            'memeUrl' => 'https://larepublicaweb.com/wp-content/uploads/2024/09/meme-generator-1.png',
            'continue_after_next' => true,
            'continue_after_alternative' => true,
            'correct_option' => ['1'],
            'alternative_option' => ['2'],
            'alternative_option_is_correct_too' => true,
        ],
        'infomemes_mod4' => [
            'response' => ['infomemes/modulo4/modulo.txt', 'infomemes/modulo4/pregunta-1.txt'],
            'next' => true,
            'alternative' => true,
			'exit' => true,
        ],
        'infomemes_mod4_respuesta1' => [
            'response' => [],
            'next' => [
                'response' => ['infomemes/modulo4/respuesta-ambiente-pregunta-1.txt', 'infomemes/modulo4/respuesta-trabajo-pregunta-1.txt', 'infomemes/modulo4/respuesta-salud-pregunta-1.txt', 'infomemes/modulo4/respuesta-educacion-pregunta-1.txt'],
                'next' => true
            ],
            'correct_option' => ['1', '2', '3', '4'],
            'continue_after_next' => true,
        ],
        'infomemes_mod4_respuesta2' => [
            'response' => [],
            'next' => [
                'response' => ['infomemes/modulo4/respuesta-estoy-listo-pregunta-2.txt'],
                'next' => true,
                'loadmeme' => true
            ],
            'alternative' => [
                'response' => ['infomemes/modulo4/respuesta-no-pregunta-2.txt'],
                'next' => true,
            ],
            'correct_option' => ['1'],
            'alternative_option' => ['2'],
        ],
        'infomemes_mod4_cierre_modulo' => [
            'response' => ['infomemes/modulo4/cierre-modulo.txt'],
            'next' => true,
            'alternative' => true,
        ],
        'infomemes_mod5' => [
            'response' => ['infomemes/modulo5/modulo.txt', 'infomemes/modulo5/info-1.txt', 'infomemes/modulo5/pregunta-1.txt'],
            'next' => true,
            'alternative' => true,
        ],
        'infomemes_mod5_respuesta1' => [
            'response' => [],
            'next' => [
                'response' => ['infomemes/modulo5/respuesta-facebook-pregunta-1.txt', 'infomemes/modulo5/respuesta-x-pregunta-1.txt', 'infomemes/modulo5/respuesta-instagram-pregunta-1.txt', 'infomemes/modulo5/respuesta-otra-red-pregunta-1.txt'],
                'next' => true
            ],
            'correct_option' => ['1', '2', '3', '4'],
            'continue_after_next' => true,
        ],
        'infomemes_mod6' => [
            'response' => ['infomemes/modulo6/modulo.txt', 'infomemes/modulo6/info-1.txt', 'infomemes/modulo6/crear-certificado.txt'],
            'next' => true,
            'alternative' => true,
            'exit' => true,
        ],
        'infomemes_mod6_certificado' => [
            'response' => [],
            'next' => [
                'response' => ['infomemes/modulo6/certificado.txt'],
                'next' => true
            ],
            'certificado' => true,
            'continue_after_next' => true,
        ],
        'infomemes_mod6_cierre' => [
            'response' => ['infomemes/modulo6/cierre-modulo.txt'],
            'exit' => true,
            'next' => true,
            'alternative' => true,
        ],
        'infomemes_mod6_cierre_2' => [
            'response' => [],
            'next' => [
                'response' => ['infomemes/modulo6/cierre-modulo-2.txt'],
                'next' => true
            ],
            'location_user' => true,
        ]
    ];

    if ($MediaUrl) {
        $memeUrl = download_image_from_url($MediaUrl);
        update_infomemes_progression_status($from, 'infomemes_meme_url', $memeUrl);
    }

    foreach ($infomemes_progression as $step => $data) {
        if (!get_infomemes_progression_status($from, $step)) {
            send_responses($data['response'], $to, $from, $client);

            if (isset($data['exit'])) {
                update_infomemes_progression_status($from, $step, 1);
                return;
            }

            if (isset($data['location_user'])) {
                update_infomemes_progression_status($from, $step, 1);
                update_infomemes_progression_status($from, 'infomemes_location_user', $messageReceived);
                send_responses($data['next']['response'], $to, $from, $client);

                // Último paso del curso de infomemes: aquí termina.
                datav_followup_send('infomemes_completed', $from, $to, $client);
                return;
            }

            if (isset($data['certificado'])) {
                // generar la imagen del certificado
                update_infomemes_progression_status($from, $step, 1);

                $certificate_image_path = create_certificate($messageReceived);

                $upload_dir = wp_upload_dir();
                $certificate_image_url = $upload_dir['baseurl'] . '/certificates/' . basename($certificate_image_path);

                send_responses($data['next']['response'], $to, $from, $client, $certificate_image_url);

                update_infomemes_progression_status($from, 'infomemes_certificate_url', $certificate_image_url);

                datav_safe_sleep(3);
            }

            if (!isset($data['correct_option']) && !isset($data['alternative_option'])) {
                update_infomemes_progression_status($from, $step, 1);
                $messageReceived = null;
                continue;
            }

            if (isset($data['correct_option']) && in_array(strtolower($messageReceived), $data['correct_option'])) {
                update_infomemes_progression_status($from, $step, 1);

                if (isset($data['next']['loadmeme'])) {
                    //enviar el meme a los administradores o algo asi
                }

                if (isset($data['next']['response'])) {

                    if (count($data['correct_option']) > 1) {
                        $indice = array_search(strtolower($messageReceived), $data['correct_option']);
                        send_twilio_message($to, $from, get_message($data['next']['response'][$indice]), $client);
                    } else {
                        send_responses($data['next']['response'], $to, $from, $client, isset($data['memeUrl']) ? $data['memeUrl'] : null);
                        datav_safe_sleep(3);
                    }

                    if (isset($data['continue_after_next'])) {
                        $messageReceived = null;

                        continue;
                    }
                } else {
                    continue;
                }
            } elseif (isset($data['alternative_option']) && in_array(strtolower($messageReceived), $data['alternative_option'])) {
                send_responses($data['alternative']['response'], $to, $from, $client);

                if (isset($data['alternative_option_is_correct_too'])) {
                    update_infomemes_progression_status($from, $step, 1);
                }

                if (isset($data['continue_after_alternative'])) {
                    continue;
                }
            } else {
                send_twilio_message($to, $from, get_message('respuesta_incorrecta_curso.txt'), $client);
            }

            return;
        }
    }

    send_twilio_message($to, $from, get_message('respuesta_incorrecta_curso.txt'), $client);
}

function send_responses($responses, $to, $from, $client, $memeUrl = null)
{
    foreach ($responses as $response) {
        send_twilio_message($to, $from, get_message($response), $client, $memeUrl);
        datav_safe_sleep(2);
    }
}

// Función para descargar la imagen enviada por el usuario
function download_image_from_url($image_url)
{
    try {
        // Obtén el directorio de uploads
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'] . '/memesDesca';
        $upload_url = $upload_dir['baseurl'] . '/memesDesca';

        // Crear el directorio de certificados si no existe
        if (!file_exists($upload_path)) {
            mkdir($upload_path, 0755, true);
        }

        // Descarga el archivo
        $response = wp_remote_get($image_url);
        if (is_wp_error($response)) {
            throw new Exception('Error al descargar la imagen: ' . $response->get_error_message());
        }

        // Verifica el código de estado HTTP
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code != 200) {
            throw new Exception('Error al descargar la imagen. Código de estado HTTP: ' . $status_code);
        }

        // Obtén el tipo de contenido de la imagen
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!$content_type) {
            throw new Exception('No se pudo determinar el tipo de contenido de la imagen.');
        }

        // Mapea el tipo de contenido a una extensión de archivo
        $extension = '';
        switch ($content_type) {
            case 'image/jpeg':
                $extension = 'jpg';
                break;
            case 'image/png':
                $extension = 'png';
                break;
            case 'image/gif':
                $extension = 'gif';
                break;
            default:
                throw new Exception('Tipo de imagen no soportado: ' . $content_type);
        }

        // Crea un nombre de archivo único
        $file_name = uniqid('image_', true) . '.' . $extension;
        $file_path = $upload_path . '/' . $file_name;

        // Obtén los datos de la imagen
        $image_data = wp_remote_retrieve_body($response);
        if (false === $image_data) {
            throw new Exception('Error al obtener los datos de la imagen.');
        }

        // Guarda el archivo
        if (false === file_put_contents($file_path, $image_data)) {
            throw new Exception('Error al guardar la imagen en el servidor.');
        }

        return $upload_url . '/' . $file_name;
    } catch (Exception $e) {
        error_log('Error: ' . $e->getMessage());
        return null;
    }
}

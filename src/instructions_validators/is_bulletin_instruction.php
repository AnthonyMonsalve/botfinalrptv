<?php

function isBulletinInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $BulletinInstruction = [
    '4', 'boletin', 'Boletin', 'BOLETIN', 'Boletín', 'BOLETÍN', 'boletín', 'Recibir el boletín', 'Recibir información', 'Newsletter', 'newsletter', 'NEWSLETTER', 'Información', 'información', 'INFORMACIÓN', 'recibir información', 'recibir el boletín', 'quiero el boletín', 'enviarme el boletín', 'boletín', 'ver boletín', 'mostrar boletín', 'quiero ver el boletín', 'dame el boletín', 'necesito el boletín', 'puedo ver el boletín', 'boletín informativo', 'información del boletín', 'suscribirme al boletín', 'me interesa el boletín', 'leer el boletín', 'boletín por favor', 'envíame el boletín', 'dame boletín', 'obtener boletín', 'cómo puedo ver el boletín', 'donde está el boletín', 'quiero el boletín informativo', 'boletín semanal', 'boletín mensual', 'ver el boletín informativo', 'información del boletín por favor',
    'quiero el boletín',
    'enviarme el boletín',
    'boletín',
    'ver boletín',
    'mostrar boletín',
    'quiero ver el boletín',
    'dame el boletín',
    'necesito el boletín',
    'puedo ver el boletín',
    'boletín informativo',
    'información del boletín',
    'suscribirme al boletín',
    'me interesa el boletín',
    'leer el boletín',
    'boletín por favor',
    'envíame el boletín',
    'dame boletín',
    'obtener boletín',
    'cómo puedo ver el boletín',
    'donde está el boletín',
    'quiero el boletín informativo',
    'boletín semanal',
    'boletín mensual',
    'ver el boletín informativo',
    'información del boletín por favor'
  ];

  if (in_array($instruction, $BulletinInstruction)) {
    return true;
  }
}

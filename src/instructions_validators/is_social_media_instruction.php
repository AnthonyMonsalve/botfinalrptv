<?php

function isSocialMediaInstruction($instruction)
{
  $instruction = strtolower($instruction);

  $SocialMediaInstruction =
    [
      '4',
      'Reses Sociales',
      'Redes sociales',
      'RRSS',
      'redes sociales',
      'redes Sociales',
      'redessociales',
      'Red social',
      'red social',
      'Redes Sociales',
      'Redes sociales',
      'Redes sociales',
      'Social media',
      'social media',
      'SM',
      'Social networks',
      'social networks',
      'red social',
      'Red Social',
      'media social',
      'Media Social',
      'mediasocial',
      'socialnetwork',
      'SocialNetwork',
      'quiero ver redes sociales',
      'muéstrame las redes sociales',
      'información sobre redes sociales',
      'dame las redes sociales',
      'opciones de redes sociales',
      'ver redes sociales',
      'mostrar redes sociales',
      'necesito redes sociales',
      'me interesan las redes sociales',
      'cuáles son las redes sociales',
      'quiero saber sobre redes sociales',
      'enséñame las redes sociales',
      'redes sociales por favor',
      'puedo ver redes sociales',
      'dame opción de redes sociales'
    ];

  if (in_array($instruction, $SocialMediaInstruction)) {
    return true;
  }
}

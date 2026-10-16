<?php

require_once plugin_dir_path(__FILE__) . 'update_status_infomemes.php';
require_once plugin_dir_path(__FILE__) . 'update_infomemes_progression_status.php';

function logout_infomemes($from)
{
    update_course_infomemes($from, 'not_started');
    update_infomemes_progression_status($from, 'infomemes_intro', 0);
    update_infomemes_progression_status($from, 'infomemes_mod1', 0);
    update_infomemes_progression_status($from, 'infomemes_mod1_respuesta1', 0);
    update_infomemes_progression_status($from, 'infomemes_mod2', 0);
    update_infomemes_progression_status($from, 'infomemes_mod2_respuesta1', 0);
    update_infomemes_progression_status($from, 'infomemes_mod3', 0);
    update_infomemes_progression_status($from, 'infomemes_mod3_respuesta1', 0);
    update_infomemes_progression_status($from, 'infomemes_mod4', 0);
    update_infomemes_progression_status($from, 'infomemes_mod4_respuesta1', 0);
    update_infomemes_progression_status($from, 'infomemes_mod4_respuesta2', 0);
    update_infomemes_progression_status($from, 'infomemes_mod4_cierre_modulo', 0);
    update_infomemes_progression_status($from, 'infomemes_mod5', 0);
    update_infomemes_progression_status($from, 'infomemes_mod5_respuesta1', 0);
    update_infomemes_progression_status($from, 'infomemes_mod6', 0);
    update_infomemes_progression_status($from, 'infomemes_mod6_certificado', 0);
    update_infomemes_progression_status($from, 'infomemes_mod6_cierre', 0);
    update_infomemes_progression_status($from, 'infomemes_mod6_cierre_2', 0);
}

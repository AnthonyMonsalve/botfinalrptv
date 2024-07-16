<?php

require_once plugin_dir_path(__FILE__) . 'updateStatusCourse.php';
require_once plugin_dir_path(__FILE__) . 'updateTestStatus.php';


function logout_course($from)
{
    update_course_status($from, 'not_started');
    update_test_status($from, 'test1', 0);
    update_test_status($from, 'test2', 0);
    update_test_status($from, 'test3', 0);
    update_test_status($from, 'test4', 0);
    update_test_status($from, 'test5', 0);
    update_test_status($from, 'test6', 0);
    update_test_status($from, 'test7', 0);
}

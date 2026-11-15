<?php
// Fake push service (test container only): keeps each request it receives, answers 201 like a real one.
$dir = '/tmp/push';
@mkdir($dir, 0777, true);
$n = count(glob($dir.'/req-*.json')) + 1;
file_put_contents($dir.'/req-'.$n.'.json', json_encode([
    'path'    => $_SERVER['REQUEST_URI'],
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body'    => bin2hex(file_get_contents('php://input')),
]));
http_response_code(201);

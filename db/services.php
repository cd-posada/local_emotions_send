<?php
// Este archivo define los webservices del plugin.
// El flujo principal es JS (inferencia en el navegador) -> save.php (sin token);
// se mantiene el servicio por compatibilidad y para uso futuro.
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_emotions_send_post_emotion' => [
        'classname'   => 'local_emotions_send\external\post_emotion',
        'methodname'  => 'execute',
        'description' => 'Recibe y almacena datos de emocion detectada',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
];

$services = [
    'Emotions API' => [
        'functions'       => ['local_emotions_send_post_emotion'],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'emotions_api',
    ],
];

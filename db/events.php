<?php
/**
 * local/emotions_send/db/events.php
 *
 * Learning_Session ligada al inicio y cierre de sesion en Moodle
 * (ver classes/observer.php):
 *  - user_loggedin:  abre una sesion en cada curso del estudiante que tiene
 *                    la actividad mod_emotions.
 *  - user_loggedout: cierra sus sesiones abiertas (closure_reason 'Logout').
 */
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_loggedin',
        'callback'  => '\local_emotions_send\observer::user_loggedin',
    ],
    [
        'eventname' => '\core\event\user_loggedout',
        'callback'  => '\local_emotions_send\observer::user_loggedout',
    ],
];

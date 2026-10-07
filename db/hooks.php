<?php
/**
 * local/emotions_send/db/hooks.php
 *
 * Moodle 4.4+: el widget flotante se inyecta con el hook
 * before_footer_html_generation, que reemplaza al callback clasico
 * local_emotions_send_before_footer() (se conserva en lib.php para
 * Moodle < 4.4, donde este archivo se ignora).
 */
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_emotions_send\hook_callbacks::class, 'before_footer_html_generation'],
    ],
];

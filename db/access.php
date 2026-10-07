<?php
/**
 * local/emotions_send/db/access.php
 * Decide quien ver el report
 */
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'local/emotions_send:viewreport' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'local/emotions_send:managelogs' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

];

<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_emotions_send';
$plugin->version   = 2026100600;
$plugin->requires  = 2024042200; // Moodle 4.4+ (probado en 4.5)
$plugin->supported = [404, 405];
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = 'v7.1 - consentimiento informado del registro facial';

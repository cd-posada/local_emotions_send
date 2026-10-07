<?php
/**
 * local/emotions_send/usermodel.php
 *
 * Administracion del sitio > Plugins > Plugins locales > Base externa user_model_v5_1.
 * Muestra la conexion y el estado del esquema de la base externa, y permite
 * crear/completar el esquema (usermodel_schema::install(): idempotente, nunca
 * borra datos). Para sitios sin acceso por consola; por CLI es
 * cli/setup_usermodel.php.
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_emotions_send\local\data_helper;
use local_emotions_send\local\usermodel_db;
use local_emotions_send\local\usermodel_schema;

admin_externalpage_setup('local_emotions_send_usermodel');

$log = null;
$error = null;
if (optional_param('install', 0, PARAM_BOOL) && confirm_sesskey()) {
    try {
        $log = usermodel_schema::install();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$c = usermodel_db::config();
$st = usermodel_schema::status();
$yes = get_string('yes');
$no = get_string('no');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('usermodel:pagetitle', 'local_emotions_send'));
echo html_writer::tag('p', get_string('usermodel:pageintro', 'local_emotions_send'));

if (data_helper::storage_mode() !== data_helper::STORAGE_USERMODEL) {
    echo $OUTPUT->notification(get_string('usermodel:notactive', 'local_emotions_send'), 'info');
}
if ($log !== null) {
    echo $OUTPUT->notification(html_writer::alist(array_map('s', $log)), 'success');
}
if ($error !== null) {
    echo $OUTPUT->notification(get_string('usermodel:installerror', 'local_emotions_send', s($error)), 'error');
}

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->data = [
    [get_string('usermodel:connection', 'local_emotions_send'), s("{$c['user']}@{$c['host']}:{$c['port']}/{$c['name']}")],
    [get_string('usermodel:connected', 'local_emotions_send'),
        $st['connected'] ? $yes . ' (' . s($st['server']) . ')' : $no . ($st['error'] ? ' — ' . s($st['error']) : '')],
    [get_string('usermodel:missingtables', 'local_emotions_send'), $st['missingtables'] ? s(implode(', ', $st['missingtables'])) : '—'],
    [get_string('usermodel:missingcolumns', 'local_emotions_send'), $st['missingcolumns'] ? s(implode(', ', $st['missingcolumns'])) : '—'],
    [get_string('usermodel:triggers', 'local_emotions_send'), $st['missingtriggers'] ? $no : $yes],
    [get_string('usermodel:view', 'local_emotions_send'), $st['viewok'] ? $yes : $no],
    [get_string('usermodel:ready', 'local_emotions_send'), $st['ready'] ? $yes : $no],
];
echo html_writer::table($table);

if ($st['connected']) {
    echo $OUTPUT->single_button(new moodle_url('/local/emotions_send/usermodel.php', ['install' => 1]),
        get_string('usermodel:install', 'local_emotions_send'), 'post', ['type' => single_button::BUTTON_PRIMARY]);
}
echo $OUTPUT->footer();

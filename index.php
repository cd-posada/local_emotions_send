<?php
require_once('../../config.php');
require_login();

$PAGE->set_url(new moodle_url('/local/emotions_send/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Emotions - Panel');
$PAGE->set_heading('Emotions - Panel');

echo $OUTPUT->header();
echo html_writer::tag('h3', 'Plugin de Registro de Emociones');
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/local/emotions_send/report.php'),
    'Ver reporte de emociones'
));
echo $OUTPUT->footer();

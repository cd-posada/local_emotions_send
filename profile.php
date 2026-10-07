<?php
/**
 * local/emotions_send/profile.php
 *
 * Pagina donde el estudiante completa su perfil extendido, actualizada
 * para el modelo v3 (contexto como campos libres, idioma preferido de
 * interfaz).
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/form/profile_form.php');

require_login();

use local_emotions_send\local\data_helper;
use local_emotions_send\form\profile_form;

$PAGE->set_url(new moodle_url('/local/emotions_send/profile.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('profile:title', 'local_emotions_send'));
$PAGE->set_heading(get_string('profile:title', 'local_emotions_send'));

global $USER;

$existing = data_helper::get_user_profile($USER->id);
$userlangs = data_helper::get_user_languages($USER->id);
$usercontexts = data_helper::get_user_context_entries($USER->id);

$defaults = new stdClass();
if ($existing) {
    $defaults->gender     = $existing->gender;
    $defaults->birthdate  = $existing->birthdate;
    $defaults->programid  = $existing->programid;
}
$defaults->nativelanguages = array_map(function ($r) {
    return $r->languageid;
}, $userlangs['native']);
$defaults->otherlanguages = array_map(function ($r) {
    return $r->languageid;
}, $userlangs['other']);
$defaults->preferredlanguageid = $userlangs['preferred_languageid'] ?: '';

foreach ($usercontexts as $ctx) {
    if ($ctx->type === 'device') {
        $defaults->ctx_device = $ctx->value_context;
    } else if ($ctx->type === 'connection') {
        $defaults->ctx_connection = $ctx->value_context;
    } else if ($ctx->type === 'modality') {
        $defaults->ctx_modality = $ctx->value_context;
    }
}

$mform = new profile_form();
$mform->set_data($defaults);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/my/'));
} else if ($data = $mform->get_data()) {

    $extralangid = 0;
    if (!empty($data->newlanguage)) {
        $extralangid = data_helper::get_or_create_language($data->newlanguage);
    }

    $otherlanguages = $data->otherlanguages ?? [];
    if ($extralangid) {
        $otherlanguages[] = $extralangid;
    }

    data_helper::save_user_profile($USER->id, (object)[
        'gender'    => $data->gender,
        'birthdate' => $data->birthdate,
        'programid' => $data->programid,
    ]);

    data_helper::set_user_languages(
        $USER->id,
        $data->nativelanguages ?? [],
        $otherlanguages,
        (int)($data->preferredlanguageid ?? 0)
    );

    data_helper::set_user_context_value($USER->id, 'device', $data->ctx_device ?? '');
    data_helper::set_user_context_value($USER->id, 'connection', $data->ctx_connection ?? '');
    data_helper::set_user_context_value($USER->id, 'modality', $data->ctx_modality ?? '');

    redirect(
        new moodle_url('/local/emotions_send/profile.php'),
        get_string('profile:saved', 'local_emotions_send'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('profile:title', 'local_emotions_send'));
echo html_writer::tag('p', get_string('profile:intro', 'local_emotions_send'),
    ['style' => 'color:#6c757d;max-width:640px;']);
$mform->display();
echo $OUTPUT->footer();

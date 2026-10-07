<?php
/**
 * local/emotions_send/classes/form/profile_form.php
 *
 * Actualizado para el modelo v3:
 *  - Contexto ya NO es una seleccion de un catalogo compartido: son
 *    campos libres propios del usuario (type + value_context). Se
 *    simplifica a 3 campos fijos (dispositivo, conexion, modalidad) en
 *    vez de un selector dinamico -- si mas adelante se necesitan mas
 *    tipos de contexto, agregar mas campos aqui es directo.
 *  - Idiomas: se agrega un select de "idioma preferido para la
 *    interfaz" (is_interface_preferred), ademas de nativos/otros.
 */
namespace local_emotions_send\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

use local_emotions_send\local\data_helper;

class profile_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;

        // Genero.
        $mform->addElement('select', 'gender', get_string('profile:gender', 'local_emotions_send'), [
            ''        => get_string('choosedots'),
            'female'  => get_string('profile:gender_female', 'local_emotions_send'),
            'male'    => get_string('profile:gender_male', 'local_emotions_send'),
            'other'   => get_string('profile:gender_other', 'local_emotions_send'),
            'na'      => get_string('profile:gender_na', 'local_emotions_send'),
        ]);
        $mform->setType('gender', PARAM_ALPHA);
        $mform->addRule('gender', null, 'required', null, 'client');

        // Fecha de nacimiento.
        $mform->addElement('date_selector', 'birthdate', get_string('profile:birthdate', 'local_emotions_send'));
        $mform->addRule('birthdate', null, 'required', null, 'client');

        // Nivel -> Programa academico.
        $levels = data_helper::get_levels();
        $leveloptions = ['' => get_string('choosedots')];
        foreach ($levels as $level) {
            $leveloptions[$level->id] = format_string($level->value);
        }
        $mform->addElement('select', 'levelid', get_string('profile:level', 'local_emotions_send'), $leveloptions);
        $mform->setType('levelid', PARAM_INT);

        $programs = data_helper::get_programs();
        $programoptions = ['' => get_string('choosedots')];
        foreach ($programs as $program) {
            $programoptions[$program->id] = format_string($program->name);
        }
        $mform->addElement('select', 'programid', get_string('profile:program', 'local_emotions_send'), $programoptions);
        $mform->setType('programid', PARAM_INT);
        $mform->addRule('programid', null, 'required', null, 'client');

        // Idiomas.
        $languages = data_helper::get_languages();
        $langoptions = [];
        foreach ($languages as $lang) {
            $langoptions[$lang->id] = format_string($lang->name);
        }

        $mform->addElement('select', 'nativelanguages',
            get_string('profile:nativelanguages', 'local_emotions_send'), $langoptions)
            ->setMultiple(true);
        $mform->setType('nativelanguages', PARAM_INT);

        $mform->addElement('select', 'otherlanguages',
            get_string('profile:otherlanguages', 'local_emotions_send'), $langoptions)
            ->setMultiple(true);
        $mform->setType('otherlanguages', PARAM_INT);

        $mform->addElement('text', 'newlanguage', get_string('profile:newlanguage', 'local_emotions_send'));
        $mform->setType('newlanguage', PARAM_TEXT);

        $preferredoptions = ['' => get_string('profile:none', 'local_emotions_send')] + $langoptions;
        $mform->addElement('select', 'preferredlanguageid',
            get_string('profile:preferredlanguage', 'local_emotions_send'), $preferredoptions);
        $mform->setType('preferredlanguageid', PARAM_INT);

        // Contexto (campos libres por usuario).
        $mform->addElement('header', 'contextheader', get_string('profile:contextheader', 'local_emotions_send'));
        $mform->addElement('text', 'ctx_device', get_string('profile:ctx_device', 'local_emotions_send'));
        $mform->setType('ctx_device', PARAM_TEXT);
        $mform->addElement('text', 'ctx_connection', get_string('profile:ctx_connection', 'local_emotions_send'));
        $mform->setType('ctx_connection', PARAM_TEXT);
        $mform->addElement('select', 'ctx_modality', get_string('profile:ctx_modality', 'local_emotions_send'), [
            ''            => get_string('choosedots'),
            'presencial'  => get_string('profile:ctx_modality_presencial', 'local_emotions_send'),
            'virtual'     => get_string('profile:ctx_modality_virtual', 'local_emotions_send'),
            'hibrido'     => get_string('profile:ctx_modality_hibrido', 'local_emotions_send'),
        ]);
        $mform->setType('ctx_modality', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('profile:save', 'local_emotions_send'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['birthdate']) && $data['birthdate'] > time()) {
            $errors['birthdate'] = get_string('profile:error_futuredate', 'local_emotions_send');
        }
        return $errors;
    }
}

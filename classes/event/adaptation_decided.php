<?php
/**
 * local/emotions_send/classes/event/adaptation_decided.php
 *
 * Evento del logstore de Moodle para cada decision de adaptacion de la interfaz
 * (Adaptation_Event PLANNED o GATED). No reemplaza a user_model_v5_1 (el logstore
 * solo guarda segundos enteros): deja la adaptacion visible en los informes estandar
 * de Moodle para auditoria y para que el docente constate que ocurrio en la sesion.
 *
 * other: eventid (Adaptation_Event), state (PLANNED | GATED), emotion.
 */
namespace local_emotions_send\event;

defined('MOODLE_INTERNAL') || die();

class adaptation_decided extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    public static function get_name() {
        return get_string('event_adaptation_decided', 'local_emotions_send');
    }

    public function get_description() {
        $o = $this->other;
        return "The user with id '{$this->userid}' received an interface adaptation decision "
            . "(Adaptation_Event {$o['eventid']}, state '{$o['state']}', emotion '{$o['emotion']}') "
            . "in the course with id '{$this->courseid}'.";
    }

    protected function validate_data() {
        parent::validate_data();
        foreach (['eventid', 'state', 'emotion'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    public static function get_other_mapping() {
        return false;
    }
}

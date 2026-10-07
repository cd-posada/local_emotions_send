<?php
/**
 * local/emotions_send/classes/external/post_emotion.php
 * Webservice para guardar emociones. Igual patron que save.php: abre/
 * reusa una Learning_Session antes de registrar el Emotion_Record.
 */
namespace local_emotions_send\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_emotions_send\local\data_helper;

class post_emotion extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid'     => new external_value(PARAM_INT,   'ID del usuario'),
            'emotion'    => new external_value(PARAM_TEXT,  'Etiqueta de emocion'),
            'confidence' => new external_value(PARAM_FLOAT, 'Nivel de confianza 0-1'),
            'courseid'   => new external_value(PARAM_INT,   'ID del curso', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($userid, $emotion, $confidence, $courseid = 0) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'     => $userid,
            'emotion'    => $emotion,
            'confidence' => $confidence,
            'courseid'   => $courseid,
        ]);

        if (!$DB->record_exists('user', ['id' => $params['userid']])) {
            return ['status' => 'error', 'message' => 'Usuario no encontrado'];
        }

        $emotionid = data_helper::get_or_create_emotion(clean_param($params['emotion'], PARAM_TEXT));

        $localcourseid = 0;
        if ($params['courseid'] > 0) {
            $localcourseid = data_helper::get_or_create_course_from_moodle($params['courseid']);
        }
        if ($localcourseid === 0) {
            return ['status' => 'error', 'message' => 'No se pudo resolver el curso local'];
        }

        $actcontextid = data_helper::get_or_create_actcontext($localcourseid, 'general', 'general');
        $sessionid = data_helper::get_or_create_open_session($params['userid'], $localcourseid);
        $infmodelid = data_helper::get_default_inference_model_id();

        try {
            $recordid = data_helper::record_emotion([
                'sessionid'    => $sessionid,
                'emotionid'    => $emotionid,
                'actcontextid' => $actcontextid,
                'infmodelid'   => $infmodelid,
                'confidence'   => round((float)$params['confidence'], 4),
            ]);

            data_helper::record_reference_observation($sessionid, $emotionid, data_helper::REFOBS_METHOD, null, null);

            // Los Adaptation_Event los registra block_adaptive_ui (ver adaptation_logger).

            return ['status' => 'ok', 'message' => 'Guardado', 'id' => $recordid];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()];
        }
    }

    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'Estado'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje'),
            'id'      => new external_value(PARAM_INT,  'ID del registro', VALUE_OPTIONAL),
        ]);
    }
}

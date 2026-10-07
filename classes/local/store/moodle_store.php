<?php
/**
 * local/emotions_send/classes/local/store/moodle_store.php
 *
 * Almacenamiento en las tablas local_emotions_* de la base de Moodle
 * (opcion 'moodle', la de siempre). Es el mismo codigo que antes vivia en
 * data_helper, ahora como uno de los dos almacenes intercambiables (el
 * otro es usermodel_store, la base externa user_model_v5_1).
 */
namespace local_emotions_send\local\store;

defined('MOODLE_INTERNAL') || die();

use local_emotions_send\local\data_helper;
use local_emotions_send\local\fer_model;
use local_emotions_send\local\catalog_seeder;

class moodle_store implements store {

    // ---------------------------------------------------------------
    // Emotion
    // ---------------------------------------------------------------

    public function get_emotions(): array {
        global $DB;
        return $DB->get_records('local_emotions_emotion', null, 'name ASC');
    }

    public function get_emotion_by_name(string $name) {
        global $DB;
        return $DB->get_record('local_emotions_emotion', ['name' => $name]);
    }

    /** Resuelve una emocion por nombre; si no existe la crea con un code generado del nombre. */
    public function get_or_create_emotion(string $name): int {
        global $DB;
        $name = trim($name);
        if ($name === '') {
            $name = 'Desconocido';
        }
        $existing = $this->get_emotion_by_name($name);
        if ($existing) {
            return (int)$existing->id;
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        return (int)$DB->insert_record('local_emotions_emotion', (object)[
            'code' => \core_text::substr($code, 0, 40),
            'name' => \core_text::substr($name, 0, 80),
        ]);
    }

    // ---------------------------------------------------------------
    // Idiomas y User_Language
    // ---------------------------------------------------------------

    public function get_languages(): array {
        global $DB;
        return $DB->get_records('local_emotions_language', null, 'name ASC');
    }

    public function get_or_create_language(string $name): int {
        global $DB;
        $name = trim($name);
        $existing = $DB->get_record('local_emotions_language', ['name' => $name]);
        if ($existing) {
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('local_emotions_language', (object)[
            'code_iso_639_1' => \core_text::substr(strtolower($name), 0, 10),
            'name'           => \core_text::substr($name, 0, 80),
        ]);
    }

    public function get_user_languages(int $userid): array {
        global $DB;
        $sql = "SELECT ul.languageid, ul.is_native, ul.is_interface_preferred, l.name
                  FROM {local_emotions_userlang} ul
                  JOIN {local_emotions_language} l ON l.id = ul.languageid
                 WHERE ul.userid = :userid
              ORDER BY l.name ASC";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid]);

        $result = ['native' => [], 'other' => [], 'preferred_languageid' => 0];
        foreach ($rows as $row) {
            $key = !empty($row->is_native) ? 'native' : 'other';
            $result[$key][] = $row;
            if (!empty($row->is_interface_preferred)) {
                $result['preferred_languageid'] = (int)$row->languageid;
            }
        }
        return $result;
    }

    public function set_user_languages(int $userid, array $nativeids, array $otherids, int $preferredid = 0): void {
        global $DB;

        $DB->delete_records('local_emotions_userlang', ['userid' => $userid]);

        $seen = [];
        foreach (array_unique($nativeids) as $langid) {
            $langid = (int)$langid;
            if ($langid <= 0 || isset($seen[$langid])) {
                continue;
            }
            $seen[$langid] = true;
            $DB->insert_record('local_emotions_userlang', (object)[
                'userid' => $userid, 'languageid' => $langid,
                'is_native' => 1, 'is_interface_preferred' => ($langid === $preferredid) ? 1 : 0,
            ]);
        }
        foreach (array_unique($otherids) as $langid) {
            $langid = (int)$langid;
            if ($langid <= 0 || isset($seen[$langid])) {
                continue;
            }
            $seen[$langid] = true;
            $DB->insert_record('local_emotions_userlang', (object)[
                'userid' => $userid, 'languageid' => $langid,
                'is_native' => 0, 'is_interface_preferred' => ($langid === $preferredid) ? 1 : 0,
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Academic_Level / Academic_Program / Course / Activity_context
    // ---------------------------------------------------------------

    public function get_levels(): array {
        global $DB;
        return $DB->get_records('local_emotions_level', null, 'value ASC');
    }

    public function get_programs(int $levelid = 0): array {
        global $DB;
        $params = [];
        $where = '';
        if ($levelid > 0) {
            $where = 'WHERE levelid = :levelid';
            $params['levelid'] = $levelid;
        }
        return $DB->get_records_sql("SELECT * FROM {local_emotions_program} {$where} ORDER BY name ASC", $params);
    }

    public function create_program(string $name, int $levelid, string $faculty = ''): int {
        global $DB;
        if ($levelid <= 0) {
            $any = $DB->get_records('local_emotions_level', null, 'id ASC', 'id', 0, 1);
            $levelid = $any ? (int)reset($any)->id : 0;
        }
        return (int)$DB->insert_record('local_emotions_program', (object)[
            'name'    => \core_text::substr($name, 0, 160),
            'levelid' => $levelid,
            'faculty' => $faculty !== '' ? \core_text::substr($faculty, 0, 160) : null,
        ]);
    }

    public function get_course_by_moodle_id(int $moodlecourseid) {
        global $DB;
        return $DB->get_record('local_emotions_course', ['moodlecourseid' => $moodlecourseid]);
    }

    public function create_course(int $programid, string $name, int $moodlecourseid = 0): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_course', (object)[
            'programid'      => $programid,
            'moodlecourseid' => $moodlecourseid ?: null,
            'name'           => \core_text::substr($name, 0, 180),
        ]);
    }

    /** Deduce Course (y su Academic_Program) desde la categoria de Moodle, sin pedirle nada al estudiante. */
    public function get_or_create_course_from_moodle(int $moodlecourseid): int {
        global $DB;

        if ($moodlecourseid <= 1) {
            return 0;
        }

        $existing = $this->get_course_by_moodle_id($moodlecourseid);
        if ($existing) {
            return (int)$existing->id;
        }

        $course = $DB->get_record('course', ['id' => $moodlecourseid], 'id, category, fullname');
        if (!$course) {
            return 0;
        }

        $programid = 0;
        if (!empty($course->category)) {
            $category = $DB->get_record('course_categories', ['id' => $course->category], 'id, name');
            if ($category) {
                $categoryname = \core_text::substr(trim($category->name), 0, 160);
                $program = $DB->get_record('local_emotions_program', ['name' => $categoryname]);
                $programid = $program ? (int)$program->id : $this->create_program($categoryname, 0);
            }
        }
        if ($programid === 0) {
            $fallback = $DB->get_record('local_emotions_program', ['name' => 'Sin programa asignado']);
            $programid = $fallback ? (int)$fallback->id : $this->create_program('Sin programa asignado', 0);
        }

        return $this->create_course($programid, $course->fullname, $moodlecourseid);
    }

    public function get_or_create_actcontext(int $courseid, string $module, string $activitytype): int {
        global $DB;
        if ($courseid <= 0) {
            return 0;
        }
        $activitytype = $activitytype !== '' ? $activitytype : 'general';
        $existing = $DB->get_record('local_emotions_actcontext', [
            'courseid' => $courseid,
            'module'   => \core_text::substr($module, 0, 100),
        ]);
        if ($existing) {
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('local_emotions_actcontext', (object)[
            'courseid'     => $courseid,
            'module'       => \core_text::substr($module, 0, 100),
            'activitytype' => \core_text::substr($activitytype, 0, 60),
            'isassessed'   => 0,
        ]);
    }

    // ---------------------------------------------------------------
    // Context (filas libres por usuario)
    // ---------------------------------------------------------------

    public function get_user_context_entries(int $userid): array {
        global $DB;
        return $DB->get_records('local_emotions_context', ['userid' => $userid], 'type ASC');
    }

    public function set_user_context_value(int $userid, string $type, string $value): void {
        global $DB;
        $type = \core_text::substr($type, 0, 60);
        $existing = $DB->get_record('local_emotions_context', ['userid' => $userid, 'type' => $type]);
        if ($value === '') {
            if ($existing) {
                $DB->delete_records('local_emotions_context', ['id' => $existing->id]);
            }
            return;
        }
        if ($existing) {
            $existing->value_context = $value;
            $DB->update_record('local_emotions_context', $existing);
        } else {
            $DB->insert_record('local_emotions_context', (object)[
                'userid' => $userid, 'type' => $type, 'value_context' => $value,
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Learning_Session
    // ---------------------------------------------------------------

    /**
     * Reusa una sesion abierta reciente del usuario en este curso, o crea
     * una nueva (cerrando la anterior por 'Timeout' si estaba inactiva
     * mas de SESSION_IDLE_TIMEOUT). $devicetype/$viewport son opcionales,
     * solo se guardan al CREAR la sesion.
     */
    public function get_or_create_open_session(int $userid, int $localcourseid,
                                                        string $devicetype = '', string $viewport = ''): int {
        global $DB;
        if ($localcourseid <= 0) {
            return 0;
        }

        $open = $DB->get_record_sql(
            "SELECT * FROM {local_emotions_session}
              WHERE userid = :userid AND courseid = :courseid AND endedat IS NULL
           ORDER BY startedat DESC",
            ['userid' => $userid, 'courseid' => $localcourseid],
            IGNORE_MULTIPLE
        );

        if ($open) {
            $lastactivity = (int)$DB->get_field_sql(
                "SELECT MAX(timecreated) FROM {local_emotions_log} WHERE sessionid = :sid",
                ['sid' => $open->id]
            );
            $reference = max($lastactivity, (int)$open->startedat);
            if ((time() - $reference) < data_helper::SESSION_IDLE_TIMEOUT) {
                // Sesion abierta en el login (sin viewport): se completa con la primera deteccion.
                if (empty($open->viewport) && $viewport !== '') {
                    $DB->set_field('local_emotions_session', 'viewport', \core_text::substr($viewport, 0, 40), ['id' => $open->id]);
                }
                return (int)$open->id;
            }
            // Sesion abandonada: se cierra sola antes de abrir una nueva.
            $this->close_session((int)$open->id, 'Timeout');
        }

        return (int)$DB->insert_record('local_emotions_session', (object)[
            'userid'            => $userid,
            'courseid'          => $localcourseid,
            'startedat'         => time(),
            'devicetype'        => $devicetype !== '' ? \core_text::substr($devicetype, 0, 60) : null,
            'viewport'          => $viewport !== '' ? \core_text::substr($viewport, 0, 40) : null,
            'fercaptureenabled' => 1,
        ]);
    }

    public function close_session(int $sessionid, string $reason = 'Logout'): void {
        global $DB;
        $DB->set_field('local_emotions_session', 'endedat', time(), ['id' => $sessionid]);
        $DB->set_field('local_emotions_session', 'closurereason', \core_text::substr($reason, 0, 80), ['id' => $sessionid]);
    }

    public function start_session(int $userid, int $localcourseid, string $devicetype = '', string $viewport = ''): int {
        global $DB;
        if ($localcourseid <= 0) {
            return 0;
        }
        $open = $DB->get_fieldset_select('local_emotions_session', 'id',
            'userid = ? AND courseid = ? AND endedat IS NULL', [$userid, $localcourseid]);
        foreach ($open as $sid) {
            $this->close_session((int)$sid, 'NewLogin');
        }
        return (int)$DB->insert_record('local_emotions_session', (object)[
            'userid'            => $userid,
            'courseid'          => $localcourseid,
            'startedat'         => time(),
            'devicetype'        => $devicetype !== '' ? \core_text::substr($devicetype, 0, 60) : null,
            'viewport'          => $viewport !== '' ? \core_text::substr($viewport, 0, 40) : null,
            'fercaptureenabled' => 1,
        ]);
    }

    public function close_user_sessions(int $userid, string $reason = 'Logout'): int {
        global $DB;
        $open = $DB->get_fieldset_select('local_emotions_session', 'id', 'userid = ? AND endedat IS NULL', [$userid]);
        foreach ($open as $sid) {
            $this->close_session((int)$sid, $reason);
        }
        return count($open);
    }

    public function get_session(int $sessionid) {
        global $DB;
        return $DB->get_record('local_emotions_session', ['id' => $sessionid]);
    }

    // ---------------------------------------------------------------
    // Modelos emocionales, Norman_Level, Rules
    // ---------------------------------------------------------------

    public function get_models(): array {
        global $DB;
        return $DB->get_records('local_emotions_model', null, 'id ASC');
    }

    public function create_model(string $description): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_model', (object)['description' => trim($description)]);
    }

    public function get_model_levels(int $modelid = 0): array {
        global $DB;
        $params = [];
        $where = '';
        if ($modelid > 0) {
            $where = 'WHERE modelid = :modelid';
            $params['modelid'] = $modelid;
        }
        return $DB->get_records_sql("SELECT * FROM {local_emotions_modellevel} {$where} ORDER BY code ASC", $params);
    }

    public function create_model_level(int $modelid, string $code, string $description = ''): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_modellevel', (object)[
            'modelid' => $modelid,
            'code'    => \core_text::substr(trim($code), 0, 40),
            'description' => $description !== '' ? $description : null,
        ]);
    }

    public function get_rules(): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT r.*, m.description AS modelname, ml.code AS levelcode, e.name AS emotionname
               FROM {local_emotions_rule} r
               JOIN {local_emotions_modellevel} ml ON ml.id = r.modellevelid
               JOIN {local_emotions_model} m ON m.id = ml.modelid
               JOIN {local_emotions_emotion} e ON e.id = r.emotionid
           ORDER BY m.id ASC, ml.code ASC"
        );
    }

    public function create_rule(int $modellevelid, int $emotionid, string $uidimension, string $rulestatement): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_rule', (object)[
            'modellevelid'   => $modellevelid,
            'emotionid'      => $emotionid,
            'ui_dimension'   => \core_text::substr(trim($uidimension), 0, 60),
            'rule_statement' => trim($rulestatement),
        ]);
    }

    public function delete_rule(int $ruleid): void {
        global $DB;
        $DB->delete_records('local_emotions_rule', ['id' => $ruleid]);
    }

    /** Cualquier regla ligada a esta emocion (Rules.emotionid es obligatorio en v5). */
    public function match_rule(int $emotionid): int {
        global $DB;
        if ($emotionid <= 0) {
            return 0;
        }
        $rule = $DB->get_record('local_emotions_rule', ['emotionid' => $emotionid], '*', IGNORE_MULTIPLE);
        return $rule ? (int)$rule->id : 0;
    }

    // ---------------------------------------------------------------
    // Inference_Model
    // ---------------------------------------------------------------

    public function get_inference_models(): array {
        global $DB;
        return $DB->get_records('local_emotions_infmodel', null, 'name ASC');
    }

    public function register_inference_model(array $data): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_infmodel', (object)[
            'name'               => \core_text::substr($data['name'] ?? 'Sin especificar', 0, 120),
            'version'            => \core_text::substr($data['version'] ?? '0', 0, 30),
            'architecture'       => $data['architecture'] ?? null,
            'trainingdataset'    => $data['trainingdataset'] ?? null,
            'macrof1'            => $data['macrof1'] ?? null,
            'calibrationmethod'  => $data['calibrationmethod'] ?? null,
            'perclassf1'         => isset($data['perclassf1']) ? json_encode($data['perclassf1']) : null,
            'calibrationerror'   => $data['calibrationerror'] ?? null,
        ]);
    }

    public function get_default_inference_model_id(): int {
        global $DB;
        $existing = $DB->get_record('local_emotions_infmodel', ['name' => 'Sin especificar']);
        return $existing ? (int)$existing->id : $this->register_inference_model(['name' => 'Sin especificar', 'version' => '0']);
    }

    /** Inference_Model del modelo ONNX que corre en el navegador (ver fer_model). */
    public function get_or_create_client_inference_model_id(): int {
        global $DB;
        $name = fer_model::inference_model_name();
        $existing = $DB->get_record('local_emotions_infmodel',
            ['name' => $name, 'version' => fer_model::MODEL_VERSION], 'id', IGNORE_MULTIPLE);
        if ($existing) {
            return (int)$existing->id;
        }
        return $this->register_inference_model([
            'name'            => $name,
            'version'         => fer_model::MODEL_VERSION,
            'architecture'    => fer_model::MODEL_ARCHITECTURE,
            'trainingdataset' => fer_model::MODEL_DATASET,
        ]);
    }

    // ---------------------------------------------------------------
    // Emotion_Record
    // ---------------------------------------------------------------

    public function record_emotion(array $data): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_log', (object)[
            'sessionid'          => $data['sessionid'],
            'emotionid'          => $data['emotionid'],
            'actcontextid'       => $data['actcontextid'],
            'infmodelid'         => $data['infmodelid'],
            'timecreated'        => $data['timecreated'] ?? time(),
            'intensitydaisee'    => $data['intensitydaisee'] ?? null,
            'confidence'         => $data['confidence'] ?? 0,
            'sourcemodel'        => $data['sourcemodel'] ?? null,
            'classprobabilities' => isset($data['classprobabilities']) ? json_encode($data['classprobabilities']) : null,
            'entropy'            => $data['entropy'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------
    // Reference_Observation (ground truth manual, sin UI todavia)
    // ---------------------------------------------------------------

    public function record_reference_observation(int $sessionid, int $emotionid, string $method,
                                                          ?string $observer = null, ?int $intensity = null): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_refobs', (object)[
            'sessionid'  => $sessionid,
            'emotionid'  => $emotionid,
            'observedat' => time(),
            'method'     => \core_text::substr($method, 0, 60),
            'observer'   => $observer,
            'intensity'  => $intensity,
        ]);
    }

    // ---------------------------------------------------------------
    // Adaptation_Event / Adaptation_Change / Adaptation_Feedback
    // ---------------------------------------------------------------

    /**
     * Registra un evento de adaptacion. $decisionstate: APPLIED, GATED,
     * SKIPPED o REVERTED. Por ahora save.php siempre usa 'APPLIED' cuando
     * encuentra una regla; el resto de estados quedan disponibles para
     * cuando el modulo del companero implemente su propia logica de
     * "gating" (por que se bloqueo o se salto una adaptacion).
     */
    public function log_adaptation_event(int $emorecordid, int $sessionid, string $decisionstate = 'APPLIED',
                                                  ?string $experimentalcond = null, ?string $gatereason = null): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_adaptlog', (object)[
            'emorecordid'      => $emorecordid,
            'sessionid'        => $sessionid,
            'timecreated'      => time(),
            'experimentalcond' => $experimentalcond,
            'decisionstate'    => \core_text::substr($decisionstate, 0, 60),
            'gatereason'       => $gatereason,
        ]);
    }

    public function log_adaptation_change(int $eventid, int $ruleid, string $uidimension,
                                                    string $targettoken, ?string $before, ?string $after): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_adaptchange', (object)[
            'eventid'      => $eventid,
            'ruleid'       => $ruleid,
            'ui_dimension' => \core_text::substr($uidimension, 0, 60),
            'targettoken'  => \core_text::substr($targettoken, 0, 120),
            'valuebefore'  => $before,
            'valueafter'   => $after,
        ]);
    }

    public function get_emotion_intensity_mode(int $sessionid, int $emotionid, int $since): ?int {
        global $DB;
        $v = $DB->get_field_sql(
            "SELECT intensitydaisee FROM {local_emotions_log}
              WHERE sessionid = ? AND emotionid = ? AND timecreated >= ? AND intensitydaisee IS NOT NULL
           GROUP BY intensitydaisee ORDER BY COUNT(*) DESC, intensitydaisee DESC",
            [$sessionid, $emotionid, $since], IGNORE_MULTIPLE);
        return $v === false || $v === null ? null : (int)$v;
    }

    public function get_last_adaptation_time(int $sessionid): int {
        global $DB;
        return (int)$DB->get_field_sql(
            "SELECT MAX(timecreated) FROM {local_emotions_adaptlog}
              WHERE sessionid = ? AND decisionstate IN ('PLANNED', 'APPLIED')", [$sessionid]);
    }

    public function get_latest_emotion_record_id(int $sessionid): int {
        global $DB;
        return (int)$DB->get_field_sql(
            "SELECT id FROM {local_emotions_log} WHERE sessionid = ? ORDER BY timecreated DESC, id DESC",
            [$sessionid], IGNORE_MULTIPLE);
    }

    public function record_adaptation_event(array $data): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_adaptlog', (object)[
            'emorecordid'      => (int)$data['emorecordid'],
            'sessionid'        => (int)$data['sessionid'],
            'tokensbefore'     => isset($data['tokensbefore']) ? json_encode($data['tokensbefore']) : null,
            'tokensafter'      => isset($data['tokensafter']) ? json_encode($data['tokensafter']) : null,
            'timecreated'      => time(),
            'experimentalcond' => $data['experimentalcond'] ?? null,
            'decisionstate'    => \core_text::substr($data['decisionstate'], 0, 60),
            'gatereason'       => isset($data['gatereason']) ? \core_text::substr($data['gatereason'], 0, 180) : null,
            'sessionmoment'    => $data['sessionmoment'] ?? null,
            'magnitud'         => $data['magnitud'] ?? null,
        ]);
    }

    public function set_adaptation_decision(int $eventid, string $decisionstate, string $onlyfrom = ''): void {
        global $DB;
        $conditions = ['id' => $eventid];
        if ($onlyfrom !== '') {
            $conditions['decisionstate'] = $onlyfrom;
        }
        $DB->set_field('local_emotions_adaptlog', 'decisionstate', \core_text::substr($decisionstate, 0, 60), $conditions);
    }

    public function find_pending_adaptation_event(int $sessionid, int $since): int {
        global $DB;
        return (int)$DB->get_field_sql(
            "SELECT a.id FROM {local_emotions_adaptlog} a
          LEFT JOIN {local_emotions_adaptfb} f ON f.adaptlogid = a.id
              WHERE a.sessionid = ? AND a.timecreated >= ? AND f.id IS NULL
           ORDER BY a.timecreated DESC, a.id DESC", [$sessionid, $since], IGNORE_MULTIPLE);
    }

    public function get_or_create_rule(string $levelcode, int $emotionid, string $uidimension, string $statement): int {
        global $DB;
        $levelid = (int)$DB->get_field('local_emotions_modellevel', 'id', ['code' => $levelcode], IGNORE_MULTIPLE);
        if (!$levelid) {
            $levelid = (int)$DB->get_field_sql("SELECT MIN(id) FROM {local_emotions_modellevel}");
        }
        $existing = $DB->get_field_select('local_emotions_rule', 'id',
            'modellevelid = ? AND emotionid = ? AND ui_dimension = ? AND ' . $DB->sql_compare_text('rule_statement') . ' = ' . $DB->sql_compare_text('?'),
            [$levelid, $emotionid, $uidimension, $statement], IGNORE_MULTIPLE);
        return $existing ? (int)$existing : $this->create_rule($levelid, $emotionid, $uidimension, $statement);
    }

    public function get_adaptation_event_session(int $eventid): int {
        global $DB;
        return (int)$DB->get_field('local_emotions_adaptlog', 'sessionid', ['id' => $eventid]);
    }

    public function save_adaptation_rating(int $eventid, ?int $usefulness, ?string $comment): void {
        global $DB;
        $existing = $DB->get_record('local_emotions_adaptfb', ['adaptlogid' => $eventid]);
        if ($existing) {
            $existing->perceivedusefulness = $usefulness ?? $existing->perceivedusefulness;
            $existing->comment = $comment ?? $existing->comment;
            $DB->update_record('local_emotions_adaptfb', $existing);
        } else {
            $DB->insert_record('local_emotions_adaptfb', (object)[
                'adaptlogid' => $eventid, 'accepted' => 1,
                'perceivedusefulness' => $usefulness, 'comment' => $comment,
            ]);
        }
    }

    public function mark_adaptation_reverted(int $eventid): void {
        global $DB;
        $DB->set_field('local_emotions_adaptlog', 'revertedat', time(), ['id' => $eventid]);
        $DB->set_field('local_emotions_adaptlog', 'decisionstate', 'REVERTED', ['id' => $eventid]);
    }

    // La instrumentacion de latencia (clock_offset_ms, window_*, decision_*_at, applied_at*,
    // latency_total_ms) existe solo en el esquema del estudio, user_model_v5_1: con las tablas
    // de Moodle no se registra (las marcas del logstore son de segundos enteros).
    public function set_session_clock_offset(int $sessionid, int $offsetms): bool {
        return false;
    }

    public function get_session_clock_offset(int $sessionid): ?int {
        return null;
    }

    public function mark_adaptation_applied(int $eventid, int $appliedclientms, int $appliedserverms): void {
    }

    public function add_adaptation_feedback(int $adaptlogid, ?int $accepted, ?int $usefulness, ?string $comment): int {
        global $DB;
        return (int)$DB->insert_record('local_emotions_adaptfb', (object)[
            'adaptlogid'          => $adaptlogid,
            'accepted'            => $accepted,
            'perceivedusefulness' => $usefulness,
            'comment'             => $comment,
        ]);
    }

    // ---------------------------------------------------------------
    // Perfil extendido del usuario
    // ---------------------------------------------------------------

    public function get_user_profile(int $userid) {
        global $DB;
        return $DB->get_record('local_emotions_userprofile', ['userid' => $userid]);
    }

    public function has_completed_profile(int $userid): bool {
        $profile = $this->get_user_profile($userid);
        return !empty($profile) && !empty($profile->birthdate);
    }

    public function save_user_profile(int $userid, \stdClass $data): void {
        global $DB;
        $record = $this->get_user_profile($userid);
        $payload = (object)[
            'userid'       => $userid,
            'gender'       => $data->gender ?? null,
            'birthdate'    => $data->birthdate ?? 0,
            'programid'    => $data->programid ?? 0,
            'timemodified' => time(),
        ];
        if ($record) {
            $payload->id = $record->id;
            $DB->update_record('local_emotions_userprofile', $payload);
        } else {
            $DB->insert_record('local_emotions_userprofile', $payload);
        }
    }

    public function set_user_program_if_missing(int $userid, int $programid): void {
        global $DB;
        if ($programid <= 0) {
            return;
        }
        $record = $this->get_user_profile($userid);
        if ($record) {
            if (empty($record->programid)) {
                $DB->set_field('local_emotions_userprofile', 'programid', $programid, ['id' => $record->id]);
            }
            return;
        }
        $DB->insert_record('local_emotions_userprofile', (object)[
            'userid' => $userid, 'gender' => null, 'birthdate' => 0,
            'programid' => $programid, 'timemodified' => time(),
        ]);
    }
    // ---------------------------------------------------------------
    // Consultas que antes estaban sueltas en report.php, save.php,
    // debug_tables.php y privacy/provider.php (ahora pasan por el almacen
    // para funcionar igual con la base externa).
    // ---------------------------------------------------------------

    public function get_course_programid(int $localcourseid): int {
        global $DB;
        return (int)$DB->get_field('local_emotions_course', 'programid', ['id' => $localcourseid]);
    }

    public function get_course_records(int $localcourseid, int $since, int $userid = 0): array {
        global $DB;
        $where  = 's.courseid = :cid AND l.timecreated >= :since';
        $params = ['cid' => $localcourseid, 'since' => $since];
        if ($userid > 0) {
            $where .= ' AND s.userid = :uid';
            $params['uid'] = $userid;
        }
        return array_values($DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.confidence, l.intensitydaisee, s.userid,
                    e.name AS emotion, ac.module AS cm_label, ac.activitytype
               FROM {local_emotions_log} l
               JOIN {local_emotions_session} s ON s.id = l.sessionid
          LEFT JOIN {local_emotions_emotion} e ON e.id = l.emotionid
          LEFT JOIN {local_emotions_actcontext} ac ON ac.id = l.actcontextid
              WHERE {$where}
           ORDER BY s.userid, l.timecreated DESC", $params));
    }

    public function get_course_userids(int $localcourseid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM {local_emotions_session} WHERE courseid = ?", [$localcourseid]));
    }

    public function delete_course_records(int $localcourseid, int $userid = 0, bool $withsessions = false): void {
        global $DB;
        $sessionwhere = 'courseid = :cid' . ($userid > 0 ? ' AND userid = :uid' : '');
        $params = ['cid' => $localcourseid, 'uid' => $userid];
        $DB->execute("DELETE FROM {local_emotions_log}
                       WHERE sessionid IN (SELECT id FROM {local_emotions_session} WHERE {$sessionwhere})", $params);
        if ($withsessions) {
            // Todo lo que cuelga de las sesiones (p. ej. al retirar el consentimiento informado):
            // observaciones y adaptaciones con su feedback y sus cambios.
            $adaptlogs = "SELECT id FROM {local_emotions_adaptlog}
                           WHERE sessionid IN (SELECT id FROM {local_emotions_session} WHERE {$sessionwhere})";
            $DB->execute("DELETE FROM {local_emotions_adaptfb} WHERE adaptlogid IN ({$adaptlogs})", $params);
            $DB->execute("DELETE FROM {local_emotions_adaptchange} WHERE eventid IN ({$adaptlogs})", $params);
            $DB->execute("DELETE FROM {local_emotions_adaptlog}
                           WHERE sessionid IN (SELECT id FROM {local_emotions_session} WHERE {$sessionwhere})", $params);
            $DB->execute("DELETE FROM {local_emotions_refobs}
                           WHERE sessionid IN (SELECT id FROM {local_emotions_session} WHERE {$sessionwhere})", $params);
            $DB->execute("DELETE FROM {local_emotions_session} WHERE {$sessionwhere}", $params);
        }
    }

    public function get_user_moodle_course_ids(int $userid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT lc.moodlecourseid
               FROM {local_emotions_session} s
               JOIN {local_emotions_course} lc ON lc.id = s.courseid
              WHERE s.userid = ? AND lc.moodlecourseid IS NOT NULL", [$userid]));
    }

    public function export_user_course_records(int $userid, int $localcourseid): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT l.id, l.timecreated, l.confidence, l.intensitydaisee, e.name AS emotion
               FROM {local_emotions_log} l
               JOIN {local_emotions_session} s ON s.id = l.sessionid
          LEFT JOIN {local_emotions_emotion} e ON e.id = l.emotionid
              WHERE s.userid = ? AND s.courseid = ?
           ORDER BY l.timecreated", [$userid, $localcourseid]));
    }

    public function has_profile_data(int $userid): bool {
        global $DB;
        return $DB->record_exists('local_emotions_userprofile', ['userid' => $userid])
            || $DB->record_exists('local_emotions_userlang', ['userid' => $userid])
            || $DB->record_exists('local_emotions_context', ['userid' => $userid]);
    }

    public function delete_user_profile_data(int $userid): void {
        global $DB;
        $DB->delete_records('local_emotions_userprofile', ['userid' => $userid]);
        $DB->delete_records('local_emotions_userlang', ['userid' => $userid]);
        $DB->delete_records('local_emotions_context', ['userid' => $userid]);
    }

    public function seed_catalogs(): void {
        catalog_seeder::seed();
    }

    /**
     * El log de sesion (Registro_JSON) solo existe en la base externa
     * user_model_v5_1: con las tablas de Moodle no se guarda (sigue solo en
     * el sessionStorage del navegador).
     */
    public function record_session_log_entries(int $sessionid, array $entries): int {
        return 0;
    }

    public function describe(): string {
        return 'Base de datos de Moodle (tablas local_emotions_*)';
    }

    public function debug_tables(int $limit = 15): array {
        global $DB;
        $tables = [
            'local_emotions_emotion'     => 'Catalogo de emociones',
            'local_emotions_language'    => 'Catalogo de idiomas',
            'local_emotions_userlang'    => 'Idiomas por usuario',
            'local_emotions_level'       => 'Niveles academicos',
            'local_emotions_program'     => 'Programas academicos',
            'local_emotions_course'      => 'Cursos (propios del plugin)',
            'local_emotions_actcontext'  => 'Contextos de actividad',
            'local_emotions_context'     => 'Contexto por usuario',
            'local_emotions_userprofile' => 'Perfil extendido de usuario',
            'local_emotions_session'     => 'Learning_Session',
            'local_emotions_model'       => 'Modelos emocionales',
            'local_emotions_modellevel'  => 'Niveles (Norman_Level)',
            'local_emotions_rule'        => 'Reglas de clasificacion',
            'local_emotions_infmodel'    => 'Modelos de inferencia (IA)',
            'local_emotions_log'         => 'Detecciones (Emotion_Record)',
            'local_emotions_refobs'      => 'Reference_Observation',
            'local_emotions_adaptlog'    => 'Adaptation_Event',
            'local_emotions_adaptchange' => 'Adaptation_Change',
            'local_emotions_adaptfb'     => 'Adaptation_Feedback',
        ];
        $out = [];
        foreach ($tables as $table => $label) {
            if (!$DB->get_manager()->table_exists($table)) {
                $out[] = ['table' => $table, 'label' => $label, 'total' => null, 'rows' => []];
                continue;
            }
            // local_emotions_userlang no tiene columna id (clave compuesta).
            $orderby = $DB->get_manager()->field_exists($table, new \xmldb_field('id')) ? 'id DESC' : '';
            $out[] = ['table' => $table, 'label' => $label, 'total' => $DB->count_records($table),
                      'rows' => array_values($DB->get_records($table, null, $orderby, '*', 0, $limit))];
        }
        return $out;
    }
}

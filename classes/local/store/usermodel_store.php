<?php
/**
 * local/emotions_send/classes/local/store/usermodel_store.php
 *
 * Almacenamiento en la base EXTERNA user_model_v5_1 (esquema de
 * user_model_v5.1_mysql.sql), opcion 'usermodel'. Es la UNICA fuente de
 * datos del plugin cuando esta activa: detecciones, sesiones, perfil,
 * idiomas, contexto, catalogos, reglas y eventos de adaptacion.
 *
 * Mapeo tabla a tabla (plugin -> user_model_v5_1):
 *   local_emotions_emotion     -> Emotion              (name -> name_emotion)
 *   local_emotions_language    -> Language             (name -> name_language, code_iso_639_1 -> `code_iso_639-1`)
 *   local_emotions_userlang    -> User_Language        (userid -> id_user)
 *   local_emotions_context     -> Context              (userid -> id_user)
 *   local_emotions_userprofile -> User                 (gender, birthdate -> birth_date DATE)
 *   local_emotions_level       -> Academic_Level
 *   local_emotions_program     -> Academic_Program     (name -> name_academic_program)
 *   local_emotions_course      -> Course               (name -> name_course)
 *   local_emotions_actcontext  -> Activity_context
 *   local_emotions_session     -> Learning_Session     (PK `id_learning_sesion`, fechas DATETIME)
 *   local_emotions_model       -> Emotional_model
 *   local_emotions_modellevel  -> Norman_Level
 *   local_emotions_rule        -> Rule
 *   local_emotions_infmodel    -> Inference_Model
 *   local_emotions_log         -> Emotion_Record       (timecreated -> `date`)
 *   local_emotions_refobs      -> Reference_Observation
 *   local_emotions_adaptlog    -> Adaptation_Event     (`experimiental_condition`, `timestamp`)
 *   local_emotions_adaptfb     -> Adaptation_Feedback  (`preceived_usefulness`)
 *   local_emotions_adaptchange -> Adaptation_Change    (PK `id_adaptatio_change`)
 * (los nombres con errores de tipeo se respetan tal como estan en el script)
 *
 * Lo que el esquema v5.1 NO tiene y como se resuelve:
 *  - Ids de Moodle: User y Course no guardan el id de mdl_user/mdl_course.
 *    La correspondencia vive en Moodle, en local_emotions_extmap
 *    (entity 'user' | 'course', moodleid -> extid). Un usuario que ya
 *    exista en User con el mismo email se enlaza en vez de duplicarse.
 *  - Programa academico declarado por el estudiante: User no tiene
 *    columna de programa; se guarda en Context (type = 'academic_program',
 *    value_context = id_academic_program).
 *  - Fechas: DATETIME en UTC (ver usermodel_db); aqui se exponen como
 *    timestamps, igual que en las tablas de Moodle.
 */
namespace local_emotions_send\local\store;

defined('MOODLE_INTERNAL') || die();

use local_emotions_send\local\data_helper;
use local_emotions_send\local\fer_model;
use local_emotions_send\local\catalog_seeder;
use local_emotions_send\local\usermodel_db as xdb;

class usermodel_store implements store {

    /** Tipo de fila de Context donde se guarda el programa academico del usuario. */
    const CONTEXT_PROGRAM = 'academic_program';

    /** @var bool catalogos verificados en esta peticion */
    private static $seeded = false;

    public function __construct() {
        if (!self::$seeded) {
            self::$seeded = true;
            // Una consulta por peticion: si faltan los catalogos (base recien
            // creada con el script SQL), se cargan antes de usarla. Evita que
            // get_or_create_emotion() cree emociones con codigos inventados.
            if (!xdb::get_field("SELECT COUNT(*) FROM `Emotion` WHERE `code` = 'BOREDOM'")) {
                $this->seed_catalogs();
            }
        }
    }

    // ---------------------------------------------------------------
    // Correspondencia de ids de Moodle <-> user_model_v5_1
    // ---------------------------------------------------------------

    private function map_get(string $entity, int $moodleid): int {
        global $DB;
        return (int)$DB->get_field('local_emotions_extmap', 'extid', ['entity' => $entity, 'moodleid' => $moodleid]);
    }

    private function map_set(string $entity, int $moodleid, int $extid): void {
        global $DB;
        $existing = $DB->get_record('local_emotions_extmap', ['entity' => $entity, 'moodleid' => $moodleid]);
        if ($existing) {
            $DB->set_field('local_emotions_extmap', 'extid', $extid, ['id' => $existing->id]);
        } else {
            $DB->insert_record('local_emotions_extmap', (object)[
                'entity' => $entity, 'moodleid' => $moodleid, 'extid' => $extid, 'timecreated' => time(),
            ]);
        }
    }

    /** Ids de Moodle de un conjunto de ids externos (los que no tienen correspondencia se omiten). */
    private function map_reverse(string $entity, array $extids): array {
        global $DB;
        if (!$extids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values(array_unique($extids)), SQL_PARAMS_NAMED);
        $params['entity'] = $entity;
        $rows = $DB->get_records_select('local_emotions_extmap', "entity = :entity AND extid {$insql}", $params, '', 'id, moodleid, extid');
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->extid] = (int)$r->moodleid;
        }
        return $map;
    }

    /**
     * id_user en user_model_v5_1 del usuario de Moodle. Con $create=true lo
     * crea si falta (enlazando por email si ya existe en User).
     */
    private function ext_user_id(int $userid, bool $create = true): int {
        global $DB, $CFG;
        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email', MUST_EXIST);
        $email = trim((string)$user->email);
        if ($email === '') {
            // User.email es NOT NULL UNIQUE: marcador estable si Moodle no tiene email.
            $email = 'moodle-user-' . $user->id . '@' . (parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'moodle.invalid');
        }

        // La correspondencia solo vale si la fila sigue siendo de ESTE usuario
        // (mismo email). User se puede editar fuera de Moodle (p. ej. cargar
        // estudiantes reutilizando ids): si la fila enlazada ahora tiene otro
        // email, la correspondencia esta obsoleta y NUNCA se escribe en esa fila.
        $extid = $this->map_get('user', $userid);
        if ($extid) {
            $rowemail = xdb::get_field("SELECT `email` FROM `User` WHERE `id_user` = ?", [$extid]);
            if ($rowemail !== false && \core_text::strtolower((string)$rowemail) === \core_text::strtolower($email)) {
                return $extid;
            }
            if ($rowemail !== false) {
                error_log("[local_emotions_send] correspondencia obsoleta: usuario Moodle {$userid} -> User {$extid} "
                    . "(la fila ahora es de {$rowemail}); se re-enlaza por email.");
            }
        }
        if (!$create) {
            // Solo lectura: re-enlaza si ya existe una fila con su email, pero no crea nada.
            $byemail = (int)xdb::get_field("SELECT `id_user` FROM `User` WHERE `email` = ?", [$email]);
            if ($byemail) {
                $this->map_set('user', $userid, $byemail);
            }
            return $byemail;
        }
        $extid = (int)xdb::get_field("SELECT `id_user` FROM `User` WHERE `email` = ?", [$email]);
        if (!$extid) {
            $extid = xdb::insert("INSERT INTO `User` (`name`, `last_name`, `email`) VALUES (?, ?, ?)", [
                \core_text::substr((string)$user->firstname, 0, 120),
                \core_text::substr((string)$user->lastname, 0, 120),
                \core_text::substr($email, 0, 180),
            ]);
        }
        // Columna opcional `usuario` (extension del esquema v5.1, no esta en el
        // script original): nombre de usuario de Moodle, si esta vacia.
        if (self::user_has_usuario_column()) {
            $username = $DB->get_field('user', 'username', ['id' => $userid]);
            xdb::execute("UPDATE `User` SET `usuario` = ? WHERE `id_user` = ? AND (`usuario` IS NULL OR `usuario` = '')",
                [\core_text::substr((string)$username, 0, 100), $extid]);
        }
        $this->map_set('user', $userid, $extid);
        return $extid;
    }

    /** @var bool|null cache por peticion: ¿User tiene la columna opcional `usuario`? */
    private static $hasusuario = null;

    private static function user_has_usuario_column(): bool {
        if (self::$hasusuario === null) {
            $c = xdb::config();
            self::$hasusuario = (bool)xdb::get_field(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'User' AND COLUMN_NAME = 'usuario'", [$c['name']]);
        }
        return self::$hasusuario;
    }

    /**
     * id_user del usuario de Moodle en user_model_v5_1, creandolo/enlazandolo
     * si falta. Publico para scripts de preparacion (p. ej. cargar estudiantes).
     */
    public function ensure_user(int $userid): int {
        return $this->ext_user_id($userid);
    }

    /** Timestamp de Moodle -> fecha 'Y-m-d' en la zona horaria del usuario (y viceversa). */
    private static function epoch_to_date(int $epoch): ?string {
        if ($epoch <= 0) {
            return null;
        }
        $dt = new \DateTime('@' . $epoch);
        $dt->setTimezone(\core_date::get_user_timezone_object());
        return $dt->format('Y-m-d');
    }

    private static function date_to_epoch(?string $date): int {
        if (empty($date)) {
            return 0;
        }
        return (new \DateTime($date . ' 00:00:00', \core_date::get_user_timezone_object()))->getTimestamp();
    }

    // ---------------------------------------------------------------
    // Emotion
    // ---------------------------------------------------------------

    private const EMOTION_COLS = "`id_emotion` AS id, `code`, `name_emotion` AS name, `taxonomy_source`, `valence`, `arousal`";

    public function get_emotions(): array {
        return xdb::get_records("SELECT " . self::EMOTION_COLS . " FROM `Emotion` ORDER BY `name_emotion`");
    }

    public function get_emotion_by_name(string $name) {
        return xdb::get_record("SELECT " . self::EMOTION_COLS . " FROM `Emotion` WHERE `name_emotion` = ? LIMIT 1", [$name]);
    }

    public function get_or_create_emotion(string $name): int {
        $name = trim($name);
        if ($name === '') {
            $name = 'Desconocido';
        }
        $existing = $this->get_emotion_by_name($name);
        if ($existing) {
            return (int)$existing->id;
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        return xdb::insert("INSERT INTO `Emotion` (`code`, `name_emotion`) VALUES (?, ?)",
            [\core_text::substr($code, 0, 40), \core_text::substr($name, 0, 80)]);
    }

    // ---------------------------------------------------------------
    // Language / User_Language
    // ---------------------------------------------------------------

    public function get_languages(): array {
        return xdb::get_records("SELECT `id_language` AS id, `code_iso_639-1` AS code_iso_639_1, `name_language` AS name
                                   FROM `Language` ORDER BY `name_language`");
    }

    public function get_or_create_language(string $name): int {
        $name = trim($name);
        $id = (int)xdb::get_field("SELECT `id_language` FROM `Language` WHERE `name_language` = ? LIMIT 1", [$name]);
        if ($id) {
            return $id;
        }
        // `code_iso_639-1` es UNIQUE: si el codigo derivado ya existe, se usa el idioma existente.
        $code = \core_text::substr(strtolower($name), 0, 10);
        $id = (int)xdb::get_field("SELECT `id_language` FROM `Language` WHERE `code_iso_639-1` = ?", [$code]);
        return $id ?: xdb::insert("INSERT INTO `Language` (`code_iso_639-1`, `name_language`) VALUES (?, ?)",
            [$code, \core_text::substr($name, 0, 80)]);
    }

    public function get_user_languages(int $userid): array {
        $result = ['native' => [], 'other' => [], 'preferred_languageid' => 0];
        $extid = $this->ext_user_id($userid, false);
        if (!$extid) {
            return $result;
        }
        $rows = xdb::get_records(
            "SELECT ul.`id_language` AS languageid, ul.`is_native`, ul.`is_interface_preferred`, l.`name_language` AS name
               FROM `User_Language` ul JOIN `Language` l ON l.`id_language` = ul.`id_language`
              WHERE ul.`id_user` = ? ORDER BY l.`name_language`", [$extid]);
        foreach ($rows as $row) {
            $result[!empty($row->is_native) ? 'native' : 'other'][] = $row;
            if (!empty($row->is_interface_preferred)) {
                $result['preferred_languageid'] = (int)$row->languageid;
            }
        }
        return $result;
    }

    public function set_user_languages(int $userid, array $nativeids, array $otherids, int $preferredid = 0): void {
        $extid = $this->ext_user_id($userid);
        xdb::execute("DELETE FROM `User_Language` WHERE `id_user` = ?", [$extid]);
        $seen = [];
        foreach ([[$nativeids, 1], [$otherids, 0]] as [$ids, $isnative]) {
            foreach (array_unique($ids) as $langid) {
                $langid = (int)$langid;
                if ($langid <= 0 || isset($seen[$langid])) {
                    continue;
                }
                $seen[$langid] = true;
                xdb::insert("INSERT INTO `User_Language` (`id_user`, `id_language`, `is_native`, `is_interface_preferred`)
                             VALUES (?, ?, ?, ?)", [$extid, $langid, $isnative, $langid === $preferredid ? 1 : 0]);
            }
        }
    }

    // ---------------------------------------------------------------
    // Academic_Level / Academic_Program / Course / Activity_context
    // ---------------------------------------------------------------

    public function get_levels(): array {
        return xdb::get_records("SELECT `id_academic_level` AS id, `value` FROM `Academic_Level` ORDER BY `value`");
    }

    public function get_programs(int $levelid = 0): array {
        $sql = "SELECT `id_academic_program` AS id, `name_academic_program` AS name, `id_academic_level` AS levelid, `faculty`
                  FROM `Academic_Program`";
        $params = [];
        if ($levelid > 0) {
            $sql .= " WHERE `id_academic_level` = ?";
            $params[] = $levelid;
        }
        return xdb::get_records($sql . " ORDER BY `name_academic_program`", $params);
    }

    public function create_program(string $name, int $levelid, string $faculty = ''): int {
        if ($levelid <= 0) {
            $levelid = (int)xdb::get_field("SELECT `id_academic_level` FROM `Academic_Level` ORDER BY `id_academic_level` LIMIT 1");
        }
        return xdb::insert("INSERT INTO `Academic_Program` (`id_academic_level`, `name_academic_program`, `faculty`) VALUES (?, ?, ?)",
            [$levelid, \core_text::substr($name, 0, 160), $faculty !== '' ? \core_text::substr($faculty, 0, 160) : null]);
    }

    public function get_course_by_moodle_id(int $moodlecourseid) {
        $extid = $this->map_get('course', $moodlecourseid);
        if (!$extid) {
            return false;
        }
        $row = xdb::get_record("SELECT `id_course` AS id, `id_academic_program` AS programid, `name_course` AS name
                                  FROM `Course` WHERE `id_course` = ?", [$extid]);
        if ($row) {
            $row->moodlecourseid = $moodlecourseid;
        }
        return $row;
    }

    public function create_course(int $programid, string $name, int $moodlecourseid = 0): int {
        $id = xdb::insert("INSERT INTO `Course` (`id_academic_program`, `name_course`) VALUES (?, ?)",
            [$programid, \core_text::substr($name, 0, 180)]);
        if ($moodlecourseid > 0) {
            $this->map_set('course', $moodlecourseid, $id);
        }
        return $id;
    }

    /** Igual que en Moodle: Course y su Academic_Program se deducen de la categoria del curso. */
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
        $programname = catalog_seeder::DEFAULT_PROGRAM;
        if (!empty($course->category)) {
            $category = $DB->get_field('course_categories', 'name', ['id' => $course->category]);
            if ($category) {
                $programname = \core_text::substr(trim($category), 0, 160);
            }
        }
        $programid = (int)xdb::get_field(
            "SELECT `id_academic_program` FROM `Academic_Program` WHERE `name_academic_program` = ? LIMIT 1", [$programname]);
        if (!$programid) {
            $programid = $this->create_program($programname, 0);
        }
        return $this->create_course($programid, $course->fullname, $moodlecourseid);
    }

    public function get_or_create_actcontext(int $courseid, string $module, string $activitytype): int {
        if ($courseid <= 0) {
            return 0;
        }
        $module = \core_text::substr($module, 0, 100);
        $id = (int)xdb::get_field("SELECT `id_activity_context` FROM `Activity_context`
                                    WHERE `id_course` = ? AND `module` = ? LIMIT 1", [$courseid, $module]);
        return $id ?: xdb::insert("INSERT INTO `Activity_context` (`id_course`, `module`, `activity_type`, `is_assessed`)
                                   VALUES (?, ?, ?, 0)",
            [$courseid, $module, \core_text::substr($activitytype !== '' ? $activitytype : 'general', 0, 60)]);
    }

    // ---------------------------------------------------------------
    // Context (filas libres por usuario)
    // ---------------------------------------------------------------

    public function get_user_context_entries(int $userid): array {
        $extid = $this->ext_user_id($userid, false);
        if (!$extid) {
            return [];
        }
        $rows = xdb::get_records("SELECT `id_context` AS id, `id_user` AS userid, `type`, `value_context`
                                    FROM `Context` WHERE `id_user` = ? ORDER BY `type`", [$extid]);
        foreach ($rows as $r) {
            $r->userid = $userid;
        }
        return $rows;
    }

    public function set_user_context_value(int $userid, string $type, string $value): void {
        $type = \core_text::substr($type, 0, 60);
        $extid = $this->ext_user_id($userid, $value !== '');
        if (!$extid) {
            return;
        }
        $id = (int)xdb::get_field("SELECT `id_context` FROM `Context` WHERE `id_user` = ? AND `type` = ? LIMIT 1", [$extid, $type]);
        if ($value === '') {
            if ($id) {
                xdb::execute("DELETE FROM `Context` WHERE `id_context` = ?", [$id]);
            }
        } else if ($id) {
            xdb::execute("UPDATE `Context` SET `value_context` = ? WHERE `id_context` = ?", [$value, $id]);
        } else {
            xdb::insert("INSERT INTO `Context` (`id_user`, `type`, `value_context`) VALUES (?, ?, ?)", [$extid, $type, $value]);
        }
    }

    // ---------------------------------------------------------------
    // Learning_Session
    // ---------------------------------------------------------------

    public function get_or_create_open_session(int $userid, int $localcourseid,
                                               string $devicetype = '', string $viewport = ''): int {
        if ($localcourseid <= 0) {
            return 0;
        }
        $extuser = $this->ext_user_id($userid);
        $open = xdb::get_record(
            "SELECT `id_learning_sesion` AS id, UNIX_TIMESTAMP(`started_at`) AS startedat
               FROM `Learning_Session`
              WHERE `id_user` = ? AND `id_course` = ? AND `ended_at` IS NULL
           ORDER BY `started_at` DESC LIMIT 1", [$extuser, $localcourseid]);
        if ($open) {
            $last = (int)xdb::get_field("SELECT UNIX_TIMESTAMP(MAX(`date`)) FROM `Emotion_Record` WHERE `id_learning_session` = ?",
                [(int)$open->id]);
            if ((time() - max($last, (int)$open->startedat)) < data_helper::SESSION_IDLE_TIMEOUT) {
                // Sesion abierta en el login (sin viewport): se completa con la primera deteccion.
                if ($viewport !== '') {
                    xdb::execute("UPDATE `Learning_Session` SET `viewport` = ?
                                   WHERE `id_learning_sesion` = ? AND `viewport` IS NULL",
                        [\core_text::substr($viewport, 0, 40), (int)$open->id]);
                }
                return (int)$open->id;
            }
            $this->close_session((int)$open->id, 'Timeout');
        }
        return xdb::insert(
            "INSERT INTO `Learning_Session` (`id_user`, `id_course`, `started_at`, `device_type`, `viewport`, `fer_capture_enabled`)
             VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, 1)",
            [$extuser, $localcourseid, time(),
             $devicetype !== '' ? \core_text::substr($devicetype, 0, 60) : null,
             $viewport !== '' ? \core_text::substr($viewport, 0, 40) : null]);
    }

    public function close_session(int $sessionid, string $reason = 'Logout'): void {
        xdb::execute("UPDATE `Learning_Session` SET `ended_at` = FROM_UNIXTIME(?), `closure_reason` = ?
                       WHERE `id_learning_sesion` = ?", [time(), \core_text::substr($reason, 0, 80), $sessionid]);
    }

    public function start_session(int $userid, int $localcourseid, string $devicetype = '', string $viewport = ''): int {
        if ($localcourseid <= 0) {
            return 0;
        }
        $extuser = $this->ext_user_id($userid);
        xdb::execute("UPDATE `Learning_Session` SET `ended_at` = FROM_UNIXTIME(?), `closure_reason` = 'NewLogin'
                       WHERE `id_user` = ? AND `id_course` = ? AND `ended_at` IS NULL", [time(), $extuser, $localcourseid]);
        return xdb::insert(
            "INSERT INTO `Learning_Session` (`id_user`, `id_course`, `started_at`, `device_type`, `viewport`, `fer_capture_enabled`)
             VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, 1)",
            [$extuser, $localcourseid, time(),
             $devicetype !== '' ? \core_text::substr($devicetype, 0, 60) : null,
             $viewport !== '' ? \core_text::substr($viewport, 0, 40) : null]);
    }

    public function close_user_sessions(int $userid, string $reason = 'Logout'): int {
        $extuser = $this->ext_user_id($userid, false);
        if (!$extuser) {
            return 0;
        }
        return xdb::execute("UPDATE `Learning_Session` SET `ended_at` = FROM_UNIXTIME(?), `closure_reason` = ?
                              WHERE `id_user` = ? AND `ended_at` IS NULL",
            [time(), \core_text::substr($reason, 0, 80), $extuser]);
    }

    public function get_session(int $sessionid) {
        $s = xdb::get_record(
            "SELECT `id_learning_sesion` AS id, `id_user` AS extuserid, `id_course` AS courseid,
                    UNIX_TIMESTAMP(`started_at`) AS startedat, UNIX_TIMESTAMP(`ended_at`) AS endedat,
                    `closure_reason` AS closurereason, `device_type` AS devicetype, `viewport`,
                    `fer_capture_enabled` AS fercaptureenabled
               FROM `Learning_Session` WHERE `id_learning_sesion` = ?", [$sessionid]);
        if ($s) {
            $s->userid = $this->map_reverse('user', [(int)$s->extuserid])[(int)$s->extuserid] ?? 0;
        }
        return $s;
    }

    // ---------------------------------------------------------------
    // Emotional_model, Norman_Level, Rule
    // ---------------------------------------------------------------

    public function get_models(): array {
        return xdb::get_records("SELECT `id_emotional_model` AS id, `model_description` AS description
                                   FROM `Emotional_model` ORDER BY `id_emotional_model`");
    }

    public function create_model(string $description): int {
        return xdb::insert("INSERT INTO `Emotional_model` (`model_description`) VALUES (?)", [trim($description)]);
    }

    public function get_model_levels(int $modelid = 0): array {
        $sql = "SELECT `id_norman_level` AS id, `id_emotional_model` AS modelid, `code`, `description_level` AS description
                  FROM `Norman_Level`";
        $params = [];
        if ($modelid > 0) {
            $sql .= " WHERE `id_emotional_model` = ?";
            $params[] = $modelid;
        }
        return xdb::get_records($sql . " ORDER BY `code`", $params);
    }

    public function create_model_level(int $modelid, string $code, string $description = ''): int {
        return xdb::insert("INSERT INTO `Norman_Level` (`id_emotional_model`, `code`, `description_level`) VALUES (?, ?, ?)",
            [$modelid, \core_text::substr(trim($code), 0, 40), $description !== '' ? $description : null]);
    }

    public function get_rules(): array {
        return xdb::get_records(
            "SELECT r.`id_rule` AS id, r.`id_norman_level` AS modellevelid, r.`id_emotion` AS emotionid,
                    r.`ui_dimension`, r.`rule_statement`,
                    m.`model_description` AS modelname, nl.`code` AS levelcode, e.`name_emotion` AS emotionname
               FROM `Rule` r
               JOIN `Norman_Level` nl ON nl.`id_norman_level` = r.`id_norman_level`
               JOIN `Emotional_model` m ON m.`id_emotional_model` = nl.`id_emotional_model`
               JOIN `Emotion` e ON e.`id_emotion` = r.`id_emotion`
           ORDER BY m.`id_emotional_model`, nl.`code`");
    }

    public function create_rule(int $modellevelid, int $emotionid, string $uidimension, string $rulestatement): int {
        return xdb::insert("INSERT INTO `Rule` (`id_norman_level`, `id_emotion`, `ui_dimension`, `rule_statement`) VALUES (?, ?, ?, ?)",
            [$modellevelid, $emotionid, \core_text::substr(trim($uidimension), 0, 60), trim($rulestatement)]);
    }

    public function delete_rule(int $ruleid): void {
        xdb::execute("DELETE FROM `Rule` WHERE `id_rule` = ?", [$ruleid]);
    }

    public function match_rule(int $emotionid): int {
        if ($emotionid <= 0) {
            return 0;
        }
        return (int)xdb::get_field("SELECT `id_rule` FROM `Rule` WHERE `id_emotion` = ? ORDER BY `id_rule` LIMIT 1", [$emotionid]);
    }

    // ---------------------------------------------------------------
    // Inference_Model
    // ---------------------------------------------------------------

    public function get_inference_models(): array {
        return xdb::get_records(
            "SELECT `id_inference_model` AS id, `name`, `version`, `architecture`, `training_dataset` AS trainingdataset,
                    `macro_f1` AS macrof1, `calibration_method` AS calibrationmethod, `per_class_f1` AS perclassf1,
                    `calibration_error` AS calibrationerror
               FROM `Inference_Model` ORDER BY `name`");
    }

    public function register_inference_model(array $data): int {
        return xdb::insert(
            "INSERT INTO `Inference_Model` (`name`, `version`, `architecture`, `training_dataset`, `macro_f1`,
                                            `calibration_method`, `per_class_f1`, `calibration_error`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [\core_text::substr($data['name'] ?? 'Sin especificar', 0, 120),
             \core_text::substr($data['version'] ?? '0', 0, 30),
             $data['architecture'] ?? null, $data['trainingdataset'] ?? null, $data['macrof1'] ?? null,
             $data['calibrationmethod'] ?? null,
             isset($data['perclassf1']) ? json_encode($data['perclassf1']) : null,
             $data['calibrationerror'] ?? null]);
    }

    public function get_default_inference_model_id(): int {
        $id = (int)xdb::get_field("SELECT `id_inference_model` FROM `Inference_Model` WHERE `name` = 'Sin especificar' LIMIT 1");
        return $id ?: $this->register_inference_model(['name' => 'Sin especificar', 'version' => '0']);
    }

    public function get_or_create_client_inference_model_id(): int {
        $name = fer_model::inference_model_name();
        $id = (int)xdb::get_field("SELECT `id_inference_model` FROM `Inference_Model` WHERE `name` = ? AND `version` = ? LIMIT 1",
            [$name, fer_model::MODEL_VERSION]);
        return $id ?: $this->register_inference_model([
            'name'            => $name,
            'version'         => fer_model::MODEL_VERSION,
            'architecture'    => fer_model::MODEL_ARCHITECTURE,
            'trainingdataset' => fer_model::MODEL_DATASET,
        ]);
    }

    // ---------------------------------------------------------------
    // Emotion_Record / Reference_Observation / Adaptation_*
    // ---------------------------------------------------------------

    public function record_emotion(array $data): int {
        return xdb::insert(
            "INSERT INTO `Emotion_Record` (`id_learning_session`, `id_emotion`, `id_activity_context`, `id_inference_model`,
                                           `date`, `intensity_daisee`, `confidence`, `source_model`, `class_probabilities`, `entropy`,
                                           `window_start`, `window_end`, `inference_completed_at`)
             VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, FROM_UNIXTIME(? / 1000), FROM_UNIXTIME(? / 1000), FROM_UNIXTIME(? / 1000))",
            [(int)$data['sessionid'], (int)$data['emotionid'], (int)$data['actcontextid'], (int)$data['infmodelid'],
             (int)($data['timecreated'] ?? time()),
             isset($data['intensitydaisee']) ? (int)$data['intensitydaisee'] : null,
             (float)($data['confidence'] ?? 0),
             $data['sourcemodel'] ?? null,
             isset($data['classprobabilities']) ? json_encode($data['classprobabilities']) : null,
             isset($data['entropy']) ? (float)$data['entropy'] : null,
             // Marcas de la ventana (ms de epoca, UTC, ya corregidas con clock_offset_ms): DATETIME(3).
             self::ms_or_null($data['windowstartms'] ?? null),
             self::ms_or_null($data['windowendms'] ?? null),
             self::ms_or_null($data['inferencecompletedms'] ?? null)]);
    }

    /** Milisegundos de epoca como entero para FROM_UNIXTIME(? / 1000); null si no hay marca. */
    private static function ms_or_null($ms): ?int {
        return $ms === null ? null : (int)$ms;
    }

    public function record_reference_observation(int $sessionid, int $emotionid, string $method,
                                                 ?string $observer = null, ?int $intensity = null): int {
        return xdb::insert(
            "INSERT INTO `Reference_Observation` (`id_learning_session`, `id_emotion`, `observed_at`, `method`, `observer`, `intensity`)
             VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, ?)",
            [$sessionid, $emotionid, time(), \core_text::substr($method, 0, 60), $observer, $intensity]);
    }

    public function log_adaptation_event(int $emorecordid, int $sessionid, string $decisionstate = 'APPLIED',
                                         ?string $experimentalcond = null, ?string $gatereason = null): int {
        return xdb::insert(
            "INSERT INTO `Adaptation_Event` (`id_emotion_record`, `id_learning_session`, `timestamp`,
                                             `experimiental_condition`, `decision_state`, `gate_reason`)
             VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, ?)",
            [$emorecordid, $sessionid, time(), $experimentalcond, \core_text::substr($decisionstate, 0, 60), $gatereason]);
    }

    public function log_adaptation_change(int $eventid, int $ruleid, string $uidimension,
                                          string $targettoken, ?string $before, ?string $after): int {
        return xdb::insert(
            "INSERT INTO `Adaptation_Change` (`id_adaptation_event`, `id_rule`, `ui_dimension`, `target_token`, `value_before`, `value_after`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$eventid, $ruleid, \core_text::substr($uidimension, 0, 60), \core_text::substr($targettoken, 0, 120), $before, $after]);
    }

    public function get_emotion_intensity_mode(int $sessionid, int $emotionid, int $since): ?int {
        $v = xdb::get_field(
            "SELECT `intensity_daisee` FROM `Emotion_Record`
              WHERE `id_learning_session` = ? AND `id_emotion` = ? AND `date` >= FROM_UNIXTIME(?)
                AND `intensity_daisee` IS NOT NULL
           GROUP BY `intensity_daisee` ORDER BY COUNT(*) DESC, `intensity_daisee` DESC LIMIT 1",
            [$sessionid, $emotionid, $since]);
        return $v === false || $v === null ? null : (int)$v;
    }

    public function get_last_adaptation_time(int $sessionid): int {
        return (int)xdb::get_field(
            "SELECT UNIX_TIMESTAMP(MAX(`timestamp`)) FROM `Adaptation_Event`
              WHERE `id_learning_session` = ? AND `decision_state` IN ('PLANNED', 'APPLIED')", [$sessionid]);
    }

    public function get_latest_emotion_record_id(int $sessionid): int {
        return (int)xdb::get_field("SELECT `id_emotion_record` FROM `Emotion_Record` WHERE `id_learning_session` = ?
                                     ORDER BY `date` DESC, `id_emotion_record` DESC LIMIT 1", [$sessionid]);
    }

    public function record_adaptation_event(array $data): int {
        return xdb::insert(
            "INSERT INTO `Adaptation_Event` (`id_emotion_record`, `id_learning_session`, `tokens_before`, `tokens_after`,
                                             `timestamp`, `experimiental_condition`, `decision_state`, `gate_reason`,
                                             `session_moment`, `magnitud`, `decision_requested_at`, `decision_generated_at`)
             VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, FROM_UNIXTIME(? / 1000), FROM_UNIXTIME(? / 1000))",
            [(int)$data['emorecordid'], (int)$data['sessionid'],
             isset($data['tokensbefore']) ? json_encode($data['tokensbefore']) : null,
             isset($data['tokensafter']) ? json_encode($data['tokensafter']) : null,
             time(), $data['experimentalcond'] ?? null,
             \core_text::substr($data['decisionstate'], 0, 60),
             isset($data['gatereason']) ? \core_text::substr($data['gatereason'], 0, 180) : null,
             $data['sessionmoment'] ?? null,
             isset($data['magnitud']) ? (float)$data['magnitud'] : null,
             // Tramo de decision (reloj del servidor): envio de la solicitud al LLM y recepcion de la especificacion.
             self::ms_or_null($data['decisionrequestedms'] ?? null),
             self::ms_or_null($data['decisiongeneratedms'] ?? null)]);
    }

    public function set_adaptation_decision(int $eventid, string $decisionstate, string $onlyfrom = ''): void {
        $sql = "UPDATE `Adaptation_Event` SET `decision_state` = ? WHERE `id_adaptation_event` = ?";
        $params = [\core_text::substr($decisionstate, 0, 60), $eventid];
        if ($onlyfrom !== '') {
            $sql .= " AND `decision_state` = ?";
            $params[] = $onlyfrom;
        }
        xdb::execute($sql, $params);
    }

    public function find_pending_adaptation_event(int $sessionid, int $since): int {
        return (int)xdb::get_field(
            "SELECT ae.`id_adaptation_event` FROM `Adaptation_Event` ae
          LEFT JOIN `Adaptation_Feedback` af ON af.`id_adaptation_event` = ae.`id_adaptation_event`
              WHERE ae.`id_learning_session` = ? AND ae.`timestamp` >= FROM_UNIXTIME(?) AND af.`id_feedback` IS NULL
           ORDER BY ae.`timestamp` DESC, ae.`id_adaptation_event` DESC LIMIT 1", [$sessionid, $since]);
    }

    public function get_or_create_rule(string $levelcode, int $emotionid, string $uidimension, string $statement): int {
        $levelid = (int)xdb::get_field("SELECT `id_norman_level` FROM `Norman_Level` WHERE `code` = ? ORDER BY `id_norman_level` LIMIT 1",
            [$levelcode]);
        if (!$levelid) {
            $levelid = (int)xdb::get_field("SELECT MIN(`id_norman_level`) FROM `Norman_Level`");
        }
        $existing = (int)xdb::get_field(
            "SELECT `id_rule` FROM `Rule` WHERE `id_norman_level` = ? AND `id_emotion` = ? AND `ui_dimension` = ?
                AND `rule_statement` = ? LIMIT 1", [$levelid, $emotionid, $uidimension, $statement]);
        return $existing ?: $this->create_rule($levelid, $emotionid, $uidimension, $statement);
    }

    public function get_adaptation_event_session(int $eventid): int {
        return (int)xdb::get_field("SELECT `id_learning_session` FROM `Adaptation_Event` WHERE `id_adaptation_event` = ?",
            [$eventid]);
    }

    public function save_adaptation_rating(int $eventid, ?int $usefulness, ?string $comment): void {
        // Adaptation_Feedback.id_adaptation_event es UNIQUE: se actualiza la fila creada al
        // aceptar la adaptacion; si aun no existe (carrera con record_action), se crea.
        $n = xdb::execute(
            "UPDATE `Adaptation_Feedback`
                SET `preceived_usefulness` = COALESCE(?, `preceived_usefulness`), `comment` = COALESCE(?, `comment`)
              WHERE `id_adaptation_event` = ?", [$usefulness, $comment, $eventid]);
        if ($n === 0 && !xdb::get_field("SELECT COUNT(*) FROM `Adaptation_Feedback` WHERE `id_adaptation_event` = ?", [$eventid])) {
            xdb::insert("INSERT INTO `Adaptation_Feedback` (`id_adaptation_event`, `accepted`, `preceived_usefulness`, `comment`)
                         VALUES (?, 1, ?, ?)", [$eventid, $usefulness, $comment]);
        }
    }

    public function mark_adaptation_reverted(int $eventid): void {
        xdb::execute("UPDATE `Adaptation_Event` SET `reverted_at` = FROM_UNIXTIME(?), `decision_state` = 'REVERTED'
                       WHERE `id_adaptation_event` = ?", [time(), $eventid]);
    }

    public function set_session_clock_offset(int $sessionid, int $offsetms): bool {
        // Una sola escritura por sesion: el valor medido al abrirla no se reemplaza.
        return xdb::execute("UPDATE `Learning_Session` SET `clock_offset_ms` = ?
                              WHERE `id_learning_sesion` = ? AND `clock_offset_ms` IS NULL", [$offsetms, $sessionid]) > 0;
    }

    public function get_session_clock_offset(int $sessionid): ?int {
        $v = xdb::get_field("SELECT `clock_offset_ms` FROM `Learning_Session` WHERE `id_learning_sesion` = ?", [$sessionid]);
        return $v === false || $v === null ? null : (int)$v;
    }

    public function mark_adaptation_applied(int $eventid, int $appliedclientms, int $appliedserverms): void {
        // latency_total_ms NO se escribe aqui: lo deriva el trigger trg_adaptation_event_latency_bu
        // (applied_at_server - Emotion_Record.window_end) en este mismo UPDATE.
        xdb::execute("UPDATE `Adaptation_Event`
                         SET `applied_at` = FROM_UNIXTIME(? / 1000), `applied_at_server` = FROM_UNIXTIME(? / 1000)
                       WHERE `id_adaptation_event` = ? AND `applied_at_server` IS NULL",
            [$appliedclientms, $appliedserverms, $eventid]);
    }

    public function add_adaptation_feedback(int $adaptlogid, ?int $accepted, ?int $usefulness, ?string $comment): int {
        return xdb::insert(
            "INSERT INTO `Adaptation_Feedback` (`id_adaptation_event`, `accepted`, `preceived_usefulness`, `comment`)
             VALUES (?, ?, ?, ?)", [$adaptlogid, $accepted, $usefulness, $comment]);
    }

    // ---------------------------------------------------------------
    // Perfil del usuario (User + Context 'academic_program')
    // ---------------------------------------------------------------

    public function get_user_profile(int $userid) {
        $extid = $this->ext_user_id($userid, false);
        if (!$extid) {
            return false;
        }
        $u = xdb::get_record("SELECT `id_user` AS id, `gender`, `birth_date` FROM `User` WHERE `id_user` = ?", [$extid]);
        if (!$u) {
            return false;
        }
        $programid = (int)xdb::get_field("SELECT `value_context` FROM `Context` WHERE `id_user` = ? AND `type` = ? LIMIT 1",
            [$extid, self::CONTEXT_PROGRAM]);
        return (object)[
            'id'           => (int)$u->id,
            'userid'       => $userid,
            'gender'       => $u->gender,
            'birthdate'    => self::date_to_epoch($u->birth_date),
            'programid'    => $programid,
            'timemodified' => 0,
        ];
    }

    public function has_completed_profile(int $userid): bool {
        $extid = $this->ext_user_id($userid, false);
        return $extid && xdb::get_field("SELECT `birth_date` IS NOT NULL FROM `User` WHERE `id_user` = ?", [$extid]);
    }

    public function save_user_profile(int $userid, \stdClass $data): void {
        $extid = $this->ext_user_id($userid);
        xdb::execute("UPDATE `User` SET `gender` = ?, `birth_date` = ? WHERE `id_user` = ?", [
            isset($data->gender) && $data->gender !== '' ? \core_text::substr((string)$data->gender, 0, 40) : null,
            self::epoch_to_date((int)($data->birthdate ?? 0)),
            $extid,
        ]);
        $this->set_user_context_value($userid, self::CONTEXT_PROGRAM, !empty($data->programid) ? (string)(int)$data->programid : '');
    }

    public function set_user_program_if_missing(int $userid, int $programid): void {
        if ($programid <= 0) {
            return;
        }
        $extid = $this->ext_user_id($userid);
        if (!xdb::get_field("SELECT COUNT(*) FROM `Context` WHERE `id_user` = ? AND `type` = ?", [$extid, self::CONTEXT_PROGRAM])) {
            $this->set_user_context_value($userid, self::CONTEXT_PROGRAM, (string)$programid);
        }
    }

    // ---------------------------------------------------------------
    // Reporte, privacidad, inspector
    // ---------------------------------------------------------------

    public function get_course_programid(int $localcourseid): int {
        return (int)xdb::get_field("SELECT `id_academic_program` FROM `Course` WHERE `id_course` = ?", [$localcourseid]);
    }

    public function get_course_records(int $localcourseid, int $since, int $userid = 0): array {
        $sql = "SELECT er.`id_emotion_record` AS id, UNIX_TIMESTAMP(er.`date`) AS timecreated, er.`confidence`,
                       er.`intensity_daisee` AS intensitydaisee, s.`id_user` AS extuserid,
                       e.`name_emotion` AS emotion, ac.`module` AS cm_label, ac.`activity_type` AS activitytype
                  FROM `Emotion_Record` er
                  JOIN `Learning_Session` s ON s.`id_learning_sesion` = er.`id_learning_session`
             LEFT JOIN `Emotion` e ON e.`id_emotion` = er.`id_emotion`
             LEFT JOIN `Activity_context` ac ON ac.`id_activity_context` = er.`id_activity_context`
                 WHERE s.`id_course` = ? AND er.`date` >= FROM_UNIXTIME(?)";
        $params = [$localcourseid, $since];
        if ($userid > 0) {
            $extid = $this->ext_user_id($userid, false);
            if (!$extid) {
                return [];
            }
            $sql .= " AND s.`id_user` = ?";
            $params[] = $extid;
        }
        $rows = xdb::get_list($sql . " ORDER BY s.`id_user`, er.`date` DESC", $params);
        $map = $this->map_reverse('user', array_map(function ($r) {
            return (int)$r->extuserid;
        }, $rows));
        foreach ($rows as $r) {
            // Usuarios creados fuera de Moodle (sin correspondencia) quedan con userid 0.
            $r->userid = $map[(int)$r->extuserid] ?? 0;
        }
        return $rows;
    }

    public function get_course_userids(int $localcourseid): array {
        $ext = array_map(function ($r) {
            return (int)$r->id_user;
        }, xdb::get_list("SELECT DISTINCT `id_user` FROM `Learning_Session` WHERE `id_course` = ?", [$localcourseid]));
        return array_values($this->map_reverse('user', $ext));
    }

    public function delete_course_records(int $localcourseid, int $userid = 0, bool $withsessions = false): void {
        $where = "`id_course` = ?";
        $params = [$localcourseid];
        if ($userid > 0) {
            $extid = $this->ext_user_id($userid, false);
            if (!$extid) {
                return;
            }
            $where .= " AND `id_user` = ?";
            $params[] = $extid;
        }
        if ($withsessions) {
            // ON DELETE CASCADE borra tambien Emotion_Record, Reference_Observation y Adaptation_*.
            xdb::execute("DELETE FROM `Learning_Session` WHERE {$where}", $params);
        } else {
            xdb::execute("DELETE FROM `Emotion_Record` WHERE `id_learning_session` IN
                            (SELECT `id_learning_sesion` FROM `Learning_Session` WHERE {$where})", $params);
        }
    }

    public function get_user_moodle_course_ids(int $userid): array {
        global $DB;
        $extid = $this->ext_user_id($userid, false);
        if (!$extid) {
            return [];
        }
        $extcourses = array_map(function ($r) {
            return (int)$r->id_course;
        }, xdb::get_list("SELECT DISTINCT `id_course` FROM `Learning_Session` WHERE `id_user` = ?", [$extid]));
        return array_values($this->map_reverse('course', $extcourses));
    }

    public function export_user_course_records(int $userid, int $localcourseid): array {
        $extid = $this->ext_user_id($userid, false);
        if (!$extid) {
            return [];
        }
        return xdb::get_list(
            "SELECT er.`id_emotion_record` AS id, UNIX_TIMESTAMP(er.`date`) AS timecreated, er.`confidence`,
                    er.`intensity_daisee` AS intensitydaisee, e.`name_emotion` AS emotion
               FROM `Emotion_Record` er
               JOIN `Learning_Session` s ON s.`id_learning_sesion` = er.`id_learning_session`
          LEFT JOIN `Emotion` e ON e.`id_emotion` = er.`id_emotion`
              WHERE s.`id_user` = ? AND s.`id_course` = ?
           ORDER BY er.`date`", [$extid, $localcourseid]);
    }

    public function has_profile_data(int $userid): bool {
        $extid = $this->ext_user_id($userid, false);
        if (!$extid) {
            return false;
        }
        return (bool)xdb::get_field(
            "SELECT (u.`gender` IS NOT NULL OR u.`birth_date` IS NOT NULL
                     OR EXISTS (SELECT 1 FROM `User_Language` WHERE `id_user` = u.`id_user`)
                     OR EXISTS (SELECT 1 FROM `Context` WHERE `id_user` = u.`id_user`))
               FROM `User` u WHERE u.`id_user` = ?", [$extid]);
    }

    public function delete_user_profile_data(int $userid): void {
        $extid = $this->ext_user_id($userid, false);
        if (!$extid) {
            return;
        }
        xdb::execute("UPDATE `User` SET `gender` = NULL, `birth_date` = NULL WHERE `id_user` = ?", [$extid]);
        xdb::execute("DELETE FROM `User_Language` WHERE `id_user` = ?", [$extid]);
        xdb::execute("DELETE FROM `Context` WHERE `id_user` = ?", [$extid]);
    }

    /** Mismos catalogos que catalog_seeder (instalacion en Moodle), idempotente. */
    public function seed_catalogs(): void {
        foreach (catalog_seeder::EMOTIONS as $code => $name) {
            if (xdb::get_field("SELECT COUNT(*) FROM `Emotion` WHERE `code` = ?", [$code])) {
                continue;
            }
            $byname = (int)xdb::get_field("SELECT `id_emotion` FROM `Emotion` WHERE `name_emotion` = ? LIMIT 1", [$name]);
            if ($byname) {
                xdb::execute("UPDATE `Emotion` SET `code` = ? WHERE `id_emotion` = ?", [$code, $byname]);
            } else {
                xdb::insert("INSERT INTO `Emotion` (`code`, `name_emotion`) VALUES (?, ?)", [$code, $name]);
            }
        }
        foreach (catalog_seeder::LANGUAGES as $iso => $name) {
            if (!xdb::get_field("SELECT COUNT(*) FROM `Language` WHERE `code_iso_639-1` = ?", [$iso])) {
                xdb::insert("INSERT INTO `Language` (`code_iso_639-1`, `name_language`) VALUES (?, ?)", [$iso, $name]);
            }
        }
        foreach (catalog_seeder::LEVELS as $value) {
            if (!xdb::get_field("SELECT COUNT(*) FROM `Academic_Level` WHERE `value` = ?", [$value])) {
                xdb::insert("INSERT INTO `Academic_Level` (`value`) VALUES (?)", [$value]);
            }
        }
        if (!xdb::get_field("SELECT COUNT(*) FROM `Academic_Program` WHERE `name_academic_program` = ?",
                [catalog_seeder::DEFAULT_PROGRAM])) {
            $levelid = (int)xdb::get_field("SELECT `id_academic_level` FROM `Academic_Level` WHERE `value` = ? LIMIT 1",
                [catalog_seeder::LEVELS[0]]);
            xdb::insert("INSERT INTO `Academic_Program` (`id_academic_level`, `name_academic_program`) VALUES (?, ?)",
                [$levelid, catalog_seeder::DEFAULT_PROGRAM]);
        }
        $modelid = (int)xdb::get_field("SELECT `id_emotional_model` FROM `Emotional_model` WHERE `model_description` = ? LIMIT 1",
            [catalog_seeder::NORMAN_MODEL]);
        if (!$modelid) {
            $modelid = xdb::insert("INSERT INTO `Emotional_model` (`model_description`) VALUES (?)", [catalog_seeder::NORMAN_MODEL]);
        }
        foreach (catalog_seeder::NORMAN_LEVELS as $code) {
            if (!xdb::get_field("SELECT COUNT(*) FROM `Norman_Level` WHERE `id_emotional_model` = ? AND `code` = ?", [$modelid, $code])) {
                xdb::insert("INSERT INTO `Norman_Level` (`id_emotional_model`, `code`) VALUES (?, ?)", [$modelid, $code]);
            }
        }
        $this->get_default_inference_model_id();
    }

    /** @var bool|null cache por peticion: ¿existe la tabla Registro_JSON? */
    private static $hasregistrojson = null;

    /**
     * Guarda entradas del log de sesion (ya validadas por
     * data_helper::clean_session_log_entries) en Registro_JSON, ligadas a la
     * Learning_Session. Registro_JSON es una extension del esquema v5.1 (la
     * crea el ambiente de prueba); si no existe, no se guarda nada.
     */
    public function record_session_log_entries(int $sessionid, array $entries): int {
        if (self::$hasregistrojson === null) {
            $c = xdb::config();
            self::$hasregistrojson = (bool)xdb::get_field(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'Registro_JSON'",
                [$c['name']]);
        }
        if (!self::$hasregistrojson) {
            error_log('[local_emotions_send] Registro_JSON no existe en la base externa: el log de sesion no se guarda.');
            return 0;
        }
        $n = 0;
        foreach ($entries as $e) {
            xdb::insert(
                "INSERT INTO `Registro_JSON` (`id_learning_session`, `recorded_at`, `type`, `timestamp`,
                                              `duration_seconds`, `emotion`, `level`, `probability`)
                 VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?)",
                [$sessionid > 0 ? $sessionid : null, (int)$e['epoch'], $e['type'], (float)$e['timestamp'],
                 $e['duration_seconds'], $e['emotion'], $e['level'], $e['probability']]);
            $n++;
        }
        return $n;
    }

    public function describe(): string {
        $c = xdb::config();
        return "Base externa {$c['name']} en {$c['host']}:{$c['port']} (usuario {$c['user']})";
    }

    public function debug_tables(int $limit = 15): array {
        $labels = [
            'Emotion' => 'Catalogo de emociones', 'Language' => 'Catalogo de idiomas', 'User' => 'Usuarios',
            'User_Language' => 'Idiomas por usuario', 'Context' => 'Contexto por usuario',
            'Academic_Level' => 'Niveles academicos', 'Academic_Program' => 'Programas academicos',
            'Course' => 'Cursos', 'Activity_context' => 'Contextos de actividad', 'Learning_Session' => 'Learning_Session',
            'Emotional_model' => 'Modelos emocionales', 'Norman_Level' => 'Niveles (Norman_Level)',
            'Rule' => 'Reglas', 'Inference_Model' => 'Modelos de inferencia (IA)',
            'Emotion_Record' => 'Detecciones (Emotion_Record)', 'Reference_Observation' => 'Reference_Observation',
            'Adaptation_Event' => 'Adaptation_Event', 'Adaptation_Change' => 'Adaptation_Change',
            'Adaptation_Feedback' => 'Adaptation_Feedback',
        ];
        $missing = xdb::check_schema();
        $out = [];
        foreach ($labels as $table => $label) {
            if (in_array($table, $missing)) {
                $out[] = ['table' => $table, 'label' => $label, 'total' => null, 'rows' => []];
                continue;
            }
            // Orden por la primera columna (la clave) descendente: las filas mas recientes primero.
            $total = (int)xdb::get_field("SELECT COUNT(*) FROM `{$table}`");
            $rows = xdb::get_list("SELECT * FROM `{$table}` ORDER BY 1 DESC LIMIT " . (int)$limit);
            $out[] = ['table' => $table, 'label' => $label, 'total' => $total, 'rows' => $rows];
        }
        return $out;
    }
}

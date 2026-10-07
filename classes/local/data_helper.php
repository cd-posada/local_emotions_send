<?php
/**
 * local/emotions_send/classes/local/data_helper.php
 *
 * Punto UNICO de acceso a datos del plugin (modelo v5). Desde la v6.2 es
 * una fachada: cada metodo delega en el almacen configurado en el ajuste
 * 'storage' del plugin (Administracion del sitio > Plugins > Plugins
 * locales > Emotions Send):
 *
 *   - 'moodle'    (por defecto): tablas local_emotions_* de Moodle
 *                 (store/moodle_store.php).
 *   - 'usermodel': base de datos EXTERNA user_model_v5_1, creada con
 *                 user_model_v5.1_mysql.sql (store/usermodel_store.php,
 *                 conexion en usermodel_db.php). Es la unica fuente de
 *                 datos cuando esta activa.
 *
 * Las paginas (save.php, report.php, profile.php, manage_rules.php,
 * debug_tables.php, privacy) solo hablan con esta clase, asi que no
 * dependen de cual almacen se use. El contrato esta en store/store.php.
 */
namespace local_emotions_send\local;

defined('MOODLE_INTERNAL') || die();

use local_emotions_send\local\store\store;
use local_emotions_send\local\store\moodle_store;
use local_emotions_send\local\store\usermodel_store;

class data_helper {

    /** Si una sesion abierta no recibe detecciones en este tiempo, se
     * considera abandonada y se cierra sola al pedir la siguiente. */
    const SESSION_IDLE_TIMEOUT = 1800; // 30 minutos

    const STORAGE_MOODLE    = 'moodle';
    const STORAGE_USERMODEL = 'usermodel';

    /** \var store|null */
    private static $store = null;

    /** Almacen configurado ('moodle' si el ajuste no existe o no es valido). */
    public static function storage_mode(): string {
        $mode = get_config('local_emotions_send', 'storage');
        return $mode === self::STORAGE_USERMODEL ? self::STORAGE_USERMODEL : self::STORAGE_MOODLE;
    }

    public static function store(): store {
        if (self::$store === null) {
            self::$store = self::storage_mode() === self::STORAGE_USERMODEL ? new usermodel_store() : new moodle_store();
        }
        return self::$store;
    }

    /** Metodo con que se registran en Reference_Observation las emociones detectadas por el plugin. */
    const REFOBS_METHOD = 'FER_ONNX';

    /** Tipo de dispositivo (Desktop, Tablet, Mobile) a partir del User-Agent. */
    public static function detect_device_type(string $useragent): string {
        if (preg_match('/tablet|ipad/i', $useragent)) {
            return 'Tablet';
        }
        if (preg_match('/mobile|android|iphone/i', $useragent)) {
            return 'Mobile';
        }
        return 'Desktop';
    }

    /** Maximo de entradas del log de sesion aceptadas por peticion a log.php. */
    const SESSION_LOG_MAX_BATCH = 200;

    /**
     * Valida las entradas del log de sesion que envia session_log.js (mismo
     * formato que sessionStorage['emotions_log']):
     *   {type:'second'|'window', timestamp, duration_seconds, emotion, level,
     *    probability, epoch_ms}
     * epoch_ms es la hora del navegador en que se genero la entrada (solo
     * viaja al servidor, no se guarda en sessionStorage). Descarta las
     * entradas invalidas; retorna arreglos limpios con 'epoch' en segundos
     * (acotado a las ultimas 24 h; si no es valido, la hora del servidor).
     */
    public static function clean_session_log_entries(array $raw): array {
        $now = time();
        $clean = [];
        foreach (array_slice($raw, 0, self::SESSION_LOG_MAX_BATCH) as $e) {
            if (!is_array($e) || !in_array($e['type'] ?? null, ['second', 'window'], true)) {
                continue;
            }
            $ts = $e['timestamp'] ?? null;
            if (!is_numeric($ts) || $ts < 0 || $ts > 99999999) {
                continue;
            }
            $dur = $e['duration_seconds'] ?? null;
            $dur = (is_numeric($dur) && $dur >= 0 && $dur < 1000000) ? round((float)$dur, 2) : null;
            $emotion = $e['emotion'] ?? null;
            $emotion = is_string($emotion) && trim($emotion) !== '' ? \core_text::substr(clean_param(trim($emotion), PARAM_TEXT), 0, 80) : null;
            $level = $e['level'] ?? null;
            $level = (is_numeric($level) && (int)$level == $level && $level >= 0 && $level <= 3) ? (int)$level : null;
            $prob = $e['probability'] ?? null;
            $prob = (is_numeric($prob) && $prob >= 0 && $prob <= 1) ? round((float)$prob, 4) : null;
            $epoch = isset($e['epoch_ms']) && is_numeric($e['epoch_ms']) ? (int)floor($e['epoch_ms'] / 1000) : 0;
            if ($epoch < $now - DAYSECS || $epoch > $now + 60) {
                $epoch = $now;
            }
            $clean[] = [
                'type' => $e['type'], 'timestamp' => round((float)$ts, 2), 'duration_seconds' => $dur,
                'emotion' => $emotion, 'level' => $level, 'probability' => $prob, 'epoch' => $epoch,
            ];
        }
        return $clean;
    }

    public static function record_session_log_entries(int $sessionid, array $entries): int {
        return self::store()->record_session_log_entries($sessionid, $entries);
    }

    /** Solo para pruebas / CLI: olvida el almacen para releer la configuracion. */
    public static function reset_store(): void {
        self::$store = null;
    }

    /**
     * Valida el JSON de probabilidades que manda el cliente: un objeto con
     * exactamente las 4 ramas (Boredom, Engagement, Confusion, Frustration),
     * cada una con 4 probabilidades (niveles 0-3) en [0, 1]. Retorna el
     * arreglo limpio (redondeado a 4 decimales) o null si no es valido.
     */
    public static function clean_branch_probabilities(string $raw): ?array {
        if ($raw === '' || strlen($raw) > 2000) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $clean = [];
        foreach (['Boredom', 'Engagement', 'Confusion', 'Frustration'] as $state) {
            if (!isset($decoded[$state]) || !is_array($decoded[$state]) || count($decoded[$state]) !== 4) {
                return null;
            }
            $clean[$state] = [];
            foreach (array_values($decoded[$state]) as $p) {
                if (!is_numeric($p) || $p < 0 || $p > 1) {
                    return null;
                }
                $clean[$state][] = round((float)$p, 4);
            }
        }
        return count($decoded) === 4 ? $clean : null;
    }

    // ---- Delegacion al almacen (ver store/store.php) ----

    public static function get_emotions(): array {
        return self::store()->get_emotions();
    }

    public static function get_emotion_by_name(string $name) {
        return self::store()->get_emotion_by_name($name);
    }

    public static function get_or_create_emotion(string $name): int {
        return self::store()->get_or_create_emotion($name);
    }

    public static function get_languages(): array {
        return self::store()->get_languages();
    }

    public static function get_or_create_language(string $name): int {
        return self::store()->get_or_create_language($name);
    }

    public static function get_user_languages(int $userid): array {
        return self::store()->get_user_languages($userid);
    }

    public static function set_user_languages(int $userid, array $nativeids, array $otherids, int $preferredid = 0): void {
        self::store()->set_user_languages($userid, $nativeids, $otherids, $preferredid);
    }

    public static function get_levels(): array {
        return self::store()->get_levels();
    }

    public static function get_programs(int $levelid = 0): array {
        return self::store()->get_programs($levelid);
    }

    public static function create_program(string $name, int $levelid, string $faculty = ''): int {
        return self::store()->create_program($name, $levelid, $faculty);
    }

    public static function get_course_by_moodle_id(int $moodlecourseid) {
        return self::store()->get_course_by_moodle_id($moodlecourseid);
    }

    public static function create_course(int $programid, string $name, int $moodlecourseid = 0): int {
        return self::store()->create_course($programid, $name, $moodlecourseid);
    }

    public static function get_or_create_course_from_moodle(int $moodlecourseid): int {
        return self::store()->get_or_create_course_from_moodle($moodlecourseid);
    }

    public static function get_or_create_actcontext(int $courseid, string $module, string $activitytype): int {
        return self::store()->get_or_create_actcontext($courseid, $module, $activitytype);
    }

    public static function get_user_context_entries(int $userid): array {
        return self::store()->get_user_context_entries($userid);
    }

    public static function set_user_context_value(int $userid, string $type, string $value): void {
        self::store()->set_user_context_value($userid, $type, $value);
    }

    public static function get_or_create_open_session(int $userid, int $localcourseid, string $devicetype = '', string $viewport = ''): int {
        return self::store()->get_or_create_open_session($userid, $localcourseid, $devicetype, $viewport);
    }

    public static function close_session(int $sessionid, string $reason = 'Logout'): void {
        self::store()->close_session($sessionid, $reason);
    }

    /** Abre una sesion NUEVA (cierra antes la abierta del mismo curso). La usa el login. */
    public static function start_session(int $userid, int $localcourseid, string $devicetype = '', string $viewport = ''): int {
        return self::store()->start_session($userid, $localcourseid, $devicetype, $viewport);
    }

    /** Cierra todas las sesiones abiertas del usuario (logout). Retorna cuantas cerro. */
    public static function close_user_sessions(int $userid, string $reason = 'Logout'): int {
        return self::store()->close_user_sessions($userid, $reason);
    }

    public static function get_session(int $sessionid) {
        return self::store()->get_session($sessionid);
    }

    public static function get_models(): array {
        return self::store()->get_models();
    }

    public static function create_model(string $description): int {
        return self::store()->create_model($description);
    }

    public static function get_model_levels(int $modelid = 0): array {
        return self::store()->get_model_levels($modelid);
    }

    public static function create_model_level(int $modelid, string $code, string $description = ''): int {
        return self::store()->create_model_level($modelid, $code, $description);
    }

    public static function get_rules(): array {
        return self::store()->get_rules();
    }

    public static function create_rule(int $modellevelid, int $emotionid, string $uidimension, string $rulestatement): int {
        return self::store()->create_rule($modellevelid, $emotionid, $uidimension, $rulestatement);
    }

    public static function delete_rule(int $ruleid): void {
        self::store()->delete_rule($ruleid);
    }

    public static function match_rule(int $emotionid): int {
        return self::store()->match_rule($emotionid);
    }

    public static function get_inference_models(): array {
        return self::store()->get_inference_models();
    }

    public static function register_inference_model(array $data): int {
        return self::store()->register_inference_model($data);
    }

    public static function get_default_inference_model_id(): int {
        return self::store()->get_default_inference_model_id();
    }

    public static function get_or_create_client_inference_model_id(): int {
        return self::store()->get_or_create_client_inference_model_id();
    }

    public static function record_emotion(array $data): int {
        return self::store()->record_emotion($data);
    }

    public static function record_reference_observation(int $sessionid, int $emotionid, string $method, ?string $observer = null, ?int $intensity = null): int {
        return self::store()->record_reference_observation($sessionid, $emotionid, $method, $observer, $intensity);
    }

    public static function log_adaptation_event(int $emorecordid, int $sessionid, string $decisionstate = 'APPLIED', ?string $experimentalcond = null, ?string $gatereason = null): int {
        return self::store()->log_adaptation_event($emorecordid, $sessionid, $decisionstate, $experimentalcond, $gatereason);
    }

    public static function log_adaptation_change(int $eventid, int $ruleid, string $uidimension, string $targettoken, ?string $before, ?string $after): int {
        return self::store()->log_adaptation_change($eventid, $ruleid, $uidimension, $targettoken, $before, $after);
    }

    /** Nivel DAiSEE (0-3) mas frecuente de esa emocion en la sesion desde $since; null si no hay. */
    public static function get_emotion_intensity_mode(int $sessionid, int $emotionid, int $since): ?int {
        return self::store()->get_emotion_intensity_mode($sessionid, $emotionid, $since);
    }

    /** Timestamp de la ultima adaptacion planeada o aplicada en la sesion (0 = ninguna). */
    public static function get_last_adaptation_time(int $sessionid): int {
        return self::store()->get_last_adaptation_time($sessionid);
    }

    public static function get_latest_emotion_record_id(int $sessionid): int {
        return self::store()->get_latest_emotion_record_id($sessionid);
    }

    /**
     * Adaptation_Event completo. $data: emorecordid, sessionid, decisionstate y opcionales
     * tokensbefore/tokensafter (arrays, se guardan como JSON), gatereason, magnitud,
     * experimentalcond, sessionmoment.
     */
    public static function record_adaptation_event(array $data): int {
        return self::store()->record_adaptation_event($data);
    }

    /** Cambia el decision_state; con $onlyfrom, solo si el estado actual es ese (p. ej. 'PLANNED'). */
    public static function set_adaptation_decision(int $eventid, string $decisionstate, string $onlyfrom = ''): void {
        self::store()->set_adaptation_decision($eventid, $decisionstate, $onlyfrom);
    }

    /** Ultimo Adaptation_Event de la sesion SIN Adaptation_Feedback, creado desde $since (0 = ninguno). */
    public static function find_pending_adaptation_event(int $sessionid, int $since): int {
        return self::store()->find_pending_adaptation_event($sessionid, $since);
    }

    /** Regla (Rule) para nivel de Norman + emocion + dimension de UI; la crea si no existe. */
    public static function get_or_create_rule(string $levelcode, int $emotionid, string $uidimension, string $statement): int {
        return self::store()->get_or_create_rule($levelcode, $emotionid, $uidimension, $statement);
    }

    /** Learning_Session del Adaptation_Event (0 si no existe). */
    public static function get_adaptation_event_session(int $eventid): int {
        return self::store()->get_adaptation_event_session($eventid);
    }

    /**
     * Calificacion (preceived_usefulness) y comentario del estudiante sobre una adaptacion, en
     * su Adaptation_Feedback (una fila por evento: se actualiza la creada al aceptarla).
     */
    public static function save_adaptation_rating(int $eventid, ?int $usefulness, ?string $comment): void {
        self::store()->save_adaptation_rating($eventid, $usefulness, $comment);
    }

    /** El estudiante revirtio la adaptacion: reverted_at = ahora, decision_state = REVERTED. */
    public static function mark_adaptation_reverted(int $eventid): void {
        self::store()->mark_adaptation_reverted($eventid);
    }

    // ---------------------------------------------------------------
    // Instrumentacion de latencia de adaptacion (campos nuevos de user_model_v5_1)
    // ---------------------------------------------------------------

    /** Hora del servidor en milisegundos de epoca Unix (UTC): el reloj de referencia del estudio. */
    public static function server_now_ms(): int {
        return (int)round(microtime(true) * 1000);
    }

    /**
     * Learning_Session.clock_offset_ms (reloj del navegador - reloj del servidor; positivo = el
     * cliente adelanta). Se escribe UNA sola vez por sesion: retorna false si ya tenia valor.
     */
    public static function set_session_clock_offset(int $sessionid, int $offsetms): bool {
        return self::store()->set_session_clock_offset($sessionid, $offsetms);
    }

    public static function get_session_clock_offset(int $sessionid): ?int {
        return self::store()->get_session_clock_offset($sessionid);
    }

    /**
     * Confirmacion de aplicacion: applied_at (reloj del navegador, SIN corregir) y
     * applied_at_server (llegada de la confirmacion). latency_total_ms lo calcula el trigger.
     */
    public static function mark_adaptation_applied(int $eventid, int $appliedclientms, int $appliedserverms): void {
        self::store()->mark_adaptation_applied($eventid, $appliedclientms, $appliedserverms);
    }

    public static function add_adaptation_feedback(int $adaptlogid, ?int $accepted, ?int $usefulness, ?string $comment): int {
        return self::store()->add_adaptation_feedback($adaptlogid, $accepted, $usefulness, $comment);
    }

    public static function get_user_profile(int $userid) {
        return self::store()->get_user_profile($userid);
    }

    public static function has_completed_profile(int $userid): bool {
        return self::store()->has_completed_profile($userid);
    }

    public static function save_user_profile(int $userid, \stdClass $data): void {
        self::store()->save_user_profile($userid, $data);
    }

    public static function set_user_program_if_missing(int $userid, int $programid): void {
        self::store()->set_user_program_if_missing($userid, $programid);
    }

    public static function get_course_programid(int $localcourseid): int {
        return self::store()->get_course_programid($localcourseid);
    }

    public static function get_course_records(int $localcourseid, int $since, int $userid = 0): array {
        return self::store()->get_course_records($localcourseid, $since, $userid);
    }

    public static function get_course_userids(int $localcourseid): array {
        return self::store()->get_course_userids($localcourseid);
    }

    public static function delete_course_records(int $localcourseid, int $userid = 0, bool $withsessions = false): void {
        self::store()->delete_course_records($localcourseid, $userid, $withsessions);
    }

    public static function get_user_moodle_course_ids(int $userid): array {
        return self::store()->get_user_moodle_course_ids($userid);
    }

    public static function export_user_course_records(int $userid, int $localcourseid): array {
        return self::store()->export_user_course_records($userid, $localcourseid);
    }

    public static function has_profile_data(int $userid): bool {
        return self::store()->has_profile_data($userid);
    }

    public static function delete_user_profile_data(int $userid): void {
        self::store()->delete_user_profile_data($userid);
    }

    public static function seed_catalogs(): void {
        self::store()->seed_catalogs();
    }

    public static function describe(): string {
        return self::store()->describe();
    }

    public static function debug_tables(int $limit = 15): array {
        return self::store()->debug_tables($limit);
    }
}

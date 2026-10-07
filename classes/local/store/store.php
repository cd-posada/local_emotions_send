<?php
/**
 * local/emotions_send/classes/local/store/store.php
 *
 * Contrato comun de los almacenes de datos del plugin:
 *  - moodle_store:    tablas local_emotions_* de la base de Moodle.
 *  - usermodel_store: base externa user_model_v5_1 (user_model_v5.1_mysql.sql).
 * data_helper elige uno segun el ajuste 'storage' y le delega todo.
 *
 * Convencion: los parametros $userid / $courseid 'de Moodle' son ids de
 * mdl_user / mdl_course; $localcourseid, $sessionid, $emotionid, etc. son
 * ids PROPIOS del almacen. Los objetos devueltos usan siempre los nombres
 * de campo de las tablas local_emotions_* (id, name, userid...), sin
 * importar el almacen, para que las paginas no dependan de cual se usa.
 */
namespace local_emotions_send\local\store;

defined('MOODLE_INTERNAL') || die();

interface store {
    public function get_emotions(): array;
    public function get_emotion_by_name(string $name);
    public function get_or_create_emotion(string $name): int;
    public function get_languages(): array;
    public function get_or_create_language(string $name): int;
    public function get_user_languages(int $userid): array;
    public function set_user_languages(int $userid, array $nativeids, array $otherids, int $preferredid = 0): void;
    public function get_levels(): array;
    public function get_programs(int $levelid = 0): array;
    public function create_program(string $name, int $levelid, string $faculty = ''): int;
    public function get_course_by_moodle_id(int $moodlecourseid);
    public function create_course(int $programid, string $name, int $moodlecourseid = 0): int;
    public function get_or_create_course_from_moodle(int $moodlecourseid): int;
    public function get_or_create_actcontext(int $courseid, string $module, string $activitytype): int;
    public function get_user_context_entries(int $userid): array;
    public function set_user_context_value(int $userid, string $type, string $value): void;
    public function get_or_create_open_session(int $userid, int $localcourseid, string $devicetype = '', string $viewport = ''): int;
    public function close_session(int $sessionid, string $reason = 'Logout'): void;
    public function start_session(int $userid, int $localcourseid, string $devicetype = '', string $viewport = ''): int;
    public function close_user_sessions(int $userid, string $reason = 'Logout'): int;
    public function get_session(int $sessionid);
    public function get_models(): array;
    public function create_model(string $description): int;
    public function get_model_levels(int $modelid = 0): array;
    public function create_model_level(int $modelid, string $code, string $description = ''): int;
    public function get_rules(): array;
    public function create_rule(int $modellevelid, int $emotionid, string $uidimension, string $rulestatement): int;
    public function delete_rule(int $ruleid): void;
    public function match_rule(int $emotionid): int;
    public function get_inference_models(): array;
    public function register_inference_model(array $data): int;
    public function get_default_inference_model_id(): int;
    public function get_or_create_client_inference_model_id(): int;
    public function record_emotion(array $data): int;
    public function record_reference_observation(int $sessionid, int $emotionid, string $method, ?string $observer = null, ?int $intensity = null): int;
    public function log_adaptation_event(int $emorecordid, int $sessionid, string $decisionstate = 'APPLIED', ?string $experimentalcond = null, ?string $gatereason = null): int;
    public function log_adaptation_change(int $eventid, int $ruleid, string $uidimension, string $targettoken, ?string $before, ?string $after): int;
    public function get_latest_emotion_record_id(int $sessionid): int;
    public function get_emotion_intensity_mode(int $sessionid, int $emotionid, int $since): ?int;
    public function get_last_adaptation_time(int $sessionid): int;
    public function record_adaptation_event(array $data): int;
    public function set_adaptation_decision(int $eventid, string $decisionstate, string $onlyfrom = ''): void;
    public function find_pending_adaptation_event(int $sessionid, int $since): int;
    public function get_or_create_rule(string $levelcode, int $emotionid, string $uidimension, string $statement): int;
    public function get_adaptation_event_session(int $eventid): int;
    public function save_adaptation_rating(int $eventid, ?int $usefulness, ?string $comment): void;
    public function mark_adaptation_reverted(int $eventid): void;
    // Instrumentacion de latencia (user_model_v5_1): marcas en milisegundos de epoca Unix (UTC).
    public function set_session_clock_offset(int $sessionid, int $offsetms): bool;
    public function get_session_clock_offset(int $sessionid): ?int;
    public function mark_adaptation_applied(int $eventid, int $appliedclientms, int $appliedserverms): void;
    public function add_adaptation_feedback(int $adaptlogid, ?int $accepted, ?int $usefulness, ?string $comment): int;
    public function get_user_profile(int $userid);
    public function has_completed_profile(int $userid): bool;
    public function save_user_profile(int $userid, \stdClass $data): void;
    public function set_user_program_if_missing(int $userid, int $programid): void;
    public function get_course_programid(int $localcourseid): int;
    public function get_course_records(int $localcourseid, int $since, int $userid = 0): array;
    public function get_course_userids(int $localcourseid): array;
    public function delete_course_records(int $localcourseid, int $userid = 0, bool $withsessions = false): void;
    public function get_user_moodle_course_ids(int $userid): array;
    public function export_user_course_records(int $userid, int $localcourseid): array;
    public function has_profile_data(int $userid): bool;
    public function delete_user_profile_data(int $userid): void;
    public function seed_catalogs(): void;
    public function describe(): string;
    public function record_session_log_entries(int $sessionid, array $entries): int;
    public function debug_tables(int $limit = 15): array;
}

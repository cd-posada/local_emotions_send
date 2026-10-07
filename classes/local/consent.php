<?php
/**
 * local/emotions_send/classes/local/consent.php
 *
 * Consentimiento informado para el registro facial, por usuario y curso
 * (tabla local_emotions_consent). Aplica en los cursos que tienen la
 * actividad mod_emotions, que es donde se enciende la camara.
 *
 *  - Sin consentimiento aceptado (para la version vigente del texto) la camara
 *    no se enciende: lib.php solo muestra la ventana del consentimiento, y
 *    save.php / log.php / clock.php rechazan los datos.
 *  - Modo 'block' (ajuste consent_mode): el contenido del curso queda bloqueado
 *    hasta aceptar. Lo hace cumplir el servidor con la finalizacion de la
 *    actividad Emotions (regla "completionconsent" de mod_emotions) y las
 *    restricciones de acceso de las secciones del curso.
 *    Modo 'nocamera': quien no acepta sigue en el curso, sin camara.
 *  - Retirar el consentimiento (revoke) BORRA los datos del estudiante en ese
 *    curso (sesiones, detecciones, adaptaciones, calificaciones, log de sesion)
 *    y vuelve a bloquear el contenido.
 *  - Version = huella del texto: si el texto cambia, se vuelve a pedir.
 *  - Los docentes (moodle/course:viewhiddenactivities) no quedan bloqueados.
 */
namespace local_emotions_send\local;

defined('MOODLE_INTERNAL') || die();

class consent {

    const TABLE = 'local_emotions_consent';
    const ACCEPTED = 'accepted';
    const DECLINED = 'declined';
    const REVOKED  = 'revoked';
    const MODE_BLOCK = 'block';
    const MODE_NOCAMERA = 'nocamera';

    /** Tipo de fila de Context (user_model_v5_1) con el estado del consentimiento. */
    const CONTEXT_TYPE = 'consentimiento_informado';

    public static function enabled(): bool {
        $v = get_config('local_emotions_send', 'consent_enabled');
        return $v === false || $v === null || (bool)$v;
    }

    public static function mode(): string {
        $v = (string)get_config('local_emotions_send', 'consent_mode');
        return $v === self::MODE_NOCAMERA ? self::MODE_NOCAMERA : self::MODE_BLOCK;
    }

    /** Texto vigente (HTML): el del ajuste consent_text o el texto por defecto del plugin. */
    public static function text(): string {
        $t = trim((string)get_config('local_emotions_send', 'consent_text'));
        return $t !== '' ? $t : get_string('consent:defaulttext', 'local_emotions_send');
    }

    /** Huella del texto vigente: cambia si el texto cambia, y entonces se vuelve a pedir. */
    public static function version(): string {
        return substr(sha1(self::text()), 0, 16);
    }

    /** ¿El curso tiene la actividad Emotions (registro facial)? */
    public static function course_requires(int $courseid): bool {
        global $DB;
        if ($courseid <= 1 || !self::enabled()) {
            return false;
        }
        return $DB->record_exists_sql(
            "SELECT 1 FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
              WHERE m.name = 'emotions' AND cm.course = :c AND cm.deletioninprogress = 0", ['c' => $courseid]);
    }

    public static function get_record(int $userid, int $courseid) {
        global $DB;
        return $DB->get_record(self::TABLE, ['userid' => $userid, 'courseid' => $courseid]);
    }

    /** ¿Acepto el texto vigente en este curso? (true si el curso no exige consentimiento) */
    public static function has_accepted(int $userid, int $courseid): bool {
        if (!self::course_requires($courseid)) {
            return true;
        }
        $r = self::get_record($userid, $courseid);
        return $r && $r->status === self::ACCEPTED && $r->version === self::version();
    }

    /** Docentes y administradores: ven el curso aunque no acepten (no son participantes). */
    public static function is_exempt(int $courseid): bool {
        $ctx = \context_course::instance($courseid, IGNORE_MISSING);
        return is_siteadmin() || ($ctx && has_capability('moodle/course:viewhiddenactivities', $ctx));
    }

    public static function accept(int $userid, int $courseid): void {
        self::set_status($userid, $courseid, self::ACCEPTED);
        self::usermodel_mark($userid, $courseid, 'aceptado');
        self::update_completion($userid, $courseid);
    }

    public static function decline(int $userid, int $courseid): void {
        self::set_status($userid, $courseid, self::DECLINED);
        self::update_completion($userid, $courseid);
    }

    /**
     * Retira el consentimiento: borra los datos del estudiante en el curso y bloquea de nuevo el
     * contenido. Retorna true si el borrado de datos se completo.
     */
    public static function revoke(int $userid, int $courseid): bool {
        global $DB;
        $ok = true;
        try {
            $local = data_helper::get_course_by_moodle_id($courseid);
            if ($local) {
                // Con sesiones: en user_model_v5_1, ON DELETE CASCADE borra tambien
                // Emotion_Record, Reference_Observation, Adaptation_*, Registro_JSON.
                data_helper::delete_course_records((int)$local->id, $userid, true);
            }
            self::usermodel_mark($userid, $courseid, 'revocado');
        } catch (\Throwable $e) {
            $ok = false;
            error_log('[local_emotions_send] consent::revoke (datos): ' . $e->getMessage());
        }
        // Datos del bloque de adaptacion (si esta instalado).
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('block_adaptive_ui_effectiveness')) {
            $DB->delete_records('block_adaptive_ui_effectiveness', ['userid' => $userid, 'courseid' => $courseid]);
        }
        if ($dbman->table_exists('block_adaptive_ui_prefs')) {
            $DB->delete_records('block_adaptive_ui_prefs', ['userid' => $userid]);
        }
        self::set_status($userid, $courseid, self::REVOKED);
        self::update_completion($userid, $courseid);
        return $ok;
    }

    // ---------------------------------------------------------------- auxiliares

    private static function set_status(int $userid, int $courseid, string $status): void {
        global $DB;
        $now = time();
        $r = self::get_record($userid, $courseid);
        if ($r) {
            $r->status = $status;
            $r->version = self::version();
            $r->timemodified = $now;
            $DB->update_record(self::TABLE, $r);
        } else {
            $DB->insert_record(self::TABLE, (object)['userid' => $userid, 'courseid' => $courseid, 'status' => $status,
                'version' => self::version(), 'timecreated' => $now, 'timemodified' => $now]);
        }
    }

    /** Estado del consentimiento tambien en el modelo de usuario (Context), para el estudio. */
    private static function usermodel_mark(int $userid, int $courseid, string $state): void {
        try {
            data_helper::set_user_context_value($userid, self::CONTEXT_TYPE,
                "{$state} | curso {$courseid} | version " . self::version() . ' | ' . gmdate('Y-m-d H:i:s') . ' UTC');
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] consent::usermodel_mark: ' . $e->getMessage());
        }
    }

    /** Recalcula la finalizacion de las actividades Emotions del curso (regla completionconsent). */
    private static function update_completion(int $userid, int $courseid): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $course = get_course($courseid);
        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            return;
        }
        foreach (get_fast_modinfo($course, $userid)->get_instances_of('emotions') as $cm) {
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
            }
        }
    }
}

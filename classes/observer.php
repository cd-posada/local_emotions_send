<?php
/**
 * local/emotions_send/classes/observer.php
 *
 * Observadores de eventos de Moodle (registrados en db/events.php).
 *
 * Al INICIAR sesion se abre una Learning_Session en cada curso del
 * estudiante que tiene la actividad mod_emotions (los mismos cursos donde
 * aparece el widget). Las detecciones que envie save.php despues se
 * registran en esa sesion. Al CERRAR sesion se cierran todas sus sesiones
 * abiertas con closure_reason = 'Logout'.
 *
 * Funciona con cualquier almacen (tablas de Moodle o user_model_v5_1).
 * Nunca debe impedir el login/logout: cualquier error se registra en el log
 * del servidor y se ignora.
 */
namespace local_emotions_send;

defined('MOODLE_INTERNAL') || die();

use local_emotions_send\local\data_helper;

class observer {

    public static function user_loggedin(\core\event\user_loggedin $event): void {
        $userid = (int)$event->userid;
        if ($userid <= 0 || isguestuser($userid)) {
            return;
        }
        try {
            $device = data_helper::detect_device_type($_SERVER['HTTP_USER_AGENT'] ?? '');
            foreach (self::courses_with_emotions($userid) as $courseid) {
                // Sin consentimiento informado aceptado no se abre sesion (no se registra nada).
                if (!\local_emotions_send\local\consent::has_accepted($userid, (int)$courseid)) {
                    continue;
                }
                $localcourseid = data_helper::get_or_create_course_from_moodle($courseid);
                if ($localcourseid > 0) {
                    data_helper::start_session($userid, $localcourseid, $device);
                }
            }
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] observer::user_loggedin: ' . $e->getMessage());
        }
    }

    public static function user_loggedout(\core\event\user_loggedout $event): void {
        $userid = (int)$event->objectid;
        if ($userid <= 0) {
            return;
        }
        try {
            data_helper::close_user_sessions($userid, 'Logout');
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] observer::user_loggedout: ' . $e->getMessage());
        }
    }

    /** Cursos (ids de Moodle) donde el usuario esta matriculado y existe la actividad mod_emotions. */
    private static function courses_with_emotions(int $userid): array {
        global $DB;
        $courses = enrol_get_users_courses($userid, true, 'id');
        if (!$courses) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED);
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT cm.course
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE m.name = 'emotions' AND cm.deletioninprogress = 0 AND cm.course {$insql}", $params));
    }
}

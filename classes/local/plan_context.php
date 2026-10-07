<?php
/**
 * local/emotions_send/classes/local/plan_context.php
 *
 * Datos del modelo de usuario v5 que necesita la fase Plan del modulo de adaptacion
 * (block_adaptive_ui, prompt v6 "WCAG 2.1 y 2.2") y que el bloque no tiene por si solo:
 *
 *  - rules:          las reglas de la emocion activa de la tabla Rule, con su id_rule
 *                    (el prompt las cita en reglas_aplicadas / reglas_no_aplicadas).
 *  - intensity:      nivel DAiSEE (0-3) mas frecuente de esa emocion en los ultimos 90 s
 *                    (Emotion_Record.intensity_daisee -> magnitud de la adaptacion).
 *  - momento_sesion: "inicio" (primeros 5 min de la Learning_Session) o "durante_actividad"
 *                    (activa los niveles de Norman del prompt). El cierre de actividad o
 *                    de sesion no es observable desde aqui.
 *  - seconds_since_last_adaptation: desde el ultimo Adaptation_Event PLANNED/APPLIED
 *                    (compuerta del Paso 1b).
 *  - perfil:         idiomas (nativo/otros), programa y nivel academico, contexto de uso
 *                    (dispositivo, conexion, modalidad) declarados por el estudiante.
 *
 * Todo es opcional: cada dato que falte se omite y el prompt lo trata como ausente.
 * Nunca lanza excepciones (el Plan no debe fallar por un dato de contexto).
 */
namespace local_emotions_send\local;

defined('MOODLE_INTERNAL') || die();

class plan_context {

    /** Ventana del analisis de emociones (igual que WINDOW_SECONDS de block_adaptive_ui). */
    const WINDOW_SECONDS = 90;

    /** Minutos iniciales de la sesion considerados "inicio". */
    const INICIO_MINUTES = 5;

    public static function build(int $userid, int $moodlecourseid, string $emotion): array {
        $ctx = [];
        self::safe($ctx, 'rules', function () use ($emotion) {
            $rules = [];
            foreach (data_helper::get_rules() as $r) {
                if ((string)$r->emotionname !== $emotion
                        || strpos((string)$r->rule_statement, 'Adaptacion generada por LLM') === 0) {
                    continue;
                }
                $rules[] = [
                    'id'        => (int)$r->id,
                    'level'     => (string)$r->levelcode,
                    'dimension' => (string)$r->ui_dimension,
                    'text'      => (string)$r->rule_statement,
                ];
            }
            return $rules;
        });

        self::safe($ctx, 'session', function () use ($userid, $moodlecourseid, $emotion) {
            if ($userid <= 0 || $moodlecourseid <= 1) {
                return null;
            }
            $localcourseid = data_helper::get_or_create_course_from_moodle($moodlecourseid);
            $sessionid = $localcourseid ? data_helper::get_or_create_open_session($userid, $localcourseid) : 0;
            if (!$sessionid) {
                return null;
            }
            $session = data_helper::get_session($sessionid);
            $emo = data_helper::get_emotion_by_name($emotion);
            $minutes = $session ? (int)floor((time() - (int)$session->startedat) / 60) : null;
            $last = data_helper::get_last_adaptation_time($sessionid);
            return [
                'intensity'      => $emo ? data_helper::get_emotion_intensity_mode(
                    $sessionid, (int)$emo->id, time() - self::WINDOW_SECONDS) : null,
                'minutes'        => $minutes,
                'momento_sesion' => $minutes === null ? null
                    : ($minutes < self::INICIO_MINUTES ? 'inicio' : 'durante_actividad'),
                'seconds_since_last_adaptation' => $last > 0 ? time() - $last : null,
            ];
        });

        self::safe($ctx, 'profile', function () use ($userid) {
            if ($userid <= 0) {
                return null;
            }
            $names = function (array $rows) {
                return array_values(array_map(function ($r) {
                    return (string)$r->name;
                }, $rows));
            };
            $langs = data_helper::get_user_languages($userid);
            $profile = data_helper::get_user_profile($userid);
            $program = $level = null;
            if ($profile && !empty($profile->programid)) {
                $programs = data_helper::get_programs();
                if (isset($programs[$profile->programid])) {
                    $program = (string)$programs[$profile->programid]->name;
                    $levels = data_helper::get_levels();
                    $lid = (int)$programs[$profile->programid]->levelid;
                    $level = isset($levels[$lid]) ? (string)$levels[$lid]->value : null;
                }
            }
            $context = [];
            foreach (data_helper::get_user_context_entries($userid) as $c) {
                if (in_array($c->type, ['device', 'connection', 'modality'], true)) {
                    $context[$c->type] = (string)$c->value_context;
                }
            }
            return [
                'native_languages' => $names($langs['native']),
                'other_languages'  => $names($langs['other']),
                'program'          => $program,
                'level'            => $level,
                'context'          => $context,
            ];
        });
        return $ctx;
    }

    private static function safe(array &$ctx, string $key, callable $fn): void {
        try {
            $value = $fn();
            if ($value !== null) {
                $ctx[$key] = $value;
            }
        } catch (\Throwable $e) {
            error_log("[local_emotions_send] plan_context ({$key}): " . $e->getMessage());
        }
    }
}

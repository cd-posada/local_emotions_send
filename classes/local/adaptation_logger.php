<?php
/**
 * local/emotions_send/classes/local/adaptation_logger.php
 *
 * API para que el modulo de adaptacion visual (block_adaptive_ui) registre
 * cada adaptacion en el modelo de usuario v5 (Adaptation_Event,
 * Adaptation_Change, Adaptation_Feedback), en el almacen configurado: tablas
 * de Moodle o base externa user_model_v5_1. Asi el estudio completo
 * (detecciones + adaptaciones) queda en una sola base.
 *
 * Ciclo de una adaptacion:
 *   1. planned(): el LLM propuso una adaptacion (apply_adaptation) ->
 *      Adaptation_Event 'PLANNED' + una Adaptation_Change por cada variable
 *      CSS y cada accion DOM.
 *      gated():  el LLM no respondio o su respuesta no paso los filtros ->
 *      Adaptation_Event 'GATED' con gate_reason.
 *   2. decision(): el estudiante decide en el panel (record_action):
 *      - 'activated': el evento pendiente pasa a 'APPLIED' + Adaptation_Feedback accepted=1.
 *      - 'closed':    el evento pendiente (o uno nuevo, si no hubo Plan) pasa a
 *                     'SKIPPED' + Adaptation_Feedback accepted=0.
 *
 * Cada evento se liga a la Learning_Session abierta del estudiante en el
 * curso y a su deteccion mas reciente (Emotion_Record), que es la evidencia
 * que disparo la recomendacion. Si no hay ninguna deteccion en la sesion, no
 * se registra nada (id_emotion_record es obligatorio en el esquema).
 *
 * Latencia de adaptacion (campos nuevos de user_model_v5_1): el bloque toma
 * triggering_record() ANTES de llamar al LLM (la deteccion que origina la
 * adaptacion, cuyo window_end es el punto de partida de latency_total_ms) y
 * pasa a planned()/gated() las marcas del servidor decision_requested_at y
 * decision_generated_at. Tras repintar, el navegador confirma con applied(),
 * que guarda applied_at (reloj del navegador, sin corregir) y
 * applied_at_server; el trigger de la base deriva latency_total_ms.
 * Ademas, planned()/gated() registran el evento adaptation_decided en el
 * logstore de Moodle.
 *
 * Todos los metodos son a prueba de fallos: nunca lanzan excepciones (el
 * registro no debe romper la adaptacion en la pagina); los errores van al log
 * del servidor.
 */
namespace local_emotions_send\local;

defined('MOODLE_INTERNAL') || die();

class adaptation_logger {

    /** Segundos (10 min) en los que una decision del estudiante se liga al ultimo evento pendiente. */
    const PENDING_WINDOW = 600;

    /**
     * Valores por defecto de los tokens CSS de la interfaz (tokens_before). Copia de
     * DEFAULT_CSS en block_adaptive_ui/amd/src/mape/config.js: dom_applier revierte a estos
     * valores y luego aplica los del LLM, asi que son el "antes" real de cada adaptacion.
     * SINCRONIZACION MANUAL si cambian alla.
     */
    const DEFAULT_TOKENS = [
        '--emotion-bg'             => '#f5f5f5',
        '--emotion-primary'        => '#0f6cbf',
        '--emotion-primary-text'   => '#0f6cbf',
        '--emotion-on-primary'     => '#ffffff',
        '--emotion-text-size'      => '1rem',
        '--emotion-spacing'        => '1rem',
        '--emotion-font-weight'    => '400',
        '--emotion-line-height'    => '1.5',
        '--emotion-border-radius'  => '4px',
        '--emotion-letter-spacing' => '0em',
        '--emotion-font-family'    => 'system-ui, sans-serif',
    ];

    /** Codigos de Norman_Level segun el texto que devuelve el LLM (p. ej. "Visceral + Reflexivo"). */
    const NORMAN_CODES = [
        'visceral'   => 'Visceral',
        'conductual' => 'Behavioral',
        'behavioral' => 'Behavioral',
        'reflexivo'  => 'Reflective',
        'reflective' => 'Reflective',
    ];

    /**
     * El LLM propuso una adaptacion. $cssparams: el JSON css_params de apply_adaptation
     * ({css_vars, dom_actions, ...}). Retorna el id del Adaptation_Event (0 si no se registro).
     */
    public static function planned(int $userid, int $moodlecourseid, string $emotion, float $confidence,
                                   string $normanlevel, string $cssparams, string $model = '',
                                   array $timing = []): int {
        try {
            $ctx = self::context($userid, $moodlecourseid, (int)($timing['emorecordid'] ?? 0));
            if (!$ctx) {
                return 0;
            }
            $params = json_decode($cssparams, true) ?: [];
            $cssvars = is_array($params['css_vars'] ?? null) ? $params['css_vars'] : [];
            $domactions = is_array($params['dom_actions'] ?? null) ? $params['dom_actions'] : [];
            // Trazabilidad del prompt v6 (decision, reglas aplicadas/no aplicadas, contraste...).
            $trace = is_array($params['trace'] ?? null) ? $params['trace'] : [];
            $estado = (string)($trace['decision']['estado'] ?? 'adaptar');
            $gated = $estado !== '' && $estado !== 'adaptar';
            $appliedrules = self::applied_rule_ids($trace, $emotion);

            $eventid = data_helper::record_adaptation_event([
                'emorecordid'      => $ctx['emorecordid'],
                'sessionid'        => $ctx['sessionid'],
                'tokensbefore'     => self::DEFAULT_TOKENS,
                'tokensafter'      => [
                    'css_vars'    => array_merge(self::DEFAULT_TOKENS, $cssvars),
                    'dom_actions' => $domactions,
                    'emotion'     => $emotion,
                    'confidence'  => round($confidence, 4),
                    'norman_level'=> $normanlevel,
                    'trace'       => $trace ?: null,
                ],
                // Compuerta del prompt v6: "mantener"/"sin_adaptacion" es una decision valida del
                // Plan, no un fallo -> GATED con su motivo; la decision del estudiante no lo
                // convierte en APPLIED (ver decision()).
                'decisionstate'    => $gated ? 'GATED' : 'PLANNED',
                'gatereason'       => $gated ? \core_text::substr('LLM: ' . $estado . ' - '
                    . (string)($trace['decision']['motivo'] ?? ''), 0, 180) : null,
                'magnitud'         => count($cssvars) + count($domactions),
                'experimentalcond' => $model !== '' ? \core_text::substr('LLM:' . $model, 0, 60) : null,
            ] + self::decision_marks($timing));
            self::log_moodle_event($userid, $moodlecourseid, $eventid, $gated ? 'GATED' : 'PLANNED', $emotion);

            $levelcode = self::norman_code($normanlevel);
            $emotionid = data_helper::get_or_create_emotion($emotion);
            $source = $model !== '' ? $model : 'LLM';
            foreach ($cssvars as $token => $value) {
                $dim = self::css_dimension((string)$token);
                data_helper::log_adaptation_change($eventid,
                    self::rule_for($appliedrules, $dim, $levelcode, $emotionid, $emotion, $source),
                    $dim, (string)$token, self::DEFAULT_TOKENS[$token] ?? null, (string)$value);
            }
            foreach ($domactions as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $dim = self::dom_dimension($a);
                $target = trim(($a['selector'] ?? '') . ' ' . ($a['action'] ?? ''));
                data_helper::log_adaptation_change($eventid,
                    self::rule_for($appliedrules, $dim, $levelcode, $emotionid, $emotion, $source),
                    $dim, $target, null,
                    json_encode(['value' => $a['value'] ?? null, 'rationale' => $a['rationale'] ?? null],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            return $eventid;
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] adaptation_logger::planned: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * La adaptacion no se pudo planear (LLM sin respuesta, filtros de etica/sanitizacion...).
     * Las marcas de decision que existan se conservan; applied_at* quedan nulas.
     */
    public static function gated(int $userid, int $moodlecourseid, string $emotion, string $reason,
                                 array $timing = []): int {
        try {
            $ctx = self::context($userid, $moodlecourseid, (int)($timing['emorecordid'] ?? 0));
            if (!$ctx) {
                return 0;
            }
            $eventid = data_helper::record_adaptation_event([
                'emorecordid'   => $ctx['emorecordid'],
                'sessionid'     => $ctx['sessionid'],
                'tokensbefore'  => self::DEFAULT_TOKENS,
                'decisionstate' => 'GATED',
                'gatereason'    => $reason,
                'magnitud'      => 0,
            ] + self::decision_marks($timing));
            self::log_moodle_event($userid, $moodlecourseid, $eventid, 'GATED', $emotion);
            return $eventid;
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] adaptation_logger::gated: ' . $e->getMessage());
            return 0;
        }
    }

    /** Decision del estudiante en el panel: 'activated' o 'closed'. */
    public static function decision(int $userid, int $moodlecourseid, string $emotion, string $action): void {
        try {
            $ctx = self::context($userid, $moodlecourseid);
            if (!$ctx) {
                return;
            }
            $accepted = $action === 'activated';
            $eventid = data_helper::find_pending_adaptation_event($ctx['sessionid'], time() - self::PENDING_WINDOW);
            if ($eventid) {
                // Solo un evento PLANNED cambia de estado; uno GATED (el Plan decidio no adaptar)
                // conserva su estado y solo recibe el feedback del estudiante.
                data_helper::set_adaptation_decision($eventid, $accepted ? 'APPLIED' : 'SKIPPED', 'PLANNED');
            } else {
                // Cerrar sin pedir la adaptacion (no hubo Plan): igual queda la decision.
                $eventid = data_helper::record_adaptation_event([
                    'emorecordid'   => $ctx['emorecordid'],
                    'sessionid'     => $ctx['sessionid'],
                    'tokensbefore'  => self::DEFAULT_TOKENS,
                    'decisionstate' => $accepted ? 'APPLIED' : 'SKIPPED',
                    'gatereason'    => $accepted ? null : 'Recomendacion rechazada por el estudiante',
                    'magnitud'      => 0,
                ]);
            }
            data_helper::add_adaptation_feedback($eventid, $accepted ? 1 : 0, null, null);
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] adaptation_logger::decision: ' . $e->getMessage());
        }
    }

    /** Escala de la calificacion de la nueva interfaz (Adaptation_Feedback.preceived_usefulness). */
    const RATINGS = [1 => 'OK', 2 => 'Buena', 3 => 'Mala'];

    /**
     * Calificacion, comentario y/o reversion de una adaptacion aplicada, desde la caja de
     * explicacion del bloque. Solo acepta eventos de una sesion del propio estudiante.
     * $rating: 1 (OK), 2 (Buena), 3 (Mala) o 0 si no califico. Retorna false si el evento no
     * es suyo o no existe.
     */
    public static function rate(int $userid, int $eventid, int $rating, string $comment, bool $reverted): bool {
        try {
            $sessionid = $eventid > 0 ? data_helper::get_adaptation_event_session($eventid) : 0;
            $session = $sessionid ? data_helper::get_session($sessionid) : null;
            if (!$session || (int)$session->userid !== $userid) {
                return false;
            }
            $comment = trim($comment);
            if ($rating > 0 || $comment !== '') {
                data_helper::save_adaptation_rating($eventid,
                    isset(self::RATINGS[$rating]) ? $rating : null,
                    $comment !== '' ? \core_text::substr($comment, 0, 1000) : null);
            }
            if ($reverted) {
                data_helper::mark_adaptation_reverted($eventid);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] adaptation_logger::rate: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Id de la deteccion mas reciente del estudiante en el curso (su Emotion_Record), tomado
     * ANTES de llamar al LLM: es la evidencia que origina la adaptacion y su window_end es el
     * origen de latency_total_ms. Si se tomara despues, seria una deteccion posterior a la
     * decision y la latencia dejaria fuera el tramo del LLM. 0 si no hay.
     */
    public static function triggering_record(int $userid, int $moodlecourseid): int {
        try {
            $ctx = self::context($userid, $moodlecourseid);
            return $ctx ? (int)$ctx['emorecordid'] : 0;
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] adaptation_logger::triggering_record: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Confirmacion de que la adaptacion quedo pintada en el navegador. $appliedclientms: marca
     * del navegador (ms de epoca) tomada en el repintado posterior a aplicar el CSS, SIN
     * corregir; $serverms: llegada de la confirmacion al servidor. Solo acepta eventos de una
     * sesion del propio estudiante y se registra una sola vez por evento.
     */
    public static function applied(int $userid, int $eventid, int $appliedclientms, int $serverms): bool {
        try {
            $sessionid = $eventid > 0 ? data_helper::get_adaptation_event_session($eventid) : 0;
            $session = $sessionid ? data_helper::get_session($sessionid) : null;
            if (!$session || (int)$session->userid !== $userid || $appliedclientms <= 0) {
                return false;
            }
            data_helper::mark_adaptation_applied($eventid, $appliedclientms, $serverms);
            return true;
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] adaptation_logger::applied: ' . $e->getMessage());
            return false;
        }
    }

    // ---------------------------------------------------------------- auxiliares

    /** decision_requested_at / decision_generated_at (ms del servidor) para record_adaptation_event. */
    private static function decision_marks(array $timing): array {
        $marks = [];
        foreach (['requestedms' => 'decisionrequestedms', 'generatedms' => 'decisiongeneratedms'] as $in => $out) {
            if (!empty($timing[$in])) {
                $marks[$out] = (int)$timing[$in];
            }
        }
        return $marks;
    }

    /** Evento adaptation_decided en el logstore de Moodle (auditoria; no reemplaza a las tablas). */
    private static function log_moodle_event(int $userid, int $moodlecourseid, int $eventid, string $state,
                                             string $emotion): void {
        if ($eventid <= 0) {
            return;
        }
        try {
            \local_emotions_send\event\adaptation_decided::create([
                'context'  => \context_course::instance($moodlecourseid),
                'userid'   => $userid,
                'courseid' => $moodlecourseid,
                'other'    => ['eventid' => $eventid, 'state' => $state, 'emotion' => $emotion],
            ])->trigger();
        } catch (\Throwable $e) {
            error_log('[local_emotions_send] adaptation_logger::log_moodle_event: ' . $e->getMessage());
        }
    }

    /**
     * Sesion abierta del estudiante en el curso y su deteccion mas reciente (o $emorecordid, la
     * tomada con triggering_record() antes de llamar al LLM); null si no aplica.
     */
    private static function context(int $userid, int $moodlecourseid, int $emorecordid = 0): ?array {
        if ($userid <= 0 || $moodlecourseid <= 1) {
            return null;
        }
        $localcourseid = data_helper::get_or_create_course_from_moodle($moodlecourseid);
        if ($localcourseid <= 0) {
            return null;
        }
        $sessionid = data_helper::get_or_create_open_session($userid, $localcourseid);
        if ($emorecordid <= 0) {
            $emorecordid = $sessionid ? data_helper::get_latest_emotion_record_id($sessionid) : 0;
        }
        if (!$emorecordid) {
            error_log("[local_emotions_send] adaptation_logger: usuario {$userid} sin detecciones en la sesion {$sessionid}; "
                . 'no se registra la adaptacion (Adaptation_Event exige id_emotion_record).');
            return null;
        }
        return ['sessionid' => $sessionid, 'emorecordid' => $emorecordid];
    }

    private static function norman_code(string $normanlevel): string {
        $lower = \core_text::strtolower($normanlevel);
        foreach (self::NORMAN_CODES as $needle => $code) {
            if (strpos($lower, $needle) !== false) {
                return $code;
            }
        }
        return 'Visceral';
    }

    /** Dimension de UI de una variable CSS. */
    private static function css_dimension(string $token): string {
        if (preg_match('/font|text-size|line-height|letter-spacing|weight/', $token)) {
            return 'TYPOGRAPHY';
        }
        if (preg_match('/spacing|radius|width|gap/', $token)) {
            return 'LAYOUT';
        }
        if (preg_match('/transition|duration/', $token)) {
            return 'MOTION';
        }
        return 'COLOR';
    }

    /** Dimension de UI de una accion DOM (por la clase/valor que aplica). */
    private static function dom_dimension(array $a): string {
        $value = \core_text::strtolower((string)($a['value'] ?? '') . ' ' . ($a['action'] ?? ''));
        if (preg_match('/heading|font|typo|text/', $value)) {
            return 'TYPOGRAPHY';
        }
        if (preg_match('/pulse|anim|icon|motion|transition/', $value)) {
            return 'MOTION';
        }
        if (preg_match('/color|highlight|contrast/', $value)) {
            return 'COLOR';
        }
        return 'LAYOUT';
    }

    /**
     * id_rule declarados por el LLM en trace.reglas_aplicadas, agrupados por dimension de
     * cambio (COLOR / TYPOGRAPHY / LAYOUT), validados contra las reglas reales de la emocion.
     */
    private static function applied_rule_ids(array $trace, string $emotion): array {
        $valid = [];
        foreach (data_helper::get_rules() as $r) {
            if ((string)$r->emotionname === $emotion) {
                $valid[(int)$r->id] = (string)$r->ui_dimension;
            }
        }
        $bydim = [];
        foreach ((array)($trace['reglas_aplicadas'] ?? []) as $ra) {
            $id = (int)preg_replace('/\D/', '', (string)($ra['id_regla'] ?? ''));
            if ($id > 0 && isset($valid[$id])) {
                $bydim[$valid[$id]][] = $id;
            }
        }
        return $bydim;
    }

    /**
     * Regla para un cambio: la regla real que el LLM declaro aplicar en esa dimension (MOTION
     * se ancla a LAYOUT: el documento v6 no tiene dimension de movimiento) o, si no declaro
     * ninguna, la regla "generada por LLM" de esa dimension.
     */
    private static function rule_for(array $appliedrules, string $dim, string $levelcode, int $emotionid,
                                     string $emotion, string $source): int {
        $key = $dim === 'MOTION' ? 'LAYOUT' : $dim;
        if (!empty($appliedrules[$key])) {
            return (int)$appliedrules[$key][0];
        }
        return self::rule($levelcode, $emotionid, $dim, $emotion, $source);
    }

    /** Regla "generada por LLM" para nivel + emocion + dimension (Adaptation_Change exige id_rule). */
    private static function rule(string $levelcode, int $emotionid, string $dim, string $emotion, string $source): int {
        return data_helper::get_or_create_rule($levelcode, $emotionid, $dim,
            "Adaptacion generada por LLM ({$source}) para {$emotion} - dimension {$dim}");
    }
}

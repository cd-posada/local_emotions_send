<?php
/**
 * local/emotions_send/lib.php
 *
 * Widget global de Moodle. local_emotions_send_widget_html() arma el HTML
 * del widget flotante; se inyecta al final de cada pagina desde el hook
 * \core\hook\output\before_footer_html_generation (Moodle 4.4+, ver
 * db/hooks.php y classes/hook_callbacks.php) o, en versiones anteriores,
 * desde el callback clasico local_emotions_send_before_footer().
 * Solo se inyecta cuando:
 *  1. El usuario esta autenticado y no es invitado.
 *  2. Esta dentro de un curso real (no la pagina de sitio).
 *  3. Ese curso tiene la actividad mod_emotions activa.
 *  4. El usuario esta matriculado activamente en ese curso (o es siteadmin).
 *
 * Reconocimiento facial 100% en el cliente: ya no hay backend externo.
 * Se cargan, en orden:
 *  1. session_log.js: log de detecciones en sessionStorage, para que el
 *     modulo del companero (adaptive_ui) lea la emocion dominante reciente
 *     sin pasar por el servidor.
 *  2. fer_engine.js: OpenCV.js (Haar Cascade) + ONNX Runtime Web; el
 *     modelo y el cascade se descargan de assets.php (ver fer_model).
 *  3. camera_global.js: camara, analisis 1 vez por segundo, widget y
 *     guardado en save.php.
 *
 * Seguridad:
 *  - No se hace ninguna consulta SQL contra tablas propias del plugin
 *    salvo la verificacion (muy liviana) de perfil completo; el resto
 *    sigue usando solo tablas del core, para no romper Moodle si el
 *    plugin tiene un problema de instalacion (este hook corre en CADA
 *    pagina).
 *  - El courseid y cmid que se envian al JS se usan solo como ETIQUETA;
 *    save.php los vuelve a validar del lado del servidor.
 */
defined('MOODLE_INTERNAL') || die();

function local_emotions_send_widget_html(): string {
    global $USER, $DB, $PAGE, $CFG;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    $skip = ['admin', 'maintenance', 'embedded', 'popup', 'frametop', 'login', 'base'];
    if (in_array($PAGE->pagelayout, $skip)) {
        return '';
    }

    $courseid = isset($PAGE->course->id) ? (int)$PAGE->course->id : 0;
    if ($courseid <= 1) {
        return '';
    }

    $sql_mod = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE m.name = 'emotions'
                   AND cm.course = :courseid
                   AND cm.deletioninprogress = 0";
    if (!$DB->record_exists_sql($sql_mod, ['courseid' => $courseid])) {
        return '';
    }

    $userid = (int)$USER->id;
    $show   = false;

    if (is_siteadmin()) {
        $show = true;
    } else {
        $course_ctx = context_course::instance($courseid, IGNORE_MISSING);
        if ($course_ctx) {
            $show = is_enrolled($course_ctx, $userid, '', true);
        }
    }

    if (!$show) {
        return '';
    }

    // Consentimiento informado: mientras no acepte el texto vigente no se carga NADA del
    // registro facial (ni camara ni widget), solo la ventana del consentimiento.
    if (!\local_emotions_send\local\consent::has_accepted($userid, $courseid)) {
        return local_emotions_send_consent_html($userid, $courseid);
    }

    $html = '';

    // Aviso de perfil incompleto (no bloqueante). Corre en CADA pagina del
    // curso: si el almacen falla (p. ej. la base externa user_model_v5_1 no
    // responde) se omite el aviso en vez de romper la pagina; el widget se
    // muestra igual y save.php reportara el error.
    try {
        $profilecomplete = \local_emotions_send\local\data_helper::has_completed_profile($userid);
    } catch (\Throwable $e) {
        error_log('[local_emotions_send] has_completed_profile: ' . $e->getMessage());
        $profilecomplete = true;
    }
    if (!$profilecomplete) {
        $profileurl = new moodle_url('/local/emotions_send/profile.php');
        $html .= html_writer::div(
            get_string('profile:completebanner', 'local_emotions_send', $profileurl->out(false)),
            '',
            [
                'style' => 'position:fixed;bottom:150px;right:16px;z-index:99998;max-width:260px;'
                         . 'background:#fff3cd;border:1px solid #ffe69c;color:#664d03;'
                         . 'padding:10px 14px;border-radius:8px;font-size:0.78em;'
                         . 'box-shadow:0 4px 14px rgba(0,0,0,.12);',
            ]
        );
    }

    $jsver       = '2026100600';
    $js_url      = $CFG->wwwroot . '/local/emotions_send/js/camera_global.js?v=' . $jsver;
    $engine_url  = $CFG->wwwroot . '/local/emotions_send/js/fer_engine.js?v=' . $jsver;
    $sesslog_url = $CFG->wwwroot . '/local/emotions_send/js/session_log.js?v=' . $jsver;
    $clock_url   = $CFG->wwwroot . '/local/emotions_send/js/clock_sync.js?v=' . $jsver;

    $cmid = 0;
    if (!empty($PAGE->cm->id)) {
        $cmid = (int)$PAGE->cm->id;
    }

    // Se imprime inline (y no con js_init_code) para que ya exista cuando
    // corren los <script> de abajo. json_encode escapa todo valor.
    $config = array_merge(\local_emotions_send\local\fer_model::js_config(), [
        'saveUrl'  => $CFG->wwwroot . '/local/emotions_send/save.php',
        'userid'   => $userid,
        'courseid' => $courseid,
        'cmid'     => $cmid,
        'sesskey'  => sesskey(),
        // Solo con la base externa: session_log.js envia cada entrada del log
        // de sesion a log.php, que la guarda en Registro_JSON.
        'logUrl'   => \local_emotions_send\local\data_helper::storage_mode() === 'usermodel'
            ? $CFG->wwwroot . '/local/emotions_send/log.php' : '',
        // Solo con la base externa: clock_sync.js mide Learning_Session.clock_offset_ms contra
        // clock.php (la instrumentacion de latencia existe solo en user_model_v5_1).
        'clockUrl' => \local_emotions_send\local\data_helper::storage_mode() === 'usermodel'
            ? $CFG->wwwroot . '/local/emotions_send/clock.php' : '',
    ]);
    $config_json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);

    // Enlace permanente para retirar el consentimiento informado (borra los datos del curso).
    $revokehtml = '';
    if (\local_emotions_send\local\consent::course_requires($courseid)) {
        $revokehtml = '<div style="margin-top:6px;font-size:0.72em;">'
            . '<a href="#" onclick="if (window.EmotionsConsent) { EmotionsConsent.revoke(); } return false;" '
            . 'style="color:#6c757d;text-decoration:underline;">'
            . s(get_string('consent:revoke', 'local_emotions_send')) . '</a></div>'
            . local_emotions_send_consent_config_script($courseid);
    }

    $html .= <<<HTML
<div id="emotions_widget" style="
    position:fixed; bottom:16px; right:16px; z-index:99999;
    background:#fff; border:1px solid #dee2e6; border-radius:10px;
    font-size:0.80em; font-family:sans-serif;
    box-shadow:0 4px 14px rgba(0,0,0,.15); min-width:200px;
    overflow:hidden; transition: all 0.25s ease;">

    <video id="emotions_global_cam" width="1" height="1" autoplay muted playsinline
           style="position:absolute;opacity:0;pointer-events:none;"></video>

    <div id="ew_cam_panel" style="display:none; background:#000; line-height:0;">
        <canvas id="ew_canvas" data-emotions-view="compact" width="320" height="240"
                style="width:240px;max-width:100%;display:block;"></canvas>
    </div>

    <div style="padding:10px 13px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
            <span style="font-weight:600;color:#212529;font-size:0.95em;">
                Registro de atencion
            </span>
            <div style="display:flex;align-items:center;gap:6px;">
                <span id="ew_dot"
                      style="width:8px;height:8px;border-radius:50%;background:#adb5bd;
                             display:inline-block;flex-shrink:0;" title="Estado"></span>
                <button id="ew_toggle"
                        onclick="emotionsWidgetToggle()"
                        title="Expandir / contraer"
                        style="background:none;border:1px solid #dee2e6;border-radius:4px;
                               padding:1px 5px;cursor:pointer;font-size:0.85em;
                               color:#6c757d;line-height:1.4;">
                    +
                </button>
            </div>
        </div>
        <div id="ew_emotion"
             style="font-size:1.1em;font-weight:700;color:#0d6efd;min-height:1.3em;">
            Iniciando...
        </div>
        <div id="ew_conf" style="color:#6c757d;margin-top:2px;">—</div>
        <div id="ew_status" style="color:#adb5bd;font-size:0.72em;margin-top:4px;">
            Solicitando permiso de camara...
        </div>
        {$revokehtml}
    </div>
</div>

<script>
var _ewExpanded = false;

// El canvas #ew_canvas lo redibuja camera_global.js mientras este visible.
function emotionsWidgetToggle() {
    _ewExpanded = !_ewExpanded;
    document.getElementById('ew_cam_panel').style.display = _ewExpanded ? 'block' : 'none';
    document.getElementById('ew_toggle').textContent = _ewExpanded ? '−' : '+';
}
window.EMOTIONS_GLOBAL_CONFIG = {$config_json};
</script>
<script src="{$clock_url}"></script>
<script src="{$sesslog_url}"></script>
<script src="{$engine_url}"></script>
<script src="{$js_url}"></script>
HTML;

    return $html;
}

/**
 * Configuracion de js/consent.js (ventana y retiro del consentimiento) + el script.
 */
function local_emotions_send_consent_config_script(int $courseid): string {
    global $CFG;
    $cfg = json_encode([
        'url'      => $CFG->wwwroot . '/local/emotions_send/consent.php',
        'courseid' => $courseid,
        'sesskey'  => sesskey(),
        'blocking' => \local_emotions_send\local\consent::mode() === \local_emotions_send\local\consent::MODE_BLOCK,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    $src = $CFG->wwwroot . '/local/emotions_send/js/consent.js?v=2026100602';
    return "<script>window.EMOTIONS_CONSENT = {$cfg};</script><script src=\"{$src}\"></script>";
}

/**
 * Ventana modal del consentimiento informado (bloqueante). Si el usuario ya dijo "No acepto" al
 * texto vigente y no queda bloqueado (modo 'nocamera', o docente), no se vuelve a mostrar.
 */
function local_emotions_send_consent_html(int $userid, int $courseid): string {
    $c = \local_emotions_send\local\consent::class;
    $rec = $c::get_record($userid, $courseid);
    $blocked = $c::mode() === $c::MODE_BLOCK && !$c::is_exempt($courseid);
    if (!$blocked && $rec && $rec->status === $c::DECLINED && $rec->version === $c::version()) {
        return '';
    }
    $str = function (string $k): string {
        return get_string('consent:' . $k, 'local_emotions_send');
    };
    $text = format_text($c::text(), FORMAT_HTML, ['context' => context_system::instance()]);
    $exiturl = (new moodle_url('/my/'))->out(false);
    $btn = 'padding:8px 18px;border-radius:6px;font-size:1rem;cursor:pointer;';

    // Mientras la ventana este abierta se ocultan los tours de Moodle (tool_usertours): en la primera
    // visita al curso el tour se superpone y su fondo bloquea los botones del consentimiento. El tour
    // no queda marcado como visto, asi que aparece despues de aceptar.
    return '<style id="emotions_consent_hidetours">[data-flexitour]{display:none !important;}</style>'
        . '<style>#emotions_consent .emotions-consent-text h3{font-size:1.1rem;font-weight:700;margin:1.1rem 0 .4rem;}'
        . '#emotions_consent .emotions-consent-text p{margin-bottom:.6rem;}</style>'
        . '<div id="emotions_consent" style="position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.6);'
        . 'display:flex;align-items:center;justify-content:center;padding:16px;">'
        . '<div role="dialog" aria-modal="true" aria-labelledby="emotions_consent_title" '
        . 'style="background:#fff;color:#1d2125;max-width:780px;width:100%;max-height:92vh;overflow:auto;'
        . 'border-radius:10px;padding:24px 28px;box-shadow:0 10px 40px rgba(0,0,0,.35);line-height:1.55;">'
        // Panel principal: texto + declaracion + decision.
        . '<div id="emotions_consent_main">'
        . '<h2 id="emotions_consent_title" tabindex="-1" style="margin-top:0;font-size:1.4rem;">' . s($str('title')) . '</h2>'
        . '<div class="emotions-consent-text" style="font-size:0.95rem;">' . $text . '</div>'
        . '<div style="border-top:1px solid #dee2e6;margin-top:16px;padding-top:14px;">'
        . '<label style="display:flex;gap:10px;align-items:flex-start;font-weight:600;">'
        . '<input type="checkbox" id="emotions_consent_check" style="margin-top:5px;width:18px;height:18px;flex-shrink:0;"> '
        . s($str('check')) . '</label>'
        . '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">'
        . '<button type="button" id="emotions_consent_accept" disabled style="' . $btn
        . 'border:none;background:#0f6cbf;color:#fff;">' . s($str('accept')) . '</button>'
        . '<button type="button" id="emotions_consent_decline" style="' . $btn
        . 'border:1px solid #6c757d;background:#fff;color:#1d2125;">' . s($str('decline')) . '</button>'
        . '</div>'
        . '<p id="emotions_consent_status" role="status" style="margin:10px 0 0;min-height:1.2em;"></p>'
        . '</div></div>'
        // Panel tras "No acepto" (modo bloqueante).
        . '<div id="emotions_consent_declined" hidden>'
        . '<h2 tabindex="-1" style="margin-top:0;font-size:1.3rem;">' . s($str('declinedtitle')) . '</h2>'
        . '<p>' . s($str('declinedtext')) . '</p>'
        . '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
        . '<button type="button" id="emotions_consent_reread" style="' . $btn
        . 'border:none;background:#0f6cbf;color:#fff;">' . s($str('reread')) . '</button>'
        . '<a href="' . s($exiturl) . '" style="' . $btn . 'border:1px solid #6c757d;color:#1d2125;text-decoration:none;">'
        . s($str('exit')) . '</a>'
        . '</div></div>'
        . '</div></div>'
        . local_emotions_send_consent_config_script($courseid);
}

/**
 * Callback clasico (Moodle < 4.4). En Moodle 4.4+ lo reemplaza el hook
 * \core\hook\output\before_footer_html_generation (db/hooks.php): Moodle
 * detecta que el plugin ya migro y deja de llamar a esta funcion (sin el
 * aviso de depuracion "should be migrated to new hook callback").
 */
function local_emotions_send_before_footer() {
    return local_emotions_send_widget_html();
}

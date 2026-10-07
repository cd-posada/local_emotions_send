<?php
/**
 * local/emotions_send/save.php
 *
 * Endpoint AJAX llamado por camera_global.js tras cada deteccion.
 * Actualizado para v5: antes de guardar la deteccion, resuelve/abre una
 * Learning_Session para este usuario+curso (se reusa si ya hay una
 * activa reciente; si estaba inactiva mas de 30 min se cierra sola por
 * 'Timeout' y se abre una nueva). El Emotion_Record cuelga de esa
 * sesion, no directo de userid/courseid.
 *
 * Inferencia en el cliente: ademas de emotion/confidence ahora llegan
 * 'level' (nivel DAiSEE 0-3 del estado dominante -> intensitydaisee) y
 * 'probabilities' (softmax de las 4 ramas -> classprobabilities). El
 * Inference_Model se resuelve server-side desde fer_model (el modelo que
 * el propio plugin le entrega al navegador), no desde un dato del cliente.
 *
 * Latencia (campos nuevos de user_model_v5_1): el navegador envia en ms de
 * epoca, con SU reloj, window_start_ms / window_end_ms (segmento de muestreo
 * que origino esta inferencia: desde la captura anterior hasta la de este
 * fotograma) e inference_completed_ms (fin del clasificador). Aqui se
 * corrigen con el Learning_Session.clock_offset_ms de la sesion
 * (servidor = cliente - desfase) y se guardan como DATETIME(3) UTC. Si la
 * sesion aun no tiene desfase, el que midio el navegador (clock_offset_ms)
 * se registra primero; sin desfase conocido las marcas quedan NULL, porque
 * sin corregir no son comparables con las del servidor.
 *
 * Seguridad (igual que siempre):
 *  - require_login() + confirm_sesskey(): autenticacion y CSRF.
 *  - El courseid del cliente se valida contra la matricula real.
 *  - El cmid se valida contra el courseid.
 *  - PARAM_* en todos los inputs; 'probabilities' se valida estructura y
 *    rango antes de guardarse.
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();

use local_emotions_send\local\data_helper;
use local_emotions_send\local\fer_model;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!confirm_sesskey()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid sesskey']);
    exit;
}

$emotion    = optional_param('emotion',    '',  PARAM_TEXT);
$confidence = optional_param('confidence', 0.0, PARAM_FLOAT);
$courseid   = optional_param('courseid',   0,   PARAM_INT);
$cmid       = optional_param('cmid',       0,   PARAM_INT);
$viewport   = optional_param('viewport',   '',  PARAM_TEXT);
$level      = optional_param('level',      -1,  PARAM_INT);
$probsraw   = optional_param('probabilities', '', PARAM_RAW);
$winstartms = optional_param('window_start_ms', 0, PARAM_INT);
$winendms   = optional_param('window_end_ms', 0, PARAM_INT);
$infdonems  = optional_param('inference_completed_ms', 0, PARAM_INT);
$clientoffs = optional_param('clock_offset_ms', '', PARAM_RAW);
// Entropia de Shannon normalizada [0, 1], calculada en el navegador en el mismo instante que la
// inferencia (fer_engine.js: cabeza DAiSEE de la emocion seleccionada). Fuera de rango -> NULL.
$entropyraw = optional_param('entropy', '', PARAM_RAW);
$entropy = (is_numeric($entropyraw) && (float)$entropyraw >= 0 && (float)$entropyraw <= 1)
    ? round((float)$entropyraw, 4) : null;

$level = ($level >= 0 && $level <= 3) ? $level : null;
$classprobabilities = data_helper::clean_branch_probabilities($probsraw);

if (empty($emotion)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing emotion']);
    exit;
}

global $USER, $DB;

$userid = (int)$USER->id;

$valid_course = 0;
if ($courseid > 1) {
    $course_ctx = context_course::instance($courseid, IGNORE_MISSING);
    if ($course_ctx && (is_enrolled($course_ctx, $userid, '', true) || is_siteadmin())) {
        $valid_course = $courseid;
    }
}

if ($valid_course === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'No matriculado en el curso indicado']);
    exit;
}

// Sin consentimiento informado aceptado no se recibe ningun dato del registro facial.
if (!\local_emotions_send\local\consent::has_accepted($userid, $valid_course)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin consentimiento informado']);
    exit;
}

$valid_cmid = 0;
$cmname = 'general';
$cmactivitytype = 'general';
if ($cmid > 0) {
    $cm = $DB->get_record('course_modules', ['id' => $cmid, 'course' => $valid_course]);
    if ($cm) {
        $valid_cmid = $cmid;
        $modinfo = $DB->get_record('modules', ['id' => $cm->module], 'name');
        if ($modinfo) {
            $cmname = $modinfo->name . '_' . $cmid;
            $cmactivitytype = $modinfo->name;
        }
    }
}

$devicetype = data_helper::detect_device_type($_SERVER['HTTP_USER_AGENT'] ?? '');

// Todo el acceso a datos pasa por data_helper, que usa el almacen
// configurado (tablas de Moodle o la base externa user_model_v5_1). Si el
// almacen falla (p. ej. la base externa no responde) se responde 503 y el
// widget sigue funcionando; el registro de ese segundo se pierde.
try {
    $emotionid = data_helper::get_or_create_emotion(clean_param($emotion, PARAM_TEXT));

    $localcourseid = data_helper::get_or_create_course_from_moodle($valid_course);
    if ($localcourseid === 0) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo resolver el curso local']);
        exit;
    }
    data_helper::set_user_program_if_missing($userid, data_helper::get_course_programid($localcourseid));

    $actcontextid = data_helper::get_or_create_actcontext($localcourseid, $cmname, $cmactivitytype);
    $sessionid = data_helper::get_or_create_open_session($userid, $localcourseid, $devicetype,
        clean_param($viewport, PARAM_TEXT));
    $infmodelid = data_helper::get_or_create_client_inference_model_id();

    // Desfase de reloj de la sesion (se escribe una sola vez) y correccion de las marcas.
    if (preg_match('/^-?\d{1,10}$/', (string)$clientoffs) && abs((int)$clientoffs) <= 2147483647) {
        data_helper::set_session_clock_offset($sessionid, (int)$clientoffs);
    }
    $offset = data_helper::get_session_clock_offset($sessionid);
    $corrected = function (int $clientms) use ($offset): ?int {
        return ($offset === null || $clientms <= 0) ? null : $clientms - $offset;
    };

    $recordid = data_helper::record_emotion([
        'sessionid'          => $sessionid,
        'emotionid'          => $emotionid,
        'actcontextid'       => $actcontextid,
        'infmodelid'         => $infmodelid,
        'confidence'         => round(min(1.0, max(0.0, (float)$confidence)), 4),
        'intensitydaisee'    => $level,
        'sourcemodel'        => fer_model::MODEL_FILE,
        'classprobabilities' => $classprobabilities,
        'entropy'            => $entropy,
        'windowstartms'        => $corrected($winstartms),
        'windowendms'          => $corrected($winendms),
        'inferencecompletedms' => $corrected($infdonems),
    ]);

    // La misma deteccion tambien queda como observacion en Reference_Observation
    // (metodo FER_ONNX, observador = modelo que la produjo, intensidad = nivel DAiSEE).
    $refobsid = data_helper::record_reference_observation($sessionid, $emotionid,
        data_helper::REFOBS_METHOD, fer_model::MODEL_FILE, $level);

    // Los Adaptation_Event ya NO se crean aqui: antes, si existia cualquier regla para la
    // emocion, cada deteccion (1 por segundo) generaba un evento 'APPLIED' provisional. Ahora
    // los registra el modulo de adaptacion real (block_adaptive_ui -> adaptation_logger), con
    // la decision del estudiante y los cambios que propuso el LLM.

    echo json_encode([
        'status'     => 'ok',
        'storage'    => data_helper::storage_mode(),
        'id'         => $recordid,
        'refobsid'   => $refobsid,
        'emotionid'  => $emotionid,
        'sessionid'  => $sessionid,
    ]);
} catch (Throwable $e) {
    // Al log del servidor (no a la respuesta: romperia el JSON con la depuracion en pantalla).
    error_log('[local_emotions_send] save.php (' . data_helper::storage_mode() . '): ' . $e->getMessage());
    http_response_code(data_helper::storage_mode() === data_helper::STORAGE_USERMODEL ? 503 : 500);
    echo json_encode(['error' => 'Error al guardar el registro', 'storage' => data_helper::storage_mode()]);
}

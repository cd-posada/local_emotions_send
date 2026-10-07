<?php
/**
 * local/emotions_send/log.php
 *
 * Endpoint AJAX que recibe, en lotes, las entradas del log de sesion del
 * navegador (sessionStorage['emotions_log'], ver js/session_log.js) y las
 * guarda en la tabla Registro_JSON de la base externa user_model_v5_1,
 * ligadas a la Learning_Session abierta del estudiante en el curso.
 *
 * Solo tiene efecto con el almacenamiento 'usermodel' (con 'moodle' el log
 * sigue solo en el navegador y lib.php ni siquiera configura esta URL).
 *
 * Peticion (POST): sesskey, courseid, viewport y entries = JSON con una
 * lista de hasta 200 entradas {type, timestamp, duration_seconds, emotion,
 * level, probability, epoch_ms}. Admite navigator.sendBeacon (FormData).
 *
 * Seguridad (igual que save.php): require_login + sesskey; el courseid se
 * valida contra la matricula real; cada entrada se valida campo por campo
 * (data_helper::clean_session_log_entries).
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();

use local_emotions_send\local\data_helper;

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

$courseid = optional_param('courseid', 0, PARAM_INT);
$viewport = optional_param('viewport', '', PARAM_TEXT);
$raw      = optional_param('entries', '', PARAM_RAW);

$userid = (int)$USER->id;
$coursectx = $courseid > 1 ? context_course::instance($courseid, IGNORE_MISSING) : null;
if (!$coursectx || !(is_enrolled($coursectx, $userid, '', true) || is_siteadmin())) {
    http_response_code(403);
    echo json_encode(['error' => 'No matriculado en el curso indicado']);
    exit;
}

// Sin consentimiento informado aceptado no se recibe ningun dato del registro facial.
if (!\local_emotions_send\local\consent::has_accepted($userid, $courseid)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin consentimiento informado']);
    exit;
}

$decoded = (strlen($raw) > 0 && strlen($raw) <= 200000) ? json_decode($raw, true) : null;
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(['error' => 'entries debe ser una lista JSON']);
    exit;
}
$entries = data_helper::clean_session_log_entries($decoded);

if (data_helper::storage_mode() !== data_helper::STORAGE_USERMODEL) {
    echo json_encode(['status' => 'ok', 'stored' => 0, 'storage' => data_helper::storage_mode()]);
    exit;
}

try {
    $stored = 0;
    if ($entries) {
        $localcourseid = data_helper::get_or_create_course_from_moodle($courseid);
        $sessionid = data_helper::get_or_create_open_session($userid, $localcourseid,
            data_helper::detect_device_type($_SERVER['HTTP_USER_AGENT'] ?? ''), clean_param($viewport, PARAM_TEXT));
        $stored = data_helper::record_session_log_entries($sessionid, $entries);
    }
    echo json_encode([
        'status'   => 'ok',
        'storage'  => data_helper::storage_mode(),
        'received' => count($decoded),
        'stored'   => $stored,
        'rejected' => count($decoded) - count($entries),
    ]);
} catch (Throwable $e) {
    // Al log del servidor (no a la respuesta: romperia el JSON con la depuracion en pantalla).
    error_log('[local_emotions_send] log.php: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Error al guardar el log de sesion']);
}

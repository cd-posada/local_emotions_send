<?php
/**
 * local/emotions_send/clock.php
 *
 * Servicio ligero para medir Learning_Session.clock_offset_ms: el desfase entre
 * el reloj del navegador y el del servidor (positivo = el reloj del cliente
 * adelanta al del servidor). Lo usa js/clock_sync.js al cargar la pagina:
 *
 *  - action=ping: intercambio de tiempos al estilo NTP. El navegador anota t0
 *    al enviar y t3 al recibir; el servidor responde t1 (llegada de la
 *    peticion, REQUEST_TIME_FLOAT) y t2 (envio de la respuesta), en ms de
 *    epoca Unix. Con t1 = t2 la formula del cliente se reduce a la del
 *    documento de diseno; separarlas descuenta el tiempo de arranque de Moodle,
 *    que de otro modo sesgaria el desfase en decenas de ms.
 *  - action=store: guarda el desfase medido (el de menor ida y vuelta) en la
 *    Learning_Session abierta del estudiante en el curso. Se escribe UNA sola
 *    vez por sesion (si ya tiene valor, no se reemplaza).
 *
 * Seguridad: require_login() + confirm_sesskey(); el curso se valida contra la
 * matricula real, igual que en save.php.
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();

use local_emotions_send\local\data_helper;

header('Content-Type: application/json');
header('Cache-Control: no-store');

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

$action = optional_param('action', 'ping', PARAM_ALPHA);

if ($action === 'ping') {
    $t1 = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (int)round($_SERVER['REQUEST_TIME_FLOAT'] * 1000)
        : data_helper::server_now_ms();
    echo json_encode(['t1' => $t1, 't2' => data_helper::server_now_ms()]);
    exit;
}

if ($action !== 'store') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$courseid = optional_param('courseid', 0, PARAM_INT);
$offset   = optional_param('offset', '', PARAM_RAW);
$userid   = (int)$USER->id;

// Entero con signo en ms; Learning_Session.clock_offset_ms es INT (+-24 dias).
if (!preg_match('/^-?\d{1,10}$/', (string)$offset) || abs((int)$offset) > 2147483647) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid offset']);
    exit;
}

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

try {
    $localcourseid = data_helper::get_or_create_course_from_moodle($courseid);
    $sessionid = $localcourseid > 0 ? data_helper::get_or_create_open_session($userid, $localcourseid,
        data_helper::detect_device_type($_SERVER['HTTP_USER_AGENT'] ?? '')) : 0;
    if (!$sessionid) {
        http_response_code(500);
        echo json_encode(['error' => 'Sin sesion de aprendizaje']);
        exit;
    }
    $stored = data_helper::set_session_clock_offset($sessionid, (int)$offset);
    echo json_encode([
        'status'          => 'ok',
        'stored'          => $stored,
        'sessionid'       => $sessionid,
        'clock_offset_ms' => data_helper::get_session_clock_offset($sessionid),
    ]);
} catch (Throwable $e) {
    error_log('[local_emotions_send] clock.php (' . data_helper::storage_mode() . '): ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Error al guardar el desfase']);
}

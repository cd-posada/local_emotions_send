<?php
/**
 * local/emotions_send/consent.php
 *
 * Decision del estudiante sobre el consentimiento informado del registro facial
 * en un curso (ventana de js/consent.js):
 *  - action=accept:  acepta (habilita la camara y, en modo 'block', el contenido).
 *  - action=decline: no acepta (sin camara; en modo 'block' el contenido sigue bloqueado).
 *  - action=revoke:  retira el consentimiento: borra sus datos en el curso y bloquea de nuevo.
 *
 * Seguridad: POST, require_login() + confirm_sesskey(); el curso se valida contra la
 * matricula real. Siempre sobre el usuario actual.
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();

use local_emotions_send\local\consent;

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

$action   = required_param('action', PARAM_ALPHA);
$courseid = required_param('courseid', PARAM_INT);
$userid   = (int)$USER->id;

$ctx = $courseid > 1 ? context_course::instance($courseid, IGNORE_MISSING) : null;
if (!$ctx || !(is_enrolled($ctx, $userid, '', true) || is_siteadmin()) || !consent::course_requires($courseid)) {
    http_response_code(403);
    echo json_encode(['error' => 'Curso no valido']);
    exit;
}

try {
    switch ($action) {
        case 'accept':
            consent::accept($userid, $courseid);
            echo json_encode(['status' => 'accepted']);
            break;
        case 'decline':
            consent::decline($userid, $courseid);
            echo json_encode(['status' => 'declined', 'blocked' => consent::mode() === consent::MODE_BLOCK
                && !consent::is_exempt($courseid)]);
            break;
        case 'revoke':
            $deleted = consent::revoke($userid, $courseid);
            echo json_encode(['status' => 'revoked', 'datadeleted' => $deleted]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Accion no valida']);
    }
} catch (Throwable $e) {
    error_log('[local_emotions_send] consent.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo guardar la decision']);
}

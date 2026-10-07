<?php
/**
 * local/emotions_send/assets.php
 *
 * Entrega al navegador los archivos que necesita el reconocimiento facial
 * en el cliente: el modelo ONNX (~30 MB) y el Haar Cascade de OpenCV.
 *
 * Se sirven por PHP (y no como archivo estatico) para:
 *  - Exigir sesion iniciada: el modelo no queda publico.
 *  - No depender de la configuracion del servidor web (algunos bloquean
 *    extensiones desconocidas como .onnx dentro del dirroot).
 *  - Mandar cabeceras de cache largas: la URL lleva ?v=MODEL_VERSION, asi
 *    que el navegador lo descarga una sola vez por version del modelo.
 *
 * Seguridad: solo claves de la lista blanca fer_model::assets(), nunca
 * rutas recibidas del cliente.
 */
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_emotions_send\local\fer_model;

require_login(null, false);

$file = required_param('file', PARAM_ALPHA);
$path = fer_model::asset_path($file);

if ($path === null || !is_readable($path)) {
    send_file_not_found();
}

// Liberar el bloqueo de sesion antes de transmitir 30 MB: si no, cualquier
// otra peticion del mismo usuario queda en espera hasta que termine.
\core\session\manager::write_close();

[$filename, $mimetype] = fer_model::assets()[$file];
send_file($path, $filename, YEARSECS, 0, false, false, $mimetype);

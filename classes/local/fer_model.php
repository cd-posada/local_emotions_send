<?php
/**
 * local/emotions_send/classes/local/fer_model.php
 *
 * Configuracion UNICA del reconocimiento facial 100% en el cliente
 * (ONNX Runtime Web + OpenCV.js). Reemplaza al backend Python
 * (emotions_backend en Render): el navegador del estudiante descarga el
 * modelo ONNX y el Haar Cascade desde Moodle (via assets.php) y hace
 * deteccion de rostro + inferencia localmente. Ningun fotograma sale del
 * equipo del estudiante; a Moodle solo llega la emocion ya clasificada.
 *
 * lib.php (config del widget), assets.php (entrega de archivos) y
 * save.php (registro del Inference_Model) leen todos de aqui, para que
 * cambiar de modelo sea tocar UN solo archivo: copiar el nuevo .onnx a
 * models/, actualizar MODEL_FILE / MODEL_LABEL y subir MODEL_VERSION
 * (invalida la cache del navegador).
 */
namespace local_emotions_send\local;

defined('MOODLE_INTERNAL') || die();

class fer_model {

    /** Archivo ONNX dentro de local/emotions_send/models/. */
    const MODEL_FILE = 'trat20_efficientnet_b2_focal_loss_best_15k.onnx';

    /** Nombre visible en el panel de diagnostico (igual que web_onnx_test_v5). */
    const MODEL_LABEL = 'EfficientNet-B2 [trat20 - 15k]';

    /** Se agrega como ?v= a la URL del modelo: subirlo al cambiar el .onnx. */
    const MODEL_VERSION = '2026092500';

    const MODEL_ARCHITECTURE = 'EfficientNet-B2, 4 ramas DAiSEE (nivel 0-3 por estado)';
    const MODEL_DATASET      = 'DAiSEE';

    /** Haar Cascade de OpenCV (mismo XML que los scripts Python y web_onnx_test_v5). */
    const CASCADE_FILE = 'haarcascade_frontalface_default.xml';

    /** ONNX Runtime Web (misma version que web_onnx_test_v5). */
    const ORT_URL        = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.20.1/dist/ort.min.js';
    const ORT_WASM_PATHS = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.20.1/dist/';

    /**
     * OpenCV.js 4.9.0 (misma build que docs.opencv.org/4.9.0/opencv.js,
     * espejada en jsDelivr). Se usa jsDelivr y no docs.opencv.org porque
     * el script se descarga con fetch() (ver fer_engine.js) y
     * docs.opencv.org no manda cabeceras CORS.
     */
    const OPENCV_URL = 'https://cdn.jsdelivr.net/npm/@techstark/opencv-js@4.9.0-release.2/dist/opencv.js';

    /**
     * Archivos que assets.php puede entregar: clave => [archivo, mimetype].
     * Lista blanca: assets.php no acepta rutas arbitrarias.
     */
    public static function assets(): array {
        return [
            'model'   => [self::MODEL_FILE,   'application/octet-stream'],
            'cascade' => [self::CASCADE_FILE, 'application/xml'],
        ];
    }

    public static function asset_path(string $key): ?string {
        global $CFG;
        $assets = self::assets();
        if (!isset($assets[$key])) {
            return null;
        }
        return $CFG->dirroot . '/local/emotions_send/models/' . $assets[$key][0];
    }

    public static function asset_url(string $key): string {
        return (new \moodle_url('/local/emotions_send/assets.php',
            ['file' => $key, 'v' => self::MODEL_VERSION]))->out(false);
    }

    /** Nombre del modelo como se registra en local_emotions_infmodel. */
    public static function inference_model_name(): string {
        return preg_replace('/\.onnx$/i', '', self::MODEL_FILE);
    }

    /** Configuracion que consume el JS del cliente (window.EMOTIONS_GLOBAL_CONFIG). */
    public static function js_config(): array {
        return [
            'modelUrl'     => self::asset_url('model'),
            'modelFile'    => self::MODEL_FILE,
            'modelLabel'   => self::MODEL_LABEL,
            'cascadeUrl'   => self::asset_url('cascade'),
            'ortUrl'       => self::ORT_URL,
            'ortWasmPaths' => self::ORT_WASM_PATHS,
            'opencvUrl'    => self::OPENCV_URL,
        ];
    }
}

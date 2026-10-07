<?php
/**
 * local/emotions_send/cli/check_usermodel.php
 *
 * Verifica la base externa user_model_v5_1 (almacenamiento 'usermodel'):
 * conexion, esquema (las 19 tablas de user_model_v5.1_mysql.sql) y carga
 * idempotente de los catalogos (emociones, idiomas, niveles, programa por
 * defecto, modelo de Norman, Inference_Model "Sin especificar").
 *
 *   php local/emotions_send/cli/check_usermodel.php
 *
 * Codigo de salida: 0 = todo bien, 1 = sin conexion o esquema incompleto.
 * Funciona aunque el ajuste 'storage' este en 'moodle' (sirve para probar
 * la conexion antes de activarlo).
 */
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

use local_emotions_send\local\data_helper;
use local_emotions_send\local\usermodel_db;
use local_emotions_send\local\store\usermodel_store;

$c = usermodel_db::config();
mtrace("Almacenamiento configurado: " . data_helper::storage_mode());
mtrace("Base externa: {$c['user']}@{$c['host']}:{$c['port']}/{$c['name']}");

try {
    $version = usermodel_db::get_field("SELECT VERSION()");
    mtrace("  Conexion OK (servidor {$version})");
} catch (Throwable $e) {
    mtrace("  ERROR de conexion: " . $e->getMessage());
    exit(1);
}

$missing = usermodel_db::check_schema();
if ($missing) {
    mtrace("  ERROR: faltan tablas del esquema v5.1: " . implode(', ', $missing));
    mtrace("  Prepararla con: php local/emotions_send/cli/setup_usermodel.php (o la pagina usermodel.php).");
    exit(1);
}
mtrace("  Esquema OK (" . count(usermodel_db::TABLES) . " tablas)");

// El constructor carga los catalogos si faltan; seed_catalogs() completa el resto.
$store = new usermodel_store();
$store->seed_catalogs();
foreach (['Emotion', 'Language', 'Academic_Level', 'Academic_Program', 'Norman_Level', 'Inference_Model'] as $t) {
    mtrace(sprintf("  %-18s %d fila(s)", $t, usermodel_db::get_field("SELECT COUNT(*) FROM `{$t}`")));
}
exit(0);

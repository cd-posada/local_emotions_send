<?php
/**
 * local/emotions_send/cli/setup_usermodel.php
 *
 * Prepara la base externa user_model_v5_1 con la conexion configurada en el
 * plugin (Administracion del sitio > Plugins > Plugins locales > Emotions Send):
 * crea las tablas que falten, las extensiones del plugin (Registro_JSON, campos
 * de latencia, triggers, vista) y los catalogos. Idempotente; nunca borra datos.
 * Equivale al boton de la pagina local/emotions_send/usermodel.php.
 *
 * Uso:  php local/emotions_send/cli/setup_usermodel.php
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_emotions_send\local\usermodel_db;
use local_emotions_send\local\usermodel_schema;

$c = usermodel_db::config();
mtrace("Base externa: {$c['user']}@{$c['host']}:{$c['port']}/{$c['name']}");
try {
    foreach (usermodel_schema::install() as $line) {
        mtrace('  ' . $line);
    }
} catch (Throwable $e) {
    mtrace('  ERROR: ' . $e->getMessage());
    mtrace('  Revise la conexion y que el usuario tenga permisos CREATE, ALTER, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW y TRIGGER sobre la base.');
    exit(1);
}
$st = usermodel_schema::status();
mtrace($st['ready'] ? '  Esquema completo.' : '  AVISO: el esquema sigue incompleto: ' . json_encode($st));
exit($st['ready'] ? 0 : 1);

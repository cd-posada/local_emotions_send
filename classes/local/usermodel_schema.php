<?php
/**
 * local/emotions_send/classes/local/usermodel_schema.php
 *
 * Prepara y verifica el esquema de la base EXTERNA user_model_v5_1 (almacen
 * 'usermodel') con la conexion configurada en el plugin. Lo usan la pagina de
 * administracion usermodel.php y cli/setup_usermodel.php.
 *
 * install() es idempotente y NUNCA borra datos:
 *  1. Tablas del modelo v5.1 que falten (db/usermodel/user_model_v5_1.sql,
 *     CREATE TABLE IF NOT EXISTS).
 *  2. Extensiones del plugin:
 *     - Registro_JSON: log de sesion del navegador (session_log.js -> log.php).
 *     - Instrumentacion de latencia: Learning_Session.clock_offset_ms;
 *       Emotion_Record.window_start/window_end/inference_completed_at;
 *       Adaptation_Event.decision_requested_at/decision_generated_at/
 *       applied_at/applied_at_server/latency_total_ms.
 *     - Triggers que derivan latency_total_ms (applied_at_server - window_end).
 *     - Vista v_latencia_adaptacion con los tramos de la latencia.
 *  3. Catalogos (Emotion, Language, Academic_Level, Norman_Level, ...).
 *
 * Las columnas se agregan tras consultar information_schema (no con
 * ADD COLUMN IF NOT EXISTS, que solo existe en MariaDB): funciona igual en
 * MySQL 8 y en MariaDB 10.4+.
 *
 * La BASE y su USUARIO los crea el DBA (ver la guia de instalacion). Ese usuario
 * necesita, sobre esa base: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER,
 * INDEX, REFERENCES, CREATE VIEW, SHOW VIEW y TRIGGER (no necesita DROP: la
 * vista se crea solo si falta). Con el registro binario activo en
 * MySQL, crear triggers exige ademas log_bin_trust_function_creators = 1.
 */
namespace local_emotions_send\local;

defined('MOODLE_INTERNAL') || die();

use local_emotions_send\local\usermodel_db as xdb;

class usermodel_schema {

    /** Tablas de extension del plugin (ademas de usermodel_db::TABLES). */
    const EXTRA_TABLES = ['Registro_JSON'];

    /** Columnas de la instrumentacion de latencia: [tabla, columna, definicion, despues de]. */
    const COLUMNS = [
        ['Learning_Session', 'clock_offset_ms',
            "INT NULL COMMENT 'Desfase reloj cliente - servidor en ms, medido al abrir la sesion'", 'fer_capture_enabled'],
        ['Emotion_Record', 'window_start',
            "DATETIME(3) NULL COMMENT 'Inicio de la ventana de observacion (UTC)'", 'entropy'],
        ['Emotion_Record', 'window_end',
            "DATETIME(3) NULL COMMENT 'Fin de la ventana de observacion (UTC)'", 'window_start'],
        ['Emotion_Record', 'inference_completed_at',
            "DATETIME(3) NULL COMMENT 'Fin del computo del clasificador (UTC)'", 'window_end'],
        ['Adaptation_Event', 'decision_requested_at',
            "DATETIME(3) NULL COMMENT 'Envio de la solicitud de adaptacion (UTC, reloj servidor)'", 'reverted_at'],
        ['Adaptation_Event', 'decision_generated_at',
            "DATETIME(3) NULL COMMENT 'Recepcion de la especificacion de adaptacion (UTC, reloj servidor)'", 'decision_requested_at'],
        ['Adaptation_Event', 'applied_at',
            "DATETIME(3) NULL COMMENT 'CSS aplicado (UTC, reloj del navegador, sin corregir)'", 'decision_generated_at'],
        ['Adaptation_Event', 'applied_at_server',
            "DATETIME(3) NULL COMMENT 'Confirmacion de aplicacion recibida (UTC, reloj servidor)'", 'applied_at'],
        ['Adaptation_Event', 'latency_total_ms',
            "BIGINT NULL COMMENT 'Derivado por trigger: applied_at_server - window_end, en ms'", 'applied_at_server'],
    ];

    const TRIGGERS = ['trg_adaptation_event_latency_bi', 'trg_adaptation_event_latency_bu'];
    const VIEW = 'v_latencia_adaptacion';

    /**
     * Estado del esquema. Nunca lanza excepciones.
     * @return array {connected, server, error, missingtables[], missingcolumns[], missingtriggers[], viewok, ready}
     */
    public static function status(): array {
        $st = ['connected' => false, 'server' => '', 'error' => '', 'missingtables' => [],
               'missingcolumns' => [], 'missingtriggers' => [], 'viewok' => false, 'ready' => false];
        try {
            $st['server'] = (string)xdb::get_field('SELECT VERSION()');
            $st['connected'] = true;
            $db = xdb::config()['name'];
            $tables = array_map(function ($r) {
                return $r->t;
            }, xdb::get_list("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$db]));
            $st['missingtables'] = array_values(array_diff(array_merge(xdb::TABLES, self::EXTRA_TABLES), $tables));
            foreach (self::COLUMNS as [$table, $column]) {
                if (in_array($table, $tables, true) && !self::column_exists($table, $column)) {
                    $st['missingcolumns'][] = "{$table}.{$column}";
                }
            }
            $triggers = array_map(function ($r) {
                return $r->t;
            }, xdb::get_list("SELECT TRIGGER_NAME AS t FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?", [$db]));
            $st['missingtriggers'] = array_values(array_diff(self::TRIGGERS, $triggers));
            $st['viewok'] = (bool)xdb::get_field("SELECT COUNT(*) FROM information_schema.VIEWS
                                                   WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$db, self::VIEW]);
            $st['ready'] = !$st['missingtables'] && !$st['missingcolumns'] && !$st['missingtriggers'] && $st['viewok'];
        } catch (\Throwable $e) {
            $st['error'] = $e->getMessage();
        }
        return $st;
    }

    /**
     * Crea lo que falte del esquema. Idempotente; nunca borra datos.
     * @return string[] lineas de registro de lo que hizo
     * @throws \moodle_exception si una sentencia falla (la conexion o los permisos no alcanzan)
     */
    public static function install(): array {
        global $CFG;
        $log = [];
        $m = xdb::conn();
        $db = xdb::config()['name'];
        $q = '`' . str_replace('`', '', $db) . '`';

        // 1. Tablas base del modelo v5.1.
        $before = count(self::existing_tables($db));
        self::run_script($m, file_get_contents($CFG->dirroot . '/local/emotions_send/db/usermodel/user_model_v5_1.sql'));
        $created = count(self::existing_tables($db)) - $before;
        $log[] = $created > 0 ? "Tablas del modelo v5.1 creadas: {$created}" : 'Tablas del modelo v5.1: ya existian';

        // 2a. Registro_JSON.
        self::exec($m, "CREATE TABLE IF NOT EXISTS {$q}.`Registro_JSON` (
            `id`                  BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID unico de la entrada',
            `id_learning_session` BIGINT NULL COMMENT 'FK hacia Learning_Session(id_learning_sesion)',
            `recorded_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha/hora real de la entrada (UTC)',
            `type`                VARCHAR(20)   NOT NULL COMMENT 'Tipo de entrada: second | window',
            `timestamp`           DECIMAL(10,2) NOT NULL COMMENT 'Segundos desde que cargo la pagina (reloj de session_log.js)',
            `duration_seconds`    DECIMAL(8,2)  NULL COMMENT 'Duracion de la ventana en segundos (solo type=window)',
            `emotion`             VARCHAR(80)   NULL COMMENT 'Emocion (nombre, p. ej. Confundido)',
            `level`               TINYINT       NULL COMMENT 'Nivel de intensidad DAiSEE (0-3)',
            `probability`         DECIMAL(5,4)  NULL COMMENT 'Probabilidad (0-1)',
            KEY `idx_regjson_session` (`id_learning_session`),
            CONSTRAINT `fk_regjson_session` FOREIGN KEY (`id_learning_session`)
                REFERENCES {$q}.`Learning_Session` (`id_learning_sesion`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Entradas del log de sesion del plugin (formato JSON de session_log.js)'");
        $log[] = 'Tabla Registro_JSON lista';

        // 2b. Columnas de latencia.
        $added = [];
        foreach (self::COLUMNS as [$table, $column, $definition, $after]) {
            if (!self::column_exists($table, $column)) {
                $pos = self::column_exists($table, $after) ? " AFTER `{$after}`" : '';
                self::exec($m, "ALTER TABLE {$q}.`{$table}` ADD COLUMN `{$column}` {$definition}{$pos}");
                $added[] = "{$table}.{$column}";
            }
        }
        $log[] = $added ? 'Columnas de latencia agregadas: ' . implode(', ', $added) : 'Columnas de latencia: ya existian';

        // 2c. Triggers de latency_total_ms: la aplicacion nunca la escribe, asi no puede
        // contradecir a las marcas de las que deriva.
        $body = "FOR EACH ROW SET NEW.`latency_total_ms` = (
            SELECT ROUND(TIMESTAMPDIFF(MICROSECOND, er.`window_end`, NEW.`applied_at_server`) / 1000)
              FROM {$q}.`Emotion_Record` er WHERE er.`id_emotion_record` = NEW.`id_emotion_record`)";
        foreach (['trg_adaptation_event_latency_bi' => 'BEFORE INSERT', 'trg_adaptation_event_latency_bu' => 'BEFORE UPDATE']
                as $name => $timing) {
            self::exec($m, "DROP TRIGGER IF EXISTS {$q}.`{$name}`");
            self::exec($m, "CREATE TRIGGER {$q}.`{$name}` {$timing} ON {$q}.`Adaptation_Event` {$body}");
        }
        $log[] = 'Triggers de latency_total_ms listos';

        // 2d. Vista de analisis de la latencia. Se crea solo si falta: CREATE OR REPLACE VIEW
        // exige el permiso DROP, que el usuario del plugin no necesita para nada mas.
        if (self::view_exists($db)) {
            $log[] = 'Vista ' . self::VIEW . ': ya existia';
        } else {
            self::exec($m, "CREATE VIEW {$q}.`" . self::VIEW . "` AS
                SELECT ae.`id_adaptation_event`, ae.`id_learning_session`, ls.`id_user`, ae.`decision_state`,
                       ae.`id_emotion_record`, ls.`clock_offset_ms`,
                       ROUND(TIMESTAMPDIFF(MICROSECOND, er.`window_start`, er.`window_end`) / 1000) AS `ventana_ms`,
                       ROUND(TIMESTAMPDIFF(MICROSECOND, er.`window_end`, er.`inference_completed_at`) / 1000) AS `tramo1_inferencia_ms`,
                       ROUND(TIMESTAMPDIFF(MICROSECOND, ae.`decision_requested_at`, ae.`decision_generated_at`) / 1000) AS `tramo2_decision_ms`,
                       ROUND(TIMESTAMPDIFF(MICROSECOND, ae.`decision_generated_at`, ae.`applied_at_server`) / 1000) AS `tramo3_aplicacion_ms`,
                       ae.`latency_total_ms`,
                       ROUND(TIMESTAMPDIFF(MICROSECOND, ae.`applied_at` - INTERVAL ls.`clock_offset_ms` * 1000 MICROSECOND,
                                           ae.`applied_at_server`) / 1000) AS `verificacion_applied_ms`
                  FROM {$q}.`Adaptation_Event` ae
                  JOIN {$q}.`Emotion_Record` er ON er.`id_emotion_record` = ae.`id_emotion_record`
                  JOIN {$q}.`Learning_Session` ls ON ls.`id_learning_sesion` = ae.`id_learning_session`");
            $log[] = 'Vista ' . self::VIEW . ' lista';
        }

        // 3. Catalogos.
        (new store\usermodel_store())->seed_catalogs();
        $log[] = 'Catalogos cargados (Emotion, Language, Academic_Level, Norman_Level, Inference_Model, ...)';
        return $log;
    }

    // ---------------------------------------------------------------- auxiliares

    private static function existing_tables(string $db): array {
        return xdb::get_list("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$db]);
    }

    private static function view_exists(string $db): bool {
        return (bool)xdb::get_field("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$db, self::VIEW]);
    }

    private static function column_exists(string $table, string $column): bool {
        return (bool)xdb::get_field("SELECT COUNT(*) FROM information_schema.COLUMNS
                                      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [xdb::config()['name'], $table, $column]);
    }

    private static function exec(\mysqli $m, string $sql): void {
        if (!$m->query($sql)) {
            throw new \moodle_exception('usermodel:queryerror', 'local_emotions_send', '', $m->error);
        }
    }

    /** Ejecuta un script de varias sentencias; cualquier error lo aborta. */
    private static function run_script(\mysqli $m, string $sql): void {
        if (!$m->multi_query($sql)) {
            throw new \moodle_exception('usermodel:queryerror', 'local_emotions_send', '', $m->error);
        }
        do {
            if ($res = $m->store_result()) {
                $res->free();
            }
            if ($m->errno) {
                throw new \moodle_exception('usermodel:queryerror', 'local_emotions_send', '', $m->error);
            }
        } while ($m->more_results() && $m->next_result());
        if ($m->errno) {
            throw new \moodle_exception('usermodel:queryerror', 'local_emotions_send', '', $m->error);
        }
    }
}

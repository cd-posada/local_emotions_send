<?php
/**
 * local/emotions_send/classes/local/usermodel_db.php
 *
 * Conexion a la base de datos EXTERNA del modelo de usuario v5.1
 * (user_model_v5_1, creada con user_model_v5.1_mysql.sql), usada cuando
 * la opcion de almacenamiento del plugin es 'usermodel'.
 *
 * Por que mysqli propio y no un segundo moodle_database: la DML de Moodle
 * arma INSERT/UPDATE con los nombres de columna SIN comillas invertidas, y
 * el esquema v5.1 tiene columnas que las exigen (`code_iso_639-1`, con
 * guion) y tablas/columnas que coinciden con palabras clave (`User`,
 * `Rule`, `date`, `timestamp`). Aqui todo el SQL se escribe a mano, con
 * comillas invertidas y SIEMPRE con sentencias preparadas.
 *
 * Fechas: la sesion se fija en UTC (time_zone = '+00:00'); las columnas
 * DATETIME se escriben con FROM_UNIXTIME() y se leen con UNIX_TIMESTAMP(),
 * asi el resto del plugin sigue trabajando con timestamps de Moodle.
 */
namespace local_emotions_send\local;

defined('MOODLE_INTERNAL') || die();

class usermodel_db {

    /** Las 19 tablas que debe tener la base (verificadas por check_schema()). */
    const TABLES = [
        'Language', 'User', 'User_Language', 'Context', 'Academic_Level', 'Academic_Program',
        'Course', 'Learning_Session', 'Activity_context', 'Emotional_model', 'Norman_Level',
        'Inference_Model', 'Emotion', 'Rule', 'Emotion_Record', 'Reference_Observation',
        'Adaptation_Event', 'Adaptation_Feedback', 'Adaptation_Change',
    ];

    /** @var \mysqli|null */
    private static $conn = null;

    /** Configuracion de conexion (ajustes del plugin; ver settings.php). */
    public static function config(): array {
        $cfg = get_config('local_emotions_send');
        return [
            'host' => (string)($cfg->extdb_host ?? '127.0.0.1'),
            'port' => (int)($cfg->extdb_port ?? 3306),
            'name' => (string)($cfg->extdb_name ?? 'user_model_v5_1'),
            'user' => (string)($cfg->extdb_user ?? ''),
            'pass' => (string)($cfg->extdb_pass ?? ''),
        ];
    }

    /** Conexion unica por peticion. Lanza \moodle_exception si falla. */
    public static function conn(): \mysqli {
        if (self::$conn !== null) {
            return self::$conn;
        }
        $c = self::config();
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = mysqli_init();
        $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        if (!@$m->real_connect($c['host'], $c['user'], $c['pass'], $c['name'], $c['port'])) {
            throw new \moodle_exception('usermodel:connectionerror', 'local_emotions_send', '',
                "{$c['user']}@{$c['host']}:{$c['port']}/{$c['name']}: " . mysqli_connect_error());
        }
        $m->set_charset('utf8mb4');
        $m->query("SET time_zone = '+00:00'");
        self::$conn = $m;
        return $m;
    }

    /** Ejecuta una sentencia preparada con parametros posicionales (?). */
    private static function run(string $sql, array $params = []): \mysqli_stmt {
        $m = self::conn();
        $stmt = $m->prepare($sql);
        if (!$stmt) {
            throw new \moodle_exception('usermodel:queryerror', 'local_emotions_send', '', $m->error . ' | ' . $sql);
        }
        if ($params) {
            $params = array_values($params);
            $types = '';
            foreach ($params as $i => $p) {
                if (is_int($p) || is_bool($p)) {
                    $types .= 'i';
                    $params[$i] = (int)$p;
                } else if (is_float($p)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            throw new \moodle_exception('usermodel:queryerror', 'local_emotions_send', '', $stmt->error . ' | ' . $sql);
        }
        return $stmt;
    }

    /** Filas como objetos, indexadas por la primera columna (igual que $DB->get_records). */
    public static function get_records(string $sql, array $params = []): array {
        $stmt = self::run($sql, $params);
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[reset($row)] = (object)$row;
        }
        $stmt->close();
        return $rows;
    }

    /** Filas como lista (sin indexar; para consultas cuya primera columna se repite). */
    public static function get_list(string $sql, array $params = []): array {
        $stmt = self::run($sql, $params);
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = (object)$row;
        }
        $stmt->close();
        return $rows;
    }

    public static function get_record(string $sql, array $params = []) {
        $rows = self::get_list($sql, $params);
        return $rows ? $rows[0] : false;
    }

    public static function get_field(string $sql, array $params = []) {
        $row = self::get_record($sql, $params);
        if (!$row) {
            return false;
        }
        $values = array_values((array)$row);
        return $values[0];
    }

    /** INSERT; retorna el id AUTO_INCREMENT generado. */
    public static function insert(string $sql, array $params = []): int {
        $stmt = self::run($sql, $params);
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /** UPDATE/DELETE; retorna filas afectadas. */
    public static function execute(string $sql, array $params = []): int {
        $stmt = self::run($sql, $params);
        $n = (int)$stmt->affected_rows;
        $stmt->close();
        return $n;
    }

    /** Tablas del esquema v5.1 que faltan en la base (vacio = esquema completo). */
    public static function check_schema(): array {
        $c = self::config();
        $present = array_map(function ($r) {
            return $r->t;
        }, self::get_list(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$c['name']]));
        return array_values(array_diff(self::TABLES, $present));
    }

    /** Para pruebas / CLI: cierra la conexion actual. */
    public static function close(): void {
        if (self::$conn) {
            self::$conn->close();
            self::$conn = null;
        }
    }
}

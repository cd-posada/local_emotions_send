<?php
/**
 * local/emotions_send/debug_tables.php
 *
 * Pagina de SOLO LECTURA, exclusiva para siteadmin, para inspeccionar
 * rapidamente el contenido de las tablas del modelo v3 durante pruebas.
 */
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/emotions_send/debug_tables.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Emotions Send - Inspector de tablas');
$PAGE->set_heading('Emotions Send - Inspector de tablas (solo pruebas)');

global $DB, $OUTPUT;

// Las tablas y sus filas las entrega el almacen configurado (tablas de
// Moodle o base externa user_model_v5_1): ver data_helper::debug_tables().
try {
    $tables = \local_emotions_send\local\data_helper::debug_tables(15);
    $storagedesc = \local_emotions_send\local\data_helper::store()->describe();
    $storageerror = '';
} catch (Throwable $e) {
    $tables = [];
    $storagedesc = \local_emotions_send\local\data_helper::storage_mode();
    $storageerror = $e->getMessage();
}

echo $OUTPUT->header();
?>
<style>
.dbg-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px;
            padding:14px 16px; margin-bottom:16px; }
.dbg-card h3 { margin:0 0 4px; font-size:0.95em; }
.dbg-count { color:#6c757d; font-size:0.8em; margin-bottom:8px; }
.dbg-table { width:100%; border-collapse:collapse; font-size:0.78em; }
.dbg-table th { background:#f8f9fa; text-align:left; padding:4px 6px;
                border-bottom:2px solid #dee2e6; }
.dbg-table td { padding:4px 6px; border-bottom:1px solid #f1f3f5; }
.dbg-empty { color:#adb5bd; font-style:italic; font-size:0.82em; }
</style>
<div style="max-width:1000px;margin:0 auto;padding:16px;">
<p style="color:#6c757d;font-size:0.85em;">
    Muestra hasta 15 filas mas recientes de cada tabla. Solo visible para administradores del sitio.
</p>
<?php
echo '<p style="font-size:0.85em;"><strong>Almacenamiento:</strong> ' . s($storagedesc) . '</p>';
if ($storageerror !== '') {
    echo '<div class="dbg-card" style="border-color:#dc3545;color:#dc3545;">' . s($storageerror) . '</div>';
}

foreach ($tables as $info) {
    echo '<div class="dbg-card"><h3>' . s($info['label']) . '</h3>';
    echo '<div class="dbg-count"><code>' . s($info['table']) . '</code></div>';

    if ($info['total'] === null) {
        echo '<div class="dbg-empty">La tabla no existe (falta el upgrade o el esquema de la base externa).</div></div>';
        continue;
    }

    echo '<div class="dbg-count">' . $info['total'] . ' fila(s) en total</div>';

    if ($info['total'] === 0 || !$info['rows']) {
        echo '<div class="dbg-empty">Sin datos aun.</div></div>';
        continue;
    }

    $rows = $info['rows'];
    $columns = array_keys((array)reset($rows));

    echo '<div style="overflow-x:auto;"><table class="dbg-table"><thead><tr>';
    foreach ($columns as $col) {
        echo '<th>' . s($col) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $col) {
            echo '<td>' . s((string)$row->{$col}) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
}
?>
</div>
<?php
echo $OUTPUT->footer();

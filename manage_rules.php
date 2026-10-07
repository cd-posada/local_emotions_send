<?php
/**
 * local/emotions_send/manage_rules.php
 *
 * Pantalla de administracion (solo siteadmin) para cargar Modelos
 * emocionales, Norman_Level y Rules. En v5, una regla SIEMPRE liga a
 * una Emocion especifica y declara su ui_dimension (ambos obligatorios
 * en el diagrama, a diferencia de v3 donde eran opcionales).
 */
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

use local_emotions_send\local\data_helper;

$PAGE->set_url(new moodle_url('/local/emotions_send/manage_rules.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Emotions Send - Modelos y reglas');
$PAGE->set_heading('Modelos emocionales, niveles y reglas');

global $DB;

$action = optional_param('action', '', PARAM_ALPHA);

if ($action && confirm_sesskey()) {
    if ($action === 'addmodel') {
        $desc = required_param('description', PARAM_TEXT);
        if (trim($desc) !== '') {
            data_helper::create_model($desc);
        }
    } else if ($action === 'addlevel') {
        $modelid = required_param('modelid', PARAM_INT);
        $code    = required_param('code', PARAM_TEXT);
        $desc    = optional_param('description', '', PARAM_TEXT);
        if ($modelid > 0 && trim($code) !== '') {
            data_helper::create_model_level($modelid, $code, $desc);
        }
    } else if ($action === 'addrule') {
        $modellevelid = required_param('modellevelid', PARAM_INT);
        $emotionid    = required_param('emotionid', PARAM_INT);
        $uidimension  = required_param('ui_dimension', PARAM_TEXT);
        $rule         = required_param('rule', PARAM_TEXT);
        if ($modellevelid > 0 && $emotionid > 0 && trim($uidimension) !== '' && trim($rule) !== '') {
            data_helper::create_rule($modellevelid, $emotionid, $uidimension, $rule);
        }
    } else if ($action === 'deleterule') {
        $ruleid = required_param('ruleid', PARAM_INT);
        data_helper::delete_rule($ruleid);
    }
    redirect(new moodle_url('/local/emotions_send/manage_rules.php'));
}

$models    = data_helper::get_models();
$levels    = data_helper::get_model_levels();
$rules     = data_helper::get_rules();
$emotions  = data_helper::get_emotions();

echo $OUTPUT->header();
?>
<style>
.mr-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px;
           padding:16px 18px; margin-bottom:18px; max-width:860px; }
.mr-card h3 { margin:0 0 10px; font-size:0.95em; }
.mr-form { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
.mr-form label { display:block; font-size:0.78em; font-weight:600; margin-bottom:2px; }
.mr-form input[type=text], .mr-form select {
    border:1px solid #ced4da; border-radius:5px; padding:5px 8px; font-size:0.83em;
}
.mr-form button { background:#0d6efd; color:#fff; border:none; border-radius:5px;
                   padding:6px 14px; font-size:0.83em; cursor:pointer; }
.mr-table { width:100%; border-collapse:collapse; font-size:0.82em; }
.mr-table th { background:#f8f9fa; text-align:left; padding:5px 8px; border-bottom:2px solid #dee2e6; }
.mr-table td { padding:5px 8px; border-bottom:1px solid #f1f3f5; }
.mr-empty { color:#adb5bd; font-style:italic; font-size:0.85em; }
.mr-del { color:#dc3545; text-decoration:none; font-size:0.85em; }
</style>

<div style="max-width:880px;margin:0 auto;padding:16px;">

<div class="mr-card">
    <h3>1. Modelos emocionales</h3>
    <form class="mr-form" method="post">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="addmodel">
        <div>
            <label>Descripcion del modelo</label>
            <input type="text" name="description" placeholder="Ej: Modelo de Norman" required>
        </div>
        <button type="submit">Agregar</button>
    </form>
    <?php if (empty($models)): ?>
        <div class="mr-empty">Aun no hay modelos emocionales cargados.</div>
    <?php else: ?>
        <table class="mr-table"><thead><tr><th>ID</th><th>Descripcion</th></tr></thead><tbody>
        <?php foreach ($models as $m): ?>
            <tr><td><?php echo $m->id; ?></td><td><?php echo s($m->description); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>

<div class="mr-card">
    <h3>2. Niveles (Norman_Level)</h3>
    <?php if (empty($models)): ?>
        <div class="mr-empty">Primero crea al menos un modelo emocional arriba.</div>
    <?php else: ?>
    <form class="mr-form" method="post">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="addlevel">
        <div>
            <label>Modelo</label>
            <select name="modelid" required>
                <?php foreach ($models as $m): ?>
                <option value="<?php echo $m->id; ?>"><?php echo s($m->description); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Codigo</label>
            <input type="text" name="code" placeholder="Ej: Visceral" required>
        </div>
        <div>
            <label>Descripcion (opcional)</label>
            <input type="text" name="description" placeholder="Detalle del nivel">
        </div>
        <button type="submit">Agregar</button>
    </form>
    <?php endif; ?>
    <?php if (empty($levels)): ?>
        <div class="mr-empty">Aun no hay niveles cargados.</div>
    <?php else: ?>
        <table class="mr-table"><thead><tr><th>ID</th><th>Modelo</th><th>Codigo</th><th>Descripcion</th></tr></thead><tbody>
        <?php foreach ($levels as $lv):
            $modelname = $models[$lv->modelid]->description ?? '—'; ?>
            <tr>
                <td><?php echo $lv->id; ?></td>
                <td><?php echo s($modelname); ?></td>
                <td><?php echo s($lv->code); ?></td>
                <td><?php echo s($lv->description ?? '—'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>

<div class="mr-card">
    <h3>3. Reglas de clasificacion</h3>
    <p style="font-size:0.8em;color:#6c757d;margin-top:-4px;">
        En este modelo, Emocion y Dimension de UI son obligatorias en cada regla.
    </p>
    <?php if (empty($levels) || empty($emotions)): ?>
        <div class="mr-empty">Primero crea al menos un nivel arriba (y confirma que el catalogo de emociones tenga datos).</div>
    <?php else: ?>
    <form class="mr-form" method="post">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="addrule">
        <div>
            <label>Nivel</label>
            <select name="modellevelid" required>
                <?php foreach ($levels as $lv): ?>
                <option value="<?php echo $lv->id; ?>"><?php echo s($lv->code); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Emocion</label>
            <select name="emotionid" required>
                <?php foreach ($emotions as $e): ?>
                <option value="<?php echo $e->id; ?>"><?php echo s($e->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Dimension de UI</label>
            <input type="text" name="ui_dimension" placeholder="Ej: COLOR, TYPOGRAPHY, LAYOUT" required>
        </div>
        <div>
            <label>Regla (rule_statement)</label>
            <input type="text" name="rule" placeholder="Enunciado de la regla" required>
        </div>
        <button type="submit">Agregar</button>
    </form>
    <?php endif; ?>
    <?php if (empty($rules)): ?>
        <div class="mr-empty">Aun no hay reglas cargadas.</div>
    <?php else: ?>
        <table class="mr-table">
            <thead><tr><th>Regla</th><th>Modelo</th><th>Nivel</th><th>Emocion</th><th>UI</th><th></th></tr></thead>
            <tbody>
        <?php foreach ($rules as $r): ?>
            <tr>
                <td><?php echo s($r->rule_statement); ?></td>
                <td><?php echo s($r->modelname); ?></td>
                <td><?php echo s($r->levelcode); ?></td>
                <td><?php echo s($r->emotionname); ?></td>
                <td><?php echo s($r->ui_dimension); ?></td>
                <td>
                    <a class="mr-del"
                       href="?action=deleterule&ruleid=<?php echo $r->id; ?>&sesskey=<?php echo sesskey(); ?>"
                       onclick="return confirm('Eliminar esta regla?');">
                        Eliminar
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</div>
<?php
echo $OUTPUT->footer();

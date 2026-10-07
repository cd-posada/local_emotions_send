<?php
/**
 * local/emotions_send/report.php
 *
 * Actualizado para v5: local_emotions_log ya no tiene userid/courseid/
 * cmid directos, cuelga de local_emotions_session (que si tiene userid
 * + courseid LOCAL). El $courseid de la URL sigue siendo el courseid de
 * MOODLE (como siempre), asi que primero se resuelve a que curso local
 * corresponde antes de filtrar. El "contenido" que se veia antes via
 * course_modules ahora se lee directo de local_emotions_actcontext
 * (module/activitytype), que ya guarda una etiqueta legible.
 *
 * Seguridad igual que siempre: capability por curso Moodle, sesskey en
 * la accion de borrar, SQL parametrizado.
 */
require_once(__DIR__ . '/../../config.php');
require_login();

use local_emotions_send\local\data_helper;

global $DB, $OUTPUT, $USER;

$courseid = required_param('courseid', PARAM_INT);

if ($courseid <= 1) {
    throw new moodle_exception('invalidcourseid', 'error');
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($courseid);

require_login($course);
require_capability('local/emotions_send:viewreport', $coursecontext);

$can_manage = has_capability('local/emotions_send:managelogs', $coursecontext);

$PAGE->set_context($coursecontext);
$PAGE->set_url('/local/emotions_send/report.php', ['courseid' => $courseid]);
$PAGE->set_title('Reporte de Emociones');
$PAGE->set_heading('Reporte de Emociones de Aprendizaje');
$PAGE->set_pagelayout('report');

// La sesion NO se cierra antes de tiempo (antes habia un write_close()
// aqui): esta pagina despues escribe en $SESSION al renderizar la
// navegacion ($OUTPUT->header) y al redirigir con mensaje tras borrar, y
// Moodle avisa "mutated the session after it was closed".

// Todo el acceso a datos pasa por data_helper (tablas de Moodle o base
// externa user_model_v5_1, segun el ajuste 'storage'). Como la base
// externa no puede unirse con {user} en SQL, los nombres de los usuarios
// se completan aparte desde Moodle.
$localcourse = data_helper::get_course_by_moodle_id($courseid);

$filter_userid = optional_param('userid', 0, PARAM_INT);
$filter_days   = optional_param('days',   7, PARAM_INT);
$do_clear      = optional_param('clear',  0, PARAM_BOOL);

if ($do_clear && $localcourse) {
    require_sesskey();
    require_capability('local/emotions_send:managelogs', $coursecontext);
    data_helper::delete_course_records((int)$localcourse->id);
    redirect(
        new moodle_url('/local/emotions_send/report.php', ['courseid' => $courseid]),
        'Registros eliminados.',
        2
    );
}

$records = [];
if ($localcourse) {
    $since   = time() - ($filter_days * 86400);
    $records = data_helper::get_course_records((int)$localcourse->id, $since, $filter_userid);
    $userids = array_unique(array_filter(array_map(function ($r) {
        return (int)$r->userid;
    }, $records)));
    $users = $userids ? $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname, email') : [];
    foreach ($records as $i => $r) {
        if (!isset($users[$r->userid])) {
            unset($records[$i]); // sin usuario de Moodle (creado fuera de Moodle en la base externa)
            continue;
        }
        $r->firstname = $users[$r->userid]->firstname;
        $r->lastname  = $users[$r->userid]->lastname;
        $r->email     = $users[$r->userid]->email;
    }
}

$catalog  = data_helper::get_emotions();
$emo_keys = array_values(array_map(function ($e) {
    return $e->name;
}, $catalog));
if (empty($emo_keys)) {
    $emo_keys = ['Aburrido', 'Concentrado', 'Confundido', 'Frustrado'];
}

$by_user   = [];
$all_emots = array_fill_keys($emo_keys, 0);

foreach ($records as $r) {
    $emotion_label = $r->emotion ?? 'Sin clasificar';
    if (!isset($all_emots[$emotion_label])) {
        $all_emots[$emotion_label] = 0;
        $emo_keys[] = $emotion_label;
    }

    $uid = $r->userid;
    if (!isset($by_user[$uid])) {
        $by_user[$uid] = [
            'info'       => (object)[
                'firstname' => $r->firstname,
                'lastname'  => $r->lastname,
                'email'     => $r->email,
            ],
            'counts'     => array_fill_keys($emo_keys, 0),
            'detections' => [],
            'total'      => 0,
        ];
    }
    if (!isset($by_user[$uid]['counts'][$emotion_label])) {
        $by_user[$uid]['counts'][$emotion_label] = 0;
    }

    $by_user[$uid]['total']++;
    $by_user[$uid]['counts'][$emotion_label]++;
    $all_emots[$emotion_label]++;

    $cm_label = $r->cm_label ? ($r->cm_label . ($r->activitytype ? " ({$r->activitytype})" : '')) : 'Navegacion general del curso';

    $by_user[$uid]['detections'][] = [
        'ts'         => $r->timecreated,
        'emotion'    => $emotion_label,
        'confidence' => $r->confidence,
        'content'    => $cm_label,
    ];
}

$all_users = [];
if ($localcourse) {
    $courseuserids = data_helper::get_course_userids((int)$localcourse->id);
    $all_users = $courseuserids
        ? $DB->get_records_list('user', 'id', $courseuserids, 'lastname, firstname', 'id AS userid, firstname, lastname')
        : [];
}

$emo_colors_base = [
    'Aburrido'    => '#4e79a7',
    'Concentrado' => '#59a14f',
    'Confundido'  => '#f28e2b',
    'Frustrado'   => '#e15759',
];
$fallback_palette = ['#9c6ade', '#17a2b8', '#6c757d', '#d63384', '#20c997', '#fd7e14'];
$emo_colors = $emo_colors_base;
$fi = 0;
foreach (array_keys($all_emots) as $emo) {
    if (!isset($emo_colors[$emo])) {
        $emo_colors[$emo] = $fallback_palette[$fi % count($fallback_palette)];
        $fi++;
    }
}

$back_url = new moodle_url('/course/view.php', ['id' => $courseid]);

echo $OUTPUT->header();
?>
<style>
.rpt-card   { background:#fff; border:1px solid #e0e0e0; border-radius:10px;
              padding:16px 20px; margin-bottom:16px;
              box-shadow:0 1px 5px rgba(0,0,0,.05); }
.bar-wrap   { background:#f1f3f5; border-radius:4px; height:11px; flex:1; overflow:hidden; }
.bar-fill   { height:100%; border-radius:4px; transition:width .35s ease; }
.stat-pill  { background:#f8f9fa; border-radius:8px; padding:10px 14px;
              text-align:center; flex:1; min-width:95px; }
.stat-pill b { display:block; font-size:1.35em; color:#0d6efd; }
.stat-sm    { display:block; color:#6c757d; font-size:0.76em; margin-top:2px; }
.emo-badge  { display:inline-block; padding:2px 7px; border-radius:4px;
              color:#fff; font-size:0.75em; margin:2px; white-space:nowrap; }
.det-table  { width:100%; border-collapse:collapse; font-size:0.80em; margin-top:8px; }
.det-table th { background:#f8f9fa; padding:6px 8px; text-align:left;
                border-bottom:2px solid #dee2e6; color:#495057; font-weight:600; }
.det-table td { padding:5px 8px; border-bottom:1px solid #f1f3f5; color:#333; }
.det-table tr:last-child td { border-bottom:none; }
.rpt-avatar { width:40px; height:40px; border-radius:50%; background:#dee2e6;
              display:flex; align-items:center; justify-content:center;
              font-weight:700; font-size:0.95em; color:#495057; flex-shrink:0; }
</style>

<div style="max-width:1100px;margin:0 auto;padding:20px 16px;">

    <div style="display:flex;justify-content:space-between;align-items:flex-start;
                margin-bottom:18px;flex-wrap:wrap;gap:10px;">
        <div>
            <a href="<?php echo $back_url->out(false); ?>"
               style="font-size:0.83em;color:#6c757d;text-decoration:none;
                      display:inline-block;margin-bottom:6px;">
                &larr; Volver al curso
            </a>
            <h2 style="margin:0 0 3px;font-size:1.3em;">Reporte de Emociones</h2>
            <p style="color:#6c757d;margin:0;font-size:0.87em;">
                <?php echo s($course->fullname); ?>
                &nbsp;&middot;&nbsp; Ultimos <?php echo (int)$filter_days; ?> dias
            </p>
        </div>
        <?php if ($can_manage && $localcourse): ?>
        <a href="?courseid=<?php echo $courseid; ?>&clear=1&sesskey=<?php echo sesskey(); ?>"
           onclick="return confirm('Eliminar todos los registros de este curso. Esta accion no se puede deshacer.');"
           style="text-decoration:none;background:#dc3545;color:#fff;
                  padding:6px 14px;border-radius:6px;font-size:0.83em;
                  display:inline-block;align-self:flex-start;">
            Eliminar registros
        </a>
        <?php endif; ?>
    </div>

    <?php if (!$localcourse): ?>
    <div style="background:#e8f4fd;border-left:4px solid #0d6efd;
                padding:12px 16px;border-radius:6px;color:#31708f;">
        Este curso Moodle todavia no tiene ninguna deteccion registrada
        (aun no existe su curso local equivalente). En cuanto un
        estudiante genere la primera deteccion aqui, aparecera.
    </div>
    <?php else: ?>

    <div style="background:#fff;border:1px solid #dee2e6;border-radius:8px;
                padding:12px 16px;margin-bottom:20px;">
        <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
            <div>
                <label style="font-size:0.83em;font-weight:600;display:block;margin-bottom:2px;">
                    Estudiante
                </label>
                <select name="userid"
                    style="border:1px solid #ced4da;border-radius:5px;padding:4px 8px;font-size:0.83em;">
                    <option value="0">Todos</option>
                    <?php foreach ($all_users as $u): ?>
                    <option value="<?php echo (int)$u->userid; ?>"
                        <?php echo ($filter_userid == $u->userid) ? 'selected' : ''; ?>>
                        <?php echo s($u->lastname . ', ' . $u->firstname); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:0.83em;font-weight:600;display:block;margin-bottom:2px;">
                    Periodo
                </label>
                <select name="days"
                    style="border:1px solid #ced4da;border-radius:5px;padding:4px 8px;font-size:0.83em;">
                    <?php foreach ([1=>'Hoy',3=>'3 dias',7=>'7 dias',
                                    14=>'14 dias',30=>'30 dias'] as $d=>$lbl): ?>
                    <option value="<?php echo $d; ?>"
                        <?php echo ($filter_days==$d)?'selected':''; ?>>
                        <?php echo $lbl; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit"
                    style="background:#0d6efd;color:#fff;border:none;border-radius:6px;
                           padding:6px 16px;cursor:pointer;font-size:0.83em;">
                    Aplicar
                </button>
            </div>
        </form>
    </div>

    <?php if (empty($records)): ?>
    <div style="background:#e8f4fd;border-left:4px solid #0d6efd;
                padding:12px 16px;border-radius:6px;color:#31708f;">
        No se encontraron registros para el periodo seleccionado.
    </div>
    <?php else:
        $total_det  = count($records);
        $total_stud = count($by_user);
        arsort($all_emots);
    ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
        <div class="stat-pill">
            <b><?php echo $total_det; ?></b>
            <span class="stat-sm">Detecciones</span>
        </div>
        <div class="stat-pill">
            <b><?php echo $total_stud; ?></b>
            <span class="stat-sm">Estudiantes</span>
        </div>
        <?php foreach ($all_emots as $emo => $cnt):
            $pct = $total_det > 0 ? round($cnt/$total_det*100) : 0; ?>
        <div class="stat-pill">
            <b style="color:<?php echo $emo_colors[$emo]; ?>;"><?php echo $cnt; ?></b>
            <span class="stat-sm"><?php echo s($emo); ?> (<?php echo $pct; ?>%)</span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="rpt-card" style="margin-bottom:20px;">
        <h3 style="margin:0 0 14px;font-size:0.93em;font-weight:700;color:#333;">
            Distribucion global de emociones
        </h3>
        <div style="display:flex;gap:22px;align-items:center;flex-wrap:wrap;">
            <div style="width:170px;height:170px;flex-shrink:0;">
                <canvas id="globalPie"></canvas>
            </div>
            <div style="flex:1;min-width:180px;">
                <?php foreach ($all_emots as $emo => $cnt):
                    $pct = $total_det > 0 ? round($cnt/$total_det*100) : 0; ?>
                <div style="margin-bottom:9px;">
                    <div style="display:flex;justify-content:space-between;
                                font-size:0.82em;margin-bottom:2px;">
                        <span><?php echo s($emo); ?></span>
                        <span style="font-weight:600;">
                            <?php echo $cnt; ?> &mdash; <?php echo $pct; ?>%
                        </span>
                    </div>
                    <div class="bar-wrap">
                        <div class="bar-fill"
                             style="width:<?php echo $pct; ?>%;
                                    background:<?php echo $emo_colors[$emo]; ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <h3 style="font-size:0.93em;font-weight:700;color:#333;margin:0 0 12px;">
        Detalle por estudiante
    </h3>

    <?php foreach ($by_user as $uid => $udata):
        $info     = $udata['info'];
        $counts   = $udata['counts'];
        $total_u  = $udata['total'];
        $initials = strtoupper(
            mb_substr($info->firstname, 0, 1) . mb_substr($info->lastname, 0, 1)
        );
        $sorted_c = $counts;
        arsort($sorted_c);
        $dom_u    = array_key_first($sorted_c);
        $chart_id = 'pie_' . $uid;
    ?>
    <div class="rpt-card">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
            <div class="rpt-avatar"><?php echo s($initials); ?></div>
            <div style="flex:1;">
                <div style="font-weight:700;font-size:0.98em;color:#212529;">
                    <?php echo s($info->lastname . ', ' . $info->firstname); ?>
                </div>
                <div style="color:#6c757d;font-size:0.78em;">
                    <?php echo s($info->email); ?>
                </div>
            </div>
            <div style="text-align:right;">
                <span style="background:<?php echo $emo_colors[$dom_u] ?? '#aaa'; ?>;
                             color:#fff;border-radius:20px;padding:3px 11px;
                             font-size:0.78em;font-weight:600;">
                    <?php echo s($dom_u); ?>
                </span>
                <div style="color:#6c757d;font-size:0.76em;margin-top:3px;">
                    <?php echo $total_u; ?> detecciones
                </div>
            </div>
        </div>

        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;margin-bottom:14px;">
            <div style="flex:2;min-width:180px;">
                <?php foreach ($counts as $emo => $cnt):
                    $pct = $total_u > 0 ? round($cnt/$total_u*100) : 0; ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;
                                font-size:0.79em;margin-bottom:2px;">
                        <span><?php echo s($emo); ?></span>
                        <span style="font-weight:600;"><?php echo $pct; ?>%</span>
                    </div>
                    <div class="bar-wrap">
                        <div class="bar-fill"
                             style="width:<?php echo $pct; ?>%;
                                    background:<?php echo $emo_colors[$emo]; ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="flex:0 0 120px;text-align:center;">
                <canvas id="<?php echo $chart_id; ?>"
                        style="max-width:110px;max-height:110px;"></canvas>
            </div>
        </div>

        <div style="font-size:0.78em;font-weight:600;color:#6c757d;margin-bottom:5px;">
            Detalle de detecciones en este curso
        </div>
        <div style="overflow-x:auto;">
            <table class="det-table">
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Contenido</th>
                        <th>Emocion</th>
                        <th>Confianza</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $shown = 0;
                foreach ($udata['detections'] as $det) {
                    if ($shown >= 20) { break; }
                    $col = $emo_colors[$det['emotion']] ?? '#aaa';
                    echo '<tr>';
                    echo '<td>' . userdate($det['ts'], '%d/%m/%Y %H:%M:%S') . '</td>';
                    echo '<td>' . s($det['content']) . '</td>';
                    echo '<td><span class="emo-badge" style="background:' . $col . ';">'
                       . s($det['emotion']) . '</span></td>';
                    echo '<td>' . round($det['confidence'] * 100) . '%</td>';
                    echo '</tr>';
                    $shown++;
                }
                if (count($udata['detections']) > 20) {
                    echo '<tr><td colspan="4" style="color:#6c757d;font-style:italic;">'
                       . 'Mostrando 20 de ' . count($udata['detections'])
                       . ' detecciones.</td></tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function(){
        var c = document.getElementById('<?php echo $chart_id; ?>');
        if (!c || typeof Chart === 'undefined') { return; }
        new Chart(c, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($counts)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($counts)); ?>,
                    backgroundColor: <?php echo json_encode(array_values(
                        array_intersect_key($emo_colors, $counts)
                    )); ?>,
                    borderWidth: 2, borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(ctx) {
                        var t = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                        var p = t > 0 ? Math.round(ctx.raw/t*100) : 0;
                        return ' ' + ctx.label + ': ' + ctx.raw + ' (' + p + '%)';
                    }}}
                }
            }
        });
    }());
    </script>

    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    (function(){
        var c = document.getElementById('globalPie');
        if (!c) { return; }
        new Chart(c, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($all_emots)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($all_emots)); ?>,
                    backgroundColor: <?php echo json_encode(array_values(
                        array_intersect_key($emo_colors, $all_emots)
                    )); ?>,
                    borderWidth: 2, borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(ctx) {
                        var t = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                        var p = t > 0 ? Math.round(ctx.raw/t*100) : 0;
                        return ' ' + ctx.label + ': ' + ctx.raw + ' (' + p + '%)';
                    }}}
                }
            }
        });
    }());
    </script>

    <?php endif; ?>
    <?php endif; ?>

    <div style="margin-top:20px;">
        <a href="<?php echo $back_url->out(false); ?>"
           style="display:inline-block;background:#6c757d;color:#fff;
                  text-decoration:none;padding:7px 18px;border-radius:6px;font-size:0.85em;">
            &larr; Volver al curso
        </a>
    </div>

</div>

<?php echo $OUTPUT->footer(); ?>

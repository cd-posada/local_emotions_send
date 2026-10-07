<?php
/**
 * local/emotions_send/db/upgrade.php
 *
 * Historial:
 *  2026090100 - Primer modelo relacional.
 *  2026090200 - Modelos emocionales + niveles + reglas.
 *  2026090400 - Modelo v5 completo (Learning_Session, Emotion_Record
 *               ligado a sesion, Reference_Observation, Adaptation_Event/
 *               Change/Feedback). El bloque 2026090300 que existio entre
 *               medio (intentaba ALTERar tablas campo por campo) se
 *               retiro: fallaba con ddl_dependency_exception al borrar
 *               'modelid' de local_emotions_rule porque quedaba un
 *               indice dependiendo de esa columna. Como el modelo del
 *               asesor sigue cambiando de forma y esto corre en un
 *               servidor de PRUEBAS sin datos reales que preservar, de
 *               aqui en adelante los cambios de FORMA se resuelven
 *               BORRANDO y RECREANDO las tablas del plugin en vez de
 *               intentar transformarlas in-place: es mucho menos fragil
 *               mientras el modelo siga en movimiento.
 *  2026092600 - Carga idempotente de catalogos (catalog_seeder), que antes
 *               solo existia aqui y faltaba en instalaciones nuevas.
 *  2026092700 - Tabla local_emotions_extmap para el almacenamiento externo
 *               user_model_v5_1 (ajuste 'storage' = 'usermodel').
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_local_emotions_send_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026090100) {

        $table = new xmldb_table('local_emotions_emotion');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_name', XMLDB_INDEX_UNIQUE, ['name']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $defaultemotions = ['Concentrado', 'Aburrido', 'Confundido', 'Frustrado'];
        foreach ($defaultemotions as $name) {
            if (!$DB->record_exists('local_emotions_emotion', ['name' => $name])) {
                $DB->insert_record('local_emotions_emotion', (object)['name' => $name, 'description' => '']);
            }
        }

        $table = new xmldb_table('local_emotions_language');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_name', XMLDB_INDEX_UNIQUE, ['name']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $defaultlangs = ['Espanol', 'Ingles', 'Frances', 'Portugues'];
        foreach ($defaultlangs as $name) {
            if (!$DB->record_exists('local_emotions_language', ['name' => $name])) {
                $DB->insert_record('local_emotions_language', (object)['name' => $name]);
            }
        }

        $table = new xmldb_table('local_emotions_userlang');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('languageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('langtype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'other');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('fk_languageid', XMLDB_KEY_FOREIGN, ['languageid'], 'local_emotions_language', ['id']);
        $table->add_index('idx_useridlang', XMLDB_INDEX_UNIQUE, ['userid', 'languageid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_emotions_level');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('value', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $defaultlevels = ['Pregrado', 'Posgrado'];
        foreach ($defaultlevels as $value) {
            if (!$DB->record_exists('local_emotions_level', ['value' => $value])) {
                $DB->insert_record('local_emotions_level', (object)['value' => $value]);
            }
        }

        $table = new xmldb_table('local_emotions_program');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('levelid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_levelid', XMLDB_KEY_FOREIGN, ['levelid'], 'local_emotions_level', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_emotions_progcourse');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('programid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_programid', XMLDB_KEY_FOREIGN, ['programid'], 'local_emotions_program', ['id']);
        $table->add_key('fk_courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('idx_progcourse', XMLDB_INDEX_UNIQUE, ['programid', 'courseid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_emotions_context');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('value', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $defaultcontexts = ['Presencial', 'Virtual', 'Laboratorio'];
        foreach ($defaultcontexts as $value) {
            if (!$DB->record_exists('local_emotions_context', ['value' => $value])) {
                $DB->insert_record('local_emotions_context', (object)['value' => $value]);
            }
        }

        $table = new xmldb_table('local_emotions_usercontext');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('fk_contextid', XMLDB_KEY_FOREIGN, ['contextid'], 'local_emotions_context', ['id']);
        $table->add_index('idx_userctx', XMLDB_INDEX_UNIQUE, ['userid', 'contextid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_emotions_userprofile');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('gender', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('birthdate', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('programid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);
        $table->add_key('fk_programid', XMLDB_KEY_FOREIGN, ['programid'], 'local_emotions_program', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $logtable = new xmldb_table('local_emotions_log');
        $field = new xmldb_field('emotionid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'cmid');
        if (!$dbman->field_exists($logtable, $field)) {
            $dbman->add_field($logtable, $field);
        }
        $field = new xmldb_field('contextid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'emotionid');
        if (!$dbman->field_exists($logtable, $field)) {
            $dbman->add_field($logtable, $field);
        }
        $oldfield = new xmldb_field('emotion');
        if ($dbman->field_exists($logtable, $oldfield)) {
            $rs = $DB->get_recordset_select('local_emotions_log', "emotionid = 0 OR emotionid IS NULL");
            foreach ($rs as $log) {
                if (empty($log->emotion)) {
                    continue;
                }
                $cat = $DB->get_record('local_emotions_emotion', ['name' => $log->emotion]);
                if (!$cat) {
                    $catid = $DB->insert_record('local_emotions_emotion',
                        (object)['name' => $log->emotion, 'description' => '']);
                } else {
                    $catid = $cat->id;
                }
                $DB->set_field('local_emotions_log', 'emotionid', $catid, ['id' => $log->id]);
            }
            $rs->close();
            $dbman->drop_field($logtable, $oldfield);
        }
        $index = new xmldb_index('idx_emotionid', XMLDB_INDEX_NOTUNIQUE, ['emotionid']);
        if (!$dbman->index_exists($logtable, $index)) {
            $dbman->add_index($logtable, $index);
        }

        upgrade_plugin_savepoint(true, 2026090100, 'local', 'emotions_send');
    }

    if ($oldversion < 2026090200) {

        $table = new xmldb_table('local_emotions_model');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('description', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_emotions_modellevel');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('description', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_modelid', XMLDB_KEY_FOREIGN, ['modelid'], 'local_emotions_model', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_emotions_rule');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('rule', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('modellevelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_modelid', XMLDB_KEY_FOREIGN, ['modelid'], 'local_emotions_model', ['id']);
        $table->add_key('fk_modellevelid', XMLDB_KEY_FOREIGN, ['modellevelid'], 'local_emotions_modellevel', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $logtable = new xmldb_table('local_emotions_log');
        $field = new xmldb_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'contextid');
        if (!$dbman->field_exists($logtable, $field)) {
            $dbman->add_field($logtable, $field);
        }

        upgrade_plugin_savepoint(true, 2026090200, 'local', 'emotions_send');
    }

    if ($oldversion < 2026090400) {

        // Limpieza total del modelo intermedio (v2/v3, incluyendo cualquier
        // resto de un intento fallido de actualizar a 2026090300). Se
        // borra en orden hijo -> padre para respetar las FK. No hay perdida
        // real: este es un servidor de pruebas y el objetivo explicito es
        // reconstruir con la forma v5.
        $droporder = [
            'local_emotions_adaptchange',
            'local_emotions_adaptfb',
            'local_emotions_adaptlog',
            'local_emotions_refobs',
            'local_emotions_log',
            'local_emotions_rule',
            'local_emotions_infmodel',
            'local_emotions_modellevel',
            'local_emotions_model',
            'local_emotions_session',
            'local_emotions_userprofile',
            'local_emotions_userlang',
            'local_emotions_usercontext',
            'local_emotions_context',
            'local_emotions_actcontext',
            'local_emotions_course',
            'local_emotions_progcourse',
            'local_emotions_program',
            'local_emotions_level',
            'local_emotions_language',
            'local_emotions_emotion',
        ];
        foreach ($droporder as $tablename) {
            $t = new xmldb_table($tablename);
            if ($dbman->table_exists($t)) {
                $dbman->drop_table($t);
            }
        }

        // Recrear todo desde cero con la forma v5 (mismas definiciones que
        // db/install.xml; si install.xml cambia, este bloque debe reflejar
        // el mismo esquema).
        $table = new xmldb_table('local_emotions_emotion');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('code', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
        $table->add_field('taxonomy_source', XMLDB_TYPE_CHAR, '60', null, null, null, null);
        $table->add_field('valence', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_field('arousal', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_code', XMLDB_INDEX_UNIQUE, ['code']);
        $dbman->create_table($table);

        $defaultemotions = [
            ['code' => 'BOREDOM',     'name' => 'Aburrido'],
            ['code' => 'ENGAGEMENT',  'name' => 'Concentrado'],
            ['code' => 'CONFUSION',   'name' => 'Confundido'],
            ['code' => 'FRUSTRATION', 'name' => 'Frustrado'],
        ];
        foreach ($defaultemotions as $e) {
            $DB->insert_record('local_emotions_emotion', (object)$e);
        }

        $table = new xmldb_table('local_emotions_language');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('code_iso_639_1', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_code', XMLDB_INDEX_UNIQUE, ['code_iso_639_1']);
        $dbman->create_table($table);

        $defaultlangs = [
            ['code_iso_639_1' => 'es', 'name' => 'Espanol'],
            ['code_iso_639_1' => 'en', 'name' => 'Ingles'],
            ['code_iso_639_1' => 'fr', 'name' => 'Frances'],
            ['code_iso_639_1' => 'pt', 'name' => 'Portugues'],
        ];
        foreach ($defaultlangs as $l) {
            $DB->insert_record('local_emotions_language', (object)$l);
        }

        $table = new xmldb_table('local_emotions_level');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('value', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);

        $defaultlevels = ['Pregrado', 'Posgrado'];
        foreach ($defaultlevels as $value) {
            $DB->insert_record('local_emotions_level', (object)['value' => $value]);
        }

        $table = new xmldb_table('local_emotions_program');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '160', null, XMLDB_NOTNULL, null, null);
        $table->add_field('levelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('faculty', XMLDB_TYPE_CHAR, '160', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_levelid', XMLDB_KEY_FOREIGN, ['levelid'], 'local_emotions_level', ['id']);
        $dbman->create_table($table);

        $fallbacklevelid = $DB->get_field('local_emotions_level', 'id', ['value' => 'Pregrado']);
        $DB->insert_record('local_emotions_program', (object)[
            'name' => 'Sin programa asignado', 'levelid' => $fallbacklevelid, 'faculty' => null,
        ]);

        $table = new xmldb_table('local_emotions_course');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('programid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('moodlecourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '180', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_programid', XMLDB_KEY_FOREIGN, ['programid'], 'local_emotions_program', ['id']);
        $table->add_key('fk_moodlecourseid', XMLDB_KEY_FOREIGN, ['moodlecourseid'], 'course', ['id']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_actcontext');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('module', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('activitytype', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, null);
        $table->add_field('isassessed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'local_emotions_course', ['id']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_context');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, null);
        $table->add_field('value_context', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_userlang');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('languageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('is_native', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('is_interface_preferred', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['userid', 'languageid']);
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('fk_languageid', XMLDB_KEY_FOREIGN, ['languageid'], 'local_emotions_language', ['id']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_userprofile');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('gender', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('birthdate', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('programid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);
        $table->add_key('fk_programid', XMLDB_KEY_FOREIGN, ['programid'], 'local_emotions_program', ['id']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_session');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('startedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('endedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('closurereason', XMLDB_TYPE_CHAR, '80', null, null, null, null);
        $table->add_field('devicetype', XMLDB_TYPE_CHAR, '60', null, null, null, null);
        $table->add_field('viewport', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('fercaptureenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('fk_courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'local_emotions_course', ['id']);
        $table->add_index('idx_userid_started', XMLDB_INDEX_NOTUNIQUE, ['userid', 'startedat']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_model');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);

        $modelid = $DB->insert_record('local_emotions_model', (object)[
            'description' => 'Modelo de Norman (Visceral / Conductual / Reflexivo)',
        ]);

        $table = new xmldb_table('local_emotions_modellevel');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('modelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('code', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_modelid', XMLDB_KEY_FOREIGN, ['modelid'], 'local_emotions_model', ['id']);
        $dbman->create_table($table);

        foreach (['Visceral', 'Behavioral', 'Reflective'] as $code) {
            $DB->insert_record('local_emotions_modellevel', (object)[
                'modelid' => $modelid, 'code' => $code, 'description' => null,
            ]);
        }

        $table = new xmldb_table('local_emotions_infmodel');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '120', null, XMLDB_NOTNULL, null, null);
        $table->add_field('version', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('architecture', XMLDB_TYPE_CHAR, '120', null, null, null, null);
        $table->add_field('trainingdataset', XMLDB_TYPE_CHAR, '120', null, null, null, null);
        $table->add_field('macrof1', XMLDB_TYPE_NUMBER, '6, 4', null, null, null, null);
        $table->add_field('calibrationmethod', XMLDB_TYPE_CHAR, '80', null, null, null, null);
        $table->add_field('perclassf1', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('calibrationerror', XMLDB_TYPE_NUMBER, '6, 4', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);

        $definfmodelid = $DB->insert_record('local_emotions_infmodel', (object)[
            'name' => 'Sin especificar', 'version' => '0',
        ]);

        $table = new xmldb_table('local_emotions_rule');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('modellevelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('emotionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ui_dimension', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, null);
        $table->add_field('rule_statement', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_modellevelid', XMLDB_KEY_FOREIGN, ['modellevelid'], 'local_emotions_modellevel', ['id']);
        $table->add_key('fk_emotionid', XMLDB_KEY_FOREIGN, ['emotionid'], 'local_emotions_emotion', ['id']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('emotionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('actcontextid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('infmodelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('intensitydaisee', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_field('confidence', XMLDB_TYPE_NUMBER, '6, 4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sourcemodel', XMLDB_TYPE_CHAR, '120', null, null, null, null);
        $table->add_field('classprobabilities', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('entropy', XMLDB_TYPE_NUMBER, '6, 4', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_sessionid', XMLDB_KEY_FOREIGN, ['sessionid'], 'local_emotions_session', ['id']);
        $table->add_key('fk_emotionid', XMLDB_KEY_FOREIGN, ['emotionid'], 'local_emotions_emotion', ['id']);
        $table->add_key('fk_actcontextid', XMLDB_KEY_FOREIGN, ['actcontextid'], 'local_emotions_actcontext', ['id']);
        $table->add_key('fk_infmodelid', XMLDB_KEY_FOREIGN, ['infmodelid'], 'local_emotions_infmodel', ['id']);
        $table->add_index('idx_session_date', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'timecreated']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_refobs');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('emotionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('observedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('method', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, null);
        $table->add_field('observer', XMLDB_TYPE_CHAR, '120', null, null, null, null);
        $table->add_field('intensity', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_sessionid', XMLDB_KEY_FOREIGN, ['sessionid'], 'local_emotions_session', ['id']);
        $table->add_key('fk_emotionid', XMLDB_KEY_FOREIGN, ['emotionid'], 'local_emotions_emotion', ['id']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_adaptlog');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('emorecordid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tokensbefore', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('tokensafter', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('experimentalcond', XMLDB_TYPE_CHAR, '60', null, null, null, null);
        $table->add_field('decisionstate', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, null);
        $table->add_field('gatereason', XMLDB_TYPE_CHAR, '180', null, null, null, null);
        $table->add_field('sessionmoment', XMLDB_TYPE_CHAR, '60', null, null, null, null);
        $table->add_field('magnitud', XMLDB_TYPE_NUMBER, '8, 4', null, null, null, null);
        $table->add_field('revertedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_emorecordid', XMLDB_KEY_FOREIGN, ['emorecordid'], 'local_emotions_log', ['id']);
        $table->add_key('fk_sessionid', XMLDB_KEY_FOREIGN, ['sessionid'], 'local_emotions_session', ['id']);
        $table->add_index('idx_decisionstate', XMLDB_INDEX_NOTUNIQUE, ['decisionstate']);
        $table->add_index('idx_timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_adaptfb');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('adaptlogid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('accepted', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
        $table->add_field('perceivedusefulness', XMLDB_TYPE_INTEGER, '4', null, null, null, null);
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_adaptlogid', XMLDB_KEY_FOREIGN_UNIQUE, ['adaptlogid'], 'local_emotions_adaptlog', ['id']);
        $dbman->create_table($table);

        $table = new xmldb_table('local_emotions_adaptchange');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('eventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ui_dimension', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targettoken', XMLDB_TYPE_CHAR, '120', null, XMLDB_NOTNULL, null, null);
        $table->add_field('valuebefore', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('valueafter', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_eventid', XMLDB_KEY_FOREIGN, ['eventid'], 'local_emotions_adaptlog', ['id']);
        $table->add_key('fk_ruleid', XMLDB_KEY_FOREIGN, ['ruleid'], 'local_emotions_rule', ['id']);
        $dbman->create_table($table);

        upgrade_plugin_savepoint(true, 2026090400, 'local', 'emotions_send');
    }

    if ($oldversion < 2026092600) {
        // Las instalaciones NUEVAS hechas con versiones anteriores quedaban
        // con los catalogos vacios (no habia db/install.php). seed() es
        // idempotente: completa lo que falte y corrige el codigo de las
        // emociones creadas al vuelo (ABURRIDO -> BOREDOM, etc.).
        \local_emotions_send\local\catalog_seeder::seed();

        upgrade_plugin_savepoint(true, 2026092600, 'local', 'emotions_send');
    }

    if ($oldversion < 2026092700) {
        // Almacenamiento externo user_model_v5_1: correspondencia de ids de
        // Moodle (usuario, curso) con los ids de esa base, que no los guarda.
        $table = new xmldb_table('local_emotions_extmap');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('entity', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('moodleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('extid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_entity_moodleid', XMLDB_INDEX_UNIQUE, ['entity', 'moodleid']);
        $table->add_index('idx_entity_extid', XMLDB_INDEX_NOTUNIQUE, ['entity', 'extid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092700, 'local', 'emotions_send');
    }

    if ($oldversion < 2026100600) {
        // Consentimiento informado para el registro facial (por usuario y curso).
        $table = new xmldb_table('local_emotions_consent');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('version', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('idx_user_course', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100600, 'local', 'emotions_send');
    }

    return true;
}

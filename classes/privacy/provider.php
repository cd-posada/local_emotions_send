<?php
/**
 * local/emotions_send/classes/privacy/provider.php
 *
 * Actualizado para v5: local_emotions_log (Emotion_Record) ya no tiene
 * userid/courseid directos, cuelga de local_emotions_session
 * (Learning_Session), que SI los tiene. Todo lo que antes filtraba el
 * log por l.userid/l.courseid ahora filtra por s.userid/s.courseid via
 * JOIN.
 */
namespace local_emotions_send\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;
use local_emotions_send\local\data_helper;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_emotions_session',
            [
                'userid'            => 'privacy:metadata:local_emotions_session:userid',
                'courseid'          => 'privacy:metadata:local_emotions_session:courseid',
                'startedat'         => 'privacy:metadata:local_emotions_session:startedat',
                'endedat'           => 'privacy:metadata:local_emotions_session:endedat',
                'closurereason'     => 'privacy:metadata:local_emotions_session:closurereason',
                'devicetype'        => 'privacy:metadata:local_emotions_session:devicetype',
                'viewport'          => 'privacy:metadata:local_emotions_session:viewport',
            ],
            'privacy:metadata:local_emotions_session'
        );

        $collection->add_database_table(
            'local_emotions_log',
            [
                'sessionid'          => 'privacy:metadata:local_emotions_log:sessionid',
                'emotionid'          => 'privacy:metadata:local_emotions_log:emotionid',
                'actcontextid'       => 'privacy:metadata:local_emotions_log:actcontextid',
                'infmodelid'         => 'privacy:metadata:local_emotions_log:infmodelid',
                'intensitydaisee'    => 'privacy:metadata:local_emotions_log:intensitydaisee',
                'confidence'         => 'privacy:metadata:local_emotions_log:confidence',
                'classprobabilities' => 'privacy:metadata:local_emotions_log:classprobabilities',
                'entropy'            => 'privacy:metadata:local_emotions_log:entropy',
                'timecreated'        => 'privacy:metadata:local_emotions_log:timecreated',
            ],
            'privacy:metadata:local_emotions_log'
        );

        $collection->add_database_table(
            'local_emotions_userprofile',
            [
                'gender'    => 'privacy:metadata:local_emotions_userprofile:gender',
                'birthdate' => 'privacy:metadata:local_emotions_userprofile:birthdate',
                'programid' => 'privacy:metadata:local_emotions_userprofile:programid',
            ],
            'privacy:metadata:local_emotions_userprofile'
        );

        $collection->add_database_table(
            'local_emotions_userlang',
            [
                'languageid'             => 'privacy:metadata:local_emotions_userlang:languageid',
                'is_native'              => 'privacy:metadata:local_emotions_userlang:is_native',
                'is_interface_preferred' => 'privacy:metadata:local_emotions_userlang:is_interface_preferred',
            ],
            'privacy:metadata:local_emotions_userlang'
        );

        $collection->add_database_table(
            'local_emotions_context',
            [
                'type'          => 'privacy:metadata:local_emotions_context:type',
                'value_context' => 'privacy:metadata:local_emotions_context:value_context',
            ],
            'privacy:metadata:local_emotions_context'
        );

        $collection->add_database_table(
            'local_emotions_adaptfb',
            [
                'accepted'            => 'privacy:metadata:local_emotions_adaptfb:accepted',
                'perceivedusefulness' => 'privacy:metadata:local_emotions_adaptfb:perceivedusefulness',
                'comment'             => 'privacy:metadata:local_emotions_adaptfb:comment',
            ],
            'privacy:metadata:local_emotions_adaptfb'
        );

        // La deteccion facial corre en el navegador (fer_engine.js): ningun
        // fotograma sale del equipo. Pero si el almacenamiento es 'usermodel',
        // los DATOS del plugin viven en la base externa user_model_v5_1.
        $collection->add_database_table(
            'local_emotions_extmap',
            [
                'moodleid' => 'privacy:metadata:local_emotions_extmap:moodleid',
                'extid'    => 'privacy:metadata:local_emotions_extmap:extid',
            ],
            'privacy:metadata:local_emotions_extmap'
        );
        $collection->add_database_table(
            'local_emotions_consent',
            [
                'status'       => 'privacy:metadata:local_emotions_consent:status',
                'version'      => 'privacy:metadata:local_emotions_consent:version',
                'timemodified' => 'privacy:metadata:local_emotions_consent:timemodified',
            ],
            'privacy:metadata:local_emotions_consent'
        );
        $collection->add_external_location_link(
            'user_model_v5_1',
            [
                'name'           => 'privacy:metadata:user_model_v5_1:name',
                'last_name'      => 'privacy:metadata:user_model_v5_1:last_name',
                'email'          => 'privacy:metadata:user_model_v5_1:email',
                'usuario'        => 'privacy:metadata:user_model_v5_1:usuario',
                'gender'         => 'privacy:metadata:user_model_v5_1:gender',
                'birth_date'     => 'privacy:metadata:user_model_v5_1:birth_date',
                'emotion_record' => 'privacy:metadata:user_model_v5_1:emotion_record',
                'registro_json'  => 'privacy:metadata:user_model_v5_1:registro_json',
                'latency'        => 'privacy:metadata:user_model_v5_1:latency',
            ],
            'privacy:metadata:user_model_v5_1'
        );

        return $collection;
    }

    // Todo el acceso a datos pasa por data_helper: funciona igual con las
    // tablas de Moodle o con la base externa user_model_v5_1.

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Cursos MOODLE donde el usuario tiene sesiones/detecciones.
        $courseids = data_helper::get_user_moodle_course_ids($userid);
        if ($courseids) {
            global $DB;
            [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $params['contextcourse'] = CONTEXT_COURSE;
            $contextlist->add_from_sql(
                "SELECT id FROM {context} WHERE contextlevel = :contextcourse AND instanceid {$insql}", $params);
        }

        if (data_helper::has_profile_data($userid)) {
            $contextlist->add_user_context($userid);
        }

        // Cursos donde decidio sobre el consentimiento informado.
        $contextlist->add_from_sql(
            "SELECT ctx.id FROM {context} ctx
               JOIN {local_emotions_consent} lc ON lc.courseid = ctx.instanceid AND ctx.contextlevel = :lvl
              WHERE lc.userid = :userid", ['lvl' => CONTEXT_COURSE, 'userid' => $userid]);

        return $contextlist;
    }

    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context->contextlevel == CONTEXT_COURSE) {
            $lc = data_helper::get_course_by_moodle_id((int)$context->instanceid);
            if ($lc) {
                $userlist->add_users(data_helper::get_course_userids((int)$lc->id));
            }
            $userlist->add_from_sql('userid', "SELECT userid FROM {local_emotions_consent} WHERE courseid = :cid",
                ['cid' => (int)$context->instanceid]);
        } else if ($context->contextlevel == CONTEXT_USER) {
            $userlist->add_user($context->instanceid);
        }
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {

            if ($context->contextlevel == CONTEXT_COURSE) {
                $lc = data_helper::get_course_by_moodle_id((int)$context->instanceid);
                $records = $lc ? data_helper::export_user_course_records($userid, (int)$lc->id) : [];

                if (!empty($records)) {
                    $data = array_map(function ($r) {
                        return (object)[
                            'emotion'     => $r->emotion,
                            'confidence'  => $r->confidence,
                            'intensity'   => $r->intensitydaisee,
                            'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
                        ];
                    }, $records);

                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_emotions_send')],
                        (object)['emotions' => $data]
                    );
                }

                $consent = \local_emotions_send\local\consent::get_record($userid, (int)$context->instanceid);
                if ($consent) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_emotions_send'), get_string('consent:title', 'local_emotions_send')],
                        (object)[
                            'status'       => $consent->status,
                            'version'      => $consent->version,
                            'timemodified' => \core_privacy\local\request\transform::datetime($consent->timemodified),
                        ]
                    );
                }
            } else if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $userid) {

                $profile = data_helper::get_user_profile($userid);
                $langs = data_helper::get_user_languages($userid);
                $contexts = array_map(function ($c) {
                    return (object)['type' => $c->type, 'value_context' => $c->value_context];
                }, array_values(data_helper::get_user_context_entries($userid)));

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_emotions_send'), get_string('profile:title', 'local_emotions_send')],
                    (object)[
                        'gender'    => $profile->gender ?? null,
                        'birthdate' => !empty($profile->birthdate)
                            ? \core_privacy\local\request\transform::date($profile->birthdate) : null,
                        'languages' => array_merge($langs['native'], $langs['other']),
                        'contexts'  => $contexts,
                    ]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel == CONTEXT_COURSE) {
            $lc = data_helper::get_course_by_moodle_id((int)$context->instanceid);
            if ($lc) {
                data_helper::delete_course_records((int)$lc->id, 0, true);
            }
            $DB->delete_records('local_emotions_consent', ['courseid' => (int)$context->instanceid]);
        } else if ($context->contextlevel == CONTEXT_USER) {
            data_helper::delete_user_profile_data((int)$context->instanceid);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                $lc = data_helper::get_course_by_moodle_id((int)$context->instanceid);
                if ($lc) {
                    data_helper::delete_course_records((int)$lc->id, $userid, true);
                }
                $DB->delete_records('local_emotions_consent', ['courseid' => (int)$context->instanceid, 'userid' => $userid]);
            } else if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $userid) {
                data_helper::delete_user_profile_data($userid);
            }
        }
    }

    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        if ($context->contextlevel == CONTEXT_COURSE) {
            $lc = data_helper::get_course_by_moodle_id((int)$context->instanceid);
            if ($lc) {
                foreach ($userids as $userid) {
                    data_helper::delete_course_records((int)$lc->id, (int)$userid, true);
                }
            }
            foreach ($userids as $userid) {
                $DB->delete_records('local_emotions_consent', ['courseid' => (int)$context->instanceid, 'userid' => (int)$userid]);
            }
        } else if ($context->contextlevel == CONTEXT_USER) {
            foreach ($userids as $userid) {
                if ($userid == $context->instanceid) {
                    data_helper::delete_user_profile_data((int)$userid);
                }
            }
        }
    }
}

<?php
/**
 * local/emotions_send/settings.php
 *
 * Administracion del sitio > Plugins > Plugins locales > Emotions Send.
 *
 * Almacenamiento de los datos del plugin:
 *  - 'moodle'    (por defecto): tablas local_emotions_* de la base de Moodle.
 *  - 'usermodel': base de datos externa user_model_v5_1, con la conexion de
 *                 abajo. Su esquema se crea/completa en la pagina "Base
 *                 externa user_model_v5_1" (usermodel.php) o por CLI
 *                 (cli/setup_usermodel.php).
 *
 * Todos estos valores se pueden fijar desde config.php (y entonces se ven
 * bloqueados aqui), p. ej.:
 *   $CFG->forced_plugin_settings['local_emotions_send'] = [
 *       'storage' => 'usermodel', 'extdb_host' => 'db', 'extdb_port' => 3306,
 *       'extdb_name' => 'user_model_v5_1', 'extdb_user' => '...', 'extdb_pass' => '...',
 *   ];
 */
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_emotions_send', get_string('pluginname', 'local_emotions_send'));
    $ADMIN->add('localplugins', $settings);
    // Estado y preparacion del esquema de la base externa user_model_v5_1 (usermodel.php).
    $ADMIN->add('localplugins', new admin_externalpage('local_emotions_send_usermodel',
        get_string('usermodel:pagetitle', 'local_emotions_send'),
        new moodle_url('/local/emotions_send/usermodel.php')));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading('local_emotions_send/storageheading',
            get_string('settings:storageheading', 'local_emotions_send'),
            get_string('settings:storageheading_desc', 'local_emotions_send')));

        $settings->add(new admin_setting_configselect('local_emotions_send/storage',
            get_string('settings:storage', 'local_emotions_send'),
            get_string('settings:storage_desc', 'local_emotions_send'),
            'moodle',
            [
                'moodle'    => get_string('settings:storage_moodle', 'local_emotions_send'),
                'usermodel' => get_string('settings:storage_usermodel', 'local_emotions_send'),
            ]));

        $settings->add(new admin_setting_configtext('local_emotions_send/extdb_host',
            get_string('settings:extdb_host', 'local_emotions_send'), '', '127.0.0.1'));
        $settings->add(new admin_setting_configtext('local_emotions_send/extdb_port',
            get_string('settings:extdb_port', 'local_emotions_send'), '', '3306', PARAM_INT));
        $settings->add(new admin_setting_configtext('local_emotions_send/extdb_name',
            get_string('settings:extdb_name', 'local_emotions_send'), '', 'user_model_v5_1'));
        $settings->add(new admin_setting_configtext('local_emotions_send/extdb_user',
            get_string('settings:extdb_user', 'local_emotions_send'), '', ''));
        $settings->add(new admin_setting_configpasswordunmask('local_emotions_send/extdb_pass',
            get_string('settings:extdb_pass', 'local_emotions_send'), '', ''));

        // Consentimiento informado del registro facial (classes/local/consent.php).
        $settings->add(new admin_setting_heading('local_emotions_send/consentheading',
            get_string('settings:consentheading', 'local_emotions_send'),
            get_string('settings:consentheading_desc', 'local_emotions_send')));
        $settings->add(new admin_setting_configcheckbox('local_emotions_send/consent_enabled',
            get_string('settings:consent_enabled', 'local_emotions_send'),
            get_string('settings:consent_enabled_desc', 'local_emotions_send'), 1));
        $settings->add(new admin_setting_configselect('local_emotions_send/consent_mode',
            get_string('settings:consent_mode', 'local_emotions_send'),
            get_string('settings:consent_mode_desc', 'local_emotions_send'),
            'block',
            [
                'block'    => get_string('settings:consent_mode_block', 'local_emotions_send'),
                'nocamera' => get_string('settings:consent_mode_nocamera', 'local_emotions_send'),
            ]));
        $settings->add(new admin_setting_confightmleditor('local_emotions_send/consent_text',
            get_string('settings:consent_text', 'local_emotions_send'),
            get_string('settings:consent_text_desc', 'local_emotions_send'), ''));
    }
}

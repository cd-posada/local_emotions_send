<?php
/**
 * local/emotions_send/classes/hook_callbacks.php
 *
 * Callbacks de hooks de Moodle 4.4+ (registrados en db/hooks.php).
 */
namespace local_emotions_send;

defined('MOODLE_INTERNAL') || die();

class hook_callbacks {

    /** Agrega el widget flotante de registro de atencion al pie de la pagina. */
    public static function before_footer_html_generation(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/emotions_send/lib.php');
        $hook->add_html(local_emotions_send_widget_html());
    }
}

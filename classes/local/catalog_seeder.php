<?php
/**
 * local/emotions_send/classes/local/catalog_seeder.php
 *
 * Datos iniciales (catalogos) del modelo v5: emociones, idiomas, niveles
 * academicos, programa por defecto, modelo emocional de Norman con sus 3
 * niveles e Inference_Model "Sin especificar".
 *
 * Antes estos datos SOLO se insertaban en db/upgrade.php (paso 2026090400),
 * asi que una instalacion NUEVA dejaba las tablas vacias: las emociones se
 * creaban al vuelo con codigos derivados del nombre (ABURRIDO en vez de
 * BOREDOM) y el formulario de perfil no ofrecia idiomas ni niveles.
 *
 * seed() es IDEMPOTENTE: inserta solo lo que falta y corrige el codigo de
 * las emociones creadas al vuelo. La llaman db/install.php (instalacion
 * nueva) y db/upgrade.php (reparar instalaciones existentes).
 */
namespace local_emotions_send\local;

defined('MOODLE_INTERNAL') || die();

class catalog_seeder {

    /** Mismo catalogo que db/upgrade.php (2026090400). */
    const EMOTIONS = [
        'BOREDOM'     => 'Aburrido',
        'ENGAGEMENT'  => 'Concentrado',
        'CONFUSION'   => 'Confundido',
        'FRUSTRATION' => 'Frustrado',
    ];

    const LANGUAGES = [
        'es' => 'Espanol',
        'en' => 'Ingles',
        'fr' => 'Frances',
        'pt' => 'Portugues',
    ];

    const LEVELS = ['Pregrado', 'Posgrado'];

    const DEFAULT_PROGRAM = 'Sin programa asignado';

    const NORMAN_MODEL  = 'Modelo de Norman (Visceral / Conductual / Reflexivo)';
    const NORMAN_LEVELS = ['Visceral', 'Behavioral', 'Reflective'];

    public static function seed(): void {
        global $DB;

        // Emociones: por codigo; si ya existe una fila con el nombre (creada
        // al vuelo por get_or_create_emotion, p. ej. code=ABURRIDO) se le
        // corrige el codigo en vez de duplicarla.
        foreach (self::EMOTIONS as $code => $name) {
            if ($DB->record_exists('local_emotions_emotion', ['code' => $code])) {
                continue;
            }
            $byname = $DB->get_record('local_emotions_emotion', ['name' => $name], 'id', IGNORE_MULTIPLE);
            if ($byname) {
                $DB->set_field('local_emotions_emotion', 'code', $code, ['id' => $byname->id]);
            } else {
                $DB->insert_record('local_emotions_emotion', (object)['code' => $code, 'name' => $name]);
            }
        }

        foreach (self::LANGUAGES as $iso => $name) {
            if (!$DB->record_exists('local_emotions_language', ['code_iso_639_1' => $iso])) {
                $DB->insert_record('local_emotions_language', (object)['code_iso_639_1' => $iso, 'name' => $name]);
            }
        }

        foreach (self::LEVELS as $value) {
            if (!$DB->record_exists('local_emotions_level', ['value' => $value])) {
                $DB->insert_record('local_emotions_level', (object)['value' => $value]);
            }
        }

        if (!$DB->record_exists('local_emotions_program', ['name' => self::DEFAULT_PROGRAM])) {
            $levelid = $DB->get_field('local_emotions_level', 'id', ['value' => self::LEVELS[0]], IGNORE_MULTIPLE);
            $DB->insert_record('local_emotions_program', (object)[
                'name' => self::DEFAULT_PROGRAM, 'levelid' => (int)$levelid, 'faculty' => null,
            ]);
        }

        $modelid = $DB->get_field_select('local_emotions_model', 'id',
            $DB->sql_compare_text('description') . ' = ' . $DB->sql_compare_text(':d'),
            ['d' => self::NORMAN_MODEL], IGNORE_MULTIPLE);
        if (!$modelid) {
            $modelid = $DB->insert_record('local_emotions_model', (object)['description' => self::NORMAN_MODEL]);
        }
        foreach (self::NORMAN_LEVELS as $code) {
            if (!$DB->record_exists('local_emotions_modellevel', ['modelid' => $modelid, 'code' => $code])) {
                $DB->insert_record('local_emotions_modellevel', (object)[
                    'modelid' => $modelid, 'code' => $code, 'description' => null,
                ]);
            }
        }

        if (!$DB->record_exists('local_emotions_infmodel', ['name' => 'Sin especificar'])) {
            $DB->insert_record('local_emotions_infmodel', (object)['name' => 'Sin especificar', 'version' => '0']);
        }
    }
}

<?php
/**
 * local/emotions_send/db/install.php
 *
 * Se ejecuta UNA vez, justo despues de crear las tablas de install.xml en
 * una instalacion nueva: carga los catalogos iniciales (ver catalog_seeder).
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_local_emotions_send_install() {
    \local_emotions_send\local\catalog_seeder::seed();
    return true;
}

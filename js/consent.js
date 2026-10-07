/**
 * local/emotions_send/js/consent.js
 *
 * Ventana del consentimiento informado del registro facial (la arma lib.php
 * cuando el estudiante aun no acepto el texto vigente en el curso).
 *
 *  - Dialogo modal accesible (role="dialog", aria-modal, foco dentro del
 *    dialogo, resto de la pagina inerte) que no se puede cerrar sin decidir.
 *  - "Acepto" solo se habilita al marcar "He leido...". Al aceptar se recarga
 *    la pagina: el servidor ya libera el contenido (finalizacion de la
 *    actividad Emotions) y la camara arranca.
 *  - "No acepto": en modo 'block' se explica que sin aceptar no se puede
 *    continuar (con opciones de volver a leer o salir del curso); en modo
 *    'nocamera', o para docentes, la ventana se cierra y el curso sigue sin camara.
 *  - Ademas expone EmotionsConsent.revoke() para el enlace "Retirar mi
 *    consentimiento" del widget.
 *
 * Config: window.EMOTIONS_CONSENT = {url, courseid, sesskey, exitUrl}
 */
(function () {
    'use strict';

    var cfg = window.EMOTIONS_CONSENT || {};

    function post(action) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('courseid', String(cfg.courseid));
        fd.append('sesskey', cfg.sesskey);
        return fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok) { throw new Error(j.error || ('HTTP ' + r.status)); }
                return j;
            });
        });
    }

    function revoke() {
        var msg = 'Si retira su consentimiento se BORRARAN todos los datos registrados de usted en este curso '
            + '(emociones detectadas, adaptaciones, calificaciones y comentarios) y la camara se apagara.'
            + (cfg.blocking ? ' Como este curso existe solo para el estudio, el contenido volvera a quedar bloqueado.' : '')
            + '\n\n¿Desea retirar su consentimiento?';
        if (!window.confirm(msg)) { return; }
        post('revoke').then(function (j) {
            try { sessionStorage.removeItem('emotions_log'); sessionStorage.removeItem('emotions_log_pending'); } catch (e) { /* */ }
            window.alert(j.datadeleted === false
                ? 'Su consentimiento fue retirado, pero no se pudieron borrar todos sus datos. Escriba al investigador para completarlo.'
                : 'Su consentimiento fue retirado y sus datos en este curso fueron borrados.');
            window.location.reload();
        }).catch(function (e) {
            window.alert('No se pudo retirar el consentimiento: ' + e.message);
        });
    }
    window.EmotionsConsent = { revoke: revoke };

    function initDialog() {
        var overlay = document.getElementById('emotions_consent');
        if (!overlay) { return; }
        var dialog = overlay.querySelector('[role="dialog"]');
        var check = document.getElementById('emotions_consent_check');
        var accept = document.getElementById('emotions_consent_accept');
        var decline = document.getElementById('emotions_consent_decline');
        var status = document.getElementById('emotions_consent_status');
        var main = document.getElementById('emotions_consent_main');
        var declined = document.getElementById('emotions_consent_declined');

        // Moodle imprime este HTML dentro del contenedor de la pagina: se mueve al nivel de <body> para
        // que la ventana no quede inerte junto con el resto.
        document.body.appendChild(overlay);

        // Todo lo demas de la pagina queda inerte (ni clic ni teclado) mientras la ventana este abierta.
        var inerted = [];
        Array.prototype.forEach.call(document.body.children, function (el) {
            if (el !== overlay && el.tagName !== 'SCRIPT' && !el.hasAttribute('inert')) {
                el.setAttribute('inert', '');
                inerted.push(el);
            }
        });
        document.documentElement.style.overflow = 'hidden';

        function close() {
            inerted.forEach(function (el) { el.removeAttribute('inert'); });
            document.documentElement.style.overflow = '';
            overlay.remove();
            var hidetours = document.getElementById('emotions_consent_hidetours');
            if (hidetours) { hidetours.remove(); }
        }

        // Foco atrapado dentro del dialogo.
        dialog.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Tab') { return; }
            var f = Array.prototype.filter.call(dialog.querySelectorAll('a[href],button,input,[tabindex]:not([tabindex="-1"])'),
                function (el) { return !el.disabled && el.offsetParent !== null; });
            if (!f.length) { return; }
            if (ev.shiftKey && document.activeElement === f[0]) { ev.preventDefault(); f[f.length - 1].focus(); }
            else if (!ev.shiftKey && document.activeElement === f[f.length - 1]) { ev.preventDefault(); f[0].focus(); }
        });

        check.addEventListener('change', function () { accept.disabled = !check.checked; });

        function busy(b) { accept.disabled = b || !check.checked; decline.disabled = b; }

        accept.addEventListener('click', function () {
            busy(true);
            status.textContent = 'Guardando su decision...';
            post('accept').then(function () {
                status.textContent = 'Gracias. Cargando el curso...';
                window.location.reload();
            }).catch(function (e) {
                busy(false);
                status.textContent = 'No se pudo guardar su decision: ' + e.message + '. Intente de nuevo.';
            });
        });

        decline.addEventListener('click', function () {
            busy(true);
            post('decline').then(function (j) {
                if (!j.blocked) { close(); return; }
                main.hidden = true;
                declined.hidden = false;
                declined.querySelector('h2').focus();
            }).catch(function (e) {
                busy(false);
                status.textContent = 'No se pudo guardar su decision: ' + e.message + '. Intente de nuevo.';
            });
        });

        document.getElementById('emotions_consent_reread').addEventListener('click', function () {
            declined.hidden = true;
            main.hidden = false;
            busy(false);
            dialog.querySelector('h2').focus();
        });

        dialog.querySelector('h2').focus();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDialog);
    } else {
        initDialog();
    }
}());

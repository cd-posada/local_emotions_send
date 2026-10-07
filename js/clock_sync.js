/**
 * local/emotions_send/js/clock_sync.js
 *
 * Reloj del navegador para la instrumentacion de latencia (user_model_v5_1).
 * Expone window.EmotionsClock:
 *
 *  - nowMs(): hora absoluta del navegador en ms de epoca. Se mide con
 *    performance.now() (monotono: un ajuste de NTP del sistema a mitad de
 *    sesion no produce intervalos negativos) y se convierte a hora absoluta
 *    con UNA sola lectura de Date.now() tomada al cargar (W3C High Resolution
 *    Time). Todas las marcas del cliente (window_start, window_end,
 *    inference_completed_at, applied_at) salen de aqui.
 *  - offsetMs: Learning_Session.clock_offset_ms medido en esta pagina
 *    (reloj del cliente - reloj del servidor; positivo = el cliente adelanta).
 *    null mientras no se haya medido o si fallo.
 *  - ready: Promise que resuelve con offsetMs.
 *
 * Medicion (principio de NTP): SAMPLES intercambios contra clock.php. En cada
 * uno el cliente anota t0 (envio) y t3 (recepcion) y el servidor devuelve t1
 * (llegada) y t2 (respuesta). Desfase del servidor respecto al cliente:
 * theta = ((t1 - t0) + (t2 - t3)) / 2; ida y vuelta = (t3 - t0) - (t2 - t1).
 * Se usa la muestra de menor ida y vuelta (la menos afectada por la red) y se
 * guarda con el signo del modelo de datos: clock_offset_ms = -theta.
 * El servidor lo escribe una sola vez por sesion (clock.php, action=store).
 */
(function () {
    'use strict';

    var SAMPLES = 5;
    var GAP_MS = 150;

    var EPOCH0 = Date.now() - performance.now();
    function nowMs() {
        return EPOCH0 + performance.now();
    }

    var cfg = window.EMOTIONS_GLOBAL_CONFIG || {};
    var api = { nowMs: nowMs, offsetMs: null, rttMs: null, ready: null };
    window.EmotionsClock = api;

    function post(fields) {
        var fd = new FormData();
        fd.append('sesskey', cfg.sesskey || '');
        Object.keys(fields).forEach(function (k) { fd.append(k, String(fields[k])); });
        return fetch(cfg.clockUrl, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            });
    }

    function sample() {
        var t0 = nowMs();
        return post({ action: 'ping' }).then(function (j) {
            var t3 = nowMs();
            var t1 = Number(j.t1), t2 = Number(j.t2);
            return {
                theta: ((t1 - t0) + (t2 - t3)) / 2,
                rtt: (t3 - t0) - (t2 - t1),
            };
        });
    }

    function wait(ms) {
        return new Promise(function (res) { setTimeout(res, ms); });
    }

    function measure() {
        var best = null;
        var chain = Promise.resolve();
        for (var i = 0; i < SAMPLES; i++) {
            chain = chain
                .then(function () { return sample(); })
                .then(function (s) {
                    if (!best || s.rtt < best.rtt) { best = s; }
                })
                .catch(function () { /* una muestra fallida no invalida las demas */ })
                .then(function () { return wait(GAP_MS); });
        }
        return chain.then(function () {
            if (!best) { throw new Error('sin muestras'); }
            return best;
        });
    }

    if (!cfg.clockUrl || !(cfg.courseid > 1)) {
        api.ready = Promise.resolve(null);
        return;
    }

    api.ready = measure()
        .then(function (best) {
            api.offsetMs = Math.round(-best.theta);
            api.rttMs = Math.round(best.rtt);
            return post({ action: 'store', courseid: cfg.courseid, offset: api.offsetMs });
        })
        .then(function () { return api.offsetMs; })
        .catch(function (e) {
            console.warn('[Emotions] clock_sync:', e);
            return api.offsetMs;
        });
}());

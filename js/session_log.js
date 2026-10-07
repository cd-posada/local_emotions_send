/**
 * local/emotions_send/js/session_log.js
 *
 * FASE DE PRUEBAS (pedido explicito del asesor): sin cifrado. El log
 * queda en texto plano en sessionStorage para poder verificar en
 * DevTools que los datos son correctos. Para volver a cifrar mas
 * adelante (produccion real), basta con cambiar ENCRYPT a true y
 * restaurar la logica de AES-GCM (ver el historial de este archivo:
 * ya existia una version cifrada completa, se puede recuperar).
 *
 * ENVIO AL SERVIDOR (almacenamiento 'usermodel'): ademas de quedar en
 * sessionStorage, cada entrada nueva ("second" y "window") se encola y se
 * envia en lotes cada 5 s (y con sendBeacon al salir de la pagina) a
 * log.php, que la guarda en la tabla Registro_JSON de user_model_v5_1
 * ligada a la Learning_Session. Con el almacenamiento 'moodle' lib.php no
 * configura logUrl y el log sigue solo en el navegador.
 *
 * FORMATO: replica el JSON que ya genera la app de prueba ONNX
 * (web_onnx_test_v5), un solo arreglo con dos tipos de entradas
 * mezcladas cronologicamente:
 *
 *   {"type":"second", "timestamp":4.02, "emotion":"Concentrado",
 *    "level":0, "probability":0.5674}
 *
 *   {"type":"window", "timestamp":4.14, "duration_seconds":6,
 *    "emotion":"Concentrado", "level":0, "probability":0.5175,
 *    "num_measurements":5}
 *
 * Cadencia:
 *  - Una entrada "second" por CADA segundo con rostro detectado (el
 *    analisis corre en el navegador, ver fer_engine.js / camera_global.js).
 *    "emotion" es el nombre en BD (Aburrido, Concentrado, Confundido,
 *    Frustrado) y "level" el nivel DAiSEE 0-3 del estado dominante.
 *  - Una entrada "window" cada tanto, en un rango ALEATORIO de 5 a 10
 *    segundos (igual que la app de referencia), resumiendo las
 *    entradas "second" acumuladas desde la ultima ventana.
 *
 * Formula de la ventana (verificada contra el JSON real que compartio
 * el asesor, coincide exacto):
 *  - emotion  = la MAS FRECUENTE entre las entradas "second" de la ventana.
 *  - level    = la MAS FRECUENTE, pero SOLO entre las entradas que
 *               coinciden con la emotion ganadora.
 *  - probability = el PROMEDIO de probability de esas mismas entradas
 *               (las que coinciden con la emotion ganadora).
 *  - num_measurements = cuantas entradas "second" entraron en la ventana.
 *  - Si no hubo ninguna entrada "second" en el rango (ej. camara sin
 *    permiso), la ventana se guarda con emotion/level/probability en
 *    null, igual que hace la app de referencia.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'emotions_log';

    // FASE DE PRUEBAS: false = texto plano, se puede leer directo en
    // DevTools > Application > Session Storage sin descifrar nada.
    var ENCRYPT = false;

    var WINDOW_MIN_SECONDS = 5;
    var WINDOW_MAX_SECONDS = 10;

    // Envio al servidor (solo si lib.php configura logUrl, es decir, con el
    // almacenamiento 'usermodel'): cada entrada nueva del log tambien se
    // encola en PENDING_KEY y se manda en lotes a log.php, que la guarda en
    // la tabla Registro_JSON de user_model_v5_1. La cola vive en
    // sessionStorage para sobrevivir a la navegacion entre paginas.
    var PENDING_KEY = 'emotions_log_pending';
    var FLUSH_INTERVAL_MS = 5000;
    var MAX_BATCH = 200;       // igual que data_helper::SESSION_LOG_MAX_BATCH
    var MAX_PENDING = 2000;    // tope de la cola si el servidor no responde
    var _sending = false;

    var _startTime = Date.now();
    var _windowBuffer = [];   // entradas "second" acumuladas desde la ultima ventana
    var _windowStartTs = 0;

    function _pickNextWindowSeconds() {
        return WINDOW_MIN_SECONDS + Math.random() * (WINDOW_MAX_SECONDS - WINDOW_MIN_SECONDS);
    }

    function _nowSeconds() {
        // Segundos desde que arranco la captura en esta pagina, con 2
        // decimales (igual formato que el JSON de referencia).
        return Math.round(((Date.now() - _startTime) / 1000) * 100) / 100;
    }

    function _round4(n) {
        return Math.round(n * 10000) / 10000;
    }

    function _readLogSync() {
        var stored = sessionStorage.getItem(STORAGE_KEY);
        if (!stored) { return []; }
        try {
            return JSON.parse(stored);
        } catch (e) {
            console.warn('[Emotions] Log de sesion corrupto, se reinicia:', e);
            return [];
        }
    }

    function _writeLogSync(log) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(log));
        } catch (e) {
            console.warn('[Emotions] No se pudo escribir en sessionStorage:', e);
        }
    }

    function _cfg() {
        return window.EMOTIONS_GLOBAL_CONFIG || {};
    }

    function _readPending() {
        try {
            return JSON.parse(sessionStorage.getItem(PENDING_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function _writePending(list) {
        try {
            sessionStorage.setItem(PENDING_KEY, JSON.stringify(list.slice(-MAX_PENDING)));
        } catch (e) {
            console.warn('[Emotions] No se pudo escribir la cola del log:', e);
        }
    }

    /** Encola una entrada del log para enviarla a log.php (con la hora real). */
    function _queueForServer(entry) {
        if (!_cfg().logUrl) { return; }
        var copy = {};
        Object.keys(entry).forEach(function (k) { copy[k] = entry[k]; });
        copy.epoch_ms = Date.now();
        var pending = _readPending();
        pending.push(copy);
        _writePending(pending);
    }

    function _buildForm(batch) {
        var cfg = _cfg();
        var fd = new FormData();
        fd.append('sesskey', cfg.sesskey || (typeof M !== 'undefined' && M.cfg ? M.cfg.sesskey : ''));
        fd.append('courseid', String(cfg.courseid || 0));
        if (window.screen) {
            fd.append('viewport', window.screen.width + 'x' + window.screen.height);
        }
        fd.append('entries', JSON.stringify(batch));
        return fd;
    }

    /**
     * Envia la cola a log.php. Las entradas salen de la cola ANTES de
     * enviarse (asi no se duplican si la pagina se cierra a mitad) y
     * vuelven al frente de la cola si el envio falla.
     * useBeacon=true al salir de la pagina (navigator.sendBeacon).
     */
    function flushToServer(useBeacon) {
        var url = _cfg().logUrl;
        if (!url || _sending) { return; }
        var pending = _readPending();
        if (!pending.length) { return; }
        var batch = pending.slice(0, MAX_BATCH);
        _writePending(pending.slice(batch.length));

        if (useBeacon && navigator.sendBeacon) {
            if (!navigator.sendBeacon(url, _buildForm(batch))) {
                _writePending(batch.concat(_readPending()));
            }
            return;
        }
        _sending = true;
        fetch(url, { method: 'POST', body: _buildForm(batch), credentials: 'same-origin', keepalive: true })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
            })
            .catch(function (err) {
                _writePending(batch.concat(_readPending()));
                console.warn('[Emotions] log.php:', err);
            })
            .then(function () { _sending = false; });
    }

    /** Envia TODA la cola (en lotes) y llama a done() al terminar, haya o no errores. */
    function _flushAllThen(done) {
        var url = _cfg().logUrl;
        var pending = _readPending();
        if (!url || !pending.length) { done(); return; }
        var batch = pending.slice(0, MAX_BATCH);
        _writePending(pending.slice(batch.length));
        fetch(url, { method: 'POST', body: _buildForm(batch), credentials: 'same-origin', keepalive: true })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                _flushAllThen(done);
            })
            .catch(function () {
                _writePending(batch.concat(_readPending()));
                done();
            });
    }

    function _mode(values) {
        var counts = {};
        var best = null;
        var bestCount = -1;
        values.forEach(function (v) {
            var key = String(v);
            counts[key] = (counts[key] || 0) + 1;
            if (counts[key] > bestCount) {
                bestCount = counts[key];
                best = v;
            }
        });
        return best;
    }

    function _closeWindow() {
        var ts = _nowSeconds();
        var duration = Math.round((ts - _windowStartTs) * 100) / 100;

        var entry;
        if (_windowBuffer.length === 0) {
            entry = {
                type: 'window',
                timestamp: ts,
                duration_seconds: duration,
                emotion: null,
                level: null,
                probability: null,
                num_measurements: 0,
            };
        } else {
            var emotions = _windowBuffer.map(function (r) { return r.emotion; });
            var dominantEmotion = _mode(emotions);
            var matching = _windowBuffer.filter(function (r) { return r.emotion === dominantEmotion; });
            var dominantLevel = _mode(matching.map(function (r) { return r.level; }));
            var avgProb = matching.reduce(function (sum, r) { return sum + r.probability; }, 0) / matching.length;

            entry = {
                type: 'window',
                timestamp: ts,
                duration_seconds: duration,
                emotion: dominantEmotion,
                level: dominantLevel,
                probability: _round4(avgProb),
                num_measurements: _windowBuffer.length,
            };
        }

        var log = _readLogSync();
        log.push(entry);
        _writeLogSync(log);
        _queueForServer(entry);

        // Aviso para la vista de diagnostico (camera_global.js lo escribe en
        // el log de mod/emotions/view.php, como la consola de la app de
        // referencia).
        try {
            window.dispatchEvent(new CustomEvent('emotions:window', { detail: entry }));
        } catch (e) {
            // Navegador sin CustomEvent: solo se pierde la linea de log.
        }

        _windowBuffer = [];
        _windowStartTs = ts;

        // Reloj INDEPENDIENTE: se reprograma solo, sin importar si llegan
        // o no mediciones "second" mientras tanto. Antes esto se revisaba
        // solo cuando llegaba una nueva medicion, y como el backend a
        // veces tarda varios segundos en responder, la ventana terminaba
        // cerrandose con una sola medicion (la que llegara justo despues
        // de pasado el tiempo), en vez de acumular varias.
        setTimeout(_closeWindow, _pickNextWindowSeconds() * 1000);
    }

    /**
     * Se llama una vez por cada frame clasificado (idealmente 1 por
     * segundo, desde camera_global.js justo despues de cada inferencia
     * local con rostro detectado). data: { emotion, level, probability }.
     * Ya NO decide cuando cerrar la ventana -- eso lo hace el timer
     * independiente de _closeWindow().
     */
    function appendSecondMeasurement(data) {
        var ts = _nowSeconds();
        var record = {
            type: 'second',
            timestamp: ts,
            emotion: data.emotion,
            level: (typeof data.level === 'number') ? data.level : null,
            probability: _round4(data.probability || 0),
        };

        var log = _readLogSync();
        log.push(record);
        _writeLogSync(log);
        _queueForServer(record);

        _windowBuffer.push(record);
    }

    // Arranca el reloj de la primera ventana en cuanto carga el script.
    setTimeout(_closeWindow, _pickNextWindowSeconds() * 1000);

    // Envio periodico al servidor, y un ultimo envio al salir de la pagina.
    // Al cargar, se envia lo que haya quedado pendiente de la pagina anterior.
    setInterval(function () { flushToServer(false); }, FLUSH_INTERVAL_MS);
    setTimeout(function () { flushToServer(false); }, 1500);
    window.addEventListener('pagehide', function () { flushToServer(true); });

    // Cerrar sesion: el sendBeacon de 'pagehide' llegaria DESPUES de que
    // Moodle cierre la sesion y log.php lo rechazaria (se perderian los
    // ultimos segundos del log). Por eso, al hacer clic en el enlace de
    // logout, primero se envia lo pendiente y luego se sigue al logout
    // (maximo 2 s de espera).
    document.addEventListener('click', function (ev) {
        var a = ev.target && ev.target.closest ? ev.target.closest('a[href*="/login/logout.php"]') : null;
        if (!a || !_cfg().logUrl || !_readPending().length || ev.defaultPrevented) { return; }
        ev.preventDefault();
        var href = a.href;
        var go = function () { window.location.href = href; };
        var timer = setTimeout(go, 2000);
        _flushAllThen(function () { clearTimeout(timer); go(); });
    }, true);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { flushToServer(true); }
    });

    /** Lectura completa del log (se mantiene como Promise por compatibilidad
     * con codigo que ya esperaba un await aqui de la version cifrada). */
    function readLog() {
        return Promise.resolve(_readLogSync());
    }

    window.EmotionsSessionLog = {
        appendSecondMeasurement: appendSecondMeasurement,
        readLog: readLog,
        flushToServer: flushToServer,
        ENCRYPT: ENCRYPT,
    };
}());
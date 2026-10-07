/**
 * local/emotions_send/js/camera_global.js
 *
 * CAMBIO PRINCIPAL: el registro facial ahora ocurre 100% en el navegador
 * (ver fer_engine.js: OpenCV.js + ONNX Runtime Web). Ya no se manda
 * ningun fotograma al backend de Render (/process); a Moodle (save.php)
 * solo llega la emocion ya clasificada, su nivel DAiSEE 0-3, la
 * probabilidad y la distribucion de las 4 ramas.
 *
 * Cadencia (igual que la app de referencia web_onnx_test_v5 y que la
 * version anterior de este archivo): 1 analisis por segundo. Cada segundo
 * con rostro detectado:
 *  - actualiza el widget flotante,
 *  - se guarda en Moodle (saveToMoodle -> save.php),
 *  - se agrega como entrada "second" al log de sessionStorage
 *    (session_log.js, que ademas cierra las ventanas aleatorias de 5-10s).
 * Los segundos SIN rostro no se guardan (igual que la app de referencia).
 *
 * Vistas de video: cualquier <canvas data-emotions-view="compact|full">
 * de la pagina se redibuja en cada frame de animacion. "compact" = video
 * espejado + recuadro del rostro (widget flotante); "full" = ademas el
 * panel de diagnostico de la app de referencia (pagina de la actividad,
 * mod/emotions/view.php). Si la pagina tiene #emotions_diag_status y/o
 * #emotions_diag_log (view.php), tambien se llenan.
 *
 * Latencia (user_model_v5_1): cada Emotion_Record representa un segmento de
 * muestreo, desde la captura anterior (window_start) hasta la captura del
 * fotograma clasificado (window_end); con 1 muestra por segundo dura ~1000 ms
 * y se alarga si el navegador frena la pestana en segundo plano, que es lo que
 * permite verificar la ventana efectiva. inference_completed_at marca el fin
 * del clasificador. Las tres salen de window.EmotionsClock (clock_sync.js,
 * performance.now() sobre una sola lectura de Date.now()) y viajan en ms con
 * el reloj del navegador; save.php las corrige con clock_offset_ms.
 *
 *  - El sesskey se lee de window.EMOTIONS_GLOBAL_CONFIG.sesskey
 *    (inyectado server-side por lib.php) en lugar de depender
 *    unicamente de M.cfg.sesskey, que puede no estar disponible
 *    en todos los layouts de pagina.
 */
(function () {
    'use strict';

    var PROCESS_INTERVAL_MS = 1000; // 1 fps, igual que la app de referencia
    var MAX_LOG_LINES = 300;

    function init() {
        var cfg      = window.EMOTIONS_GLOBAL_CONFIG || {};
        var SAVE_URL = cfg.saveUrl    || null;
        var COURSEID = cfg.courseid   || 0;
        var CMID     = cfg.cmid       || 0;
        var SESSKEY  = cfg.sesskey    || (typeof M !== 'undefined' && M.cfg ? M.cfg.sesskey : null);
        var FER      = window.EmotionsFerEngine;

        var video    = document.getElementById('emotions_global_cam');
        var elEmo    = document.getElementById('ew_emotion');
        var elConf   = document.getElementById('ew_conf');
        var elStatus = document.getElementById('ew_status');
        var elDot    = document.getElementById('ew_dot');

        if (!video) {
            console.warn('[Emotions] Elemento video no encontrado.');
            return;
        }
        if (!FER || !cfg.modelUrl) {
            console.warn('[Emotions] Motor FER o modelUrl no configurado.');
            setStatus('Error de configuracion', '#dc3545');
            return;
        }

        var engine    = new FER.Engine(cfg);
        var stream    = null;
        var busy      = false;
        var lastResult = null;
        var startTs   = performance.now();
        // Reloj de las marcas de latencia (ver clock_sync.js); respaldo equivalente si no cargo.
        var clock = window.EmotionsClock || (function () {
            var epoch0 = Date.now() - performance.now();
            return { nowMs: function () { return epoch0 + performance.now(); }, offsetMs: null };
        }());
        var lastCaptureMs = null; // window_end del segmento anterior = window_start del siguiente

        setStatus('Solicitando permiso de camara...', '#adb5bd');

        var cameraReady = navigator.mediaDevices.getUserMedia({
            video: { width: FER.FRAME_WIDTH, height: FER.FRAME_HEIGHT },
            audio: false,
        }).then(function (s) {
            stream = s;
            window._emotionsStream = s;
            video.srcObject = s;
            return video.play();
        });

        var engineReady = engine.init(function (msg) {
            if (stream) { setStatus(msg, '#ffc107'); }
        });
        // Si la camara falla primero, el error del motor ya no se espera:
        // evita un "Uncaught (in promise)" en consola.
        engineReady.catch(function () {});

        cameraReady
            .then(function () {
                setStatus('Cargando modelo...', '#ffc107');
                return engineReady;
            })
            .then(function () {
                setStatus('Activo', '#28a745');
                setText(elEmo, 'Buscando rostro...');
                logLine('Modelo ' + engine.modelLabel + ' y Haar Cascade cargados. Analisis local, 1 muestra por segundo.');
                startTs = performance.now();
                lastCaptureMs = clock.nowMs();
                setInterval(tick, PROCESS_INTERVAL_MS);
                requestAnimationFrame(renderLoop);
            })
            .catch(function (err) {
                var denied = err && (err.name === 'NotAllowedError' || err.name === 'NotFoundError');
                setText(elEmo, '—');
                setStatus(denied ? 'Sin acceso a camara' : 'Error: ' + (err.message || err), '#dc3545');
                logLine('[ERROR] ' + (err.message || err));
                console.warn('[Emotions] Inicializacion:', err);
                if (stream) {
                    stream.getTracks().forEach(function (t) { t.stop(); });
                }
            });

        // ── Analisis 1 vez por segundo ──────────────────────────────────
        // setInterval (y no requestAnimationFrame, como en la app de
        // referencia) para que el registro siga aunque la pestana quede en
        // segundo plano, igual que hacia la version con backend.
        function tick() {
            if (busy || video.readyState < 2) { return; }
            busy = true;
            // analyze() dibuja el fotograma de forma sincronica al empezar: esa es la captura.
            var marks = { start: lastCaptureMs, end: clock.nowMs(), inference: null };
            lastCaptureMs = marks.end;
            engine.analyze(video)
                .then(function (result) {
                    marks.inference = clock.nowMs();
                    lastResult = result;
                    if (result.hasFace) {
                        onMeasurement(result, marks);
                    } else {
                        setText(elEmo, 'Buscando rostro...');
                        setText(elConf, '—');
                    }
                })
                .catch(function (err) {
                    setStatus('Error de inferencia', '#dc3545');
                    logLine('[ERROR] ' + (err.message || err));
                    console.warn('[Emotions] analyze:', err);
                })
                .then(function () { busy = false; });
        }

        function onMeasurement(r, marks) {
            setDot('#28a745');
            setText(elStatus, 'Activo');
            setText(elEmo, r.emotion + ' · Nivel ' + r.topLevel);
            setText(elConf, (r.topProb * 100).toFixed(0) + '% confianza · Atencion '
                + r.aiScore.toFixed(1) + '/9');

            var elapsed = (performance.now() - startTs) / 1000;
            logLine('[t=' + elapsed.toFixed(1) + 's] Emocion: ' + r.topState + ' - Nivel ' + r.topLevel
                + ' (' + (r.topProb * 100).toFixed(1) + '%)');

            saveToMoodle(r, marks);

            if (window.EmotionsSessionLog) {
                window.EmotionsSessionLog.appendSecondMeasurement({
                    emotion:     r.emotion,
                    level:       r.topLevel,
                    probability: r.topProb,
                });
            }
        }

        // Resumen de cada periodo aleatorio (lo emite session_log.js).
        window.addEventListener('emotions:window', function (ev) {
            var w = ev.detail || {};
            var dur = Math.round(w.duration_seconds || 0);
            if (w.emotion !== null && w.emotion !== undefined) {
                logLine('[PERIODO ' + dur + 's] Emocion resultante: ' + w.emotion + ' - Nivel ' + w.level
                    + ' (' + (w.probability * 100).toFixed(1) + '%) -- ' + w.num_measurements + ' mediciones');
            } else {
                logLine('[PERIODO ' + dur + 's] Sin detecciones de rostro -- 0 mediciones');
            }
        });

        // ── Dibujo de las vistas de video ───────────────────────────────
        function renderLoop() {
            var views = document.querySelectorAll('canvas[data-emotions-view]');
            if (video.readyState >= 2) {
                for (var i = 0; i < views.length; i++) {
                    var c = views[i];
                    if (c.offsetParent === null) { continue; } // oculto
                    engine.render(c, video, lastResult, c.getAttribute('data-emotions-view') === 'full');
                }
            }
            requestAnimationFrame(renderLoop);
        }

        function saveToMoodle(r, marks) {
            if (!SAVE_URL) { return; }
            var fd = new FormData();
            if (marks && marks.start !== null) {
                fd.append('window_start_ms',        String(Math.round(marks.start)));
                fd.append('window_end_ms',          String(Math.round(marks.end)));
                fd.append('inference_completed_ms', String(Math.round(marks.inference)));
            }
            if (clock.offsetMs !== null && clock.offsetMs !== undefined) {
                fd.append('clock_offset_ms', String(clock.offsetMs));
            }
            fd.append('emotion',       r.emotion);
            fd.append('confidence',    String(r.topProb));
            fd.append('level',         String(r.topLevel));
            fd.append('probabilities', JSON.stringify(r.probabilities));
            if (typeof r.entropy === 'number' && isFinite(r.entropy)) {
                fd.append('entropy', r.entropy.toFixed(4)); // normalizada [0, 1] -> DECIMAL(5,4)
            }
            fd.append('courseid',      String(COURSEID));
            fd.append('cmid',          String(CMID));
            if (window.screen) {
                fd.append('viewport', window.screen.width + 'x' + window.screen.height);
            }
            if (SESSKEY) {
                fd.append('sesskey', SESSKEY);
            }
            fetch(SAVE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                .catch(function (e) { console.warn('[Emotions] save:', e); });
        }

        function setStatus(t, color) {
            setText(elStatus, t);
            setText(document.getElementById('emotions_diag_status'), t);
            if (color) { setDot(color); }
        }

        function logLine(text) {
            var el = document.getElementById('emotions_diag_log');
            if (!el) { return; }
            var t = new Date().toLocaleTimeString();
            var lines = (el.textContent + '[' + t + '] ' + text + '\n').split('\n');
            if (lines.length > MAX_LOG_LINES) {
                lines = lines.slice(lines.length - MAX_LOG_LINES);
            }
            el.textContent = lines.join('\n');
            el.scrollTop = el.scrollHeight;
        }

        function setText(el, t) { if (el) { el.textContent = t; } }
        function setDot(c)      { if (elDot) { elDot.style.background = c; } }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());

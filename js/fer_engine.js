/**
 * local/emotions_send/js/fer_engine.js
 *
 * Motor de reconocimiento facial 100% en el cliente: reemplaza al backend
 * Python (emotions_backend, /process en Render). Replica el pipeline de la
 * app de referencia web_onnx_test_v5/app.js:
 *
 *  - Deteccion de rostro con Haar Cascade via OpenCV.js (mismo XML que los
 *    scripts Python), sobre el frame de video ESPEJADO (cv2.flip(frame, 1))
 *    dibujado en un canvas oculto de 640x480, sin nada encima.
 *  - Del rostro mas grande: recorte + resize a 224x224 (INTER_CUBIC) +
 *    normalizacion ImageNet (mean/std) -> tensor NCHW float32.
 *  - Inferencia con ONNX Runtime Web (WASM, un solo hilo: Moodle no manda
 *    las cabeceras COOP/COEP que exige el backend multi-hilo) sobre el
 *    modelo EfficientNet-B2 de 4 ramas: una salida por estado afectivo
 *    (Boredom, Engagement, Confusion, Frustration) con 4 logits = nivel de
 *    intensidad DAiSEE 0-3.
 *  - Por rama: softmax, nivel = argmax, probabilidad = prob de ese nivel.
 *    Estado dominante = mayor (nivel + probabilidad). Indice de Atencion
 *    con la misma formula normalizada 0-9.
 *  - Panel de diagnostico dibujado en la esquina superior derecha, igual
 *    al de la app de referencia.
 *
 * Carga de librerias en Moodle: Moodle incluye RequireJS, y OpenCV.js es
 * UMD -> si ve un `define.amd` global se registra como modulo AMD anonimo
 * y NUNCA crea window.cv (ademas de disparar "Mismatched anonymous
 * define()"). Por eso las librerias se descargan con fetch() y se ejecutan
 * como <script> inline con `define` oculto solo durante esa ejecucion
 * sincrona (ver loadScriptWithoutAmd). Ningun otro script puede correr en
 * medio, asi que ocultarlo no afecta a los modulos AMD de Moodle.
 *
 * API publica: window.EmotionsFerEngine (ver el return al final).
 */
(function () {
    'use strict';

    var AFFECT_STATES = ['Boredom', 'Engagement', 'Confusion', 'Frustration'];
    var STATE_SPANISH = {
        Boredom: 'Aburrimiento',
        Engagement: 'Compromiso',
        Confusion: 'Confusion',
        Frustration: 'Frustracion',
    };
    // Nombre con que cada estado existe en local_emotions_emotion (y el que
    // devolvia el backend Python en CLASSES): save.php resuelve la emocion
    // por este nombre, y adaptive_ui lo lee del log de sessionStorage.
    var STATE_TO_DB_NAME = {
        Boredom: 'Aburrido',
        Engagement: 'Concentrado',
        Confusion: 'Confundido',
        Frustration: 'Frustrado',
    };
    var LEVEL_NAMES = { 0: 'Muy Bajo (0)', 1: 'Bajo (1)', 2: 'Alto (2)', 3: 'Muy Alto (3)' };
    var MEAN = [0.485, 0.456, 0.406];
    var STD = [0.229, 0.224, 0.225];
    var INPUT_SIZE = 224;
    var FRAME_WIDTH = 640;
    var FRAME_HEIGHT = 480;
    var MODEL_CACHE_NAME = 'local_emotions_send-models';

    // ── Carga de librerias ──────────────────────────────────────────────

    async function loadScriptWithoutAmd(url) {
        var resp = await fetch(url, { credentials: 'omit' });
        if (!resp.ok) {
            throw new Error('No se pudo descargar ' + url + ' (HTTP ' + resp.status + ')');
        }
        var code = await resp.text();
        var savedDefine = window.define;
        window.define = undefined;
        try {
            var s = document.createElement('script');
            s.textContent = code;
            document.head.appendChild(s); // ejecucion sincrona
            document.head.removeChild(s);
        } finally {
            window.define = savedDefine;
        }
    }

    // Promesas memorizadas: si otro script de la pagina ya pidio la misma
    // libreria, se reusa la misma carga.
    var ortPromise = null;
    var cvPromise = null;

    function ensureOrt(cfg) {
        if (!ortPromise) {
            ortPromise = (async function () {
                if (!window.ort) {
                    await loadScriptWithoutAmd(cfg.ortUrl);
                }
                if (!window.ort) {
                    throw new Error('ONNX Runtime Web no quedo disponible (window.ort)');
                }
                window.ort.env.wasm.numThreads = 1;
                window.ort.env.wasm.wasmPaths = cfg.ortWasmPaths;
                return window.ort;
            }());
        }
        return ortPromise;
    }

    function ensureCv(cfg) {
        if (!cvPromise) {
            cvPromise = (async function () {
                if (!window.cv) {
                    await loadScriptWithoutAmd(cfg.opencvUrl);
                }
                var cvObj = window.cv;
                if (!cvObj) {
                    throw new Error('OpenCV.js no quedo disponible (window.cv)');
                }
                if (cvObj instanceof Promise) {
                    // Builds MODULARIZE nuevas: cv es una Promise real.
                    cvObj = await cvObj;
                    window.cv = cvObj;
                } else if (!cvObj.Mat) {
                    // Build 4.9.0 (la misma de la app de referencia): el runtime
                    // WASM termina de inicializar de forma asincrona.
                    await new Promise(function (resolve) {
                        cvObj.onRuntimeInitialized = function () { resolve(); };
                    });
                }
                // OJO: nunca resolver una Promise con el objeto cv directamente.
                // En la build 4.9.0 el Module de emscripten trae su propio
                // .then() que se resuelve con el mismo Module: la Promise lo
                // trata como "thenable" y queda desenvolviendolo en un bucle
                // infinito de microtareas que congela la pagina. Se envuelve.
                return { cv: cvObj };
            }());
        }
        return cvPromise;
    }

    async function loadFaceCascade(cv, url) {
        var resp = await fetch(url, { credentials: 'same-origin' });
        if (!resp.ok) {
            throw new Error('No se pudo descargar el Haar Cascade (HTTP ' + resp.status + ')');
        }
        var buf = await resp.arrayBuffer();
        var name = 'haarcascade_frontalface_default.xml';
        try {
            cv.FS_unlink('/' + name);
        } catch (e) {
            // No existia todavia: nada que borrar.
        }
        cv.FS_createDataFile('/', name, new Uint8Array(buf), true, false, false);
        var classifier = new cv.CascadeClassifier();
        if (!classifier.load(name)) {
            throw new Error('No se pudo cargar el Haar Cascade en OpenCV.js');
        }
        return classifier;
    }

    // Descarga el modelo (~30 MB) usando la Cache API cuando esta disponible
    // (contextos seguros / HTTPS): asi se descarga una sola vez por version
    // (la URL lleva ?v=) y no en cada pagina del curso que visita el
    // estudiante. Sin Cache API, queda la cache HTTP normal del navegador.
    async function fetchModelBytes(url) {
        var cache = null;
        if (window.caches && window.isSecureContext) {
            try {
                cache = await window.caches.open(MODEL_CACHE_NAME);
                var hit = await cache.match(url);
                if (hit) {
                    return new Uint8Array(await hit.arrayBuffer());
                }
            } catch (e) {
                cache = null;
            }
        }
        var resp = await fetch(url, { credentials: 'same-origin' });
        if (!resp.ok) {
            throw new Error('No se pudo descargar el modelo ONNX (HTTP ' + resp.status + ')');
        }
        if (cache) {
            try {
                // Borra versiones anteriores del modelo antes de guardar esta.
                var keys = await cache.keys();
                await Promise.all(keys.map(function (req) { return cache.delete(req); }));
                await cache.put(url, resp.clone());
            } catch (e) {
                // Cuota llena o modo privado: se sigue sin cache persistente.
            }
        }
        return new Uint8Array(await resp.arrayBuffer());
    }

    // ── Utilidades numericas (identicas a app.js) ───────────────────────

    var ENTROPY_SUM_TOLERANCE = 1e-3;

    function softmax(arr) {
        var max = Math.max.apply(null, arr);
        var exps = arr.map(function (v) { return Math.exp(v - max); });
        var sum = exps.reduce(function (a, b) { return a + b; }, 0);
        return exps.map(function (v) { return v / sum; });
    }

    /**
     * Entropia de Shannon normalizada a [0, 1]: H = -sum(p_i * log2 p_i) / log2(n).
     * Los terminos con p_i = 0 se omiten (lim p->0 de p log p = 0). Antes de calcular se
     * verifica que no haya probabilidades negativas o no finitas (-> null) y que sumen 1 con
     * tolerancia; si no suman 1 se normalizan, porque sin eso la entropia no tiene
     * interpretacion. Ver "Calculo del campo Entropy" (Shannon, 1948).
     * @param {number[]} probs
     * @return {?number}
     */
    function shannonEntropy(probs) {
        var n = probs.length;
        if (n < 2) { return null; }
        var sum = 0;
        for (var i = 0; i < n; i++) {
            if (!isFinite(probs[i]) || probs[i] < 0) { return null; }
            sum += probs[i];
        }
        if (!(sum > 0)) { return null; }
        var norm = Math.abs(sum - 1) > ENTROPY_SUM_TOLERANCE ? sum : 1;
        var h = 0;
        for (var j = 0; j < n; j++) {
            var p = probs[j] / norm;
            if (p > 0) { h -= p * Math.log2(p); }
        }
        return Math.min(1, Math.max(0, h / Math.log2(n)));
    }

    function computeAttentivenessIndex(b, e, c, f) {
        var aiRaw = -0.598 * b + 1.539 * e + 0.334 * c - 0.085 * f;
        var minVal = -2.049;
        var maxVal = 5.619;
        var aiNorm = ((aiRaw - minVal) / (maxVal - minVal)) * 9.0;
        return Math.min(9.0, Math.max(0.0, aiNorm));
    }

    // Recorta+redimensiona una region de un cv.Mat RGBA a 224x224 y la
    // convierte a un Float32Array NCHW normalizado (ImageNet mean/std).
    function matRegionToInputTensor(cv, ort, srcMat, rect) {
        var roi = srcMat.roi(rect);
        var resized = new cv.Mat();
        cv.resize(roi, resized, new cv.Size(INPUT_SIZE, INPUT_SIZE), 0, 0, cv.INTER_CUBIC);

        var data = resized.data; // Uint8Array RGBA
        var planeSize = INPUT_SIZE * INPUT_SIZE;
        var floatData = new Float32Array(3 * planeSize);
        for (var i = 0; i < planeSize; i++) {
            var p = i * 4;
            floatData[i] = (data[p] / 255.0 - MEAN[0]) / STD[0];
            floatData[planeSize + i] = (data[p + 1] / 255.0 - MEAN[1]) / STD[1];
            floatData[2 * planeSize + i] = (data[p + 2] / 255.0 - MEAN[2]) / STD[2];
        }

        roi.delete();
        resized.delete();
        return new ort.Tensor('float32', floatData, [1, 3, INPUT_SIZE, INPUT_SIZE]);
    }

    // ── Motor ───────────────────────────────────────────────────────────

    /**
     * cfg: { modelUrl, modelFile, modelLabel, cascadeUrl, ortUrl,
     *        ortWasmPaths, opencvUrl }
     */
    function Engine(cfg) {
        this.cfg = cfg;
        this.cv = null;
        this.ort = null;
        this.session = null;
        this.faceClassifier = null;
        this.modelLabel = cfg.modelLabel || '';
        this.modelFile = cfg.modelFile || '';

        // Canvas oculto (nunca se agrega al DOM) con el frame espejado y
        // SIN panel de diagnostico encima: la deteccion lee de aqui.
        this.detectionCanvas = document.createElement('canvas');
        this.detectionCanvas.width = FRAME_WIDTH;
        this.detectionCanvas.height = FRAME_HEIGHT;
        this.detectionCtx = this.detectionCanvas.getContext('2d', { willReadFrequently: true });
    }

    /** Carga OpenCV.js + Haar Cascade y ONNX Runtime Web + modelo, en paralelo. */
    Engine.prototype.init = async function (onProgress) {
        var self = this;
        var report = onProgress || function () {};

        var cvReady = (async function () {
            report('Cargando OpenCV.js...');
            self.cv = (await ensureCv(self.cfg)).cv;
            self.faceClassifier = await loadFaceCascade(self.cv, self.cfg.cascadeUrl);
        }());

        var modelReady = (async function () {
            report('Cargando modelo...');
            self.ort = await ensureOrt(self.cfg);
            var bytes = await fetchModelBytes(self.cfg.modelUrl);
            self.session = await self.ort.InferenceSession.create(bytes, {
                executionProviders: ['wasm'],
            });
        }());

        await Promise.all([cvReady, modelReady]);
    };

    /**
     * Dibuja el frame actual del <video> espejado en el canvas de deteccion,
     * detecta el rostro mas grande e infiere los 4 estados. Retorna:
     *   { hasFace: false }  o
     *   { hasFace: true, faceBox, topState, topLevel, topProb, aiScore,
     *     breakdown: [{state, level, prob}], probabilities: {state: [p0..p3]},
     *     emotion (nombre en BD), inferenceMs }
     */
    Engine.prototype.analyze = async function (video) {
        var cv = this.cv;
        drawMirroredVideo(this.detectionCtx, video, FRAME_WIDTH, FRAME_HEIGHT);

        var src = cv.imread(this.detectionCanvas); // RGBA
        var gray = new cv.Mat();
        var faces = new cv.RectVector();
        try {
            cv.cvtColor(src, gray, cv.COLOR_RGBA2GRAY);
            this.faceClassifier.detectMultiScale(gray, faces, 1.1, 5, 0, new cv.Size(60, 60));

            if (faces.size() === 0) {
                return { hasFace: false };
            }

            var best = faces.get(0);
            for (var i = 1; i < faces.size(); i++) {
                var r = faces.get(i);
                if (r.width * r.height > best.width * best.height) {
                    best = r;
                }
            }

            var t0 = performance.now();
            var inputTensor = matRegionToInputTensor(cv, this.ort, src, best);
            var feeds = {};
            feeds[this.session.inputNames[0]] = inputTensor;
            var outputs = await this.session.run(feeds);
            var inferenceMs = performance.now() - t0;

            var predsInfo = {};
            var probabilities = {};
            var maxScore = -1.0;
            var dominantState = null;
            AFFECT_STATES.forEach(function (state) {
                var probs = softmax(Array.from(outputs[state].data));
                var predLevel = 0;
                for (var k = 1; k < probs.length; k++) {
                    if (probs[k] > probs[predLevel]) {
                        predLevel = k;
                    }
                }
                predsInfo[state] = { level: predLevel, prob: probs[predLevel] };
                probabilities[state] = probs;

                var score = predLevel + probs[predLevel];
                if (score > maxScore) {
                    maxScore = score;
                    dominantState = state;
                }
            });

            return {
                hasFace: true,
                faceBox: { x: best.x, y: best.y, width: best.width, height: best.height },
                topState: dominantState,
                emotion: STATE_TO_DB_NAME[dominantState],
                topLevel: predsInfo[dominantState].level,
                topProb: predsInfo[dominantState].prob,
                aiScore: computeAttentivenessIndex(
                    predsInfo.Boredom.level,
                    predsInfo.Engagement.level,
                    predsInfo.Confusion.level,
                    predsInfo.Frustration.level
                ),
                breakdown: AFFECT_STATES.map(function (s) {
                    return { state: s, level: predsInfo[s].level, prob: predsInfo[s].prob * 100.0 };
                }),
                probabilities: probabilities,
                // Entropy (Emotion_Record.entropy): el modelo tiene 4 cabezas DAiSEE
                // independientes (una softmax de 4 niveles por emocion), no un vector sobre las
                // 4 emociones. Se usa la entropia normalizada de la cabeza de la emocion
                // seleccionada sobre sus 4 niveles de intensidad (la misma cabeza de la que sale
                // confidence = topProb). La de cada cabeza queda en entropyByState.
                entropy: shannonEntropy(probabilities[dominantState]),
                entropyByState: AFFECT_STATES.reduce(function (acc, s) {
                    acc[s] = shannonEntropy(probabilities[s]);
                    return acc;
                }, {}),
                inferenceMs: inferenceMs,
            };
        } finally {
            src.delete();
            gray.delete();
            faces.delete();
        }
    };

    // ── Dibujo (identico a drawFrame/drawDiagnosticPanel de app.js) ─────

    // Dibuja el frame de video espejado horizontalmente (cv2.flip(frame, 1)).
    function drawMirroredVideo(ctx, video, w, h) {
        ctx.save();
        ctx.translate(w, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(video, 0, 0, w, h);
        ctx.restore();
    }

    function drawFaceBox(ctx, result, w) {
        if (!result || !result.hasFace) {
            return;
        }
        var s = w / FRAME_WIDTH;
        var b = result.faceBox;
        ctx.strokeStyle = '#00ff00';
        ctx.lineWidth = 2;
        ctx.strokeRect(b.x * s, b.y * s, b.width * s, b.height * s);
    }

    // Esquina SUPERIOR DERECHA: panel de diagnostico de la app de referencia.
    // Se dibuja en coordenadas de 640x480; el canvas destino debe tener ese
    // tamano interno (se escala por CSS).
    function drawDiagnosticPanel(ctx, result, modelLabel, modelFile) {
        var boxWidth = 360;
        var boxHeight = 250;
        var x1 = FRAME_WIDTH - boxWidth - 15;
        var y1 = 15;

        ctx.fillStyle = 'rgba(20,20,20,0.75)';
        ctx.fillRect(x1, y1, boxWidth, boxHeight);
        ctx.strokeStyle = '#ffff00';
        ctx.lineWidth = 2;
        ctx.strokeRect(x1, y1, boxWidth, boxHeight);

        ctx.textBaseline = 'alphabetic';

        ctx.fillStyle = '#ffff00';
        ctx.font = 'bold 15px Consolas, monospace';
        ctx.fillText('Modelo: ' + modelLabel, x1 + 10, y1 + 25);

        ctx.fillStyle = '#b4b4b4';
        ctx.font = '11px Consolas, monospace';
        ctx.fillText('[' + modelFile.slice(0, 28) + ']', x1 + 10, y1 + 42);

        if (result && result.hasFace) {
            ctx.fillStyle = '#ffffff';
            ctx.font = '13px Consolas, monospace';
            ctx.fillText('Emocion : ' + result.topState + ' (' + STATE_SPANISH[result.topState] + ')',
                x1 + 10, y1 + 68);

            ctx.fillStyle = '#00ff00';
            ctx.fillText('Nivel   : ' + LEVEL_NAMES[result.topLevel], x1 + 10, y1 + 92);

            ctx.fillStyle = '#00d7ff';
            ctx.fillText('Probab. : ' + (result.topProb * 100).toFixed(1) + '%', x1 + 10, y1 + 116);

            ctx.fillStyle = '#ffb400';
            ctx.font = 'bold 13px Consolas, monospace';
            ctx.fillText('Indice Atencion: ' + result.aiScore.toFixed(2) + ' / 9.0', x1 + 10, y1 + 140);

            var lineY = y1 + 162;
            ctx.strokeStyle = '#969696';
            ctx.beginPath();
            ctx.moveTo(x1 + 10, lineY);
            ctx.lineTo(x1 + boxWidth - 10, lineY);
            ctx.stroke();

            ctx.font = '12px Consolas, monospace';
            result.breakdown.forEach(function (row) {
                lineY += 18;
                ctx.fillStyle = row.state === result.topState ? '#00ff00' : '#c8c8c8';
                ctx.fillText(row.state.slice(0, 4) + ': Nivel ' + row.level + ' (' + row.prob.toFixed(0) + '%)',
                    x1 + 10, lineY);
            });
        } else {
            ctx.fillStyle = '#ff3232';
            ctx.font = '16px Consolas, monospace';
            ctx.fillText('Buscando rostro...', x1 + 10, y1 + 100);
        }
    }

    /**
     * Dibuja video espejado + recuadro del rostro en `canvas`. Con
     * withPanel=true agrega el panel de diagnostico completo (vista grande);
     * sin el, solo video + recuadro (vista compacta del widget flotante).
     */
    Engine.prototype.render = function (canvas, video, result, withPanel) {
        var ctx = canvas.getContext('2d');
        drawMirroredVideo(ctx, video, canvas.width, canvas.height);
        drawFaceBox(ctx, result, canvas.width);
        if (withPanel) {
            drawDiagnosticPanel(ctx, result, this.modelLabel, this.modelFile);
        }
    };

    window.EmotionsFerEngine = {
        Engine: Engine,
        AFFECT_STATES: AFFECT_STATES,
        STATE_SPANISH: STATE_SPANISH,
        STATE_TO_DB_NAME: STATE_TO_DB_NAME,
        LEVEL_NAMES: LEVEL_NAMES,
        FRAME_WIDTH: FRAME_WIDTH,
        FRAME_HEIGHT: FRAME_HEIGHT,
    };
}());

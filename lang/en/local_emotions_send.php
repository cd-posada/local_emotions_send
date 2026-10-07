<?php
$string['pluginname'] = 'Emotions Send';

// Capabilities.
$string['emotions_send:viewreport']  = 'Ver el reporte de emociones del curso';
$string['emotions_send:managelogs']  = 'Eliminar los registros de emociones del curso';

// Perfil extendido.
$string['profile:title']            = 'Completa tu perfil';
$string['profile:intro']            = 'Esta informacion se usa unicamente con fines academicos del estudio de atencion. Puedes actualizarla cuando quieras.';
$string['profile:gender']           = 'Genero';
$string['profile:gender_female']    = 'Femenino';
$string['profile:gender_male']      = 'Masculino';
$string['profile:gender_other']     = 'Otro';
$string['profile:gender_na']        = 'Prefiero no decirlo';
$string['profile:birthdate']        = 'Fecha de nacimiento';
$string['profile:level']            = 'Nivel academico';
$string['profile:program']          = 'Programa academico';
$string['profile:nativelanguages']  = 'Idioma(s) nativo(s)';
$string['profile:otherlanguages']   = 'Otro(s) idioma(s) que hablas';
$string['profile:newlanguage']      = 'Agregar un idioma que no aparece en la lista';
$string['profile:preferredlanguage'] = 'Idioma preferido para la interfaz';
$string['profile:none']             = 'Ninguno';
$string['profile:contextheader']    = 'Contexto de estudio';
$string['profile:ctx_device']       = 'Dispositivo (ej: laptop, celular)';
$string['profile:ctx_connection']   = 'Conexion (ej: wifi, datos moviles)';
$string['profile:ctx_modality']     = 'Modalidad';
$string['profile:ctx_modality_presencial'] = 'Presencial';
$string['profile:ctx_modality_virtual']    = 'Virtual';
$string['profile:ctx_modality_hibrido']    = 'Hibrido';
$string['profile:save']             = 'Guardar perfil';
$string['profile:saved']            = 'Tu perfil se guardo correctamente.';
$string['profile:error_futuredate'] = 'La fecha de nacimiento no puede ser en el futuro.';
$string['profile:completebanner']   = 'Aun no has completado tu perfil academico. <a href="{$a}">Completalo aqui</a> para que tus registros de atencion queden asociados correctamente.';

// Privacy API.
$string['privacy:metadata'] = 'Este plugin almacena el estado emocional detectado del usuario durante sesiones de aprendizaje, junto con datos de perfil academico usados para el analisis del estudio. La deteccion facial se hace completamente en el navegador del usuario: los fotogramas de la camara no se envian ni a Moodle ni a ningun servicio externo.';

$string['privacy:metadata:local_emotions_session'] = 'Sesiones de aprendizaje (Learning_Session): periodos de actividad del usuario en un curso.';
$string['privacy:metadata:local_emotions_session:userid'] = 'Usuario dueño de la sesion.';
$string['privacy:metadata:local_emotions_session:courseid'] = 'Curso donde ocurrio la sesion.';
$string['privacy:metadata:local_emotions_session:startedat'] = 'Fecha y hora de inicio de la sesion.';
$string['privacy:metadata:local_emotions_session:endedat'] = 'Fecha y hora de cierre de la sesion.';
$string['privacy:metadata:local_emotions_session:closurereason'] = 'Razon de cierre de la sesion (Logout, Timeout, Abandonment).';
$string['privacy:metadata:local_emotions_session:devicetype'] = 'Tipo de dispositivo usado (Desktop, Tablet, Mobile).';
$string['privacy:metadata:local_emotions_session:viewport'] = 'Dimensiones de pantalla usadas durante la sesion.';

$string['privacy:metadata:local_emotions_log'] = 'Registros de deteccion de emociones (Emotion_Record), ligados a una sesion de aprendizaje.';
$string['privacy:metadata:local_emotions_log:sessionid'] = 'Sesion de aprendizaje a la que pertenece esta deteccion.';
$string['privacy:metadata:local_emotions_log:emotionid'] = 'Emocion detectada, referenciada al catalogo de emociones.';
$string['privacy:metadata:local_emotions_log:actcontextid'] = 'Actividad especifica del curso donde ocurrio la deteccion.';
$string['privacy:metadata:local_emotions_log:infmodelid'] = 'Modelo de inferencia (IA) que produjo esta deteccion.';
$string['privacy:metadata:local_emotions_log:intensitydaisee'] = 'Intensidad de la emocion en escala DAiSEE (0-3), si aplica.';
$string['privacy:metadata:local_emotions_log:confidence'] = 'Nivel de confianza de la deteccion (0 a 1).';
$string['privacy:metadata:local_emotions_log:classprobabilities'] = 'Distribucion completa de probabilidad por clase emocional.';
$string['privacy:metadata:local_emotions_log:entropy'] = 'Medida de incertidumbre de la prediccion.';
$string['privacy:metadata:local_emotions_log:timecreated'] = 'Marca de tiempo de la deteccion.';

$string['privacy:metadata:local_emotions_userprofile'] = 'Datos de perfil academico del usuario: genero, fecha de nacimiento y programa academico.';
$string['privacy:metadata:local_emotions_userprofile:gender'] = 'Genero declarado por el usuario.';
$string['privacy:metadata:local_emotions_userprofile:birthdate'] = 'Fecha de nacimiento declarada por el usuario.';
$string['privacy:metadata:local_emotions_userprofile:programid'] = 'Programa academico en el que esta matriculado el usuario.';

$string['privacy:metadata:local_emotions_userlang'] = 'Idiomas que habla el usuario (nativos, otros, e idioma preferido de interfaz).';
$string['privacy:metadata:local_emotions_userlang:languageid'] = 'Idioma declarado.';
$string['privacy:metadata:local_emotions_userlang:is_native'] = 'Si el idioma es nativo para el usuario.';
$string['privacy:metadata:local_emotions_userlang:is_interface_preferred'] = 'Si el usuario prefiere ver la interfaz en este idioma.';

$string['privacy:metadata:local_emotions_context'] = 'Contexto de estudio declarado por el usuario (dispositivo, conexion, modalidad).';
$string['privacy:metadata:local_emotions_context:type'] = 'Tipo de contexto declarado.';
$string['privacy:metadata:local_emotions_context:value_context'] = 'Valor del contexto declarado.';

$string['privacy:metadata:local_emotions_adaptfb'] = 'Retroalimentacion del usuario sobre una adaptacion de interfaz aplicada.';
$string['privacy:metadata:local_emotions_adaptfb:accepted'] = 'Si el usuario acepto o descarto la adaptacion.';
$string['privacy:metadata:local_emotions_adaptfb:perceivedusefulness'] = 'Utilidad percibida de la adaptacion (escala).';
$string['privacy:metadata:local_emotions_adaptfb:comment'] = 'Comentario libre del usuario sobre la adaptacion.';


// Almacenamiento (settings.php, usermodel_db).
$string['settings:storageheading']      = 'Almacenamiento de datos';
$string['settings:storageheading_desc'] = 'Donde guarda el plugin las detecciones, sesiones, perfiles, catalogos y reglas. Con "Base externa user_model_v5_1" esa base es la UNICA fuente de datos del plugin; debe existir y tener el esquema de user_model_v5.1_mysql.sql. Para verificar la conexion y cargar los catalogos: php local/emotions_send/cli/check_usermodel.php';
$string['settings:storage']             = 'Almacenamiento';
$string['settings:storage_desc']        = 'Cambiar de almacenamiento NO migra los datos existentes.';
$string['settings:storage_moodle']      = 'Base de datos de Moodle (tablas local_emotions_*)';
$string['settings:storage_usermodel']   = 'Base externa user_model_v5_1 (MySQL/MariaDB)';
$string['settings:extdb_host']          = 'Servidor de la base externa';
$string['settings:extdb_port']          = 'Puerto';
$string['settings:extdb_name']          = 'Nombre de la base';
$string['settings:extdb_user']          = 'Usuario';
$string['settings:extdb_pass']          = 'Contrasena';
$string['usermodel:connectionerror']    = 'No se pudo conectar a la base externa user_model_v5_1 ({$a}).';
$string['usermodel:queryerror']         = 'Error en la base externa user_model_v5_1: {$a}';
$string['privacy:metadata:user_model_v5_1'] = 'Con el almacenamiento externo activo, los datos del plugin (perfil, idiomas, contexto, sesiones de aprendizaje y detecciones de emocion) se guardan en la base de datos user_model_v5_1, fuera de Moodle.';
$string['privacy:metadata:user_model_v5_1:name']      = 'Nombres del usuario (copiados de Moodle).';
$string['privacy:metadata:user_model_v5_1:last_name'] = 'Apellidos del usuario (copiados de Moodle).';
$string['privacy:metadata:user_model_v5_1:email']     = 'Correo del usuario (copiado de Moodle; identifica al usuario en la base externa).';
$string['privacy:metadata:user_model_v5_1:usuario']   = 'Nombre de usuario de Moodle (columna opcional usuario de User).';
$string['privacy:metadata:user_model_v5_1:gender']    = 'Genero declarado.';
$string['privacy:metadata:user_model_v5_1:birth_date'] = 'Fecha de nacimiento declarada.';
$string['privacy:metadata:user_model_v5_1:emotion_record'] = 'Detecciones de emocion (emocion, confianza, intensidad, probabilidades, fecha) de cada sesion de aprendizaje.';
$string['privacy:metadata:user_model_v5_1:registro_json'] = 'Log de sesion del navegador (entradas por segundo y por ventana: emocion, nivel, probabilidad, fecha) de cada sesion de aprendizaje, en la tabla Registro_JSON.';
$string['privacy:metadata:local_emotions_extmap'] = 'Correspondencia entre el id del usuario en Moodle y su id en la base externa user_model_v5_1.';
$string['privacy:metadata:local_emotions_extmap:moodleid'] = 'Id del usuario (o curso) en Moodle.';
$string['privacy:metadata:local_emotions_extmap:extid'] = 'Id correspondiente en la base externa.';
$string['event_adaptation_decided'] = 'Decision de adaptacion de la interfaz';
$string['privacy:metadata:user_model_v5_1:latency'] = 'Marcas de tiempo en milisegundos para medir la latencia de adaptacion: desfase del reloj del navegador (Learning_Session.clock_offset_ms), ventana de observacion e inferencia (Emotion_Record) y solicitud, generacion y aplicacion de cada adaptacion (Adaptation_Event).';
$string['usermodel:pagetitle']      = 'Base externa user_model_v5_1';
$string['usermodel:pageintro']      = 'Estado del esquema de la base externa user_model_v5_1 (almacenamiento "usermodel"). El boton crea las tablas que falten, los campos de latencia, los triggers, la vista v_latencia_adaptacion y los catalogos. Es idempotente y nunca borra datos. La base y su usuario los crea el DBA; el usuario necesita permisos SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW y TRIGGER sobre ella (no necesita DROP).';
$string['usermodel:notactive']      = 'El almacenamiento configurado es "tablas de Moodle": esta base externa no se esta usando. Puede prepararla antes de cambiar el ajuste "Almacenamiento" a user_model_v5_1.';
$string['usermodel:connection']     = 'Conexion';
$string['usermodel:connected']      = 'Conectado';
$string['usermodel:missingtables']  = 'Tablas faltantes';
$string['usermodel:missingcolumns'] = 'Columnas de latencia faltantes';
$string['usermodel:triggers']       = 'Triggers de latency_total_ms';
$string['usermodel:view']           = 'Vista v_latencia_adaptacion';
$string['usermodel:ready']          = 'Esquema completo';
$string['usermodel:install']        = 'Crear / completar esquema';
$string['usermodel:installerror']   = 'No se pudo completar el esquema: {$a}. Revise la conexion y los permisos del usuario.';
$string['consent:title'] = 'Consentimiento informado: registro de expresiones faciales';
$string['consent:check'] = 'Declaro que soy mayor de 18 años, que leí y entendí este consentimiento informado, y que acepto voluntariamente participar en el estudio, el uso de la cámara de mi computador para el registro de expresiones faciales descrito y la transferencia de datos al servicio externo indicado.';
$string['consent:accept'] = 'Acepto';
$string['consent:decline'] = 'No acepto';
$string['consent:declinedtitle'] = 'No es posible continuar sin aceptar';
$string['consent:declinedtext'] = 'Este curso existe únicamente para el estudio, por lo que su contenido solo está disponible para quienes aceptan el consentimiento informado. Su decisión quedó registrada y no tiene ninguna consecuencia para usted. Si cambia de opinión, puede volver a leer el consentimiento.';
$string['consent:reread'] = 'Volver a leer el consentimiento';
$string['consent:exit'] = 'Salir del curso';
$string['consent:revoke'] = 'Retirar mi consentimiento';
$string['consent:defaulttext'] = '<p><strong>Estudio:</strong> «Modelo de interfaces de usuario adaptativas a partir del registro de expresiones faciales para mejorar la experiencia de usuario en LMS».</p>
<p><strong>Investigador principal:</strong> Carlos David Posada Fernández (cposadaf@unal.edu.co), Doctorado en Ingeniería – Sistemas e Informática, Facultad de Minas, Universidad Nacional de Colombia, sede Medellín.<br>
<strong>Directores:</strong> Prof. Albeiro Espinosa Bedoya, Ph.D.; Prof. John Willian Branch Bedoya, Ph.D.; Prof. Daniel Burgos, Ph.D.</p>

<h3>1. Propósito del estudio</h3>
<p>Evaluar si una interfaz de Moodle que se adapta a las emociones académicas detectadas a partir de la expresión facial (aburrimiento, confusión, frustración y compromiso) mejora la usabilidad del curso y reduce la carga de trabajo percibida por el estudiante.</p>

<h3>2. En qué consiste su participación</h3>
<p>Usted participará en una <strong>única sesión de 90 a 100 minutos</strong> en este curso de Moodle, que existe solo para este estudio. El curso tiene una sección introductoria con las generalidades y tres secciones sobre el tema de estudio; cada una incluye documentación (texto e imágenes), un video, un foro de discusión y un cuestionario de tres preguntas de selección múltiple. Durante la sesión responderá dos veces dos cuestionarios breves sobre su experiencia: NASA-TLX (carga de trabajo) y SUS (usabilidad).</p>
<p>Mientras trabaja en el curso, la cámara de su computador permanecerá encendida. Según las emociones detectadas, la apariencia de la interfaz <strong>puede</strong> cambiar (colores, tamaño de letra, espaciado u organización de los elementos). Cuando ocurra un cambio se le explicará el motivo, y usted podrá calificarlo, comentarlo o revertirlo. Los participantes se distribuyen en grupos con distintas configuraciones de la interfaz; por el diseño del estudio no se le informará en cuál está.</p>

<h3>3. Uso de la cámara</h3>
<p>Las imágenes de su cámara se analizan <strong>únicamente dentro de su navegador</strong>, en su propio computador. <strong>Ninguna imagen ni video se envía por internet, se graba ni se almacena</strong>: cada imagen se descarta en cuanto se calcula la emoción. El recuadro «Registro de atención», en la esquina de la pantalla, le indica que la cámara está activa.</p>

<h3>4. Datos que se registran</h3>
<ul>
<li>La emoción detectada, su intensidad, las probabilidades calculadas por el modelo y una medida de incertidumbre (entropía).</li>
<li>Fechas y horas de la sesión y de cada detección.</li>
<li>Tipo de dispositivo y tamaño de la pantalla.</li>
<li>El curso y la actividad en la que se encontraba.</li>
<li>Los cambios de la interfaz que se aplicaron y sus calificaciones o comentarios sobre ellos.</li>
<li>Sus respuestas a los cuestionarios SUS y NASA-TLX.</li>
</ul>
<p>Como en cualquier curso de Moodle, también quedan guardadas sus participaciones en el foro y sus respuestas a los cuestionarios del curso.</p>

<h3>5. Su identidad</h3>
<p>Usted ingresará con una <strong>cuenta ficticia</strong> del tipo «PersonaN» (N es un número entre 1 y 144) y con un correo electrónico también ficticio. <strong>El sistema no guarda su nombre real ni su correo electrónico real.</strong> Los datos quedan asociados a esa cuenta ficticia y, al exportarlos para el análisis, se anonimizan.</p>

<h3>6. Servicio externo</h3>
<p>Para proponer cada cambio de la interfaz, el servidor consulta un servicio de inteligencia artificial: <strong>OpenRouter, con el modelo DeepSeek, cuyos servidores están fuera de Colombia</strong>. Solo se le envía la emoción detectada y su intensidad, el tipo de actividad, la estructura de la página, el idioma, el programa académico y el nivel de formación. <strong>No se envían nombres, correos ni imágenes.</strong> Al aceptar, usted autoriza esta transferencia internacional de datos, en los términos de la Ley 1581 de 2012.</p>

<h3>7. Almacenamiento y conservación</h3>
<p>Los datos se almacenan en un servidor del Politécnico Colombiano Jaime Isaza Cadavid. <strong>Solo el investigador principal tiene acceso a ellos.</strong> Se usarán únicamente para su análisis durante el desarrollo de la tesis doctoral y dos años más; vencido ese plazo, serán eliminados. Los resultados se publicarán solo de forma agregada, sin identificar a ningún participante.</p>

<h3>8. Riesgos y beneficios</h3>
<p>El estudio es de <strong>riesgo mínimo</strong>. Las posibles molestias son tener la cámara encendida durante la sesión y que la interfaz cambie de apariencia. No hay un beneficio directo para usted; su participación contribuye al conocimiento sobre interfaces adaptativas en educación virtual. <strong>No hay compensación económica ni incentivo académico</strong> por participar.</p>

<h3>9. Participación voluntaria y retiro</h3>
<p>Su participación es <strong>voluntaria</strong>. Como este curso existe únicamente para el estudio, para continuar con su contenido debe aceptar este consentimiento; si no lo acepta, no participa en el estudio, sin ninguna consecuencia para usted.</p>
<p>Puede <strong>retirarse en cualquier momento</strong> con el enlace «Retirar mi consentimiento» del recuadro «Registro de atención». Al hacerlo, <strong>se borrarán todos los datos registrados de usted en este curso</strong> y la cámara se apagará.</p>

<h3>10. Requisitos</h3>
<p>Ser mayor de 18 años y contar con un computador con cámara web.</p>

<h3>11. Sus derechos</h3>
<p>De acuerdo con la Ley 1581 de 2012, usted puede conocer, actualizar, rectificar y solicitar la supresión de sus datos, y revocar esta autorización, escribiendo al investigador principal a cposadaf@unal.edu.co. A esa misma dirección puede dirigir cualquier pregunta sobre el estudio.</p>';
$string['settings:consentheading'] = 'Consentimiento informado';
$string['settings:consentheading_desc'] = 'En los cursos que tienen la actividad Emotions, el estudiante debe aceptar el consentimiento informado antes de que se encienda la cámara. Sin aceptación no se registra ningún dato. Si el texto cambia, se vuelve a pedir.';
$string['settings:consent_enabled'] = 'Exigir consentimiento informado';
$string['settings:consent_enabled_desc'] = 'Desactivarlo solo en ambientes de prueba.';
$string['settings:consent_mode'] = 'Si el estudiante no acepta';
$string['settings:consent_mode_desc'] = 'Con "Bloquear el contenido del curso", el bloqueo lo hace cumplir Moodle: en la actividad Emotions active la finalización "El estudiante aceptó el consentimiento informado" y restrinja las secciones del curso a que esa actividad esté completada. Los docentes no quedan bloqueados.';
$string['settings:consent_mode_block'] = 'Bloquear el contenido del curso (cursos que existen solo para el estudio)';
$string['settings:consent_mode_nocamera'] = 'Continuar en el curso sin cámara';
$string['settings:consent_text'] = 'Texto del consentimiento informado';
$string['settings:consent_text_desc'] = 'Vacío = texto por defecto del plugin (estudio de la tesis). Cualquier cambio del texto vuelve a pedir el consentimiento a todos.';
$string['privacy:metadata:local_emotions_consent'] = 'Decisión de cada usuario sobre el consentimiento informado del registro facial en cada curso.';
$string['privacy:metadata:local_emotions_consent:status'] = 'Decisión: aceptado, no aceptado o retirado.';
$string['privacy:metadata:local_emotions_consent:version'] = 'Huella del texto del consentimiento que se mostró.';
$string['privacy:metadata:local_emotions_consent:timemodified'] = 'Fecha y hora de la decisión.';

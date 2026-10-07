-- Esquema base del modelo de usuario v5.1 (user_model_v5_1) para local_emotions_send.
-- Generado a partir de user_model_v5.1_mysql.sql SIN DROP/CREATE DATABASE ni USE:
-- se ejecuta sobre la base ya creada por el DBA y solo crea las tablas que falten
-- (CREATE TABLE IF NOT EXISTS). Nunca borra datos. Lo ejecuta
-- classes/local/usermodel_schema.php (pagina de administracion o cli/setup_usermodel.php);
-- las extensiones (Registro_JSON, campos de latencia, triggers, vista) se agregan despues.
-- =====================================================================
-- Modelo de Usuario v5.1 — Versión Completa Nativa para MySQL 8.0+ / MariaDB
-- Archivo: user_model_v5.1_mysql.sql
-- Basado en: modelo_usuario_v5.1.jpg
-- =====================================================================


SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Tabla: Language
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Language` (
    `id_language`    INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del idioma',
    `code_iso_639-1` VARCHAR(10) NOT NULL UNIQUE COMMENT 'Código ISO 639-1 (ej. es, en, fr)',
    `name_language`  VARCHAR(80) NOT NULL COMMENT 'Nombre completo del idioma',
    INDEX `idx_language_code` (`code_iso_639-1`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Catálogo de idiomas del sistema';

-- ---------------------------------------------------------------------
-- 2. Tabla: User
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `User` (
    `id_user`    BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Identificador único del usuario',
    `name`       VARCHAR(120) NOT NULL COMMENT 'Nombres del usuario',
    `last_name`  VARCHAR(120) NOT NULL COMMENT 'Apellidos del usuario',
    `email`      VARCHAR(180) NOT NULL UNIQUE COMMENT 'Correo electrónico único',
    `gender`     VARCHAR(40) NULL COMMENT 'Género del usuario',
    `birth_date` DATE NULL COMMENT 'Fecha de nacimiento',
    INDEX `idx_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfil y datos demográficos del usuario';

-- ---------------------------------------------------------------------
-- 3. Tabla: User_Language
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `User_Language` (
    `id_user`                BIGINT NOT NULL COMMENT 'FK hacia User(id_user)',
    `id_language`            INT NOT NULL COMMENT 'FK hacia Language(id_language)',
    `is_native`              TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Indica si es el idioma nativo',
    `is_interface_preferred` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Indica si es la preferencia para la interfaz',
    PRIMARY KEY (`id_user`, `id_language`),
    INDEX `idx_user_lang_user` (`id_user`),
    INDEX `idx_user_lang_language` (`id_language`),
    CONSTRAINT `fk_user_language_user` 
        FOREIGN KEY (`id_user`) REFERENCES `User` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_user_language_language` 
        FOREIGN KEY (`id_language`) REFERENCES `Language` (`id_language`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Idiomas asociados al usuario';

-- ---------------------------------------------------------------------
-- 4. Tabla: Context
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Context` (
    `id_context`    BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del registro de contexto',
    `id_user`       BIGINT NOT NULL COMMENT 'FK hacia User(id_user)',
    `type`          VARCHAR(60) NOT NULL COMMENT 'Categoría o tipo de contexto',
    `value_context` TEXT NOT NULL COMMENT 'Valor del parámetro contextual',
    INDEX `idx_context_user` (`id_user`),
    INDEX `idx_context_type` (`type`),
    CONSTRAINT `fk_context_user` 
        FOREIGN KEY (`id_user`) REFERENCES `User` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Variables de contexto asociadas al usuario';

-- ---------------------------------------------------------------------
-- 5. Tabla: Academic_Level
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Academic_Level` (
    `id_academic_level` INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del nivel académico',
    `value`             VARCHAR(100) NOT NULL COMMENT 'Descripción del nivel'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Niveles de escolaridad/estudio';

-- ---------------------------------------------------------------------
-- 6. Tabla: Academic_Program
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Academic_Program` (
    `id_academic_program`   INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del programa académico',
    `id_academic_level`     INT NOT NULL COMMENT 'FK hacia Academic_Level(id_academic_level)',
    `name_academic_program` VARCHAR(160) NOT NULL COMMENT 'Nombre del programa académico',
    `faculty`               VARCHAR(160) NULL COMMENT 'Facultad o departamento',
    INDEX `idx_program_level` (`id_academic_level`),
    CONSTRAINT `fk_program_level` 
        FOREIGN KEY (`id_academic_level`) REFERENCES `Academic_Level` (`id_academic_level`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Programas o carreras universitarias';

-- ---------------------------------------------------------------------
-- 7. Tabla: Course
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Course` (
    `id_course`           INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del curso',
    `id_academic_program` INT NOT NULL COMMENT 'FK hacia Academic_Program(id_academic_program)',
    `name_course`         VARCHAR(180) NOT NULL COMMENT 'Nombre del curso/materia',
    INDEX `idx_course_program` (`id_academic_program`),
    CONSTRAINT `fk_course_program` 
        FOREIGN KEY (`id_academic_program`) REFERENCES `Academic_Program` (`id_academic_program`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Asignaturas ofertadas en la plataforma LMS';

-- ---------------------------------------------------------------------
-- 8. Tabla: Learning_Session
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Learning_Session` (
    `id_learning_sesion`  BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único de la sesión de interacción',
    `id_user`             BIGINT NOT NULL COMMENT 'FK hacia User(id_user)',
    `id_course`           INT NOT NULL COMMENT 'FK hacia Course(id_course)',
    `started_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha/hora de inicio',
    `ended_at`            DATETIME NULL COMMENT 'Fecha/hora de cierre',
    `closure_reason`      VARCHAR(80) NULL COMMENT 'Razón de cierre',
    `device_type`         VARCHAR(60) NULL COMMENT 'Tipo de dispositivo',
    `viewport`            VARCHAR(40) NULL COMMENT 'Dimensiones de visualización',
    `fer_capture_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Flag de captura FER activa',
    INDEX `idx_session_user` (`id_user`),
    INDEX `idx_session_course` (`id_course`),
    CONSTRAINT `fk_session_user` 
        FOREIGN KEY (`id_user`) REFERENCES `User` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_session_course` 
        FOREIGN KEY (`id_course`) REFERENCES `Course` (`id_course`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sesiones de aprendizaje del estudiante';

-- ---------------------------------------------------------------------
-- 9. Tabla: Activity_context
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Activity_context` (
    `id_activity_context` BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del contexto de actividad',
    `id_course`           INT NOT NULL COMMENT 'FK hacia Course(id_course)',
    `module`              VARCHAR(100) NULL COMMENT 'Módulo temático',
    `activity_type`       VARCHAR(60) NOT NULL COMMENT 'Tipo de recurso/actividad',
    `is_assessed`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Indica si es evaluada',
    INDEX `idx_activity_course` (`id_course`),
    CONSTRAINT `fk_activity_course` 
        FOREIGN KEY (`id_course`) REFERENCES `Course` (`id_course`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Entorno pedagógico de las actividades';

-- ---------------------------------------------------------------------
-- 10. Tabla: Emotional_model
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Emotional_model` (
    `id_emotional_model` INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del modelo emocional',
    `model_description`  TEXT NOT NULL COMMENT 'Descripción del modelo afectivo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Fundamento teórico del análisis afectivo';

-- ---------------------------------------------------------------------
-- 11. Tabla: Norman_Level
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Norman_Level` (
    `id_norman_level`    INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del nivel de Norman',
    `id_emotional_model` INT NOT NULL COMMENT 'FK hacia Emotional_model(id_emotional_model)',
    `code`               VARCHAR(40) NOT NULL COMMENT 'Código del nivel',
    `description_level`  TEXT NULL COMMENT 'Detalle del procesamiento afectivo',
    INDEX `idx_norman_model` (`id_emotional_model`),
    CONSTRAINT `fk_norman_emotional_model` 
        FOREIGN KEY (`id_emotional_model`) REFERENCES `Emotional_model` (`id_emotional_model`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Niveles de procesamiento de diseño y emoción (Norman)';

-- ---------------------------------------------------------------------
-- 12. Tabla: Inference_Model
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Inference_Model` (
    `id_inference_model` INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del modelo de inferencia',
    `name`               VARCHAR(120) NOT NULL COMMENT 'Nombre del modelo',
    `version`            VARCHAR(30) NOT NULL COMMENT 'Versión del modelo',
    `architecture`       VARCHAR(120) NULL COMMENT 'Arquitectura subyacente',
    `training_dataset`   VARCHAR(120) NULL COMMENT 'Dataset empleado',
    `macro_f1`           DECIMAL(5,4) NULL COMMENT 'Macro F1 Score',
    `calibration_method` VARCHAR(80) NULL COMMENT 'Método de calibración',
    `per_class_f1`       JSON NULL COMMENT 'F1 Score por clase',
    `calibration_error`  DECIMAL(5,4) NULL COMMENT 'Error de calibración',
    INDEX `idx_inference_name_ver` (`name`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Modelos de Deep Learning FER';

-- ---------------------------------------------------------------------
-- 13. Tabla: Emotion
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Emotion` (
    `id_emotion`      INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único de la emoción',
    `code`            VARCHAR(40) NOT NULL UNIQUE COMMENT 'Código único',
    `name_emotion`    VARCHAR(80) NOT NULL COMMENT 'Nombre en lenguaje natural',
    `taxonomy_source` VARCHAR(60) NULL COMMENT 'Taxonomía o fuente',
    `valence`         TINYINT NULL COMMENT 'Valencia afectiva',
    `arousal`         TINYINT NULL COMMENT 'Nivel de activación',
    INDEX `idx_emotion_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Emociones y métricas dimensionales';

-- ---------------------------------------------------------------------
-- 14. Tabla: Rule
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Rule` (
    `id_rule`         INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único de la regla',
    `id_norman_level` INT NOT NULL COMMENT 'FK hacia Norman_Level(id_norman_level)',
    `id_emotion`      INT NOT NULL COMMENT 'FK hacia Emotion(id_emotion)',
    `ui_dimension`    VARCHAR(60) NOT NULL COMMENT 'Dimensión UI objetivo',
    `rule_statement`  TEXT NOT NULL COMMENT 'Enunciado de la regla adaptativa',
    INDEX `idx_rules_norman` (`id_norman_level`),
    INDEX `idx_rules_emotion` (`id_emotion`),
    CONSTRAINT `fk_rules_norman` 
        FOREIGN KEY (`id_norman_level`) REFERENCES `Norman_Level` (`id_norman_level`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rules_emotion` 
        FOREIGN KEY (`id_emotion`) REFERENCES `Emotion` (`id_emotion`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Reglas de adaptación de la interfaz';

-- ---------------------------------------------------------------------
-- 15. Tabla: Emotion_Record
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Emotion_Record` (
    `id_emotion_record`   BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del registro de inferencia',
    `id_learning_session` BIGINT NOT NULL COMMENT 'FK hacia Learning_Session(id_learning_sesion)',
    `id_emotion`          INT NOT NULL COMMENT 'FK hacia Emotion(id_emotion)',
    `id_activity_context` BIGINT NOT NULL COMMENT 'FK hacia Activity_context(id_activity_context)',
    `id_inference_model`  INT NOT NULL COMMENT 'FK hacia Inference_Model(id_inference_model)',
    `date`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Marca de tiempo',
    `intensity_daisee`    TINYINT NULL COMMENT 'Intensidad ordinal (0 a 3)',
    `confidence`          DECIMAL(5,4) NOT NULL COMMENT 'Confianza estadística',
    `source_model`        VARCHAR(120) NULL COMMENT 'Identificador del modelo en runtime',
    `class_probabilities` JSON NULL COMMENT 'Probabilidades por clase',
    `entropy`             DECIMAL(5,4) NULL COMMENT 'Entropía / incertidumbre',
    INDEX `idx_record_session` (`id_learning_session`),
    INDEX `idx_record_emotion` (`id_emotion`),
    INDEX `idx_record_activity` (`id_activity_context`),
    INDEX `idx_record_inference` (`id_inference_model`),
    CONSTRAINT `fk_record_session` 
        FOREIGN KEY (`id_learning_session`) REFERENCES `Learning_Session` (`id_learning_sesion`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_record_emotion` 
        FOREIGN KEY (`id_emotion`) REFERENCES `Emotion` (`id_emotion`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_record_activity` 
        FOREIGN KEY (`id_activity_context`) REFERENCES `Activity_context` (`id_activity_context`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_record_inference` 
        FOREIGN KEY (`id_inference_model`) REFERENCES `Inference_Model` (`id_inference_model`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Capturas de estado emocional en tiempo real';

-- ---------------------------------------------------------------------
-- 16. Tabla: Reference_Observation
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Reference_Observation` (
    `id_reference_observation` BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único de la observación',
    `id_learning_session`     BIGINT NOT NULL COMMENT 'FK hacia Learning_Session(id_learning_sesion)',
    `id_emotion`              INT NOT NULL COMMENT 'FK hacia Emotion(id_emotion)',
    `observed_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp de observación',
    `method`                  VARCHAR(60) NOT NULL COMMENT 'Método de recolección',
    `observer`                VARCHAR(120) NULL COMMENT 'Identificador del observador',
    `intensity`               TINYINT NULL COMMENT 'Intensidad observada',
    INDEX `idx_obs_session` (`id_learning_session`),
    INDEX `idx_obs_emotion` (`id_emotion`),
    CONSTRAINT `fk_obs_session` 
        FOREIGN KEY (`id_learning_session`) REFERENCES `Learning_Session` (`id_learning_sesion`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_obs_emotion` 
        FOREIGN KEY (`id_emotion`) REFERENCES `Emotion` (`id_emotion`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Observaciones manuales o autoinformes de referencia';

-- ---------------------------------------------------------------------
-- 17. Tabla: Adaptation_Event
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Adaptation_Event` (
    `id_adaptation_event`    BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del evento de adaptación v5.1',
    `id_emotion_record`      BIGINT NOT NULL COMMENT 'FK hacia Emotion_Record(id_emotion_record)',
    `id_learning_session`    BIGINT NOT NULL COMMENT 'FK hacia Learning_Session(id_learning_sesion)',
    `tokens_before`          JSON NULL COMMENT 'Estado de tokens UI previo',
    `tokens_after`           JSON NULL COMMENT 'Estado de tokens UI resultante',
    `timestamp`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Momento de decisión',
    `experimiental_condition` VARCHAR(60) NULL COMMENT 'Grupo experimental',
    `decision_state`         VARCHAR(60) NOT NULL COMMENT 'Estado de la decisión',
    `gate_reason`            VARCHAR(180) NULL COMMENT 'Razón de filtrado/gating',
    `session_moment`         VARCHAR(60) NULL COMMENT 'Fase de la sesión',
    `magnitud`               DECIMAL(8,4) NULL COMMENT 'Magnitud de modificación',
    `reverted_at`            DATETIME NULL COMMENT 'Fecha/hora de reversión',
    INDEX `idx_event_record` (`id_emotion_record`),
    INDEX `idx_event_session` (`id_learning_session`),
    CONSTRAINT `fk_event_emotion_record` 
        FOREIGN KEY (`id_emotion_record`) REFERENCES `Emotion_Record` (`id_emotion_record`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_event_session` 
        FOREIGN KEY (`id_learning_session`) REFERENCES `Learning_Session` (`id_learning_sesion`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Eventos centrales de adaptación y compuerta de decisión (Gating)';

-- ---------------------------------------------------------------------
-- 18. Tabla: Adaptation_Feedback
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Adaptation_Feedback` (
    `id_feedback`          BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del feedback',
    `id_adaptation_event`  BIGINT NOT NULL UNIQUE COMMENT 'FK hacia Adaptation_Event(id_adaptation_event)',
    `accepted`             TINYINT(1) NULL COMMENT '¿El usuario aceptó la adaptación?',
    `preceived_usefulness` TINYINT NULL COMMENT 'Valoración de utilidad Likert',
    `comment`              TEXT NULL COMMENT 'Comentarios del estudiante',
    CONSTRAINT `fk_feedback_event` 
        FOREIGN KEY (`id_adaptation_event`) REFERENCES `Adaptation_Event` (`id_adaptation_event`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Retroalimentación explícita sobre adaptaciones';

-- ---------------------------------------------------------------------
-- 19. Tabla: Adaptation_Change
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `Adaptation_Change` (
    `id_adaptatio_change` BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID único del cambio de token',
    `id_adaptation_event` BIGINT NOT NULL COMMENT 'FK hacia Adaptation_Event(id_adaptation_event)',
    `id_rule`             INT NOT NULL COMMENT 'FK hacia Rule(id_rule)',
    `ui_dimension`        VARCHAR(60) NOT NULL COMMENT 'Dimensión UI',
    `target_token`        VARCHAR(120) NOT NULL COMMENT 'Nombre del token UI modificado',
    `value_before`        TEXT NULL COMMENT 'Valor previo',
    `value_after`         TEXT NULL COMMENT 'Nuevo valor aplicado',
    INDEX `idx_change_event` (`id_adaptation_event`),
    INDEX `idx_change_rule` (`id_rule`),
    CONSTRAINT `fk_change_event` 
        FOREIGN KEY (`id_adaptation_event`) REFERENCES `Adaptation_Event` (`id_adaptation_event`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_change_rule` 
        FOREIGN KEY (`id_rule`) REFERENCES `Rule` (`id_rule`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Micro-cambios ejecutados en la interfaz de usuario';

SET FOREIGN_KEY_CHECKS = 1;

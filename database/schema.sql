SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS sync_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    demo_session_id CHAR(36) NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    source_system VARCHAR(80) NOT NULL DEFAULT 'demo_shop',
    target_system VARCHAR(80) NOT NULL DEFAULT 'demo_erp',
    status ENUM('queued','processing','succeeded','retrying','failed','dead_letter') NOT NULL DEFAULT 'queued',
    payload_json LONGTEXT NOT NULL,
    normalized_payload_json LONGTEXT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NULL,
    locked_at DATETIME(6) NULL,
    locked_by VARCHAR(100) NULL,
    last_error_code VARCHAR(80) NULL,
    last_error_message TEXT NULL,
    received_at DATETIME(6) NOT NULL,
    processing_started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_events_uuid (uuid),
    UNIQUE KEY uq_sync_events_idempotency (idempotency_key),
    KEY idx_sync_events_due (status, next_attempt_at, id),
    KEY idx_sync_events_session (demo_session_id, created_at),
    KEY idx_sync_events_type (event_type, created_at),
    KEY idx_sync_events_locked (status, locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NOT NULL,
    outcome ENUM('succeeded','retryable_error','permanent_error') NOT NULL,
    request_json LONGTEXT NULL,
    response_code SMALLINT UNSIGNED NULL,
    response_json LONGTEXT NULL,
    error_code VARCHAR(80) NULL,
    error_message TEXT NULL,
    duration_ms INT UNSIGNED NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_attempt_number (event_id, attempt_number),
    KEY idx_sync_attempts_event (event_id, created_at),
    CONSTRAINT fk_sync_attempts_event
        FOREIGN KEY (event_id) REFERENCES sync_events (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_type ENUM('customer','order') NOT NULL,
    external_id VARCHAR(120) NOT NULL,
    source_event_id BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    synchronized_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_target_record (record_type, external_id),
    KEY idx_target_records_event (source_event_id),
    KEY idx_target_records_synced (synchronized_at),
    CONSTRAINT fk_target_records_event
        FOREIGN KEY (source_event_id) REFERENCES sync_events (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NULL,
    demo_session_id CHAR(36) NULL,
    action VARCHAR(80) NOT NULL,
    actor_type ENUM('system','worker','visitor','api_client') NOT NULL,
    actor_identifier VARCHAR(120) NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NULL,
    metadata_json LONGTEXT NULL,
    ip_hash CHAR(64) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_event (event_id, created_at),
    KEY idx_audit_session (demo_session_id, created_at),
    KEY idx_audit_action (action, created_at),
    CONSTRAINT fk_audit_event
        FOREIGN KEY (event_id) REFERENCES sync_events (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    bucket_key CHAR(64) NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bucket_key),
    KEY idx_rate_limit_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


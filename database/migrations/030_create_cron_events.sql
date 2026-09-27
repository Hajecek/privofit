-- 030_create_cron_events.sql
-- Fronta cron událostí. Klíč drží idempotenci, publikum odděluje zákazníka od administrace.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cron_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(190) NOT NULL,
    audience ENUM('user', 'admin') NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    payload_json MEDIUMTEXT DEFAULT NULL,
    status ENUM('pending', 'dispatching', 'dispatched', 'skipped', 'failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    scheduled_at DATETIME NOT NULL,
    dispatched_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cron_events_key (event_key),
    KEY idx_cron_events_due (status, scheduled_at),
    KEY idx_cron_events_user (user_id),
    CONSTRAINT fk_cron_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

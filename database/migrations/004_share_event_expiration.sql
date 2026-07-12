BEGIN;

ALTER TABLE share_events
    ADD COLUMN IF NOT EXISTS expires_in VARCHAR(10) NOT NULL DEFAULT '24h',
    ADD COLUMN IF NOT EXISTS first_accessed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_share_events_expires_at ON share_events(expires_at);

COMMIT;

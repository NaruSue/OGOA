BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS account_display_name VARCHAR(120),
    ADD COLUMN IF NOT EXISTS account_bio TEXT;

ALTER TABLE profiles
    ADD COLUMN IF NOT EXISTS public_token VARCHAR(64),
    ADD COLUMN IF NOT EXISTS profile_name VARCHAR(120);

UPDATE profiles
SET public_token = md5(id::text || random()::text || clock_timestamp()::text)
WHERE public_token IS NULL OR public_token = '';

UPDATE profiles
SET profile_name = display_name
WHERE profile_name IS NULL OR profile_name = '';

ALTER TABLE profiles
    ALTER COLUMN public_token SET NOT NULL,
    ALTER COLUMN profile_name SET NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_profiles_public_token ON profiles(public_token);

COMMIT;

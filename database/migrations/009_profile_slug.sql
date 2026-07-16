BEGIN;

ALTER TABLE profiles
    ADD COLUMN IF NOT EXISTS slug TEXT;

WITH slug_source AS (
    SELECT
        id,
        COALESCE(
            NULLIF(
                lower(
                    regexp_replace(
                        regexp_replace(coalesce(nullif(profile_name, ''), nullif(display_name, ''), 'profile'), '[^a-z0-9]+', '-', 'gi'),
                        '(^-|-$)',
                        '',
                        'g'
                    )
                ),
                ''
            ),
            'profile'
        ) AS base_slug
    FROM profiles
)
UPDATE profiles AS p
SET slug = CASE
    WHEN p.slug IS NOT NULL AND p.slug <> '' THEN p.slug
    WHEN EXISTS (
        SELECT 1
        FROM profiles AS p2
        WHERE p2.slug = slug_source.base_slug
          AND p2.id <> p.id
    ) THEN slug_source.base_slug || '-' || p.id::text
    ELSE slug_source.base_slug
END
FROM slug_source
WHERE p.id = slug_source.id
  AND (p.slug IS NULL OR p.slug = '');

ALTER TABLE profiles
    ALTER COLUMN slug SET NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_profiles_slug ON profiles(slug);

COMMIT;
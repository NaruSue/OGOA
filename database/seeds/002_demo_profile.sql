WITH demo_user AS (
    INSERT INTO users (google_sub, email, name, avatar_url, role)
    VALUES ('demo-sub', 'demo@1g1a.local', '1G1A Demo', NULL, 'user')
    ON CONFLICT (google_sub) DO UPDATE
        SET name = EXCLUDED.name,
            email = EXCLUDED.email
    RETURNING id
),
demo_profile AS (
    INSERT INTO profiles (user_id, slug, public_token, profile_name, display_name, bio, headline, is_public)
    SELECT id, 'demo-preview-1g1a', 'demo-preview-1g1a', 'デモ用', '1G1A Demo Profile', '1G1A の初期デモプロフィールです。QR からの導線や SNS 表示を確認できます。', '名刺代わりの共有ページ', TRUE
    FROM demo_user
    ON CONFLICT (slug) DO UPDATE
        SET display_name = EXCLUDED.display_name,
            profile_name = EXCLUDED.profile_name,
            bio = EXCLUDED.bio,
            headline = EXCLUDED.headline,
            is_public = EXCLUDED.is_public,
            public_token = EXCLUDED.public_token
    RETURNING id
)
INSERT INTO profile_sns (profile_id, sns_type_id, label, url, sort_order, is_primary)
SELECT demo_profile.id, sns_types.id, 'Official site', 'https://example.com', 10, TRUE
FROM demo_profile
JOIN sns_types ON sns_types.code = 'website'
ON CONFLICT (profile_id, sns_type_id) DO UPDATE
    SET label = EXCLUDED.label,
        url = EXCLUDED.url,
        sort_order = EXCLUDED.sort_order,
        is_primary = EXCLUDED.is_primary;

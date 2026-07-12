INSERT INTO sns_types (code, name, sort_order) VALUES
    ('x', 'X', 10),
    ('instagram', 'Instagram', 20),
    ('facebook', 'Facebook', 30),
    ('threads', 'Threads', 40),
    ('tiktok', 'TikTok', 50),
    ('youtube', 'YouTube', 60),
    ('line', 'LINE', 70),
    ('github', 'GitHub', 80),
    ('website', 'Website', 90),
    ('other', 'Other', 100)
ON CONFLICT (code) DO UPDATE SET
    name = EXCLUDED.name,
    sort_order = EXCLUDED.sort_order;

-- Đa ngôn ngữ cho combos và combo_groups (giống products/categories).
-- name  ->  name_vi ; thêm name_en, name_zh.
-- Chạy 1 lần. Muốn chạy lại thì bỏ qua các lệnh đã chạy (MySQL không hỗ trợ ADD COLUMN IF NOT EXISTS bản cũ).

-- ===== combos =====
ALTER TABLE combos
    CHANGE COLUMN name name_vi VARCHAR(160) NOT NULL,
    ADD COLUMN name_en VARCHAR(160) NULL DEFAULT NULL AFTER name_vi,
    ADD COLUMN name_zh VARCHAR(160) NULL DEFAULT NULL AFTER name_en;

-- ===== combo_groups =====
ALTER TABLE combo_groups
    CHANGE COLUMN name name_vi VARCHAR(120) NOT NULL,
    ADD COLUMN name_en VARCHAR(120) NULL DEFAULT NULL AFTER name_vi,
    ADD COLUMN name_zh VARCHAR(120) NULL DEFAULT NULL AFTER name_en;

-- ===== Dữ liệu: mặc định lấy theo tiếng Việt, rồi dịch các tên nhóm quen thuộc =====
UPDATE combos SET name_en = name_vi, name_zh = name_vi WHERE name_en IS NULL OR name_zh IS NULL;
UPDATE combo_groups SET name_en = name_vi, name_zh = name_vi WHERE name_en IS NULL OR name_zh IS NULL;

-- Nhóm đặt tên tự động "Nhóm 1", "Nhóm 2", ... => "Group 1" / "第 1 組"
UPDATE combo_groups
SET name_en = CONCAT('Group ', TRIM(SUBSTRING(name_vi, 6))),
    name_zh = CONCAT('第 ', TRIM(SUBSTRING(name_vi, 6)), ' 組')
WHERE name_vi REGEXP '^Nhóm [0-9]+$';

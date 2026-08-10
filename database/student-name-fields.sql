-- Import once after the existing Codify schema. Safe to rerun.
SET NAMES utf8mb4;

SET @add_first_name = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'first_name'),
    'DO 1',
    'ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER faculty_id'
);
PREPARE codify_add_first_name FROM @add_first_name;
EXECUTE codify_add_first_name;
DEALLOCATE PREPARE codify_add_first_name;

SET @add_last_name = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_name'),
    'DO 1',
    'ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name'
);
PREPARE codify_add_last_name FROM @add_last_name;
EXECUTE codify_add_last_name;
DEALLOCATE PREPARE codify_add_last_name;

UPDATE users
SET
    first_name = CASE
        WHEN LOCATE(' ', TRIM(name)) = 0 THEN TRIM(name)
        ELSE TRIM(LEFT(TRIM(name), LENGTH(TRIM(name)) - LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', -1))))
    END,
    last_name = CASE
        WHEN LOCATE(' ', TRIM(name)) = 0 THEN ''
        ELSE TRIM(SUBSTRING_INDEX(TRIM(name), ' ', -1))
    END
WHERE role = 'student' AND (first_name IS NULL OR last_name IS NULL);

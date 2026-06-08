-- =====================================================================
-- Import master ICD-10 (diagnosa) & ICD-9 (tindakan) — REPLACE TOTAL
-- Sumber: Docs/DIAGNOSA ICD 10 DAN ICD 9.docx  ->  Docs/icd10_import.csv / icd9_import.csv
--
-- Jalankan dari ROOT repo (path \copy relatif ke direktori psql dijalankan):
--   psql "$DB_URL" -v ON_ERROR_STOP=1 -f Docs/import_icd.sql
-- atau lokal:
--   psql -h 127.0.0.1 -U ejake -d arumed_dev -v ON_ERROR_STOP=1 -f Docs/import_icd.sql
--
-- TRUNCATE menghapus SEMUA data ICD lama (replace total). Tidak ada FK ke tabel ini.
-- =====================================================================

BEGIN;

-- ---------- ICD-10 (diagnosa) ----------
CREATE TEMP TABLE _icd10_stage (
    code text, category text, description text,
    is_eye_related boolean, is_favorite boolean
) ON COMMIT DROP;

\copy _icd10_stage (code, category, description, is_eye_related, is_favorite) FROM 'Docs/icd10_import.csv' WITH (FORMAT csv, HEADER true)

TRUNCATE icd10_codes;

INSERT INTO icd10_codes
    (id, code, category, description, is_eye_related, is_favorite, created_at, updated_at)
SELECT gen_random_uuid(), code, category, description, is_eye_related, is_favorite, now(), now()
FROM _icd10_stage;

-- ---------- ICD-9 (tindakan/prosedur) ----------
CREATE TEMP TABLE _icd9_stage (
    code text, category text, description text,
    is_eye_related boolean, is_favorite boolean
) ON COMMIT DROP;

\copy _icd9_stage (code, category, description, is_eye_related, is_favorite) FROM 'Docs/icd9_import.csv' WITH (FORMAT csv, HEADER true)

TRUNCATE icd9_codes;

INSERT INTO icd9_codes
    (id, code, category, description, is_eye_related, is_favorite, created_at, updated_at)
SELECT gen_random_uuid(), code, category, description, is_eye_related, is_favorite, now(), now()
FROM _icd9_stage;

COMMIT;

SELECT 'icd10_codes' AS tabel, count(*) FROM icd10_codes
UNION ALL
SELECT 'icd9_codes', count(*) FROM icd9_codes;

---
paths:
  - 'database/migrations/**'
---

# Migrations

## Two different `folders` tables, told apart by shape
Dot.Doc's own 2026_08_10_161301_create_folders_table runs chronologically BEFORE any 2026_09_08_* migration, so on a database where a Dot.Files instance migrated first it used to crash the whole batch with "table folders already exists". It is guarded now, and so is 161302 (which skips adding documents.folder_id entirely when `folders` is the shared table): a `folders` WITH `owner_id` is Dot.Doc's legacy shape, a `folders` WITH `uuid` is the shared Dot.Files one. Tell them apart by SHAPE, never by name, in every guard - up() and down() alike. tests/Feature/Files/SharedTreeMigrateTest runs the real `php artisan migrate` against a throwaway database pre-seeded with the shared tables; keep that case green when touching any migration in this set.

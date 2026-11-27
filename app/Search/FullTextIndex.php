<?php

namespace App\Search;

use Illuminate\Support\Facades\DB;

/**
 * The SQLite full-text index over documents (R2-5 / P-07): an FTS5 table, `documents_fts`, that indexes `title` and
 * `search_text` and reads them from `documents` itself (external content, so the text is not stored twice). Three
 * triggers keep it in step with every insert, delete and change of title or text, whatever writes the row.
 *
 * A migration that rebuilds the `documents` table (SQLite's way to change a column) drops those triggers with the old
 * table. So the index is only used while all three exist (checked once per request), and `install()` puts everything
 * back and rebuilds the index from the table: `php artisan search:rebuild`. DocumentsFullTextIndexTest fails if the
 * migrations ever end without the triggers.
 */
class FullTextIndex
{
    public const TABLE = 'documents_fts';

    /** @var list<string> */
    public const TRIGGERS = ['documents_fts_ai', 'documents_fts_ad', 'documents_fts_au'];

    private ?bool $usable = null;

    /** Whether searches can use the index: SQLite, the table and all three triggers present. */
    public function usable(): bool
    {
        if ($this->usable !== null) {
            return $this->usable;
        }

        try {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                return $this->usable = false;
            }

            $found = DB::table('sqlite_master')
                ->whereIn('name', [self::TABLE, ...self::TRIGGERS])
                ->pluck('name')
                ->all();

            return $this->usable = count(array_unique($found)) === 1 + count(self::TRIGGERS);
        } catch (\Throwable) {
            return $this->usable = false;
        }
    }

    /** Forget the per-request answer (after install()). */
    public function forget(): void
    {
        $this->usable = null;
    }

    /**
     * Create (or re-create) the table and the triggers, then fill the index from `documents`. False when this SQLite
     * has no FTS5, in which case nothing is created and search keeps using LIKE.
     */
    public function install(): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return false;
        }

        try {
            DB::statement('CREATE VIRTUAL TABLE IF NOT EXISTS '.self::TABLE.' USING fts5('
                ."title, search_text, content='documents', content_rowid='id', tokenize='unicode61 remove_diacritics 2')");
        } catch (\Throwable) {
            return false;
        }

        foreach (self::TRIGGERS as $trigger) {
            DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
        }

        $add = 'INSERT INTO '.self::TABLE.'(rowid, title, search_text) VALUES (new.id, new.title, new.search_text);';
        $remove = 'INSERT INTO '.self::TABLE.'('.self::TABLE.', rowid, title, search_text) VALUES (\'delete\', old.id, old.title, old.search_text);';

        DB::statement('CREATE TRIGGER documents_fts_ai AFTER INSERT ON documents BEGIN '.$add.' END');
        DB::statement('CREATE TRIGGER documents_fts_ad AFTER DELETE ON documents BEGIN '.$remove.' END');
        // Only a change of the indexed text re-indexes: a save that changes nothing searchable costs nothing here.
        DB::statement('CREATE TRIGGER documents_fts_au AFTER UPDATE OF title, search_text ON documents BEGIN '.$remove.' '.$add.' END');

        DB::statement('INSERT INTO '.self::TABLE.'('.self::TABLE.") VALUES ('rebuild')");
        $this->forget();

        return true;
    }

    /**
     * The safety net for a host whose web PHP cannot load FTS5 while the command-line PHP that ran the migration can
     * (two PHP builds): every write to `documents` would then fail on the triggers, so every save. Run by GET /up
     * (DiagnosingHealth), which the deploy's on-host check calls right after migrating. If the index exists but this
     * PHP cannot read it, the triggers are dropped - saves work, search falls back to LIKE - and it is reported, so a
     * person is mailed. Never throws. True when nothing was wrong.
     */
    public function guard(): bool
    {
        try {
            if (DB::connection()->getDriverName() !== 'sqlite'
                || ! DB::table('sqlite_master')->where('name', self::TABLE)->exists()) {
                return true;
            }

            try {
                DB::table(self::TABLE)->whereRaw(self::TABLE.' MATCH ?', ['"probe"'])->limit(1)->count();

                return true;
            } catch (\Throwable $e) {
                foreach (self::TRIGGERS as $trigger) {
                    DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
                }
                $this->forget();
                report(new \RuntimeException('The full-text index cannot be read by this PHP ('.class_basename($e).'): '
                    .'its triggers were removed so saves keep working, and search uses LIKE. After fixing the PHP '
                    .'(SQLite with FTS5), run "php artisan search:rebuild".', 0, $e));

                return false;
            }
        } catch (\Throwable) {
            return true;
        }
    }

    public function uninstall(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::TRIGGERS as $trigger) {
            DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
        }
        DB::statement('DROP TABLE IF EXISTS '.self::TABLE);
        $this->forget();
    }

    /**
     * The person's words as an FTS5 query: each word a quoted prefix ("repo"* finds "report"), all of them required.
     * Quoting makes every word a plain string, so nothing typed is read as FTS syntax. Single letters are left out
     * ("don't" gives "don", not also "t"*, which would match nearly everything). Null when nothing is left; the
     * title is still matched by LIKE.
     */
    public static function matchQuery(string $query): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, fn (string $w) => mb_strlen($w) >= 2);
        $words = array_slice(array_values(array_unique($words)), 0, 8);

        return $words === [] ? null : implode(' ', array_map(fn (string $w) => '"'.$w.'"*', $words));
    }
}

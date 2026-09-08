# Dot.Doc Phase 1 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the existing Dot.docs HTML-blob editor into the Dot.Doc structured-document foundation: JSON document model with block IDs, outline/numbering/TOC/cross-references, Document Styles, page model + print, redesigned shell, structured import/export, slug sharing, audit log, full-text search, and Prism-based AI plumbing.

**Architecture:** Canonical content becomes ProseMirror JSON stored in `documents.content_json`; HTML in `documents.content` is a derived render cache produced by `HtmlRenderer`. All writes go through `DocumentStore`. Deterministic engines (`Outline`, `StyleEngine`, `PrintRenderer`) operate on JSON. The editor is a Vite bundle exposing `window.DotDoc.mount()`; Livewire owns chrome and persistence.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 3, Alpine 3, TipTap 3 (ProseMirror), Vite 8, Tailwind 3.4, dompdf, PHPWord, league/commonmark + html-to-markdown, prism-php/prism, PostgreSQL 16 (SQLite in-memory for tests).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-09-07-dot-doc-platform-design.md`. Repo stays `Dot.docs`; product name in UI is **Dot.Doc**; registry key stays `docs`.
- Every block in `content_json` has `attrs.id` matching `/^[0-9A-Za-z]{8}$/`.
- `content` (HTML) is never written by a client again; only `HtmlRenderer` writes it.
- Every by-ID lookup of a child record is scoped by `document_id`.
- Tests: `php artisan test --compact --filter=<Name>`; SQLite in-memory; the 69 existing tests stay green. No test may call a real AI provider (`AI_PROVIDER=mock` in `phpunit.xml`).
- Style: `vendor/bin/pint --dirty` before each commit. PHP 8 typed signatures, constructor promotion, curly braces always.
- Fonts: Atkinson Hyperlegible Next / Mono for chrome. Never Inter, Geist, Space Grotesk, Plus Jakarta Sans.
- Colours from spec §7 only. Status never colour-only.
- No new base folders beyond: `app/Documents`, `app/Styles`, `app/Print`, `app/Audit`, `app/Ai`, `app/Search`, `resources/js/editor`.
- Commit after every task with a Conventional Commit message ending in `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

---

## File map

| Path | Responsibility |
|---|---|
| `app/Documents/Schema/BlockId.php` | Generate/validate 8-char base62 IDs |
| `app/Documents/Schema/DocumentSchema.php` | Node/mark registry, validation, `ensureIds`, walking helpers, plain text, word count |
| `app/Documents/Render/HtmlRenderer.php` | JSON → HTML (modes: `editor`, `share`, `print`) |
| `app/Documents/Import/HtmlToJson.php` | Legacy HTML → JSON (DOMDocument) |
| `app/Documents/Import/DocxImporter.php`, `MarkdownImporter.php` | File → JSON |
| `app/Documents/Export/DocxExporter.php`, `MarkdownExporter.php` | JSON → file |
| `app/Documents/Outline/Outline.php`, `OutlineResult.php` | Numbering, TOC regeneration, cross-refs |
| `app/Documents/DocumentStore.php` | Only write path; render cache; version cutting |
| `app/Styles/StyleEngine.php` | Style tokens → CSS |
| `app/Print/PrintRenderer.php` | JSON + style + page setup → PDF |
| `app/Audit/AuditLog.php` | Append-only audit writes |
| `app/Search/DocumentSearch.php` | FTS (pgsql) / LIKE (sqlite) |
| `app/Ai/Client.php`, `app/Ai/Usage.php` | Prism wrapper, mock, usage rows |
| `app/Models/{DocumentStyle,BrandKit,DocumentSuggestion,AuditLog,AiModelUsage}.php` | New models |
| `database/seeders/DocumentStyleSeeder.php` | 14 system styles |
| `database/factories/DocumentFactory.php` | Test factory |
| `app/Console/Commands/MigrateDocumentsToJson.php` | Backfill |
| `resources/js/editor/*` | Editor bundle |
| `resources/css/{app,shell,paper}.css` | Tokens, chrome, canvas |
| `resources/views/layouts/app.blade.php` | New shell |

---

### Task 1: Schema migrations, models and factory

**Files:**
- Create: `database/migrations/2026_09_07_000001_add_structured_content_to_documents_table.php`
- Create: `database/migrations/2026_09_07_000002_add_content_json_to_document_versions_table.php`
- Create: `database/migrations/2026_09_07_000003_create_document_styles_table.php`
- Create: `database/migrations/2026_09_07_000004_create_brand_kits_table.php`
- Create: `database/migrations/2026_09_07_000005_add_block_id_to_comments_table.php`
- Create: `database/migrations/2026_09_07_000006_create_document_suggestions_table.php`
- Create: `database/migrations/2026_09_07_000007_create_audit_logs_table.php`
- Create: `database/migrations/2026_09_07_000008_create_ai_model_usage_table.php`
- Create: `database/migrations/2026_09_07_000009_add_content_json_to_document_templates_table.php`
- Create: `app/Models/DocumentStyle.php`, `app/Models/BrandKit.php`, `app/Models/DocumentSuggestion.php`, `app/Models/AuditLog.php`, `app/Models/AiModelUsage.php`
- Create: `database/factories/DocumentFactory.php`
- Modify: `app/Models/Document.php` (fillable, casts, relations), `app/Models/DocumentVersion.php`, `app/Models/DocumentTemplate.php`
- Test: `tests/Feature/Documents/SchemaMigrationTest.php`

**Interfaces:**
- Produces: `Document::$content_json` (array cast), `Document::$page_setup` (array), `Document::$variables` (array), `Document::$style_key` (string, default `report`), `Document::$review_state`, `Document::$slug`, `Document::$word_count`, `Document::$health_score`; `Document::style(): BelongsTo`; `DocumentVersion::$content_json`, `$label`, `$kind`, `$summary`; `DocumentStyle` with `key,name,category,tokens(array),is_system,team_id`; `AuditLog` (no `updated_at`); `AiModelUsage`; `Document::factory()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Documents;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_stores_structured_content_and_page_setup(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'content_json' => ['type' => 'doc', 'attrs' => ['schema' => 1], 'content' => []],
            'page_setup' => ['size' => 'A4', 'orientation' => 'portrait'],
            'variables' => ['period' => 'August 2026'],
        ]);

        $doc->refresh();
        $this->assertSame('doc', $doc->content_json['type']);
        $this->assertSame('A4', $doc->page_setup['size']);
        $this->assertSame('report', $doc->style_key);
        $this->assertSame('draft', $doc->review_state);
        $this->assertSame(1, $doc->schema_version);
    }

    public function test_version_keeps_json_label_and_kind(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->for($user, 'owner')->create();
        $v = DocumentVersion::create([
            'document_id' => $doc->id, 'content_snapshot' => '<p>x</p>',
            'content_json' => ['type' => 'doc', 'content' => []],
            'version_number' => 1, 'created_by' => $user->id, 'created_at' => now(),
            'label' => 'Board draft', 'kind' => 'named',
        ]);
        $this->assertSame('named', $v->fresh()->kind);
        $this->assertSame('doc', $v->fresh()->content_json['type']);
    }

    public function test_style_and_audit_tables_exist(): void
    {
        $style = DocumentStyle::create(['key' => 'test', 'name' => 'Test', 'category' => 'report', 'tokens' => ['font' => 'x'], 'is_system' => true]);
        $this->assertSame('x', $style->fresh()->tokens['font']);

        $log = AuditLog::create(['actor_type' => 'user', 'actor_id' => 1, 'action' => 'document.viewed', 'subject_type' => Document::class, 'subject_id' => 1, 'context' => []]);
        $this->assertNotNull($log->created_at);
        $this->assertFalse($log->timestamps);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SchemaMigrationTest`
Expected: FAIL — `Document::factory()` undefined / columns missing.

- [ ] **Step 3: Write the migrations**

`2026_09_07_000001_add_structured_content_to_documents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->jsonb('content_json')->nullable()->after('content');
            $table->binary('ydoc_state')->nullable()->after('content_json');
            $table->unsignedSmallInteger('schema_version')->default(1)->after('ydoc_state');
            $table->string('style_key', 40)->default('report')->after('schema_version');
            $table->foreignId('brand_kit_id')->nullable()->after('style_key');
            $table->jsonb('page_setup')->nullable()->after('brand_kit_id');
            $table->jsonb('variables')->nullable()->after('page_setup');
            $table->unsignedSmallInteger('health_score')->nullable()->after('variables');
            $table->timestamp('health_checked_at')->nullable()->after('health_score');
            $table->string('review_state', 20)->default('draft')->after('health_checked_at');
            $table->string('slug', 80)->nullable()->unique()->after('review_state');
            $table->unsignedInteger('word_count')->default(0)->after('slug');
            $table->unsignedInteger('view_count')->default(0)->after('word_count');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE documents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(title,'') || ' ' || coalesce(search_text,''))) STORED");
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['content_json', 'ydoc_state', 'schema_version', 'style_key', 'brand_kit_id', 'page_setup', 'variables', 'health_score', 'health_checked_at', 'review_state', 'slug', 'word_count', 'view_count']);
        });
    }
};
```

Add a `search_text` `longText` nullable column **before** the pgsql statement (place `$table->longText('search_text')->nullable()->after('content');` first in the closure). It holds the plain text `DocumentStore` writes on save.

`…000002_add_content_json_to_document_versions_table.php`: add `jsonb('content_json')->nullable()`, `string('label',120)->nullable()`, `string('kind',20)->default('auto')`, `text('summary')->nullable()`, `unsignedInteger('word_count')->default(0)`.

`…000003_create_document_styles_table.php`:

```php
Schema::create('document_styles', function (Blueprint $table) {
    $table->id();
    $table->string('key', 40);
    $table->string('name', 80);
    $table->string('category', 40);
    $table->jsonb('tokens');
    $table->boolean('is_system')->default(false);
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['key', 'team_id']);
});
```

`…000004_create_brand_kits_table.php`: `id, team_id FK, name, logo_path nullable, fonts jsonb nullable, colours jsonb nullable, letterhead jsonb nullable, footer jsonb nullable, disclaimer text nullable, contact jsonb nullable, default_style_key string(40) nullable, timestamps`.

`…000005_add_block_id_to_comments_table.php`: `string('block_id', 8)->nullable()->index()` on `comments`.

`…000006_create_document_suggestions_table.php`: `id, document_id FK cascade, block_id string(8) index, author_type string(10), author_id unsignedBigInteger nullable, operation string(60), patch jsonb, rationale text nullable, status string(12) default 'pending', resolved_by FK users nullable, resolved_at nullable, timestamps`.

`…000007_create_audit_logs_table.php`:

```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->index();
    $table->string('actor_type', 20);
    $table->unsignedBigInteger('actor_id')->nullable();
    $table->string('action', 60)->index();
    $table->string('subject_type', 120);
    $table->unsignedBigInteger('subject_id');
    $table->jsonb('context')->nullable();
    $table->string('ip', 45)->nullable();
    $table->string('user_agent', 255)->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->index(['subject_type', 'subject_id']);
});
```

`…000008_create_ai_model_usage_table.php`: `id, team_id nullable index, user_id nullable, document_id nullable index, provider string(20), model string(80), operation string(60), input_tokens uint default 0, output_tokens uint default 0, cache_read_tokens uint default 0, cost_usd decimal(10,6) default 0, latency_ms uint default 0, fallback_used bool default false, created_at useCurrent`.

`…000009_add_content_json_to_document_templates_table.php`: `jsonb('content_json')->nullable()`, `string('style_key',40)->default('report')`, `jsonb('page_setup')->nullable()`.

- [ ] **Step 4: Models and factory**

`app/Models/DocumentStyle.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentStyle extends Model
{
    protected $fillable = ['key', 'name', 'category', 'tokens', 'is_system', 'team_id'];

    protected $casts = ['tokens' => 'array', 'is_system' => 'boolean'];

    public static function resolve(string $key, ?int $teamId = null): ?self
    {
        return static::where('key', $key)
            ->where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->orderByRaw('team_id IS NULL')
            ->first();
    }
}
```

`app/Models/AuditLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['team_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'context', 'ip', 'user_agent', 'created_at'];

    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
```

`AiModelUsage` (timestamps false, fillable all columns, `created_at` datetime cast), `BrandKit` (fillable all, casts json arrays), `DocumentSuggestion` (fillable all, `patch` array, `resolved_at` datetime, `document()` belongsTo).

`Document.php`: add to `$fillable`: `content_json, search_text, schema_version, style_key, brand_kit_id, page_setup, variables, health_score, health_checked_at, review_state, slug, word_count, view_count`; casts: `content_json => 'array', page_setup => 'array', variables => 'array', health_checked_at => 'datetime'`; relations `suggestions()` hasMany `DocumentSuggestion`, `brandKit()` belongsTo. Add `use HasFactory`.

`DocumentVersion.php`: fillable add `content_json, label, kind, summary, word_count`; cast `content_json => 'array'`.

`DocumentTemplate.php`: fillable add `content_json, style_key, page_setup`; casts arrays.

`database/factories/DocumentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'content' => '<p>Hello</p>',
            'content_json' => ['type' => 'doc', 'attrs' => ['schema' => 1, 'style' => 'report', 'vars' => []], 'content' => [
                ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaa1'], 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ]],
            'owner_id' => User::factory(),
            'version' => 1,
        ];
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=SchemaMigrationTest` → PASS. Then `php artisan test --compact` → 69 + 3 pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty && git add -A && git commit -m "feat(schema): structured content, styles, suggestions, audit and AI usage tables"
```

---

### Task 2: BlockId and DocumentSchema

**Files:**
- Create: `app/Documents/Schema/BlockId.php`, `app/Documents/Schema/DocumentSchema.php`
- Test: `tests/Unit/Documents/DocumentSchemaTest.php`

**Interfaces:**
- Produces: `BlockId::generate(): string`, `BlockId::isValid(string): bool`; `DocumentSchema::BLOCKS` (array of block node names), `DocumentSchema::INLINES`, `DocumentSchema::MARKS`; `DocumentSchema::ensureIds(array $doc): array`; `DocumentSchema::validate(array $doc): array` (list of error strings, empty = valid); `DocumentSchema::walk(array $node, callable $fn): void` (`$fn(array $node, array $path)`); `DocumentSchema::blocks(array $doc): array` (flat list of `['id'=>..,'type'=>..,'node'=>..]`); `DocumentSchema::plainText(array $node): string`; `DocumentSchema::wordCount(array $doc): int`; `DocumentSchema::empty(): array`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Documents;

use App\Documents\Schema\BlockId;
use App\Documents\Schema\DocumentSchema;
use PHPUnit\Framework\TestCase;

class DocumentSchemaTest extends TestCase
{
    public function test_block_ids_are_eight_base62_chars_and_unique(): void
    {
        $ids = array_map(fn () => BlockId::generate(), range(1, 200));
        foreach ($ids as $id) {
            $this->assertTrue(BlockId::isValid($id), $id);
        }
        $this->assertCount(200, array_unique($ids));
        $this->assertFalse(BlockId::isValid('abc'));
        $this->assertFalse(BlockId::isValid('abcd-fgh'));
    }

    public function test_ensure_ids_assigns_ids_to_every_block_and_keeps_existing(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1, 'id' => 'KeepMe01'], 'content' => [['type' => 'text', 'text' => 'T']]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]]]],
            ]],
        ]];
        $out = (new DocumentSchema)->ensureIds($doc);
        $this->assertSame('KeepMe01', $out['content'][0]['attrs']['id']);
        $this->assertTrue(BlockId::isValid($out['content'][1]['attrs']['id']));
        $this->assertTrue(BlockId::isValid($out['content'][1]['content'][0]['attrs']['id']));
        $this->assertTrue(BlockId::isValid($out['content'][1]['content'][0]['content'][0]['attrs']['id']));
        $this->assertArrayNotHasKey('attrs', $out['content'][0]['content'][0]);
    }

    public function test_validate_rejects_unknown_nodes_and_duplicate_ids(): void
    {
        $schema = new DocumentSchema;
        $bad = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => []],
            ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => []],
            ['type' => 'marquee', 'attrs' => ['id' => 'bbbbbbbb']],
        ]];
        $errors = $schema->validate($bad);
        $this->assertContains('Duplicate block id aaaaaaaa', $errors);
        $this->assertContains('Unknown node type marquee', $errors);
        $this->assertSame([], $schema->validate($schema->ensureIds(['type' => 'doc', 'content' => []])));
    }

    public function test_plain_text_and_word_count(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Fleet report']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ten trucks ran '], ['type' => 'text', 'text' => 'today.', 'marks' => [['type' => 'bold']]]]],
        ]];
        $schema = new DocumentSchema;
        $this->assertSame("Fleet report\nTen trucks ran today.", $schema->plainText($doc));
        $this->assertSame(6, $schema->wordCount($doc));
    }
}
```

- [ ] **Step 2: Run** `php artisan test --compact --filter=DocumentSchemaTest` → FAIL (class not found).

- [ ] **Step 3: Implement**

`app/Documents/Schema/BlockId.php`:

```php
<?php

namespace App\Documents\Schema;

final class BlockId
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function generate(): string
    {
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= self::ALPHABET[random_int(0, 61)];
        }

        return $out;
    }

    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9A-Za-z]{8}$/', $id) === 1;
    }
}
```

`app/Documents/Schema/DocumentSchema.php`:

```php
<?php

namespace App\Documents\Schema;

class DocumentSchema
{
    public const VERSION = 1;

    /** Block-level nodes that carry attrs.id */
    public const BLOCKS = [
        'paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem',
        'blockquote', 'codeBlock', 'horizontalRule', 'image', 'figure', 'caption',
        'table', 'tableRow', 'tableHeader', 'tableCell', 'toc', 'pageBreak', 'sectionBreak',
        'callout', 'columns', 'column',
    ];

    public const INLINES = ['text', 'hardBreak', 'crossRef', 'variable'];

    public const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'link', 'highlight', 'subscript', 'superscript', 'textStyle'];

    public static function empty(): array
    {
        return ['type' => 'doc', 'attrs' => ['schema' => self::VERSION, 'style' => 'report', 'vars' => []], 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()]],
        ]];
    }

    public function ensureIds(array $doc): array
    {
        $seen = [];
        $doc['content'] = array_map(fn ($n) => $this->ensureNodeIds($n, $seen), $doc['content'] ?? []);
        $doc['attrs'] = array_merge(['schema' => self::VERSION, 'style' => 'report', 'vars' => []], $doc['attrs'] ?? []);

        return $doc;
    }

    private function ensureNodeIds(array $node, array &$seen): array
    {
        if (in_array($node['type'] ?? '', self::BLOCKS, true)) {
            $id = $node['attrs']['id'] ?? null;
            if (! BlockId::isValid($id) || isset($seen[$id])) {
                $id = BlockId::generate();
            }
            $seen[$id] = true;
            $node['attrs'] = array_merge($node['attrs'] ?? [], ['id' => $id]);
        }
        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn ($c) => $this->ensureNodeIds($c, $seen), $node['content']);
        }

        return $node;
    }

    /** @return list<string> */
    public function validate(array $doc): array
    {
        $errors = [];
        $ids = [];
        if (($doc['type'] ?? null) !== 'doc') {
            $errors[] = 'Root must be doc';
        }
        $this->walk($doc, function (array $node) use (&$errors, &$ids): void {
            $type = $node['type'] ?? '';
            if ($type === 'doc') {
                return;
            }
            if (! in_array($type, self::BLOCKS, true) && ! in_array($type, self::INLINES, true)) {
                $errors[] = "Unknown node type {$type}";

                return;
            }
            if (in_array($type, self::BLOCKS, true)) {
                $id = $node['attrs']['id'] ?? null;
                if (! BlockId::isValid($id)) {
                    $errors[] = "Block {$type} has no valid id";
                } elseif (isset($ids[$id])) {
                    $errors[] = "Duplicate block id {$id}";
                }
                $ids[$id] = true;
            }
            foreach ($node['marks'] ?? [] as $mark) {
                if (! in_array($mark['type'] ?? '', self::MARKS, true)) {
                    $errors[] = 'Unknown mark type '.($mark['type'] ?? '?');
                }
            }
        });

        return array_values(array_unique($errors));
    }

    /** @param callable(array $node, array $path): void $fn */
    public function walk(array $node, callable $fn, array $path = []): void
    {
        $fn($node, $path);
        foreach ($node['content'] ?? [] as $i => $child) {
            $this->walk($child, $fn, [...$path, $i]);
        }
    }

    /** @return list<array{id:string,type:string,node:array,path:array}> */
    public function blocks(array $doc): array
    {
        $out = [];
        $this->walk($doc, function (array $node, array $path) use (&$out): void {
            if (in_array($node['type'] ?? '', self::BLOCKS, true) && isset($node['attrs']['id'])) {
                $out[] = ['id' => $node['attrs']['id'], 'type' => $node['type'], 'node' => $node, 'path' => $path];
            }
        });

        return $out;
    }

    public function plainText(array $node): string
    {
        $type = $node['type'] ?? '';
        if ($type === 'text') {
            return $node['text'] ?? '';
        }
        if ($type === 'hardBreak') {
            return "\n";
        }
        if ($type === 'variable') {
            return '{{'.($node['attrs']['key'] ?? '').'}}';
        }
        $parts = array_map(fn ($c) => $this->plainText($c), $node['content'] ?? []);
        $isBlock = in_array($type, self::BLOCKS, true) || $type === 'doc';
        $joined = implode($isBlock && $this->hasBlockChildren($node) ? "\n" : '', $parts);

        return $joined;
    }

    private function hasBlockChildren(array $node): bool
    {
        foreach ($node['content'] ?? [] as $c) {
            if (in_array($c['type'] ?? '', self::BLOCKS, true)) {
                return true;
            }
        }

        return false;
    }

    public function wordCount(array $doc): int
    {
        return str_word_count(preg_replace('/[^\p{L}\p{N}\s\']+/u', ' ', $this->plainText($doc)) ?? '');
    }
}
```

- [ ] **Step 4: Run** → PASS. If `plainText` joins differ, fix the join rule, not the test.

- [ ] **Step 5: Commit** `feat(documents): block ids and document schema`

---

### Task 3: HtmlRenderer and legacy HtmlToJson

**Files:**
- Create: `app/Documents/Render/HtmlRenderer.php`, `app/Documents/Render/RenderContext.php`, `app/Documents/Import/HtmlToJson.php`
- Test: `tests/Unit/Documents/HtmlRenderTest.php`

**Interfaces:**
- Produces: `HtmlRenderer::render(array $doc, RenderContext $ctx): string`; `RenderContext::editor()`, `::share()`, `::print()` with `public readonly string $mode`, `public array $numbers = []` (block id → label string, filled by Outline in Task 5), `public array $vars = []`; `HtmlToJson::convert(string $html): array` (already `ensureIds`'d).

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Documents;

use App\Documents\Import\HtmlToJson;
use App\Documents\Render\HtmlRenderer;
use App\Documents\Render\RenderContext;
use App\Documents\Schema\DocumentSchema;
use PHPUnit\Framework\TestCase;

class HtmlRenderTest extends TestCase
{
    public function test_renders_blocks_with_data_ids_and_marks(): void
    {
        $doc = ['type' => 'doc', 'attrs' => ['schema' => 1], 'content' => [
            ['type' => 'heading', 'attrs' => ['id' => 'h1h1h1h1', 'level' => 2], 'content' => [['type' => 'text', 'text' => 'Summary']]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'p1p1p1p1'], 'content' => [
                ['type' => 'text', 'text' => 'Bold', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' & link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.za']]]],
            ]],
            ['type' => 'pageBreak', 'attrs' => ['id' => 'pbpbpbpb']],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::share());
        $this->assertStringContainsString('<h2 data-id="h1h1h1h1">Summary</h2>', $html);
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://x.za" rel="noopener noreferrer">', $html);
        $this->assertStringContainsString('&amp; link', $html);
        $this->assertStringContainsString('<div class="page-break" data-id="pbpbpbpb"></div>', $html);
    }

    public function test_variables_and_numbers_are_substituted(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['id' => 'h2h2h2h2', 'level' => 1, 'numbered' => true], 'content' => [['type' => 'text', 'text' => 'Scope']]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'p2p2p2p2'], 'content' => [
                ['type' => 'text', 'text' => 'See '], ['type' => 'crossRef', 'attrs' => ['targetId' => 'h2h2h2h2', 'kind' => 'heading']],
                ['type' => 'text', 'text' => ' for '], ['type' => 'variable', 'attrs' => ['key' => 'period']],
            ]],
        ]];
        $ctx = RenderContext::print();
        $ctx->numbers = ['h2h2h2h2' => '1'];
        $ctx->vars = ['period' => 'August 2026'];
        $html = (new HtmlRenderer)->render($doc, $ctx);
        $this->assertStringContainsString('<span class="num">1</span>Scope', $html);
        $this->assertStringContainsString('<a class="xref" href="#h2h2h2h2">Section 1</a>', $html);
        $this->assertStringContainsString('August 2026', $html);
    }

    public function test_legacy_html_round_trips_to_json(): void
    {
        $html = '<h1>Title</h1><p>Hello <strong>world</strong></p><ul><li><p>One</p></li></ul><table><tbody><tr><td><p>c</p></td></tr></tbody></table>';
        $doc = (new HtmlToJson)->convert($html);
        $this->assertSame([], (new DocumentSchema)->validate($doc));
        $this->assertSame('heading', $doc['content'][0]['type']);
        $this->assertSame('table', $doc['content'][3]['type']);
        $this->assertSame('world', $doc['content'][1]['content'][1]['text']);
        $this->assertSame('bold', $doc['content'][1]['content'][1]['marks'][0]['type']);
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement**

`RenderContext`:

```php
<?php

namespace App\Documents\Render;

final class RenderContext
{
    /** @var array<string,string> block id => number label */
    public array $numbers = [];

    /** @var array<string,string> */
    public array $vars = [];

    /** @var array<string,string> block id => kind (heading|figure|table) */
    public array $kinds = [];

    private function __construct(public readonly string $mode) {}

    public static function editor(): self { return new self('editor'); }

    public static function share(): self { return new self('share'); }

    public static function print(): self { return new self('print'); }
}
```

`HtmlRenderer` — one `match` over node types; escape all text with `htmlspecialchars($t, ENT_QUOTES | ENT_HTML5)`; block open tags carry `data-id`. Rules:

| Node | HTML |
|---|---|
| paragraph | `<p data-id>` (attrs.align → `style="text-align:…"`) |
| heading | `<hN data-id>` + `<span class="num">{numbers[id]}</span>` when numbers has id |
| bulletList / orderedList / listItem | `<ul>/<ol start?>/<li>` |
| taskList / taskItem | `<ul class="tasks">` / `<li data-checked>` |
| blockquote, codeBlock (`<pre><code class="language-x">`), horizontalRule | standard |
| image | `<img src alt width>` (src must pass `filter_var` URL or start with `/storage/`) |
| figure | `<figure data-id class="figure figure-{kind}">` children; caption renders `<figcaption><span class="num">Figure {numbers[id]}</span> text</figcaption>` |
| table/tableRow/tableHeader/tableCell | `<table class="doc-table"><tbody>…` with `colspan/rowspan` |
| toc | `<nav class="toc" data-id data-depth>` with `<ol>` built from `$ctx->toc` entries (Task 5 supplies; render empty `<ol>` when absent) |
| pageBreak | `<div class="page-break" data-id></div>` |
| sectionBreak | `<div class="section-break" data-id data-setup='json'></div>` |
| callout | `<aside class="callout callout-{tone}" data-id>` |
| columns / column | `<div class="columns" data-id style="--cols:N">` / `<div class="column">` |
| crossRef | `<a class="xref" href="#id">{Kind} {numbers[id]}</a>` (kind label: heading→Section, figure→Figure, table→Table); if no number: `<a class="xref xref-broken">?</a>` |
| variable | `htmlspecialchars($ctx->vars[key] ?? '{{key}}')` |
| hardBreak | `<br>` |
| text | escaped text wrapped by marks: bold→strong, italic→em, underline→u, strike→s, code→code, link→`<a href rel="noopener noreferrer" target?>`, highlight→`<mark>`, subscript/superscript→sub/sup, textStyle→`<span style="color:">` (only `#hex` colours) |

Add a `public array $toc = []` property to `RenderContext` (list of `['id','level','text','number']`).

`HtmlToJson::convert()`: `DOMDocument::loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NOERROR)`, iterate body children mapping `h1-h6→heading(level)`, `p→paragraph`, `ul/ol→lists` (li children wrap bare text in a paragraph), `blockquote`, `pre→codeBlock`, `hr`, `img→image`, `table→table/tableRow/(th→tableHeader|td→tableCell)`, inline `strong/b→bold`, `em/i→italic`, `u`, `s/del→strike`, `code`, `a→link(href)`, `mark→highlight`, `br→hardBreak`, unknown blocks → paragraph of their text, unknown inline → text. Return `(new DocumentSchema)->ensureIds($doc)`.

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(documents): html renderer and legacy html import`

---

### Task 4: DocumentStore, Editor JSON save, version cutting, backfill command

**Files:**
- Create: `app/Documents/DocumentStore.php`, `app/Console/Commands/MigrateDocumentsToJson.php`
- Modify: `app/Observers/DocumentObserver.php` (remove version creation from `updated`), `app/Livewire/Documents/Editor.php` (`saveContent(array $json)`), `app/Livewire/Documents/VersionHistory.php` (`restore` via store), `app/Livewire/Documents/Index.php` (create via `DocumentStore::create`)
- Test: `tests/Feature/Documents/DocumentStoreTest.php`

**Interfaces:**
- Produces: `DocumentStore::create(User $owner, string $title, ?array $json = null, array $attrs = []): Document`; `DocumentStore::save(Document $doc, array $json, User $actor, array $opts = ['version' => 'auto'|'named'|'restore'|'none', 'label' => ?string]): Document`; `DocumentStore::json(Document $doc): array` (falls back to `HtmlToJson` when `content_json` null); `DocumentStore::cutVersion(Document, User, string $kind, ?string $label): DocumentVersion`; `DocumentStore::restore(Document, DocumentVersion, User): Document`.
- Version rule: `save()` with `version=auto` cuts a version only if the latest version is older than 120 s **or** the actor differs from the latest version's author; `named`/`restore` always cut.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentStoreTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text, string $id = 'p0p0p0p0'): array
    {
        return ['type' => 'doc', 'attrs' => ['schema' => 1], 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    public function test_save_renders_html_cache_text_and_word_count(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');
        $doc = app(DocumentStore::class)->save($doc, $this->para('Three word sentence'), $user);

        $this->assertSame('<p data-id="p0p0p0p0">Three word sentence</p>', trim($doc->content));
        $this->assertSame('Three word sentence', $doc->search_text);
        $this->assertSame(3, $doc->word_count);
        $this->assertSame(2, $doc->version);
    }

    public function test_auto_versions_coalesce_within_two_minutes_for_same_author(): void
    {
        $user = User::factory()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'R');
        $store->save($doc, $this->para('a'), $user);
        $store->save($doc, $this->para('ab'), $user);
        $this->assertSame(1, $doc->versions()->count());

        Carbon::setTestNow(now()->addMinutes(3));
        $store->save($doc, $this->para('abc'), $user);
        $this->assertSame(2, $doc->versions()->count());

        $other = User::factory()->create();
        $store->save($doc, $this->para('abcd'), $other);
        $this->assertSame(3, $doc->versions()->count());
        Carbon::setTestNow();
    }

    public function test_named_version_and_restore(): void
    {
        $user = User::factory()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'R');
        $store->save($doc, $this->para('first'), $user, ['version' => 'named', 'label' => 'v1']);
        $store->save($doc, $this->para('second'), $user, ['version' => 'named', 'label' => 'v2']);
        $v1 = $doc->versions()->where('label', 'v1')->first();
        $doc = $store->restore($doc, $v1, $user);
        $this->assertSame('first', $doc->content_json['content'][0]['content'][0]['text']);
        $this->assertSame('restore', $doc->versions()->latest('id')->first()->kind);
    }

    public function test_json_falls_back_to_legacy_html(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->for($user, 'owner')->create(['content' => '<h1>Old</h1><p>doc</p>', 'content_json' => null]);
        $json = app(DocumentStore::class)->json($doc);
        $this->assertSame('heading', $json['content'][0]['type']);
    }

    public function test_editor_accepts_json_and_rejects_invalid(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('typed'))
            ->assertSet('saved', true);
        $this->assertSame('typed', $doc->fresh()->search_text);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', ['type' => 'doc', 'content' => [['type' => 'marquee']]])
            ->assertHasErrors('content');
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement `DocumentStore`**

```php
<?php

namespace App\Documents;

use App\Documents\Import\HtmlToJson;
use App\Documents\Outline\Outline;
use App\Documents\Render\HtmlRenderer;
use App\Documents\Render\RenderContext;
use App\Documents\Schema\DocumentSchema;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DocumentStore
{
    public const AUTO_VERSION_WINDOW_SECONDS = 120;

    public function __construct(
        private DocumentSchema $schema,
        private HtmlRenderer $renderer,
        private Outline $outline,
        private HtmlToJson $legacy,
    ) {}

    public function create(User $owner, string $title, ?array $json = null, array $attrs = []): Document
    {
        $json = $this->schema->ensureIds($json ?? DocumentSchema::empty());
        $doc = new Document(array_merge(['title' => $title, 'owner_id' => $owner->id, 'team_id' => $owner->currentTeam?->id, 'version' => 1], $attrs));
        $this->fill($doc, $json);
        $doc->save();

        return $doc;
    }

    public function json(Document $doc): array
    {
        if (is_array($doc->content_json) && ($doc->content_json['type'] ?? null) === 'doc') {
            return $doc->content_json;
        }

        return $this->legacy->convert($doc->content ?? '');
    }

    /** @param array{version?:string,label?:string|null} $opts */
    public function save(Document $doc, array $json, User $actor, array $opts = []): Document
    {
        $json = $this->schema->ensureIds($json);
        $errors = $this->schema->validate($json);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }

        return DB::transaction(function () use ($doc, $json, $actor, $opts) {
            $this->fill($doc, $json);
            $doc->version = $doc->version + 1;
            $doc->save();

            $kind = $opts['version'] ?? 'auto';
            if ($kind !== 'none' && $this->shouldCut($doc, $actor, $kind)) {
                $this->cutVersion($doc, $actor, $kind, $opts['label'] ?? null);
            }
            app(WebhookService::class)->fire($doc, 'on_save');

            return $doc;
        });
    }

    public function cutVersion(Document $doc, User $actor, string $kind = 'auto', ?string $label = null): DocumentVersion
    {
        return DocumentVersion::create([
            'document_id' => $doc->id,
            'content_snapshot' => $doc->content ?? '',
            'content_json' => $doc->content_json,
            'version_number' => $doc->version,
            'created_by' => $actor->id,
            'created_at' => now(),
            'label' => $label,
            'kind' => $kind,
            'word_count' => $doc->word_count,
        ]);
    }

    public function restore(Document $doc, DocumentVersion $version, User $actor): Document
    {
        abort_unless($version->document_id === $doc->id, 404);
        $json = $version->content_json ?? $this->legacy->convert($version->content_snapshot);

        return $this->save($doc, $json, $actor, ['version' => 'restore', 'label' => 'Restored v'.$version->version_number]);
    }

    private function shouldCut(Document $doc, User $actor, string $kind): bool
    {
        if ($kind !== 'auto') {
            return true;
        }
        $latest = $doc->versions()->latest('id')->first();
        if ($latest === null) {
            return true;
        }

        return $latest->created_by !== $actor->id
            || $latest->created_at->lt(now()->subSeconds(self::AUTO_VERSION_WINDOW_SECONDS));
    }

    private function fill(Document $doc, array $json): void
    {
        $result = $this->outline->build($json);
        $json = $this->outline->apply($json, $result);
        $ctx = RenderContext::editor();
        $ctx->numbers = $result->numbers;
        $ctx->kinds = $result->kinds;
        $ctx->toc = $result->toc;
        $ctx->vars = $doc->variables ?? [];

        $doc->content_json = $json;
        $doc->schema_version = DocumentSchema::VERSION;
        $doc->content = $this->renderer->render($json, $ctx);
        $doc->search_text = $this->schema->plainText($json);
        $doc->word_count = $this->schema->wordCount($json);
    }
}
```

Until Task 5 exists, create `app/Documents/Outline/Outline.php` with a passthrough `build()` returning an `OutlineResult` with empty arrays and `apply()` returning `$json` unchanged (Task 5 replaces the internals). `OutlineResult`: `public array $numbers = [], $kinds = [], $toc = [], $headings = [], $figures = [], $tables = []`.

- [ ] **Step 4: Editor and observer**

In `DocumentObserver::updated`, delete the `DocumentVersion::create` block and the webhook call (both now live in the store); keep cache busting.

`Editor::saveContent`:

```php
public function saveContent(array $content): void
{
    $this->authorize('update', $this->document);

    try {
        $this->document = app(DocumentStore::class)->save($this->document, $content, Auth::user());
    } catch (\InvalidArgumentException $e) {
        $this->addError('content', $e->getMessage());

        return;
    }
    $this->saved = true;

    try {
        DocumentUpdated::dispatch($this->document, Auth::user(), $this->document->content, $this->document->version);
    } catch (\Throwable) {
    }
    app(PresenceService::class)->heartbeat($this->document, Auth::user());
}
```

Remove `suggestionMode` storage of whole-document `AiSuggestion` from `saveContent` (suggestion mode is rebuilt in Phase 3; keep `toggleSuggestionMode` as a no-op flag). In `mount()`, set `$this->contentJson = app(DocumentStore::class)->json($this->document)` (new public array property) and drop `$this->content` HTML hydration. `VersionHistory::restore()` calls `DocumentStore::restore`. `Index::createDocument()` calls `DocumentStore::create`.

- [ ] **Step 5: Backfill command**

`app/Console/Commands/MigrateDocumentsToJson.php` (signature `dot:documents:migrate-json {--dry-run}`): iterate `Document::whereNull('content_json')->withTrashed()->cursor()`, convert with `HtmlToJson`, set via `DocumentStore` private `fill` (expose as `public function refill(Document $doc, array $json): void` that runs `fill` + `saveQuietly()`), and `DocumentTemplate` rows likewise into `content_json`. Print counts.

- [ ] **Step 6: Run** the new test and the full suite → all PASS.

- [ ] **Step 7: Commit** `feat(documents): DocumentStore as the single write path, JSON autosave, version coalescing, backfill`

---

### Task 5: Outline engine — numbering, TOC, cross-references

**Files:**
- Replace: `app/Documents/Outline/Outline.php`, `app/Documents/Outline/OutlineResult.php`
- Test: `tests/Unit/Documents/OutlineTest.php`

**Interfaces:**
- Produces: `Outline::build(array $doc, array $rules = []): OutlineResult`; `Outline::apply(array $doc, OutlineResult $r): array` (regenerates every `toc` node's `attrs.entries`, and sets `attrs.label` on every `crossRef`); `OutlineResult` as in Task 4 plus `public array $broken = []` (crossRef target ids not found). Rules: `['headings' => 'decimal'|'none', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'|'byChapter']` default decimal/1/3/sequential.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Documents;

use App\Documents\Outline\Outline;
use PHPUnit\Framework\TestCase;

class OutlineTest extends TestCase
{
    private function h(int $level, string $text, string $id, bool $numbered = true): array
    {
        return ['type' => 'heading', 'attrs' => ['id' => $id, 'level' => $level, 'numbered' => $numbered], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function fig(string $id, string $kind = 'image'): array
    {
        return ['type' => 'figure', 'attrs' => ['id' => $id, 'kind' => $kind], 'content' => [
            $kind === 'image' ? ['type' => 'image', 'attrs' => ['id' => $id.'i', 'src' => '/storage/x.png']] : ['type' => 'table', 'attrs' => ['id' => $id.'t'], 'content' => []],
            ['type' => 'caption', 'attrs' => ['id' => $id.'c'], 'content' => [['type' => 'text', 'text' => 'Cap']]],
        ]];
    }

    public function test_decimal_numbering_and_toc(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'toc', 'attrs' => ['id' => 'tocXXXXX', 'depth' => 2]],
            $this->h(1, 'Intro', 'H1AAAAAA'), $this->h(2, 'Scope', 'H2AAAAAA'), $this->h(2, 'Method', 'H2BBBBBB'),
            $this->h(3, 'Deep', 'H3AAAAAA'), $this->h(1, 'Results', 'H1BBBBBB'), $this->h(2, 'Unnumbered', 'H2CCCCCC', false),
        ]];
        $r = (new Outline)->build($doc);
        $this->assertSame(['H1AAAAAA' => '1', 'H2AAAAAA' => '1.1', 'H2BBBBBB' => '1.2', 'H3AAAAAA' => '1.2.1', 'H1BBBBBB' => '2'], $r->numbers);
        $this->assertSame(['id' => 'H2AAAAAA', 'level' => 2, 'text' => 'Scope', 'number' => '1.1'], $r->toc[1]);
        $this->assertCount(5, $r->toc);

        $out = (new Outline)->apply($doc, $r);
        $this->assertSame('1.2', $out['content'][0]['attrs']['entries'][2]['number']);
    }

    public function test_figures_and_tables_number_separately_and_crossrefs_resolve(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            $this->fig('F1F1F1F1'), $this->fig('T1T1T1T1', 'table'), $this->fig('F2F2F2F2'),
            ['type' => 'paragraph', 'attrs' => ['id' => 'P1P1P1P1'], 'content' => [
                ['type' => 'crossRef', 'attrs' => ['targetId' => 'F2F2F2F2', 'kind' => 'figure']],
                ['type' => 'crossRef', 'attrs' => ['targetId' => 'T1T1T1T1', 'kind' => 'table']],
                ['type' => 'crossRef', 'attrs' => ['targetId' => 'NOPE0000', 'kind' => 'figure']],
            ]],
        ]];
        $r = (new Outline)->build($doc);
        $this->assertSame('2', $r->numbers['F2F2F2F2']);
        $this->assertSame('1', $r->numbers['T1T1T1T1']);
        $this->assertSame(['NOPE0000'], $r->broken);
        $out = (new Outline)->apply($doc, $r);
        $this->assertSame('Figure 2', $out['content'][3]['content'][0]['attrs']['label']);
        $this->assertSame('Table 1', $out['content'][3]['content'][1]['attrs']['label']);
        $this->assertSame('?', $out['content'][3]['content'][2]['attrs']['label']);
    }

    public function test_renumbering_after_move(): void
    {
        $doc = ['type' => 'doc', 'content' => [$this->h(1, 'A', 'AAAAAAAA'), $this->h(1, 'B', 'BBBBBBBB')]];
        $this->assertSame('2', (new Outline)->build($doc)->numbers['BBBBBBBB']);
        $doc['content'] = array_reverse($doc['content']);
        $this->assertSame('1', (new Outline)->build($doc)->numbers['BBBBBBBB']);
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement**

`Outline::build`: walk top-level and nested blocks in document order with `DocumentSchema::walk`; maintain `$counters = [0,0,0,0,0,0]`; for numbered headings within `[startLevel, maxLevel]`: increment `$counters[level-1]`, zero deeper levels, number = `implode('.', slice(startLevel-1 .. level-1))`; record `numbers[id]`, `kinds[id]='heading'`, `headings[]`, and `toc[]` (text via `DocumentSchema::plainText`, all levels ≤ maxLevel including unnumbered? — **no**: TOC lists numbered headings plus unnumbered ones with `number => ''`; the test expects 5 entries so include unnumbered too). Figures: `figure` nodes counted per `attrs.kind` (`image`→figure counter, `table`→table counter), `kinds[id]='figure'|'table'`. Cross-refs: any `crossRef` whose `targetId` is not in `numbers` → `broken[]`.

`Outline::apply`: recursive map; `toc` node → `attrs.entries = array_filter(toc, level <= depth)` re-indexed; `crossRef` → `attrs.label = match(kind){ 'heading' => 'Section ', 'figure' => 'Figure ', 'table' => 'Table ' } . number` or `'?'` when missing.

- [ ] **Step 4: Run** → PASS. Then run `DocumentStoreTest` and `HtmlRenderTest` (HtmlRenderer should use `attrs.label` on crossRef when present, falling back to `$ctx->numbers`). Update the renderer accordingly.

- [ ] **Step 5: Commit** `feat(outline): heading numbering, figure/table numbering, TOC and cross-references`

---

### Task 6: Document Styles engine and the fourteen system styles

**Files:**
- Create: `app/Styles/StyleEngine.php`, `database/seeders/DocumentStyleSeeder.php`, `resources/views/styles/tokens.blade.php` (not needed if CSS is built in PHP — keep CSS generation in PHP)
- Modify: `database/seeders/DatabaseSeeder.php` (call the style seeder), `app/Livewire/Documents/Editor.php` (`setStyle(string $key)`), `resources/views/livewire/documents/editor.blade.php` (a `<style id="doc-style">` block)
- Test: `tests/Feature/Styles/StyleEngineTest.php`

**Interfaces:**
- Produces: `StyleEngine::resolve(Document $doc): DocumentStyle` (team style → system style → `report`); `StyleEngine::css(DocumentStyle $style, string $mode /* canvas|print */): string`; `StyleEngine::systemKeys(): array`; `DocumentStyleSeeder::STYLES` constant.
- Token shape (every style must define all keys):

```php
[
  'fonts' => ['body' => 'Source Serif 4', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => 'https://fonts.googleapis.com/css2?family=...'],
  'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
  'leading' => 1.45,
  'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
  'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#8a6d05', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
  'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
  'headingCase' => 'none' | 'upper',
  'table' => ['header' => 'band' | 'rule', 'zebra' => true, 'border' => 'hairline' | 'grid' | 'none'],
  'caption' => ['position' => 'below', 'style' => 'italic'],
  'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
  'align' => 'left' | 'justify',
]
```

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Styles;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\DocumentStyle;
use App\Models\User;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StyleEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_installs_fourteen_complete_system_styles(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $this->assertSame(14, DocumentStyle::where('is_system', true)->count());
        foreach (DocumentStyle::all() as $style) {
            foreach (['fonts', 'sizes', 'leading', 'spacing', 'colours', 'numbering', 'headingCase', 'table', 'caption', 'page', 'align'] as $key) {
                $this->assertArrayHasKey($key, $style->tokens, "{$style->key} missing {$key}");
            }
        }
    }

    public function test_css_covers_every_node_in_both_modes(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $engine = app(StyleEngine::class);
        foreach (DocumentStyle::all() as $style) {
            foreach (['canvas', 'print'] as $mode) {
                $css = $engine->css($style, $mode);
                foreach (['.paper h1', '.paper h2', '.paper p', '.paper .doc-table', '.paper figure', '.paper figcaption', '.paper .toc', '.paper .callout', '.paper .page-break', '.paper .num'] as $sel) {
                    $this->assertStringContainsString($sel, $css, "{$style->key}/{$mode} lacks {$sel}");
                }
                $this->assertStringContainsString($style->tokens['fonts']['body'], $css);
            }
            $this->assertStringContainsString('@page', $engine->css($style, 'print'));
        }
    }

    public function test_team_style_overrides_system_and_editor_can_switch(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        $this->assertSame('report', app(StyleEngine::class)->resolve($doc)->key);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->call('setStyle', 'legal');
        $this->assertSame('legal', $doc->fresh()->style_key);

        $custom = DocumentStyle::create(['key' => 'legal', 'name' => 'Our legal', 'category' => 'legal', 'team_id' => $user->currentTeam->id, 'tokens' => DocumentStyle::where('key', 'legal')->whereNull('team_id')->first()->tokens]);
        $this->assertSame($custom->id, app(StyleEngine::class)->resolve($doc->fresh())->id);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->call('setStyle', 'nonsense')->assertHasErrors('style');
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Seeder** — `DocumentStyleSeeder::STYLES` with keys `corporate, executive, academic, legal, financial, technical, government, mining, engineering, marketing, proposal, report, minimal, creative`, each a full token array. Distinguishing choices (write them, do not stub):

| Key | Body / Heading | Numbering | Table | Notable |
|---|---|---|---|---|
| corporate | Source Sans 3 / Source Sans 3 | decimal 1–3 | band, zebra | accent `#1f4e79`, justify off |
| executive | Source Serif 4 / Source Sans 3 | none | rule, no zebra | larger body 12pt, wide margins 30mm |
| academic | Source Serif 4 / Source Serif 4 | decimal 1–4 | rule | justify, leading 1.6, caption above for tables |
| legal | Source Serif 4 / Source Serif 4 | decimal 1–5 | grid | headingCase upper for h1, margins 25mm, footer `{{ title }} · Page {{ page }}` |
| financial | Source Sans 3 / Source Sans 3 | decimal 1–3 | grid, right-aligned numerics via `.num-cell` | mono `IBM Plex Mono` for figures |
| technical | Source Sans 3 / Source Sans 3 | decimal 1–4 | grid | code font JetBrains Mono, callouts boxed |
| government | Source Sans 3 / Source Sans 3 | decimal 1–3 | band | headingCase upper, accent `#005a2b` |
| mining | Source Sans 3 / Source Sans 3 | decimal 1–3 | band, zebra | accent `#8a6d05`, landscape-friendly table font 9.5pt |
| engineering | Source Sans 3 / Source Sans 3 | decimal 1–4 | grid | figures by chapter |
| marketing | Source Sans 3 / Source Sans 3 (700) | none | none | accent `#c9463d`, h1 30pt |
| proposal | Source Serif 4 / Source Sans 3 | decimal 1–2 | band | accent `#0f766e` |
| report | Source Serif 4 / Source Sans 3 | decimal 1–3 | band, zebra | the default |
| minimal | Source Sans 3 / Source Sans 3 | none | rule | no colours but ink, no rules |
| creative | Source Serif 4 / Source Sans 3 | none | none | accent `#7c3aed`, h1 34pt, generous leading |

- [ ] **Step 4: Engine** — `css()` builds one string using `sprintf`/heredoc with CSS custom properties on `.paper` then rules for every selector listed in the test, `.num` (margin-right .6em; `.paper h1 .num` uses accent), `.figure`/`figcaption` (position per token), `.doc-table` header/zebra/border per token, `.callout` tones (`note`, `warning`, `success`, `danger` with left rule **only** in print? — no: spec bans thick side borders; use a full hairline border and a tone-coloured title), `.columns` grid, `.page-break` (`page-break-after: always` in print, a dashed rule labelled "page break" in canvas). Print mode adds `@page { size: A4 portrait; margin: … }` from `page` tokens and `.page-break{page-break-after:always}`, hides `.toc-empty`. Canvas mode adds `.paper{width:210mm;min-height:297mm;padding:margins;}`.

`resolve()`: `DocumentStyle::resolve($doc->style_key, $doc->team_id) ?? DocumentStyle::resolve('report')`.

Editor `setStyle(string $key)`: authorize update; validate `in_array($key, StyleEngine::systemKeys()) || DocumentStyle::where('key',$key)->where('team_id',$doc->team_id)->exists()` else `addError('style', 'Unknown style')`; save `style_key`; `dispatch('style-changed', css: $engine->css(...))`. The editor view includes `<style id="doc-style">{!! $styleCss !!}</style>` computed in `render()`.

- [ ] **Step 5: Run** → PASS; `php artisan db:seed --class=DocumentStyleSeeder` works on the dev database.
- [ ] **Step 6: Commit** `feat(styles): document style engine with fourteen system styles`

---

### Task 7: Page model and print renderer (PDF)

**Files:**
- Create: `app/Print/PrintRenderer.php`, `app/Print/PageSetup.php`, `resources/views/print/document.blade.php`
- Modify: `app/Http/Controllers/DocumentExportController.php` (`exportPdf` uses `PrintRenderer`), `app/Livewire/Documents/DocumentSettings.php` (page setup form), `resources/views/livewire/documents/document-settings.blade.php`
- Test: `tests/Feature/Print/PrintRendererTest.php`

**Interfaces:**
- Produces: `PageSetup::fromDocument(Document, DocumentStyle): PageSetup` with `size` (`A4|A3|Letter`), `orientation`, `margins` (array of four mm strings), `header`, `footer` (templates); `PageSetup::toArray()`; `PrintRenderer::html(Document $doc): string` (full HTML page with style CSS, `@page`, header/footer as dompdf `position:fixed` bands, `{{ page }}`/`{{ pages }}` replaced by dompdf's script `PAGE_NUM/PAGE_COUNT`); `PrintRenderer::pdf(Document $doc): string` (binary).
- Header/footer templates support `{{ title }}`, `{{ page }}`, `{{ pages }}`, `{{ date }}`, `{{ team }}` and any document variable.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Print;

use App\Documents\DocumentStore;
use App\Models\User;
use App\Print\PageSetup;
use App\Print\PrintRenderer;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_setup_merges_document_over_style(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report', null, ['page_setup' => ['orientation' => 'landscape', 'footer' => 'Confidential · {{ page }}']]);
        $setup = PageSetup::fromDocument($doc, app(\App\Styles\StyleEngine::class)->resolve($doc));
        $this->assertSame('A4', $setup->size);
        $this->assertSame('landscape', $setup->orientation);
        $this->assertSame('Confidential · {{ page }}', $setup->footer);
    }

    public function test_print_html_has_page_rule_header_footer_and_variables(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report', null, ['variables' => ['period' => 'August 2026'], 'page_setup' => ['header' => '{{ title }} — {{ period }}']]);
        $html = app(PrintRenderer::class)->html($doc);
        $this->assertStringContainsString('@page', $html);
        $this->assertStringContainsString('Monthly report — August 2026', $html);
        $this->assertStringContainsString('class="print-footer"', $html);
        $this->assertStringContainsString('PAGE_NUM', $html);
    }

    public function test_pdf_is_produced(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        $pdf = app(PrintRenderer::class)->pdf($doc);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_export_route_uses_print_renderer_and_logs(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        $this->actingAs($user)->get(route('documents.export', [$doc->uuid, 'pdf']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — `PageSetup` (readonly props, `fromDocument` merges `$doc->page_setup ?? []` over `$style->tokens['page']`, `toArray`), `PrintRenderer::html()` renders `print/document.blade.php` with `$css = $engine->css($style,'print')` plus a dynamic `@page { size: {size} {orientation}; margin: ... }`, header/footer divs (`position:fixed; top:-Xmm` / `bottom:-Xmm`) and a `<script type="text/php">` block that writes page numbers via `$pdf->page_text(...)` when `{{ page }}` is used (dompdf convention; replace `{{ page }}` with `{PAGE_NUM}` and `{{ pages }}` with `{PAGE_COUNT}` inside `page_text`). Body: `HtmlRenderer::render($store->json($doc), RenderContext::print() with numbers/toc/vars)`. `pdf()`: `Pdf::loadHTML($html)->setPaper(strtolower(size), orientation)->output()`. `exportPdf` in the controller returns `response($renderer->pdf($doc), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$safeTitle}.pdf\""])`. `DocumentSettings` gets fields `pageSize, orientation, marginTop…Left, header, footer` saved into `page_setup`.

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(print): page setup and print renderer with headers, footers and page numbers`

---

### Task 8: Editor bundle (Vite) — extensions, command registry, palette, slash menu, JSON autosave

**Files:**
- Create: `resources/js/editor/index.js`, `resources/js/editor/extensions/{blockId,headingNumbered,toc,figure,crossRef,pageBreak,sectionBreak,callout,columns,variable}.js`, `resources/js/editor/commands/registry.js`, `resources/js/editor/ui/{palette,slash,bubble}.js`, `resources/css/paper.css`
- Modify: `resources/js/app.js` (import `./editor/index`), `package.json` (add `@tiptap/extension-unique-id`, `@tiptap/extension-highlight`, `@tiptap/extension-subscript`, `@tiptap/extension-superscript`, `@tiptap/extension-text-align`, `@tiptap/extension-task-list`, `@tiptap/extension-task-item`, `@tiptap/suggestion`), `resources/views/livewire/documents/editor.blade.php` (mount + Alpine bridge), `vite.config.js` (add `resources/css/paper.css`)
- Delete: `resources/js/editor.js`
- Test: `tests/Feature/Documents/EditorMountTest.php` (server side: the editor page carries the JSON payload and style CSS) + manual browser verification steps below

**Interfaces:**
- Produces: `window.DotDoc.mount(el, { content, vars, styleCss, onChange(json), onSelection({blockId, type}), onCommand(name, params) }) → { editor, run(name, params), destroy() }`; command registry entries `{ name, title, group, shortcut?, run(editor, params) }`; slash menu lists registry entries with `group !== 'system'`.
- Node names and attrs must match `DocumentSchema` exactly (`figure{kind}`, `caption`, `toc{depth,entries}`, `crossRef{targetId,kind,label}`, `pageBreak`, `sectionBreak{setup}`, `callout{tone}`, `columns{count}`, `column`, `variable{key}`, `heading{level,numbered}`).

- [ ] **Step 1: Server test**

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorMountTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_page_ships_json_style_and_mount_hook(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Mount me');
        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))
            ->assertOk()
            ->assertSee('id="doc-style"', false)
            ->assertSee('DotDoc.mount', false)
            ->assertSee('&quot;type&quot;:&quot;doc&quot;', false);
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Install deps** `npm install @tiptap/extension-unique-id @tiptap/extension-highlight @tiptap/extension-subscript @tiptap/extension-superscript @tiptap/extension-text-align @tiptap/extension-task-list @tiptap/extension-task-item @tiptap/suggestion`.
- [ ] **Step 4: Extensions** — each with `Node.create({ name, group: 'block', content, atom?, addAttributes(){ id: {default:null, parseHTML: el=>el.getAttribute('data-id'), renderHTML: a=>({'data-id':a.id})}, ... }, parseHTML, renderHTML, addNodeView? })`. `UniqueID.configure({ attributeName: 'id', types: [...DocumentSchema::BLOCKS names], generateID: () => base62(8) })` — the base62 generator mirrors `BlockId`. `headingNumbered` extends `Heading` adding `numbered: {default: true}` and a decoration plugin that reads `window.DotDoc.numbers` (set from the server on mount and after each save response via Livewire dispatch `outline-updated`) to prefix `.num` spans. `toc` is an atom node view rendering `attrs.entries`. `crossRef` is an inline atom rendering `attrs.label || '?'` with a click handler that scrolls to `[data-id=targetId]`. `figure` wraps `image|table` + `caption`; command `wrapInFigure(kind)`. `pageBreak` atom renders the dashed rule with a label. `callout` block with a tone selector in a bubble. `columns` content `column{2,4}`, `column` content `block+`. `variable` inline atom with `key` rendered as a chip showing the resolved value from `options.vars`.
- [ ] **Step 5: Registry, palette, slash** — `registry.js` exports `commands = [...]` including: headings 1–3, paragraph, bullet/ordered/task lists, quote, code, table 3×3, image (opens upload), figure, caption, TOC, cross-reference (opens a picker of headings/figures from `window.DotDoc.outline`), page break, section break, callout (4 tones), columns (2/3), variable (picker from `options.vars`), align left/centre/right/justify, highlight, undo/redo, `find` (`⌘F`, native find bar), `export.pdf`, `style.switch` (dispatches to Livewire). `palette.js`: `⌘K` opens a list filtered by fuzzy match, arrow keys + Enter, Esc closes; `slash.js`: `@tiptap/suggestion` with char `/` listing the same registry. `bubble.js`: a selection bubble with bold/italic/underline/link/highlight/comment.
- [ ] **Step 6: Mount + bridge** — `index.js` builds the Editor with `content` JSON, `onUpdate` debounced 1200 ms → `onChange(editor.getJSON())`, `onSelectionUpdate` → nearest block id/type. Blade `x-data` calls `window.DotDoc.mount($refs.editorEl, { content: @js($contentJson), vars: @js($document->variables ?? []), onChange: json => @this.saveContent(json), onSelection: s => { this.selection = s } })`; listen `style-changed` to swap `#doc-style`, `outline-updated` to refresh numbers. Keep offline draft (store JSON string), presence and Echo listeners; on remote `document.updated` apply `editor.commands.setContent(e.json)` when `e.editor.id !== me` (add `json` to the `DocumentUpdated` payload).
- [ ] **Step 7: paper.css** — `.desk` (ground), `.paper` (210mm width, min-height 297mm, background `--paper`, shadow, padding from CSS variables set by style CSS), `.page-break` dashed rule, `.num`, `.xref`, `.variable-chip`, `.toc`, `.callout`, `.columns`, `.figure`, ProseMirror focus/gapcursor resets, `prefers-reduced-motion` guard.
- [ ] **Step 8: Build and verify** — `npm run build` succeeds with no warnings above the chunk limit; `php artisan test --compact --filter=EditorMountTest` → PASS. Browser (preview server `php artisan serve --port=8014`): create a document, type a heading and two sub-headings, insert `/toc` → numbers `1`, `1.1`, `1.2` appear and the TOC lists them; drag heading 2 above 1 → numbers swap; insert a cross-reference → label updates; reload → content identical (JSON round trip). Take a screenshot for the record.
- [ ] **Step 9: Commit** `feat(editor): Dot.Doc editor bundle with structured nodes, palette and slash menu`

---

### Task 9: Shell redesign — Two Inks on a Desk

**Files:**
- Replace: `resources/views/layouts/app.blade.php`, `resources/views/navigation-menu.blade.php`
- Create: `resources/css/shell.css`, `resources/views/components/shell/{rail,dock,status-line,lamp}.blade.php`, `resources/js/shell.js` (theme toggle, keyboard nav, skip link focus)
- Modify: `resources/css/app.css` (import shell + paper), `tailwind.config.js` (`darkMode: 'class'`, fonts `sans: ['Atkinson Hyperlegible Next', ...]`, `mono: ['Atkinson Hyperlegible Mono', ...]`, colour tokens as CSS vars), `resources/views/dashboard.blade.php`, `resources/views/livewire/documents/{index,editor,version-history,share-manager,document-settings,template-gallery,comment-thread}.blade.php` (onto tokens/panels), `vite.config.js`
- Test: `tests/Feature/Shell/ShellTest.php` + Impeccable run

**Interfaces:**
- Produces: CSS variables on `:root` (day) and `html.dark` (night) exactly from spec §7: `--desk, --desk-raised, --rule, --paper, --paper-shadow, --text, --text-2, --ink, --marker, --marker-bg, --signal, --good, --danger`; Blade components `<x-shell.rail>`, `<x-shell.dock>`, `<x-shell.status-line :items="[['lamp'=>'good','word'=>'SAVED'], ...]">`, `<x-shell.lamp tone word>`.
- Layout regions: `header.status-line` (single row, mono readouts), `aside.rail` (left, 260px, collapsible to 56px, `aria-label="Navigator"`), `main.desk` (scrolling canvas), `aside.dock` (right, 360px, tabs Intelligence | Data, `aria-label="Intelligence and data"`). Rails and dock share edges with `1px solid var(--rule)`; no border-radius above 4px anywhere in chrome; no box-shadow except `.paper`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Shell;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_uses_vite_tokens_and_landmarks_not_cdns(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        $res = $this->actingAs($user)->get(route('documents.edit', $doc->uuid));
        $res->assertOk()
            ->assertDontSee('cdn.tailwindcss.com', false)
            ->assertDontSee('unpkg.com/alpinejs', false)
            ->assertDontSee('Inter', false)
            ->assertSee('Atkinson+Hyperlegible', false)
            ->assertSee('class="skip-link"', false)
            ->assertSee('aria-label="Navigator"', false)
            ->assertSee('aria-label="Intelligence and data"', false)
            ->assertSee('class="status-line"', false)
            ->assertSee('<title>R · Dot.Doc</title>', false);
    }

    public function test_dashboard_and_index_render_on_the_shell(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Dot.Doc')->assertDontSee('Dot.docs');
        $this->actingAs($user)->get(route('documents.index'))->assertOk()->assertSee('class="status-line"', false);
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the layout with `@vite(['resources/css/app.css','resources/js/app.js'])`, Google Fonts link for `Atkinson+Hyperlegible+Next:wght@400;500;700&family=Atkinson+Hyperlegible+Mono:wght@400;500` and `Source+Serif+4`/`Source+Sans+3` (document defaults), `<html class="{{ theme }}">` from a `theme` cookie (default `dark`), skip link, status line slot (`@yield('status', default items: SAVED/NIGHT)`), rail with the navigator (documents, templates, shared, recent; in the editor: outline/pages/comments/versions tabs), dock (empty states are one sentence + one action), theme toggle button labelled "Day"/"Night" with `aria-pressed`. Replace every `Dot.docs` string in views/config `app.name` with `Dot.Doc`. Restyle the listed Livewire views onto `.panel` (shared-edge ledgers), `.readout` (mono), `.lamp` + word.
- [ ] **Step 4: Impeccable** — write a throwaway test that renders dashboard, index, editor, history, share, settings for a seeded user into `public/__design/*.html`, run `node /Users/sakhilebhayi/Dot/impeccable/cli/bin/cli.js detect public/__design/<file>.html` for each; fix until every run prints nothing; delete the test and the directory. Check contrast of every text token against `--desk` and `--desk-raised` in both modes (≥ 4.5:1) with a small Node script in the scratchpad.
- [ ] **Step 5: Browser check** — night and day screenshots of the editor with a document open; the paper must be the only shadowed element; rails share edges; `Tab` reaches skip link → rail → paper → dock in order.
- [ ] **Step 6: Run** full suite → PASS. **Commit** `feat(shell): Two Inks on a Desk shell, night/day, navigator rail, dock, status line`

---

### Task 10: Structured import and export

**Files:**
- Create: `app/Documents/Import/DocxImporter.php`, `app/Documents/Import/MarkdownImporter.php`, `app/Documents/Export/DocxExporter.php`, `app/Documents/Export/MarkdownExporter.php`, `tests/fixtures/documents/{sample.docx,sample.md}`
- Modify: `app/Http/Controllers/DocumentImportController.php` (dispatch by extension → JSON → `DocumentStore::save`), `app/Http/Controllers/DocumentExportController.php` (word/markdown/html via exporters and `HtmlRenderer`)
- Test: `tests/Feature/Documents/ImportExportTest.php`

**Interfaces:**
- Produces: `DocxImporter::import(string $path): array` (JSON; maps Heading1–6 styles → heading levels, list paragraphs → lists, tables → table, inline bold/italic/underline, images extracted to `storage/app/public/documents/{uuid}/`), `MarkdownImporter::import(string $md): array` (commonmark → HTML → `HtmlToJson`), `DocxExporter::export(array $json, Document $doc, DocumentStyle $style): string` (path to temp .docx; headings with numbering text, lists, tables with header row bold, images, header/footer text with page field), `MarkdownExporter::export(array $json): string`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Export\DocxExporter;
use App\Documents\Export\MarkdownExporter;
use App\Documents\Import\DocxImporter;
use App\Documents\Import\MarkdownImporter;
use App\Documents\Schema\DocumentSchema;
use App\Models\User;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpWord\IOFactory;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_markdown_round_trip(): void
    {
        $md = "# Title\n\nSome **bold** text.\n\n- one\n- two\n\n| a | b |\n|---|---|\n| 1 | 2 |\n";
        $json = (new MarkdownImporter)->import($md);
        $this->assertSame([], (new DocumentSchema)->validate($json));
        $this->assertSame('heading', $json['content'][0]['type']);
        $this->assertSame('table', $json['content'][3]['type']);
        $out = (new MarkdownExporter)->export($json);
        $this->assertStringContainsString('# Title', $out);
        $this->assertStringContainsString('**bold**', $out);
        $this->assertStringContainsString('| a | b |', $out);
    }

    public function test_docx_export_keeps_structure_and_reimports(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $json = (new MarkdownImporter)->import("# Scope\n\n## Method\n\nBody **bold**.\n\n| h1 | h2 |\n|---|---|\n| x | y |\n");
        $doc = app(DocumentStore::class)->save(app(DocumentStore::class)->create($user, 'R'), $json, $user);
        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $this->assertFileExists($path);

        $back = (new DocxImporter)->import($path);
        $types = array_column($back['content'], 'type');
        $this->assertSame(['heading', 'heading', 'paragraph', 'table'], array_slice($types, 0, 4));
        $this->assertSame(1, $back['content'][0]['attrs']['level']);
        $this->assertSame(2, $back['content'][1]['attrs']['level']);
        $this->assertSame('bold', $back['content'][2]['content'][1]['marks'][0]['type']);
    }

    public function test_routes_export_all_formats(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        foreach (['pdf' => 'application/pdf', 'word' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'html' => 'text/html', 'markdown' => 'text/markdown'] as $fmt => $mime) {
            $this->actingAs($user)->get(route('documents.export', [$doc->uuid, $fmt]))->assertOk()->assertHeader('content-type', $mime.($fmt === 'html' || $fmt === 'markdown' ? '; charset=UTF-8' : ''));
        }
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — `DocxImporter` walks `PhpOffice\PhpWord\IOFactory::load($path)->getSections()`, elements `TextRun/Text/Title/ListItemRun/Table/Image/TextBreak`; heading level from `Title::getDepth()` or paragraph style name matching `/Heading(\d)/`; consecutive `ListItem` elements merge into one list; `Table` rows/cells → JSON; images saved and referenced. `DocxExporter` uses `PhpWord` with named styles `Heading1..3` (font/size/colour from tokens), `addTitle` with number prefix from `Outline`, `addListItemRun`, `addTable` with header row bold and banding per token, images via `addImage`, header/footer via `$section->addHeader()->addText()` and `addFooter()->addPreserveText('Page {PAGE} of {NUMPAGES}')`; returns temp path. `MarkdownExporter`: JSON → HTML via `HtmlRenderer` (share mode) → `League\HTMLToMarkdown\HtmlConverter(['strip_tags'=>true, 'header_style'=>'atx'])` with a custom pass for tables (render pipe tables directly from JSON before conversion). Controller: import dispatches on extension (`docx`, `md`, `markdown`, `html`, `txt`), validates size ≤ 20 MB, saves through `DocumentStore::save(..., ['version'=>'named','label'=>'Imported '.$name])`.
- [ ] **Step 4: Run** → PASS. **Commit** `feat(io): structured DOCX and Markdown import/export`

---

### Task 11: Templates on JSON, South African starters, slug sharing, publish page

**Files:**
- Create: `database/seeders/StarterTemplateSeeder.php`, `resources/templates/{monthly-production-report,safety-incident-report,board-memorandum}.md` (authored content with variables), `resources/views/documents/published.blade.php`
- Modify: `app/Livewire/Documents/TemplateGallery.php` (`useTemplate` copies `content_json`+`style_key`+`page_setup` via `DocumentStore::create`), `app/Livewire/Documents/SaveAsTemplate.php`, `app/Livewire/Documents/ShareManager.php` (slug field, publish toggle), `routes/web.php` (`GET /d/{slug}` published page; `/shared/{uuid}` increments `view_count`), `app/Models/Document.php` (`publicUrl()`), `app/Policies/DocumentPolicy.php` (unchanged)
- Test: `tests/Feature/Documents/TemplatesAndSharingTest.php`

**Interfaces:**
- Produces: `StarterTemplateSeeder` creates three `is_global` templates with `content_json` from the Markdown files (via `MarkdownImporter`), `style_key` `mining`/`government`/`executive`, `page_setup`; `Document::$slug` validated `/^[a-z0-9-]{4,80}$/` unique; route `documents.published` (`/d/{slug}`) → same access rules as `/shared/{uuid}` (public, expiry, password), renders `published.blade.php` on the paper with the style CSS and a print button; both routes `increment('view_count')`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Documents;

use App\Livewire\Documents\ShareManager;
use App\Livewire\Documents\TemplateGallery;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Database\Seeders\StarterTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TemplatesAndSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_templates_seed_with_json_style_and_variables(): void
    {
        $this->seed([DocumentStyleSeeder::class, StarterTemplateSeeder::class]);
        $t = DocumentTemplate::where('name', 'Monthly production report')->firstOrFail();
        $this->assertSame('mining', $t->style_key);
        $this->assertSame('doc', $t->content_json['type']);
        $this->assertStringContainsString('variable', json_encode($t->content_json));
    }

    public function test_using_a_template_creates_a_styled_document(): void
    {
        $this->seed([DocumentStyleSeeder::class, StarterTemplateSeeder::class]);
        $user = User::factory()->withPersonalTeam()->create();
        $t = DocumentTemplate::where('name', 'Board memorandum')->firstOrFail();
        Livewire::actingAs($user)->test(TemplateGallery::class)->call('useTemplate', $t->id)->assertRedirect();
        $doc = Document::where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('executive', $doc->style_key);
        $this->assertSame($t->content_json['content'][0]['type'], $doc->content_json['content'][0]['type']);
    }

    public function test_slug_publish_and_view_counter(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create(['is_public' => true]);
        Livewire::actingAs($user)->test(ShareManager::class, ['uuid' => $doc->uuid])->set('slug', 'august-production')->call('saveSlug');
        $this->assertSame('august-production', $doc->fresh()->slug);

        $this->get('/d/august-production')->assertOk()->assertSee($doc->title);
        $this->assertSame(1, $doc->fresh()->view_count);

        Livewire::actingAs($user)->test(ShareManager::class, ['uuid' => $doc->uuid])->set('slug', 'A B')->call('saveSlug')->assertHasErrors('slug');
        $doc->update(['is_public' => false]);
        $this->get('/d/august-production')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — author the three Markdown templates (real headings, a TOC marker line `[[toc]]` that `MarkdownImporter` turns into a `toc` node, variables as `{{ period }}`, `{{ site }}`, `{{ prepared_by }}` turned into `variable` nodes by a post-pass over text nodes, tables for production vs target, incident classification per MHSA section 23 categories, board memo sections: Purpose, Background, Discussion, Financial implications, Recommendation, Decision required). Seeder reads them, imports, sets style/page_setup (`mining`: landscape tables section; `government` for incident; `executive` for memo). `ShareManager::saveSlug` validates `['slug' => ['nullable','regex:/^[a-z0-9-]{4,80}$/','unique:documents,slug,'.$doc->id]]`. Route `/d/{slug}` mirrors `/shared/{uuid}` logic, both call `Document::increment('view_count')` and render `published.blade.php` (paper + style CSS + `HtmlRenderer` share mode + print button + "Made with Dot.Doc" footer line).
- [ ] **Step 4: Run** → PASS. **Commit** `feat(templates,sharing): JSON templates, SA starter set, slug publishing, view counts`

---

### Task 12: Audit log, full-text search, Prism AI client

**Files:**
- Create: `app/Audit/AuditLog.php` (service, named `AuditLogger` to avoid clashing with the model — **use `App\Audit\AuditLogger`**), `app/Search/DocumentSearch.php`, `app/Ai/Client.php`, `app/Ai/Usage.php`, `app/Ai/Providers/MockProvider.php` is not needed (Prism has none built in — implement mock inside `Client`), `config/ai.php`
- Modify: `composer.json` (`prism-php/prism:^0.100`; remove `openai-php/laravel` **after** `AiService` is re-pointed), `app/Services/AiService.php` (all methods call `Client`), `app/Livewire/Documents/Index.php` (search via `DocumentSearch`), `app/Livewire/Documents/Editor.php` (`mount` logs `document.viewed`), `app/Http/Controllers/DocumentExportController.php` (`document.exported`), `app/Livewire/Documents/ShareManager.php` (`share.updated`), `app/Livewire/Documents/VersionHistory.php` (`version.restored`), `phpunit.xml` (`<env name="AI_PROVIDER" value="mock" force="true"/>`), `.env.example` (`AI_PROVIDER=mock`, `ANTHROPIC_API_KEY=`, `AI_MODEL_DRAFT=claude-sonnet-5`, `AI_MODEL_COMPOSE=claude-opus-5`, `AI_MODEL_QUICK=claude-haiku-4-5-20251001`)
- Test: `tests/Feature/Audit/AuditLogTest.php`, `tests/Feature/Search/DocumentSearchTest.php`, `tests/Feature/Ai/ClientTest.php`

**Interfaces:**
- Produces: `AuditLogger::record(string $action, Model $subject, array $context = [], ?User $actor = null): AuditLog` (fills team from subject `team_id` if present, ip/user agent from `request()` when available); `DocumentSearch::search(User $user, string $query, int $limit = 20): Collection<Document>` (only documents the user can view: owner, collaborator, current team, public; pgsql uses `search_vector @@ plainto_tsquery`, sqlite uses `LIKE` on title/search_text); `Client::text(string $role, string $system, string $user, array $opts = []): AiResult` where `AiResult{ text, inputTokens, outputTokens, model, provider, latencyMs }`; `Client::structured(string $role, string $system, string $user, array $jsonSchema): array`; roles `draft|compose|quick`; when `config('ai.provider') === 'mock'` returns `AiResult` with `text = "[mock:{role}] ".Str::limit($user, 60)` and zero tokens; every call writes an `ai_model_usage` row via `Usage::record(...)`; `config/ai.php` keys: `provider`, `models.{draft,compose,quick}`, `failover` (list), `pricing_per_million.{model}.{input,output}`, `rate_limit_per_hour` (20).

- [ ] **Step 1: Failing tests**

```php
// tests/Feature/Audit/AuditLogTest.php
public function test_viewing_exporting_and_restoring_write_audit_rows(): void
{
    $this->seed(DocumentStyleSeeder::class);
    $user = User::factory()->withPersonalTeam()->create();
    $doc = app(DocumentStore::class)->create($user, 'R');
    Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);
    $this->actingAs($user)->get(route('documents.export', [$doc->uuid, 'markdown']));
    $this->assertDatabaseHas('audit_logs', ['action' => 'document.viewed', 'subject_id' => $doc->id, 'actor_id' => $user->id, 'team_id' => $user->currentTeam->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'document.exported', 'subject_id' => $doc->id]);
    $row = AuditLog::first();
    $this->expectException(\LogicException::class);
    $row->update(['action' => 'tampered']); // model must refuse
}
```

(Make `AuditLog::updating` throw `LogicException('Audit rows are immutable')` instead of returning false, so the test is explicit.)

```php
// tests/Feature/Search/DocumentSearchTest.php
public function test_search_respects_access_and_matches_body_text(): void
{
    $me = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->withPersonalTeam()->create();
    $store = app(DocumentStore::class);
    $mine = $store->save($store->create($me, 'Fleet'), $this->para('Bell trucks below target at Klipfontein'), $me);
    $theirs = $store->save($store->create($other, 'Secret'), $this->para('Bell trucks'), $other);
    $public = $store->save($store->create($other, 'Open', null, ['is_public' => true]), $this->para('Klipfontein site'), $other);

    $ids = app(DocumentSearch::class)->search($me, 'Klipfontein')->pluck('id')->all();
    $this->assertContains($mine->id, $ids);
    $this->assertContains($public->id, $ids);
    $this->assertNotContains($theirs->id, $ids);
}
```

```php
// tests/Feature/Ai/ClientTest.php
public function test_mock_provider_returns_deterministic_text_and_records_usage(): void
{
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $result = app(Client::class)->text('quick', 'You fix grammar.', 'Teh cat', ['operation' => 'grammar']);
    $this->assertStringStartsWith('[mock:quick]', $result->text);
    $this->assertDatabaseHas('ai_model_usage', ['operation' => 'grammar', 'provider' => 'mock', 'user_id' => $user->id]);
}

public function test_ai_service_grammar_uses_client(): void
{
    $user = User::factory()->create();
    $this->actingAs($user);
    $out = app(AiService::class)->grammarCheck('<p>Teh cat</p>');
    $this->assertStringContainsString('[mock:quick]', $out);
}

public function test_rate_limit_is_enforced(): void
{
    $user = User::factory()->create();
    $svc = app(AiService::class);
    for ($i = 0; $i < 20; $i++) { $this->assertTrue($svc->checkRateLimit($user->id)); }
    $this->assertFalse($svc->checkRateLimit($user->id));
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** — `composer require prism-php/prism`; `config/ai.php` as specified; `Client::text()` → if mock, build the mock result; else `Prism::text()->using($provider, $model)->withSystemPrompt($system)->withPrompt($user)->withMaxTokens($opts['max_tokens'] ?? 2000)->asText()`, provider inferred from model prefix (`claude-`→anthropic, `gpt-`/`o`→openai, `gemini-`→google, else ollama), on exception walk `failover`; measure latency; `Usage::record()` writes the row (cost from `pricing_per_million`). `AiService`: replace each `OpenAI::chat()->create([...])` with `$this->client->text($role, $system, $text, ['operation' => 'grammar'])->text` (roles: grammar/translate → `quick`; summarise/continue/tone/outline/freePrompt/chat → `draft`). Remove `openai-php/laravel` and `config/openai.php`. `AuditLogger` wired at the four call sites. `DocumentSearch` with the two drivers and the access subquery (owner OR collaborator OR team OR public). `Index` search box uses it when the query is ≥ 2 chars.
- [ ] **Step 4: Run** full suite → PASS (target ≥ 69 + ~30 new).
- [ ] **Step 5: Commit** `feat(platform): audit log, full-text search, Prism AI client with mock default`

---

### Task 13: Phase 1 wrap-up

- [ ] Run `vendor/bin/pint` and `vendor/bin/phpstan analyse --memory-limit=1G` (fix new findings only).
- [ ] Run `php artisan dot:documents:migrate-json --dry-run` then for real on the dev database; confirm every document has `content_json`.
- [ ] Update `README.md` (product name Dot.Doc, stack table: TipTap 3 structured JSON, Prism, styles, print) and `wiki.md` §2–§3 and Change Log; add `docs/superpowers/plans/2026-09-07-dot-doc-phase-1-foundation.md` to the wiki's related links.
- [ ] Register the Phase 1 verification screenshots in the PR description.
- [ ] Commit `docs: Dot.Doc Phase 1 wrap-up` and open the PR from `feature/dot-doc-phase-1` to `main`.

---

## Self-review

**Spec coverage (Phase 1 rows in spec §10):** JSON model + IDs → T1–T4; editor bundle, CDN removal, palette, slash → T8, T9; outline/numbering/TOC/xrefs → T5; page model, sections, print preview, headers/footers → T7 (preview mode is the `print` CSS applied on the canvas via a toggle in T9's status line — add a `Print preview` toggle that swaps `#doc-style` to the print CSS); styles engine with fourteen styles → T6; brand kit schema → T1; shell redesign, night/day, accessibility → T9; templates gallery + SA starters → T11; sharing slugs/password/expiry/counters/web page → T11; import/export → T10; audit log, rate limits, SSRF guard → T12 (SSRF guard: copy `Dot.Forms/app/Support/SsrfGuard.php` into `app/Support/SsrfGuard.php` and call `SsrfGuard::isSafeUrl()` in `WebhookService::fire` — add this to T12 Step 3); FTS → T12; Prism plumbing → T12.

**Placeholder scan:** none of the banned phrases; every task has test code and implementation shape.

**Type consistency:** `DocumentStore::save(Document, array, User, array)` used identically in T4, T6, T7, T10, T11, T12; `OutlineResult` fields `numbers/kinds/toc/broken` match between T4, T5 and the renderer; `RenderContext` gains `toc` in T3 and is used in T4/T7; `StyleEngine::resolve/css` used in T7, T8, T10; `AuditLogger` (service) vs `AuditLog` (model) distinguished in T12.

---

## Addendum (2026-09-08): Dot.Files integration and the inner-page design pass

Spec: `docs/superpowers/specs/2026-09-08-dot-doc-files-integration-design.md`. Owner request: files and folders created in Dot.Doc appear in Dot.Files seamlessly; inner pages get a full design pass with the design skills and the Impeccable detector.

### Task 9 amendment
Task 9 (shell redesign) must invoke the `frontend-design` and `ui-ux-pro-max` skills before layout work, and its Impeccable run covers dashboard, documents index, editor, history, share, settings, template gallery and the published page in BOTH night and day. Contrast is measured with a script, not eyeballed.

### Task 14: Dot.Doc side of the One Tree integration

**Files:**
- Create: `database/migrations/2026_09_08_000001_create_shared_files_tree_tables.php` (guarded `objects`, `files`, `folders` exactly matching Dot.Files' columns plus `objects.uuid` unique, `files.mime_type` nullable, `files.owner_id` nullable), `database/migrations/2026_09_08_000002_drop_document_folders_after_adoption.php` (drops `documents.folder_id` and Dot.Doc's `folders` only when the adopt command has marked completion in `cache`/a `settings` row — see command), `app/Files/FilesService.php`, `app/Files/UniqueName.php`, `app/Models/Files/{Obj,File,Folder}.php` (morph map `file|folder|document` registered in `AppServiceProvider`), `app/Policies/ObjPolicy.php`, `app/Console/Commands/AdoptFilesTree.php` (`dot:files:adopt-tree {--dry-run}`), `app/Livewire/Files/Navigator.php` + view, `app/Http/Controllers/FileViewController.php` (signed inline view for team files on the `files` disk), `config/filesystems.php` `files` disk (`FILES_DISK`, `FILES_ROOT`), `resources/js/files/tree.js` (expand/collapse, drag-to-move with keyboard fallback)
- Modify: `app/Models/Document.php` (`node()`, `folder()` via node parent), `app/Documents/DocumentStore.php` (`create()` registers the document in the tree under the given parent or the team/personal root), `app/Livewire/Documents/Index.php` + view (two-pane workspace, breadcrumbs, New folder/document/Upload/Import, selection dock), `app/Http/Controllers/Auth/EcosystemAuthController.php` (safe `redirect`), `app/Http/Controllers/DocumentExportController.php` ("Save to Dot.Files" writes the export through `FilesService` + `files` disk), `resources/views/livewire/documents/editor.blade.php` (location chip + Move in dock)
- Tests: `tests/Feature/Files/{FilesServiceTest,ObjPolicyTest,AdoptFilesTreeTest,NavigatorTest,HandoffRedirectTest,FileViewTest}.php`

**Interfaces:** `FilesService::root(Team): Obj`; `children(Obj): Collection`; `createFolder(Obj $parent, string $name, User $actor): Obj`; `registerDocument(Document, Obj $parent): Obj`; `moveObject(Obj, Obj $parent, User): Obj`; `renameObject(Obj, string, User): Obj`; `deleteObject(Obj, User): void`; `UniqueName::for(Obj $parent, string $name): string`. Every method takes the team from the parent object, never from `currentTeam`.

**Acceptance:** creating a folder in Dot.Doc inserts `objects`+`folders` rows a Dot.Files instance on the same DB lists; creating a document in that folder inserts an `objects` row with `objectable_type='document'`; personal documents live under the personal-team root; adopt command migrates existing folders/documents idempotently with a dry run; handoff `redirect` rejects absolute URLs; signed view URL expires; Impeccable prints nothing for the index in night and day.

### Task 15: Dot.Files side (repo `/Users/sakhilebhayi/Dot/Dot.Files`, branch `feature/dot-doc-integration`)

**Files:** guard the three domain migrations with `Schema::hasTable`; new migration adding `objects.uuid` unique, `files.mime_type`, `files.owner_id`; `app/Providers/AppServiceProvider.php` morph alias `'document' => App\Models\Document::class` with a minimal read-only `App\Models\Document` (table `documents`, `uuid`, `title`, `team_id`, `owner_id`); copied `app/Files/FilesService.php` + `UniqueName.php`; `FileBrowser::createFolder`/`updatedUpload` call the service; document rows rendered with "Open in Dot.Doc" (handoff to `DOT_DOCS_URL/auth/ecosystem?token=…&redirect=/documents/{uuid}/edit`); "New document" action (handoff to `/documents/create?parent={objUuid}`); `FileController::view` signed inline route `files.view`; `EcosystemAuthController` safe `redirect`; `.env.example` gains `DOT_DOCS_URL`, `FILES_DISK`, `FILES_ROOT`; tests for the service, morph rendering, handoff redirect, signed view.

**Acceptance:** with both apps on one database and one `FILES_ROOT`, a folder made in either app is listed in both; a document created from Dot.Files opens in Dot.Doc signed in; a PDF uploaded in Dot.Files previews inside Dot.Doc via the signed route.

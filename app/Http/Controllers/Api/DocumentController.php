<?php

namespace App\Http\Controllers\Api;

use App\Documents\DocumentStore;
use App\Documents\Export\MarkdownExporter;
use App\Documents\Import\MarkdownImporter;
use App\Documents\StaleDocumentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreDocumentRequest;
use App\Http\Requests\Api\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use App\Search\CompanyDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The documents API (R2-1 / S-07). A token reaches only what its owner could open in the browser, and only in the
 * company the owner is standing in (list, show and update alike), and only with the permission the route needs:
 *
 *   GET  /api/documents          read    the company's documents, newest edit first, 50 a page
 *   GET  /api/documents/{uuid}   read    one document as Markdown
 *   POST /api/documents          create  a new document from Markdown
 *   PUT  /api/documents/{uuid}   update  replace its text; `base_version` must match, or 409 and nothing changes
 *
 * Writes go through DocumentStore like every other writer. Every call is logged (LogApiUse).
 */
class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam instanceof Team ? $user->currentTeam : null;

        $page = CompanyDocuments::scope(Document::query(), $team?->id, $user->id)
            ->select(['id', 'uuid', 'title', 'owner_id', 'team_id', 'version', 'updated_at'])
            ->latest('updated_at')
            ->paginate(50);

        $visible = $page->getCollection()->filter(fn (Document $d) => $user->can('view', $d))->values();

        return response()->json([
            'data' => $visible->map(fn (Document $d) => $this->summary($d))->all(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function show(Request $request, string $uuid, DocumentStore $store, MarkdownExporter $markdown): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = CompanyDocuments::find($user, $uuid);
        Gate::authorize('view', $document);

        return response()->json($this->summary($document) + ['markdown' => $markdown->export($store->json($document))]);
    }

    public function store(StoreDocumentRequest $request, DocumentStore $store, MarkdownImporter $importer): JsonResponse
    {
        $data = $request->validated();

        /** @var User $user */
        $user = $request->user();
        $document = $store->create($user, $data['title'], $importer->import((string) $data['markdown']));

        return response()->json($this->summary($document), 201);
    }

    public function update(UpdateDocumentRequest $request, string $uuid, DocumentStore $store, MarkdownImporter $importer): JsonResponse
    {
        $document = $request->document();
        $data = $request->validated();

        /** @var User $user */
        $user = $request->user();

        try {
            $document = $store->save($document, $importer->import((string) $data['markdown']), $user, [
                'expectedVersion' => (int) $data['base_version'],
            ]);
        } catch (StaleDocumentException $e) {
            return response()->json(['conflict' => true, 'version' => $e->currentVersion], 409);
        }

        return response()->json($this->summary($document));
    }

    /** @return array{uuid: string, title: string, version: int, updated_at: string|null} */
    private function summary(Document $document): array
    {
        return [
            'uuid' => (string) $document->uuid,
            'title' => (string) $document->title,
            'version' => (int) $document->version,
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}

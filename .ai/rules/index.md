# Project Rules Index

Before planning or editing, find EVERY row whose globs match the file's path and read those rule files - more than one row can match (a file under `resources/js/editor/**` is covered by both `editor.md` and `views.md`).

| Applies to | Rule file |
| --- | --- |
| app/Ai/** | .ai/rules/ai.md |
| app/** | .ai/rules/app.md |
| app/Audit/** | .ai/rules/audit.md |
| app/Documents/Import/**, app/Documents/Export/**, app/Http/Controllers/DocumentImportController.php, app/Http/Controllers/DocumentExportController.php | .ai/rules/documents-io.md |
| resources/js/editor/** | .ai/rules/editor.md |
| app/Files/**, app/Models/Files/**, app/Console/Commands/AdoptFilesTree.php, app/Policies/ObjPolicy.php, app/Livewire/Files/**, app/Http/Controllers/FileUploadController.php, app/Http/Controllers/FileViewController.php, app/Actions/Jetstream/DeleteUser.php | .ai/rules/files.md |
| resources/views/livewire/** | .ai/rules/livewire.md |
| database/migrations/** | .ai/rules/migrations.md |
| app/Documents/Outline/** | .ai/rules/outline.md |
| app/Print/** | .ai/rules/print.md |
| app/Livewire/Documents/TemplateGallery.php, app/Livewire/Documents/SaveAsTemplate.php, app/Livewire/Documents/ShareManager.php, app/Http/Controllers/PublishedDocumentController.php, app/Providers/AppServiceProvider.php, app/Observers/DocumentObserver.php, routes/web.php, resources/views/documents/published.blade.php, resources/views/documents/published-password.blade.php, resources/views/documents/shared-password.blade.php, resources/views/documents/_password-gate.blade.php | .ai/rules/publishing.md |
| app/Search/** | .ai/rules/search.md |
| app/Styles/** | .ai/rules/styles.md |
| app/Services/WebhookService.php, app/Support/SsrfGuard.php | .ai/rules/support.md |
| resources/views/**, resources/css/**, resources/js/** | .ai/rules/views.md |

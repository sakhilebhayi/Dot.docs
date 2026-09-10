---
paths:
  - 'app/Audit/**'
---

# Audit

## Audit action taxonomy: a closed vocabulary of access events
AUDIT (App\Audit\AuditLogger) is an ACCESS log, not a content log - it answers "who read, exported, shared or rolled back this document". Content writes belong to DocumentStore and versions are the record of those; do not duplicate them here. The action vocabulary is CLOSED and dotted subject.verb, past tense: document.viewed (Editor::mount), document.exported (DocumentExportController::export, context {format}), share.updated (ShareManager - is_public, slug, share_password, share_expires_at), version.restored (VersionHistory::restore, context {version_number}). Add a new action only by adding it to this list. team_id comes from the subject when it has one, else the actor's current team; ip/user_agent are only captured in an HTTP context. context is for facts about the event - never document content, never a password (hashed or not): team admins read these rows. AuditLog::updating/deleting THROW LogicException("Audit rows are immutable") - they used to return false, which a caller could not tell from success.

The search half of this pair lives in `.ai/rules/search.md`; the two were written together and the access predicate there is the same policy this log records access against.

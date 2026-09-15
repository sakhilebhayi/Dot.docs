---
paths:
  - 'app/Documents/Outline/**'
---

# Outline

## Outline: two-pass build, rules from style deferred to Task 6
Outline::build() walks the doc TWICE: pass 1 numbers headings/figures/tables (via HeadingNumberer + running counters), pass 2 resolves crossRef targetIds against the finished numbers map into OutlineResult::broken. Do not merge these into one pass - a crossRef pointing to a heading/figure that appears LATER in the document would be wrongly reported as broken if checked during the same walk that assigns numbers. build(array $doc, array $rules = []) defaults to ['headings'=>'decimal','startLevel'=>1,'maxLevel'=>3,'figures'=>'sequential']; DocumentStore::fill() currently calls build($json) with no rules and is NOT to be changed here - Task 6 wires rules from the document's style/config. A heading counts as numbered unless attrs.numbered === false.

**Corrected in review round 1** (numbering and TOC listing are orthogonal, as in Word - do not re-introduce the "unnumbered headings are dropped" behavior): an explicitly-unnumbered heading (attrs.numbered === false) is excluded from `numbers` but is STILL listed in the TOC, with `number => ''` - e.g. an unnumbered "Foreword" or "Appendix" heading must still appear in the table of contents. `rules.headings === 'none'` applies that same "listed with number ''" treatment to every in-range heading regardless of its own `numbered` attr. HeadingNumberer::number() implements both cases with one branch: `if ($this->headingsNone || ! $individuallyNumbered) { return [...,'number'=>'']; }`.

A crossRef with no `targetId` at all resolves as an empty-string target, which is never in `numbers`, so it is recorded in `OutlineResult::broken` as `''` (not silently ignored) - every `'?'` label Outline::apply()/HtmlRenderer produce is accounted for in `broken`.

HtmlRenderer::renderCrossRef already prefers attrs.label (set by Outline::apply()) and falls back to $ctx->numbers - no renderer changes were needed for Task 5. HtmlRenderer::renderCaption() (in app/Documents/Render/HtmlRenderer.php, outside this glob but adjacent) uses `$ctx->kinds[$parentId]` to choose the `Figure `/`Table ` prefix rather than hardcoding `Figure ` - a table-kind figure's caption must read "Table N".

---
paths:
  - 'app/Documents/Outline/**'
---

# Outline

## Outline: two-pass build, rules from style deferred to Task 6
Outline::build() walks the doc TWICE: pass 1 numbers headings/figures/tables (via HeadingNumberer + running counters), pass 2 resolves crossRef targetIds against the finished numbers map into OutlineResult::broken. Do not merge these into one pass - a crossRef pointing to a heading/figure that appears LATER in the document would be wrongly reported as broken if checked during the same walk that assigns numbers. build(array $doc, array $rules = []) defaults to ['headings'=>'decimal','startLevel'=>1,'maxLevel'=>3,'figures'=>'sequential']; DocumentStore::fill() currently calls build($json) with no rules and is NOT to be changed here - Task 6 wires rules from the document's style/config. A heading counts as numbered unless attrs.numbered === false; an explicitly-unnumbered heading is excluded from both numbers and the TOC entirely (not listed with a blank number) UNLESS rules.headings === 'none', in which case every in-range heading is listed in the TOC with number '' and none are added to numbers. HtmlRenderer::renderCrossRef already prefers attrs.label (set by Outline::apply()) and falls back to $ctx->numbers - no renderer changes were needed for Task 5.

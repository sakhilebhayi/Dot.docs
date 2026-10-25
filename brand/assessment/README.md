# Brand experience baseline: Dot.docs (automated static checks only)

> **Advisory. Written by an AI session. No profile is ratified.** This is a first baseline from the Brand Feeling Engine. It reads this repository's source with the engine's static checks and nothing else: no rendered pages, no assessment by a human or an AI expert, no observed behaviour, no customer feedback. Nothing here blocks a merge or a deploy. The brand profile it is measured against is the shared ecosystem draft, which the owner has not ratified, and this repository has no product profile of its own yet. Scores are low on purpose: every check that could not run counts as zero and is listed.

| | |
|---|---|
| **Evidenced score** | **14.11 / 100** |
| Ceiling for this kind of evidence | 62.2 |
| Attainment | 22.69 % |
| State | `not_ready` |
| Confidence | Low |
| Blockers | 1 |
| Commit analysed | `f31b958eddd10c3bcdf9ef924f5048e80602f99f` |
| Engine | 0.3.0 |
| Rubric | bxqs 1.0.0 (sha256 `f5a4f8cb49b821bb41cb2ea3a5355089f87e60e6233cc6439a0f7ea680342bbd`) |
| Profile | dot-ecosystem 0.1.0 (draft; sha256 `e21c37306843707affd13abbb1eca37c8b4102aafa79336411163dd88ca0dde0`) |

## Dimensions

| Dimension | Status | Points earned / weight | Ceiling (this evidence) |
|---|---|---|---|
| Emotional clarity and positioning | partially evidenced | 0.48 / 8 | 4 |
| Brand distinctiveness | partially evidenced | 0.525 / 7 | 4.2 |
| Usability and cognitive load | partially evidenced | 1.8359 / 12 | 7.2 |
| Visual and interaction craftsmanship | partially evidenced | 0.9 / 9 | 7.2 |
| Functional completeness | partially evidenced | 3.7332 / 10 | 9 |
| Trust and transparency | partially evidenced | 0.8 / 10 | 7 |
| Perceived and demonstrated value | not measured | 0 / 8 | 4 |
| Accessibility and inclusion | partially evidenced | 3.7532 / 12 | 8.4 |
| Cross-platform consistency | partially evidenced | 0.8113 / 6 | 4.8 |
| Reliability and recovery from errors | partially evidenced | 1.2799 / 8 | 6.4 |
| Retention through continuing value | not measured | 0 / 5 | 0 |
| Measurable customer outcomes | not measured | 0 / 5 | 0 |

## Blockers recorded by the engine

- WCAG AA failure of criterion 1.4.3. (`d8.token_pair_contrast.b20bb080d170`, `resources/css/paper.css`)

## What the engine would have you do first

8 task(s) were proposed; the first six, in the engine's order:

1. Resolve d8.token_pair_contrast: Adjust one of the two tokens until the pair reaches 4.5:1 on the dark theme, or stop using
2. Resolve d10.health_endpoint: Make the endpoint return a 503 when the database or another required dependency fails, so 
3. Resolve d8.focus_visible: Keep the default outline, or add a :focus-visible rule with a visible outline or box-shado
4. Resolve d5.route_inventory_vs_claims: Check that the feature is built and reachable; if it is not, remove it from the feature li
5. Resolve d3.heading_structure: Give the screen one <h1> naming the page.
6. Resolve d9.token_usage_vs_profile: Declare the colour as a design token (a custom property) and reference it with var(--name)

The full list, with files and lines, is in `2026-10-25-f31b958.plan.md`.

## What this baseline is not

- It does not say how the product feels to a person. Dimensions 7 (perceived value), 11 (retention) and 12 (customer outcomes) cannot be measured from source and stay at zero.
- 21 automated check(s) had no result (not built yet, or they need a rendered page, axe, Lighthouse or a test report); they count as zero and are named in the score file under `missing_evidence`.
- Static checks can raise false positives (for example a label that lives in another file, or a template that is never mounted); each finding names the file and line so it can be dismissed.
- `release_ready` needs observed or customer evidence that does not exist for any Dot product yet. The score will move when assessments by a fresh reviewer and real sessions are added.

## Files

- `2026-10-25-f31b958.json`: the score report (the numbers above)
- `2026-10-25-f31b958.evidence.json`: the evidence that was scored
- `2026-10-25-f31b958.analysis.json`: the raw static analysis
- `2026-10-25-f31b958.plan.md`: the ranked task list

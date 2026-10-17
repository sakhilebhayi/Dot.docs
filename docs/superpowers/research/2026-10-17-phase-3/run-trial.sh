#!/bin/sh
# Phase 3 evidence: the full trial matrix. Run from the scratch copy that holds
# node_modules, resources/js/editor (a copy of the repo's) and tests/js/moduleLoader.js.
set -e
mkdir -p results
for c in package copy; do for v in naive filtered; do for n in 2 3; do
  COLLAB=$c SEEDS=200 OPS=300 VARIANT=$v CLIENTS=$n node tests/js/collab.trial.mjs > results/trial-$c-$v-$n.jsonl
done; done; done
for n in 2 3; do
  COLLAB=copy PROFILE=typing SEEDS=200 OPS=300 VARIANT=naive CLIENTS=$n node tests/js/collab.trial.mjs > results/trial-copy-naive-$n-typing.jsonl
  COLLAB=copy SKIP_TRAILING=1 SEEDS=200 OPS=300 VARIANT=naive CLIENTS=$n node tests/js/collab.trial.mjs > results/trial-copy-naive-$n-skiptrailing.jsonl
done
COLLAB=copy SEEDS=100 OPS=300 VARIANT=naive CLIENTS=3 FINAL_DOCS=results/final-docs.json node tests/js/collab.trial.mjs > /dev/null
node tests/js/collab.scenarios.mjs > results/scenarios-package.txt
COLLAB=copy node tests/js/collab.scenarios.mjs > results/scenarios-copy.txt

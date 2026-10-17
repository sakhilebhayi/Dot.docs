#!/bin/sh
# Phase 3 evidence: run the SQLite bench scenarios one after another.
# Usage: sh run-bench.sh <workdir> <seconds>
W=$1; SECS=${2:-90}; B=$(dirname "$0")/sqlite-bench.php; S=$(dirname "$0")/sqlite-bench-summary.php
mkdir -p "$W"
scenario() { # label journal docs typists-per-doc readers-per-doc docKB fillMs [intervalMs]
  label=$1; j=$2; docs=$3; tpd=$4; rpd=$5; kb=$6; fill=$7; iv=${8:-1500}
  D="$W/$label"; rm -rf "$D"; mkdir -p "$D"
  php "$B" setup "$D/bench.sqlite" "$j" "$docs" "$kb" > /dev/null
  who=0
  for d in $(seq 1 "$docs"); do
    for t in $(seq 1 "$tpd"); do who=$((who+1)); cp=0; [ "$t" = 1 ] && cp=10
      php "$B" run "$D/bench.sqlite" typist "$d" "$who" "$SECS" "$D/p$who.json" "$iv" "$cp" "$fill" "$kb" & done
    for r in $(seq 1 "$rpd"); do who=$((who+1)); php "$B" run "$D/bench.sqlite" reader "$d" "$who" "$SECS" "$D/p$who.json" "$iv" 0 0 "$kb" & done
  done
  wait
  php "$S" "$label" "$D"
}
scenario A-delete-3typists-1doc delete 1 3 2 100 5
scenario B-wal-3typists-1doc     wal    1 3 2 100 5
scenario C-delete-12typists-4docs delete 4 3 2 100 5
scenario D-wal-12typists-4docs    wal    4 3 2 100 5
scenario E-delete-3typists-1MBdoc delete 1 3 2 1000 30
scenario F-delete-stress-24typists-500ms delete 4 6 2 100 5 500
scenario G-wal-stress-24typists-500ms    wal    4 6 2 100 5 500

#!/usr/bin/env python3
"""Distil and merge Infection mutation reports across DB backends.

The Infection workflow runs a `shard x db` matrix (each cell mutates a
subset of `src/` against one database backend). A mutant is only a true
test-suite gap if it survives in *every* backend that covered it — if any
backend kills it, our suite catches it somewhere. This script computes
that union.

Two modes:

  distill  Reduce one cell's `infection.json` (which embeds the full
           source of every mutated file — hundreds of MB) down to a
           compact per-mutant status record, safe to upload as an
           artifact.

  merge    Union the compact records from every cell. A mutant is
           classed as killed if any backend killed/timed-out/errored it,
           escaped only if it survived everywhere it ran, uncovered only
           if no backend ever covered it. Emits a Markdown summary, the
           escaped-everywhere list, a machine-readable JSON, and an
           optional Stryker-dashboard report.

MSI math mirrors Infection's own:
    MSI         = (killed + timeout + error) / (killed + timeout + error + escaped + uncovered)
    covered MSI = (killed + timeout + error) / (killed + timeout + error + escaped)
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from collections import defaultdict

# Top-level arrays Infection writes in its JSON logger. Note the report
# uses "timeouted"/"uncovered"/"errored"; skipped + ignored mutants are
# not emitted as entries and are excluded from MSI by construction.
STATUS_ARRAYS = ["killed", "escaped", "errored", "timeouted", "uncovered"]

# Mutant statuses that mean "the suite detected the mutation".
DETECTED = {"killed", "timeouted", "errored"}


def norm_path(path: str) -> str:
    """Normalise an absolute CI path to a repo-relative one (from src/)."""
    marker = "/src/"
    idx = path.find(marker)
    if idx != -1:
        return path[idx + 1 :]
    return path


def mutant_records(infection: dict, array: str):
    for entry in infection.get(array) or []:
        mutator = entry.get("mutator", {})
        yield [
            norm_path(mutator.get("originalFilePath", "")),
            mutator.get("originalStartLine", 0),
            mutator.get("mutatorName", ""),
            entry.get("diff", ""),
        ]


def distill(args: argparse.Namespace) -> None:
    with open(args.infection) as handle:
        data = json.load(handle)

    out = {"db": args.db, "shard": args.shard, "stats": data.get("stats", {})}
    for array in STATUS_ARRAYS:
        out[array] = list(mutant_records(data, array))

    with open(args.out, "w") as handle:
        json.dump(out, handle)

    counts = {array: len(out[array]) for array in STATUS_ARRAYS}
    print(f"distilled db={args.db} shard={args.shard}: {counts}")


def mutant_key(record):
    file, line, name, diff = record
    diff_hash = hashlib.sha1(diff.encode("utf-8")).hexdigest()[:12]
    return (file, line, name, diff_hash)


def classify(seen: set) -> str:
    if "killed" in seen:
        return "killed"
    if "timeouted" in seen:
        return "timeout"
    if "errored" in seen:
        return "error"
    if "escaped" in seen:
        return "escaped"
    return "uncovered"


def merge(args: argparse.Namespace) -> None:
    seen_status = defaultdict(set)   # key -> set of raw Infection statuses
    detail = {}                      # key -> [file, line, mutator, diff]
    backends = set()

    for path in args.inputs:
        with open(path) as handle:
            cell = json.load(handle)
        backends.add(cell.get("db", "?"))
        for array in STATUS_ARRAYS:
            for record in cell.get(array, []):
                key = mutant_key(record)
                seen_status[key].add(array)
                detail.setdefault(key, record)

    counts = {"killed": 0, "timeout": 0, "error": 0, "escaped": 0, "uncovered": 0}
    escaped_everywhere = []
    divergent = 0  # killed on some backends, escaped on others

    for key, seen in seen_status.items():
        bucket = classify(seen)
        counts[bucket] += 1
        if (seen & DETECTED) and ("escaped" in seen):
            divergent += 1
        if bucket == "escaped":
            escaped_everywhere.append(detail[key])

    numerator = counts["killed"] + counts["timeout"] + counts["error"]
    covered_denom = numerator + counts["escaped"]
    total_denom = covered_denom + counts["uncovered"]
    msi = round(numerator / total_denom * 100, 2) if total_denom else 0.0
    covered_msi = round(numerator / covered_denom * 100, 2) if covered_denom else 0.0

    escaped_everywhere.sort(key=lambda r: (r[0], r[1], r[2]))

    summary = {
        "backends": sorted(backends),
        "counts": counts,
        "msi": msi,
        "coveredMsi": covered_msi,
        "divergent": divergent,
        "totalMutants": total_denom,
    }

    if args.out_json:
        with open(args.out_json, "w") as handle:
            json.dump(
                {**summary, "escapedEverywhere": escaped_everywhere},
                handle,
                indent=2,
            )

    if args.out_summary:
        write_markdown(args.out_summary, summary)

    if args.out_escaped:
        write_escaped(args.out_escaped, escaped_everywhere, summary)

    if args.out_stryker:
        write_stryker(args.out_stryker, seen_status, detail, args.source_root)

    # Always echo the headline to stdout / job log.
    print(
        f"UNION MSI={msi}%  covered-MSI={covered_msi}%  "
        f"killed={counts['killed']} timeout={counts['timeout']} "
        f"error={counts['error']} escaped={counts['escaped']} "
        f"uncovered={counts['uncovered']} divergent={divergent} "
        f"backends={sorted(backends)}"
    )


def write_markdown(path: str, summary: dict) -> None:
    c = summary["counts"]
    lines = [
        "## Mutation testing — union across backends",
        "",
        f"Backends merged: **{', '.join(summary['backends'])}**",
        "",
        "| Metric | Value |",
        "| --- | --- |",
        f"| MSI | **{summary['msi']}%** |",
        f"| Covered MSI | **{summary['coveredMsi']}%** |",
        f"| Killed | {c['killed']} |",
        f"| Timed out | {c['timeout']} |",
        f"| Errored | {c['error']} |",
        f"| Escaped (every backend) | {c['escaped']} |",
        f"| Uncovered (every backend) | {c['uncovered']} |",
        f"| Backend-divergent | {summary['divergent']} |",
        f"| Total (excl. skipped) | {summary['totalMutants']} |",
        "",
        "_Escaped = survived in every backend that covered it (a true "
        "test-suite gap). Backend-divergent = killed on some backends, "
        "escaped on others (a backend-specific assertion gap)._",
        "",
    ]
    with open(path, "w") as handle:
        handle.write("\n".join(lines))


def write_escaped(path: str, escaped, summary: dict) -> None:
    lines = [
        f"# Escaped in every backend ({summary['backends']}) — {len(escaped)} mutants",
        "",
    ]
    for file, line, name, diff in escaped:
        lines.append(f"{file}:{line}  [M] {name}")
        for diff_line in diff.strip("\n").splitlines():
            lines.append(f"    {diff_line}")
        lines.append("")
    with open(path, "w") as handle:
        handle.write("\n".join(lines))


STRYKER_STATUS = {
    "killed": "Killed",
    "timeout": "Timeout",
    "error": "RuntimeError",
    "escaped": "Survived",
    "uncovered": "NoCoverage",
}


def write_stryker(path: str, seen_status, detail, source_root: str) -> None:
    """Build a Stryker-dashboard mutation report from the union.

    Locations are minimal (start line only; column/end fabricated) because
    Infection's JSON doesn't carry columns — the dashboard validates the
    schema and reads the score; the line-level view stays approximate.
    """
    files = {}
    source_cache = {}

    for key, seen in seen_status.items():
        file, line, name, _diff = detail[key]
        bucket = classify(seen)

        if file not in files:
            source = source_cache.get(file)
            if source is None:
                source = ""
                full = os.path.join(source_root, file) if source_root else file
                if os.path.isfile(full):
                    with open(full, encoding="utf-8", errors="replace") as handle:
                        source = handle.read()
                source_cache[file] = source
            files[file] = {"language": "php", "source": source, "mutants": []}

        files[file]["mutants"].append(
            {
                "id": hashlib.sha1(repr(key).encode()).hexdigest(),
                "mutatorName": name,
                "status": STRYKER_STATUS[bucket],
                "location": {
                    "start": {"line": max(line, 1), "column": 1},
                    "end": {"line": max(line, 1) + 1, "column": 1},
                },
            }
        )

    report = {
        "schemaVersion": "1",
        "thresholds": {"high": 80, "low": 60},
        "files": files,
    }
    with open(path, "w") as handle:
        json.dump(report, handle)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="mode", required=True)

    p_distill = sub.add_parser("distill", help="compact one cell's infection.json")
    p_distill.add_argument("infection")
    p_distill.add_argument("out")
    p_distill.add_argument("--db", required=True)
    p_distill.add_argument("--shard", required=True)
    p_distill.set_defaults(func=distill)

    p_merge = sub.add_parser("merge", help="union compact records from all cells")
    p_merge.add_argument("inputs", nargs="+")
    p_merge.add_argument("--out-summary")
    p_merge.add_argument("--out-escaped")
    p_merge.add_argument("--out-json")
    p_merge.add_argument("--out-stryker")
    p_merge.add_argument(
        "--source-root",
        default=".",
        help="repo root for reading source into the Stryker report",
    )
    p_merge.set_defaults(func=merge)

    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()

# DTO stack allocation benchmark

## Objective

Quantify the allocation profile of the `MaterializedResult` DTO stack that now
backs `PathFinderService::findBestPaths()` and document the guard-rail
behaviour of the bottleneck scenarios that exercise it.

## Methodology

Two PhpBench runs were captured:

1. **Baseline (pre-stack refactor)** – commit `ba12fff04cf4d427244b6ef7701f6c1e1f1b7a38`
   which predates the DTO stack landing.
2. **Current stack** – branch `work` (`HEAD`) containing the DTO stack changes.

Each run executed the bottleneck mandatory minima subject with a single
iteration and revision to surface peak allocation deltas:

```bash
vendor/bin/phpbench run \
    --config=phpbench.json \
    --report=p2p_bottleneck_breakdown \
    --filter=benchFindBottleneckMandatoryMinima
```

## Allocation comparison

| Scenario | Baseline mode | Current mode | Δ mode | Baseline mem_peak | Current mem_peak | Δ mem_peak |
| --- | ---:| ---:| ---:| ---:| ---:| ---:|
| `bottleneck-hop-3` | 4.271 ms | 4.246 ms | −0.025 ms | 5.496 MiB | 5.989 MiB | +0.493 MiB |
| `bottleneck-high-fanout-hop-4` | 16.122 ms | 8.373 ms | −7.749 ms | 6.124 MiB | 6.156 MiB | +0.032 MiB |

Baseline measurements were taken from the PhpBench run above executed against
`ba12fff04cf4d427244b6ef7701f6c1e1f1b7a38`, while the "current" column reflects
the same command on `HEAD` after the DTO stack refactor.

The high fan-out dataset benefits from the tighter DTO lifecycle, shaving
≈48 % from the steady-state runtime relative to the pre-stack baseline, while
the hop-3 fixture now lands within the noise floor of the previous measurement
(−0.025 ms). Peak allocation increases remain below 0.5 MiB in both scenarios,
with the larger DTO stack still fitting comfortably inside the existing guard
budgets.

## Guard-rail summary

Both bottleneck fixtures continue to rely on the default `PathFinder` guard
limits (250 000 visited states / 250 000 expansions with no time budget). The
fresh runs exercise only a single frontier expansion before materialisation
locks in the mandatory minima path, leaving the guard rails untouched:

| Scenario | Guard limits (visited / expansions / time) | Observed metrics | Guard status |
| --- | --- | --- | --- |
| `bottleneck-hop-3` | 250 000 / 250 000 / ∞ | 1 visited, 1 expansion, 0.018 ms elapsed | No limits approached |
| `bottleneck-high-fanout-hop-4` | 250 000 / 250 000 / ∞ | 1 visited, 1 expansion, 0.017 ms elapsed | No limits approached |

Use the guard report on `SearchOutcome::guardLimits()` when iterating on the
fixtures to ensure expansions remain well below the configured ceilings. Keep
these limits in sync with any future fixture or DTO changes so that GC and
allocation tracking continues to reflect real-world guard behaviour.

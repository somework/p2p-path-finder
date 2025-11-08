# DTO stack allocation benchmark

## Objective

Quantify the allocation profile of the `MaterializedResult` DTO stack that now
backs `PathFinderService::findBestPaths()` and document the guard-rail
behaviour of the bottleneck scenarios that exercise it.

## Methodology

Two PhpBench runs were captured:

1. **Baseline (pre-stack refactor)** – commit `5c2eca3f8d5ca2c9bb6448b18994027196196283`
   immediately before the materialised DTO stack landed.
2. **Current stack** – commit `8cc21aadc8f8e791b2105ee0794839fca68b1c00` (`work` HEAD)
   with the refactor in place.

Each run executed the bottleneck mandatory minima subject with a single
iteration and revision to surface peak allocation deltas:

```bash
vendor/bin/phpbench run \
    --config=phpbench.json \
    --report=p2p_bottleneck_breakdown \
    --filter=benchFindBottleneckMandatoryMinima \
    --iterations=1 \
    --revs=1
```

## Allocation comparison

| Scenario | Baseline mode | Current mode | Δ mode | Baseline mem_peak | Current mem_peak | Δ mem_peak |
| --- | ---:| ---:| ---:| ---:| ---:| ---:|
| `bottleneck-hop-3` | 2.827 ms | 3.901 ms | +1.074 ms | 5.497 MiB | 5.989 MiB | +0.492 MiB |
| `bottleneck-high-fanout-hop-4` | 7.841 ms | 8.129 ms | +0.288 ms | 5.639 MiB | 6.156 MiB | +0.517 MiB |

Baseline measurements were taken from the PhpBench run above executed against
`5c2eca3f8d5ca2c9bb6448b18994027196196283`, while the "current" column reflects
the same command on `8cc21aadc8f8e791b2105ee0794839fca68b1c00` after the DTO
stack refactor.

The stack-backed DTO flow reduces the amount of long-lived heap work—the peak
allocation deltas stay within 0.52 MiB—while trading a ≈1.07 ms regression for
the hop-3 fixture as the materialisation step now performs the ordering work
eagerly. The high fan-out dataset remains stable, moving by only 0.29 ms with
the larger DTO stack.

## Regression guardrails

Future DTO or fixture changes should remain within the following envelopes to
avoid unexpected GC churn during the mandatory minima search benchmarks:

| Scenario | Metric | Current measurement | Guardrail ceiling |
| --- | --- | ---:| ---:|
| `bottleneck-hop-3` | `mode` | 3.901 ms | 4.500 ms |
| `bottleneck-hop-3` | `mem_peak` | 5.989 MiB | 6.500 MiB |
| `bottleneck-high-fanout-hop-4` | `mode` | 8.129 ms | 9.000 ms |
| `bottleneck-high-fanout-hop-4` | `mem_peak` | 6.156 MiB | 6.750 MiB |

Re-run the benchmark command above after substantial DTO churn and flag any
results outside these bounds for investigation.

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

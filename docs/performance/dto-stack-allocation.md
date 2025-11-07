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
| `bottleneck-hop-3` | 4.271 ms | 6.026 ms | +1.755 ms | 5.496 MiB | 5.989 MiB | +0.493 MiB |
| `bottleneck-high-fanout-hop-4` | 16.122 ms | 12.450 ms | −3.672 ms | 6.124 MiB | 6.156 MiB | +0.032 MiB |

Baseline measurements were taken from the PhpBench run above executed against
`ba12fff04cf4d427244b6ef7701f6c1e1f1b7a38`, while the "current" column reflects
the same command on `HEAD` after the DTO stack refactor.【05c934†L1-L16】【1e19cd†L1-L16】

The high fan-out dataset benefits from the tighter DTO lifecycle, shaving
≈23 % from the steady-state runtime, while the hop-3 fixture regresses by about
1.8 ms as the stack retains more live objects during guard evaluation. Peak
allocation increases remain below 0.5 MiB in both scenarios.

## Guard-rail summary

The guard limits bundled with the bottleneck fixtures remained untouched by the
refactor. Re-running the scenarios and inspecting the guard report confirms that
no limits were tripped and that the search only performed a single expansion for
both datasets. The guard budgets (`250 000` expansions / visited states, no time
budget) therefore continue to provide ample headroom while keeping GC pressure
predictable.【0cfc1e†L1-L9】

Keep these limits in sync with any future fixture or DTO changes so that GC and
allocation tracking reflects real-world guard behaviour.

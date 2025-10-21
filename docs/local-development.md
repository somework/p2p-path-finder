# Local development without ext-bcmath

Composer enforces the `ext-bcmath` requirement during installation. When the native extension is missing you have two options:

1. Install the extension on your platform before running `composer install`.
2. Temporarily require [`symfony/polyfill-bcmath`](https://github.com/symfony/polyfill-bcmath) to emulate the functions during local development.

The polyfill provides correctness but not the same performance characteristics as the PHP extension. Keep it out of production and CI environments so their behaviour mirrors real-world deployments.

For guard-rail tuning tips (visited-state and expansion limits) see the
["Choosing search guard limits"](../README.md#choosing-search-guard-limits) section of the
README, which summarises heuristics taken from dense-graph tests and benchmarks.

## Benchmark KPIs to capture

Run `vendor/bin/phpbench run --config=phpbench.json --report=p2p_bottleneck_breakdown` after
modifying bottleneck handling so we can keep track of regressions in the mandatory-minimum
benchmarks. Capture the following KPIs once you have a stable baseline and note them in your
pull request description so the assertion thresholds can be tuned:

| Dataset label | Scenario | KPIs to record |
| --- | --- | --- |
| `bottleneck-hop-3` | Legacy three-hop minima chain | Mean execution time (ms), relative standard deviation, and peak memory |
| `bottleneck-high-fanout-hop-4` | New high-fan-out mandatory minima stress test | Mean execution time (ms), relative standard deviation, and peak memory |

Once baseline numbers exist, wire them into `phpbench.json` assertions or CI guard-rails so the
metrics fail fast when regressions exceed the agreed envelope.

For flamegraph-style breakdowns of those datasets (including allocation hotspots and
proposed mitigations) see the [hotspot profiling notes](performance/hotspot-profile.md).

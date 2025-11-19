# Local development prerequisites

The decimal stack now relies exclusively on [`brick/math`](https://github.com/brick/math),
so there is no longer an `ext-bcmath` requirement for either runtime or the test suite.
Instead, ensure your PHP 8.2+ build exposes the standard extensions reported by
`composer check-platform-reqs`:

* `ext-ctype` (or [`symfony/polyfill-ctype`](https://github.com/symfony/polyfill-ctype)).
* `ext-date`.
* `ext-dom`.
* `ext-filter`.
* `ext-hash`.
* `ext-iconv`.
* `ext-json`.
* `ext-libxml`.
* `ext-mbstring` (or [`symfony/polyfill-mbstring`](https://github.com/symfony/polyfill-mbstring)).
* `ext-openssl`.
* `ext-pcre`.
* `ext-phar`.
* `ext-reflection`.
* `ext-simplexml`.
* `ext-spl`.
* `ext-tokenizer`.
* `ext-xml`.
* `ext-xmlwriter`.

Run `composer check-platform-reqs` after provisioning PHP to verify that the locally
enabled extensions match the dependency tree. When the command succeeds, you can install
the dependencies without patching `composer.json` or adding polyfills.

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

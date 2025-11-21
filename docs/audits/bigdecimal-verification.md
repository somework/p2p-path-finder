# BigDecimal determinism verification

This audit captures reproducible logs that confirm the guarded search example and the
PathFinder service property suite still produce deterministic outputs after the BigDecimal
migration. Re-run the commands below whenever you need to compare a prospective change
against the canonical behaviour.

## Guarded search example

Command:

```bash
php examples/guarded-search-example.php
```

Output:

```
Found path with residual tolerance 0.00% and 2 segments
Explored 3/20000 states across 3/50000 expansions in 8.074ms
```

These values match the pre-migration baseline (two-hop route with zero residual tolerance
and three visited states/expansions), demonstrating that guard-limit accounting and the
path ordering heuristics remain stable under the BigDecimal workflow.

## PathFinder service property suite

Command:

```bash
vendor/bin/phpunit --testdox tests/Application/Service/PathFinder/PathFinderServicePropertyTest.php
```

Output:

```
Path Finder Service Property (SomeWork\\P2PPathFinder\\Tests\\Application\\Service\\PathFinder\\PathFinderServiceProperty)
 ✔ Random scenarios produce deterministic unique service paths
 ✔ Permuted order books produce identical results
 ✔ Custom ordering strategy applies to materialized results
 ✔ Dataset scenarios remain deterministic
```

The four property-based fixtures exercise randomised order books, permutation guards and
stable ordering hooks. Passing all assertions confirms that residual tolerance reporting and
FIFO guard-limit tie-breaking continue to behave identically to the BCMath-backed baseline.

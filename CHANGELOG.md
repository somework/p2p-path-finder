# Changelog

All notable changes to this project will be documented in this file.

The format is inspired by [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
- Community documentation: contributing guide, code of conduct and security policy to help
  external collaborators prepare for the `1.0.0-rc` release track.
- Composer metadata for discoverability (keywords, homepage, support and funding
  information).
- `PathResultSet::fromPaths()` factory for assembling ordered collections directly from
  application-level path payloads without hand-crafting `PathResultSetEntry` objects.
- BigDecimal determinism audit logs and a release checklist covering the guard example,
  property suite and quality gates required before tagging a release.

### Changed
- README now links to community resources and highlights how the changelog will track
  progress toward the `1.0.0-rc` milestone.
- Internal: All arithmetic has migrated from BCMath to `Brick\Math\BigDecimal`. Value
  objects now expose decimal accessors, documentation covers the canonical rounding
  policy, and helper guides reference the new decimal fixtures.
- Composer metadata now describes the deterministic BigDecimal search workflow so
  Packagist highlights the migration when browsing the package listing.
- Breaking: `PathFinder::findBestPaths()` now consumes `SpendConstraints` and emits
  `CandidatePath` instances, replacing the previous associative array payloads.
  Custom callbacks or integrations must migrate to the new value objects.
- Breaking: `PathFinderService::findBestPaths()` accepts a
  `PathSearchRequest` encapsulating the order book, search configuration and
  target asset. Update service integrations to construct and pass the DTO.
- Breaking: `PathFinderService` now wires its internal helpers automatically and exposes
  only the `GraphBuilder` and optional `PathOrderStrategy` in its constructor. Custom
  runner hooks are limited to the `withRunnerFactory()` helper for testing, so
  integrations relying on the old multi-argument constructor must be updated.
- Breaking: `SpendConstraints::bounds()` replaces the previous `SpendRange` exposure while
  an `internalRange()` helper now carries the implementation detail. Update callers to
  consume the `bounds()` array payload when inspecting minimum/maximum spend values.
- Documentation: README no longer recommends injecting custom `pathFinderFactory`
  callbacks and the generated API reference hides the internal graph plumbing, clarifying
  which extension points remain supported for integrators.

[Unreleased]: https://github.com/somework/p2p-path-finder/compare/main...HEAD

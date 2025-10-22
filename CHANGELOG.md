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

### Changed
- README now links to community resources and highlights how the changelog will track
  progress toward the `1.0.0-rc` milestone.
- Breaking: `PathFinder::findBestPaths()` now consumes `SpendConstraints` and emits
  `CandidatePath` instances, replacing the previous associative array payloads.
  Custom callbacks or integrations must migrate to the new value objects.

[Unreleased]: https://github.com/somework/p2p-path-finder/compare/main...HEAD

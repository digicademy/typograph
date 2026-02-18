# TypoGraph

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.30.0] - 2026-02-18

Major refactoring; away from controller-based request/response handling and
TypoScript configuration to middleware-based request/response handling and YAML site config configuration.

### Added

- Middleware for endpoint handling added
- Extension configuration added to YAML site configuration

### Fixed

- Compatibility of pagination to simple list requests secured by requiring separate schema configurations for each

### Removed

- Endpointcontroller removed
- TypoScript configuration removed

## [0.20.0] - 2026-02-17

### Added

- Pagination according to graphql.org convention

### Changed

- Improved logging of errors reported by the `webonyx/graphql-php` package

## [0.10.0] - 2026-02-17

### Added

- Capacity for nested queries
- Unit tests for resolver service

### Changed

- Escaping of slashes in response string values disabled

## [0.9.0] - 2025-11-28

### Added

- All the things for a functional extension; this is the official first pre-release.

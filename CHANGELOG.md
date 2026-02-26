# TypoGraph

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.3] - 2026-02-26

### Changed

- Using lowercase documentation file and folder names now for sane GutHub pages handling
- Update README with improved initial information


## [1.0.2] - 2026-02-25

### Changed

- Updated Composer metadata for better tagging and developer information

## [1.0.1] - 2026-02-25

### Changed

- Added patch version to fix Composer version mismatch.

## [1.0.0] - 2026-02-25

### Changed

- Tested successfully against TYPO3 12.4, 13.4. and 14.1. This pterodactyl is ready to fly.

## [0.31.0] - 2026-02-25

### Added

- CLI command for example setup (database, schemas, configuration)
- API tests

### Changed

- Reduced README (it's in the docs now)

### Fixed

- various bugs, mainly related to nested objects that had not been properly unwrapped

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

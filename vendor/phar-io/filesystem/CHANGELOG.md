# Changelog

All notable changes to phar-io/manifest are documented in this file using the [Keep a CHANGELOG](http://keepachangelog.com/) principles.


## [2.0.1] - 30.11.2020

### Changed

- Allow use of PHP 8.0


### Added
- `Filename::isLink(): bool` can now be queried


## [2.0.0] - 17.05.2020

### Changed

- BC break: When instantiated, `Directory` no longer automatically creates the given path
- BC break: `__toString` removed. Call `asString()` explicitly where needed

### Added

- `Directory::ensureExists(int $mode = 0755): void` has been added to ensure the directory exists with a given mode
- `Directory::exists(): bool` can now be queried


### Unreleased

[Unreleased]: https://github.com/phar-io/version/compare/2.0.1...HEAD
[2.0.1]: https://github.com/phar-io/version/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/phar-io/version/compare/1.0.1...2.0.0
[1.0.2]: https://github.com/phar-io/version/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/phar-io/version/compare/1.0.0...1.0.1

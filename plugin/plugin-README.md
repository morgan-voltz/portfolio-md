# plugin/ — WordPress plugin `portfolio-md`

This folder will host the WordPress plugin that accepts Markdown content, stores both the raw Markdown and a derived Gutenberg HTML representation, and exposes a custom REST API for downstream consumers.

The plugin is written in **PHP 8.2+** with modern conventions: PSR-4 autoloading via Composer, dependency injection, a layered architecture inspired by Clean Architecture principles.

**Current status**: not yet implemented. Scaffolding will be created during Phase 1 of the project roadmap.

For complete architectural details, see [`../docs/architecture/01-plugin-php.md`](../docs/architecture/01-plugin-php.md).

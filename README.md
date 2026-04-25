# portfolio-md

One Markdown source, two distinct portfolios. This project publishes a single body of content through two independent web interfaces: a standard WordPress site (for SEO and academic requirements) and a modern technical portfolio built on a C# API and a React frontend.

> **Status**: documentation is complete, implementation is scheduled in phases over the coming months. See the [roadmap](docs/architecture/00-overview.md#7-feuille-de-route) for the detailed plan.

## Why

The project answers two objectives that are usually considered contradictory. The first is an academic constraint: a WordPress portfolio must be delivered for a Computer Science degree. The second is a personal ambition: demonstrate full-stack competence through a modern architecture (Clean Architecture, headless consumption, strict separation of concerns).

The architecture resolves the tension by keeping WordPress as the master CMS while storing content in a portable pivot format (Markdown with YAML frontmatter). Both portfolios read the same source; neither has to compromise for the other.

## Architecture at a glance

The repository is a monorepo organized around three applicative components and a shared content format.

The **pivot format** is GitHub Flavored Markdown with YAML frontmatter. It is the canonical source of truth, guaranteeing that content can leave WordPress at any time without conversion, and that any GFM-compatible renderer can consume it.

The **WordPress plugin** (`plugin/`) accepts Markdown input, stores both the raw Markdown and a derived Gutenberg HTML representation, and exposes a custom REST API. Written in PHP 8.2+ with PSR-4 autoloading via Composer, and organized in layers inspired by Clean Architecture.

The **C# API** (`api/`) acts as a gateway between WordPress and the React frontend. Built on .NET 10 with ASP.NET Core Minimal APIs, it is structured in three projects following the Clean Architecture pattern: `Portfolio.Application` (pure business logic), `Portfolio.Infrastructure` (concrete implementations), and `Portfolio.Api` (HTTP surface). The structure is deliberately prepared for future reuse by additional frontends (desktop, mobile, CLI) without duplicating business logic.

The **React frontend** (`frontend/`) is the technical portfolio surface. Stack: Vite, React 18, TypeScript, React Router, TanStack Query, Tailwind CSS. It consumes the C# API exclusively and has no direct knowledge of WordPress. SEO is not a concern here since the parallel WordPress site handles it.

## Running locally

The development environment runs WordPress and MariaDB in containers (Podman rootless on the author's machine; Docker works equally well — the `docker-compose.yml` is engine-agnostic). The C# API and the React frontend run on the host.

### Prerequisites

- Podman 4+ with `podman-compose`, or Docker 24+ with Docker Compose v2
- PHP 8.2+ and Composer 2 (for the WordPress plugin)
- .NET 10 SDK (for the C# API)
- Node.js 20+ and npm (for the React frontend)
- A `.env` file at the repository root (copy from `.env.example` if provided, otherwise set `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD`, and optionally `WORDPRESS_PORT`)

### WordPress + MariaDB (containers)

```bash
# Make sure the Podman user socket is up (Podman is daemonless, but the API socket is needed)
systemctl --user start podman.socket

# Start the stack in the background
podman-compose up -d

# Tail logs
podman-compose logs -f wordpress

# Stop the stack (keeps volumes)
podman-compose down

# Reset the stack including data (destructive)
podman-compose down -v
```

WordPress is then reachable at `http://localhost:8080` (or the port set via `WORDPRESS_PORT`). Substitute `docker compose` for `podman-compose` if you use Docker.

If `up -d` fails with `container name "..." is already in use`, the stack is already running. Inspect it first, then choose whether to reuse or recreate:

```bash
# Confirm the current state of the stack
podman ps --filter "name=portfolio-md"

# Reuse as-is — nothing to do, just hit http://localhost:8080

# Force a clean recreate (e.g. after editing docker-compose.yml or .env)
podman-compose up -d --force-recreate

# Or stop everything and start fresh (volumes preserved)
podman-compose down && podman-compose up -d
```

### WordPress plugin (PHP)

```bash
cd plugin

# Install PHP dependencies
composer install

# Run the plugin test suite (once PHPUnit is configured)
composer test
```

The plugin source is mounted into the WordPress container via the bind mount declared in `docker-compose.yml`. After `composer install`, activate the plugin from the WP admin (`http://localhost:8080/wp-admin`).

### C# API

```bash
cd api

# Restore dependencies
dotnet restore

# Run the API in watch mode (hot reload on file change)
dotnet watch --project Portfolio.Api

# Run the test suite
dotnet test
```

The API listens on `http://localhost:5000` by default (see `Portfolio.Api/Properties/launchSettings.json` once scaffolded).

### React frontend

```bash
cd frontend

# Install dependencies
npm install

# Start the Vite dev server
npm run dev

# Run the test suite (Vitest)
npm test

# Production build
npm run build
```

The dev server is reachable at `http://localhost:5173`. It expects the C# API to be running on the URL configured in `.env.local` (`VITE_API_BASE_URL`).

### Typical full-stack session

1. `podman-compose up -d` — bring up WordPress and MariaDB
2. `cd api && dotnet watch --project Portfolio.Api` — start the API
3. `cd frontend && npm run dev` — start the React dev server
4. Browse `http://localhost:8080` (WordPress) and `http://localhost:5173` (React)

## Documentation

Complete architectural documentation lives in [`docs/architecture/`](docs/architecture/). The recommended reading order is:

1. [`00-overview.md`](docs/architecture/00-overview.md) — project vision and component map (about 15 minutes)
2. [`01-plugin-php.md`](docs/architecture/01-plugin-php.md) — WordPress plugin internals (about 30 minutes)
3. [`02-api-csharp.md`](docs/architecture/02-api-csharp.md) — C# API and Clean Architecture (about 25 minutes)
4. [`03-frontend-react.md`](docs/architecture/03-frontend-react.md) — React frontend architecture (about 20 minutes)
5. [`99-decisions.md`](docs/architecture/99-decisions.md) — architectural decision records

Pedagogical documentation aimed at junior developers discovering the project will live in [`docs/pedagogie/`](docs/pedagogie/), written in French.

## Working with AI

This project uses Claude Code as a mentoring tool throughout its development. The collaboration contract is formalized in [`CLAUDE.md`](CLAUDE.md). In short: Claude guides, explains, reviews code, and maintains documentation — but does not write application code on the author's behalf, and never signs commits. This constraint is deliberate. The project is, above all, a learning exercise.

## Technology stack

| Layer | Stack                                                                   |
|---|-------------------------------------------------------------------------|
| WordPress plugin | PHP 8.2+, Composer, PSR-4, league/commonmark, symfony/yaml              |
| CMS | WordPress 6.x, MariaDB, GeneratePress theme (child)                     |
| API | .NET 10, ASP.NET Core Minimal APIs, Markdig                             |
| Frontend | React 18, TypeScript, Vite, TanStack Query, React Router, Tailwind CSS  |
| Tooling | Git, GitHub, Conventional Commits, PHPUnit, xUnit, Vitest               |
| Deployment | Shared hosting for WP, Linux VPS for API, Cloudflare Pages for frontend |

## Contributing

This project is a personal learning exercise and is not actively seeking contributions at this stage. However, issues that report bugs or suggest documentation improvements are welcome. Please keep in mind that pull requests with code changes are unlikely to be merged during the initial implementation phases, as writing the code itself is part of the learning goal.

The commit convention is [Conventional Commits](https://www.conventionalcommits.org/) with mandatory scope (`plugin`, `api`, `frontend`, `docs`, `repo`). Commits are written in English, imperative present tense. The full Git workflow is documented in [`CLAUDE.md`](CLAUDE.md#5-workflow-git).

## License

Released under the MIT License. See [`LICENSE`](LICENSE) for the full text.

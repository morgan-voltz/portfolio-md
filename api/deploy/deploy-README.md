# api/deploy — container and production compose of the API (skeleton, phase 11)

These files are the deployment skeleton of `Portfolio.Api`, written before the API itself (phase 6) so that the target is known while the code is written: a container image built from `api/` by the SDK image and run by the ASP.NET runtime image as a non-root user (the API on port 8080, the metrics on port 9090 that nothing routes), published to GHCR on a tag `api-vX.Y.Z` and made public (it holds no secret), and deployed by hand on the author's VPS behind its Traefik, as one more service of that stack. The decisions and their alternatives are ADR-023 (`docs/architecture/99-decisions.md`); the operating procedure is `docs/architecture/04-deployment.md`.

| File | Role |
|---|---|
| `Dockerfile` | Two-stage build (restore on the project files first, then publish; `api/.dockerignore` keeps the host's `bin/` and `obj/` out), runtime image pinned by major version, non-root, two ports, no container health check (the runtime image has no curl; Traefik and Uptime Kuma probe `/health/ready` from outside). Not built by any workflow until the solution exists. |
| `docker-compose.prod.yml` | The reference of the live compose on the server: image pinned by tag and digest (the placeholder fails `compose config` until replaced), no host port, Traefik labels for `api.morganvoltz.dev` with a readiness probe, forwarded headers on, no new privileges, every capability dropped, read-only root, memory and pids limits. |
| `.env.example` | The settings the service reads from its environment (`PORTFOLIO__*`, a list as indexed keys); the real `.env` lives on the server only. |

Until phase 6 lands, nothing here runs. Once it does: build locally with `podman build -f deploy/Dockerfile -t portfolio-api:dev .` from `api/`, and the release workflow sketched in `04-deployment.md` publishes the image.

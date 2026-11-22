# syntax=docker/dockerfile:1
# Image of the Portfolio.Api service (ADR-023, phase 11 skeleton).
#
# Two stages: the SDK builds and publishes the API, the ASP.NET runtime image
# runs it as a non-root user, the public API on port 8080 behind the Traefik of
# the VPS and the Prometheus metrics on port 9090, which Traefik never routes.
# It builds the solution of phase 6 since its first lot (ADR-026, point 11); no
# workflow builds it yet, the release workflow comes with lot 8. Build context:
# the api/ folder (api/.dockerignore keeps the host's bin/ and obj/ and the test
# projects out of it).
#
#   podman build -f deploy/Dockerfile -t portfolio-api:dev .
#   podman run --rm -p 8080:8080 \
#     -e PORTFOLIO__WORDPRESSBASEURL=http://host.containers.internal:8000/wp-json/portfolio/v1/ \
#     portfolio-api:dev

ARG DOTNET_VERSION=11.0

# ---- build --------------------------------------------------------------------
FROM mcr.microsoft.com/dotnet/sdk:${DOTNET_VERSION} AS build
WORKDIR /src

# Restore first, on the project files alone, so that a change of code does not
# invalidate the restore layer. The restore of Portfolio.Api.csproj follows its
# ProjectReferences, so the solution file is not needed here; the two
# Directory.*.props files are, because every project imports them (the target
# framework, and the version of each package).
COPY global.json Directory.Build.props Directory.Packages.props ./
COPY Portfolio.Api/Portfolio.Api.csproj Portfolio.Api/
COPY Portfolio.Application/Portfolio.Application.csproj Portfolio.Application/
COPY Portfolio.Infrastructure/Portfolio.Infrastructure.csproj Portfolio.Infrastructure/
RUN dotnet restore Portfolio.Api/Portfolio.Api.csproj

COPY . .
RUN dotnet publish Portfolio.Api/Portfolio.Api.csproj \
      --configuration Release \
      --no-restore \
      --output /app/publish \
      /p:UseAppHost=false

# ---- runtime ------------------------------------------------------------------
FROM mcr.microsoft.com/dotnet/aspnet:${DOTNET_VERSION} AS runtime
WORKDIR /app

# The official image ships a non-root user (APP_UID, 1654) and listens on 8080
# by default since .NET 8. Kestrel listens on both ports: 8080 for the API,
# 9090 for /metrics, which Program.cs maps on that port only (RequireHost).
ENV ASPNETCORE_HTTP_PORTS="8080;9090" \
    DOTNET_EnableDiagnostics=0 \
    ASPNETCORE_ENVIRONMENT=Production
EXPOSE 8080 9090
LABEL org.opencontainers.image.source="https://github.com/morgan-voltz/portfolio-md" \
      org.opencontainers.image.description="Portfolio.Api, the C# gateway of the portfolio"
USER $APP_UID

COPY --from=build --chown=$APP_UID:$APP_UID /app/publish .

# No HEALTHCHECK here: the runtime image has neither curl nor wget, and a
# dotnet process spawned every thirty seconds costs more than it tells. The
# health of the service is read from outside, on GET /health/ready, by Traefik
# (label in docker-compose.prod.yml) and by Uptime Kuma (04-deployment.md).
ENTRYPOINT ["dotnet", "Portfolio.Api.dll"]

# syntax=docker/dockerfile:1.7
#
# Sorify ephemeral test runner image.
#
# Executes ONE user-uploaded Playwright spec per container (node /app/runner.cjs),
# hardened by DockerExecutor flags at `docker run` time:
#   --read-only --cap-drop ALL --security-opt no-new-privileges --user 1000:1000
#   --tmpfs /tmp (noexec) --pids-limit --memory --cpus
# Build via: php artisan sorify:runner-image

FROM node:20-bookworm-slim

WORKDIR /app

ENV NODE_ENV=production \
    PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright

# .npmrc's ignore-scripts=true means npm ci never downloads browsers —
# the explicit playwright install step is required (same as the app image).
# PLAYWRIGHT_BROWSERS_PATH must be set BEFORE this step so browsers land in
# /opt/ms-playwright (readable by any user) instead of /root/.cache.
COPY package.json package-lock.json .npmrc ./
RUN npm ci --omit=dev \
    && npx playwright install --with-deps chromium

# Same layout as the repo: harness.cjs resolves playwright via
# __dirname/../../node_modules — keep scripts under resources/playwright/.
COPY resources/playwright/runner.cjs resources/playwright/harness.cjs resources/playwright/
COPY resources/playwright/smoke.spec.js /smoke/smoke.spec.js

# node:20-bookworm-slim ships the "node" user with uid/gid 1000 — matches
# DockerExecutor's --user 1000:1000 run flag.
USER node

ENTRYPOINT ["node", "/app/resources/playwright/runner.cjs"]

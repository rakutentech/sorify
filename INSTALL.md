# Development (local)

**Option 1** - Simple

```sh
# Update: APP_KEY, DB_PASSWORD, MYSQL_ROOT_PASSWORD
cp .env.example .env

composer setup
php artisan dev
```

**Option 2** - Detailed

```sh
npm run dev
php artisan serve
php artisan queue:work --queue=sorify,default
php artisan schedule:work
```

# Self Hosting

```bash
cp .env.docker.example .env.docker
# Update: APP_KEY, DB_PASSWORD, MYSQL_ROOT_PASSWORD
# Change: APP_ENV=prod for prod ready
docker compose --env-file .env.docker up
```

## Test Execution Modes (local vs ephemeral sandbox)

Switched by an admin on dashboard **Admin → System**:

| Mode | What happens | Security |
|---|---|---|
| `local`  | Tests run as a node process in the app container | Test code shares the app's filesystem, network and user |
| `ephemeral` | Each test runs in a one-off hardened container on a separate (rootless) Docker daemon | Test code cannot reach the app, its secrets, or the host |

### Enabling ephemeral mode (VM with docker-compose)

```sh
# 1. One-time host setup: rootless daemon for a dedicated sorify-runner user,
#    shared runs dir, runner network, egress iptables lockdown
SORIFY_HOST_IP=<vm-primary-ip> sudo -E bin/setup-ephemeral-runner

# 2. Point compose at the rootless daemon (docker-compose.yml reads these):
#    export SORIFY_RUNNER_SOCKET=/run/user/<uid>/docker.sock
#    export SORIFY_RUNS_DIR=/srv/sorify/runs

# 3. Build the runner image
php artisan sorify:runner-image
```

Optional hardening — gVisor (`runsc`) syscall interception on the rootless
daemon:

```sh
sudo apt-get install -y --no-install-recommends gvisor-runsc
# then register runsc on the rootless daemon via its daemon.json:
# {"runtimes":{"runsc":{"path":"/usr/bin/runsc"}}}
```

Using the main (root) docker daemon also works — the admin readiness panel
warns that its socket is root-equivalent on the VM.

### Parallelism

```sh
docker compose up -d --scale queue=8   # Default 3 concurrent tests
```

Every ephemeral test container is capped at `--cpus 2 --memory 2g`
(`SORIFY_EXECUTION_CPUS` / `SORIFY_EXECUTION_MEMORY`), so size the worker
count against the VM: ~1 worker per 2 cores is a comfortable rule of thumb.

## Notes for users

- Fully self hosted, no telemetry
- What your AI does in yolo mode is your responsibility
- Files and folders on your system, that this system creates/requires
  - `~/.sorify`: your credentials
  - `~/.sorify-bin/`: chrome extension mcp
  - `~/.sorify-recordings/`: chrome extension mcp's events recordings

**Envs info**

```js
APP_URL=https://<your-host>/sorify
ASSET_URL=https://<your-host>/sorify

# Sorify App related
SORIFY_SCREENSHOT_RETENTION_DAYS=30
DB_QUEUE_RETRY_AFTER=300

# For local, check logs for invite email for new users
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=<your-mail-host>

# More sorify settings, see envs in config/sorify.php
```

# Sorify

QA automation platform for managing and running Playwright test suites.

## Deploy

GitHub Actions workflow:
```
.github/workflows/release.yml
```

## Set up Local Environment

**1. Create the database**
```sql
CREATE DATABASE sorify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**2. Install dependencies**
```bash
composer install
npm install
```

**3. Configure environment**
```bash
cp .env.example .env
php artisan key:generate
```
Update `.env` with your database credentials.

**4. Run migrations**
```bash
php artisan migrate
```

**5. Seed dummy data (optional)**
```bash
php artisan db:seed
```

**6. Start the application**
```bash
# Terminal 1 — PHP server
php artisan serve

# Terminal 2 — Frontend assets
npm run dev

# Terminal 3 — Queue worker (required for running tests)
php artisan queue:work --queue=test-runner,default
```

## Verify

```
http://localhost:8000/sorify
```

## Running Tests

```bash
php artisan test
```

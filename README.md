# Fun Alter Ego Gram — Run locally

This is a Laravel application for generating “alter-ego” anagram patterns from a source name, with a simple CRUD for Source Names and a searchable UI that supports Pause/Resume and shows live progress.

Below are minimal, copy‑pasteable steps to run locally.

---

## 1) Prerequisites
- PHP 8.1+ (with intl, mbstring, sqlite, pcntl recommended)
- Composer
- Node.js 18+ and npm (or Yarn/Pnpm)
- SQLite (recommended for quick start) or MySQL/PostgreSQL

## 2) Clone and install
```
git clone <this-repo-url>
cd fun-alter-ego-gram
composer install
npm install
cp .env.example .env
php artisan key:generate
```

## 3) Use SQLite (quick start)
```
mkdir -p database
: > database/database.sqlite
```
Edit .env and set these lines:
```
DB_CONNECTION=sqlite
DB_DATABASE="{PROJECT_PATH}/database/database.sqlite"
DB_FOREIGN_KEYS=true
```
Replace {PROJECT_PATH} with the absolute path to this project (or omit quotes on macOS/Linux if you prefer). If you prefer MySQL/PostgreSQL, set the usual DB_* variables instead and create the database beforehand.

## 4) Run migrations
```
php artisan migrate
```

## 5) Load data (tokens, words, patterns)
The app’s search requires three tables to be populated: tokens, words, and patterns.

- Seed tokens from the repository’s token lists:
```
php artisan tokens:seed
```
- Import words into the words table (you can reuse the same repository lists):
```
php artisan words:import resources/token_words
```
- Generate the pattern catalog (ordered with precomputed min lengths):
```
php artisan patterns:generate
```
Optional helpers:
- List patterns for a given source quickly:
```
php artisan patterns:list --source="John Adam Doe" --limit=20
```
- Show token word matches (by source):
```
php artisan words:matches "John Adam Doe" --include-boring
```

## 6) Start the app
In one terminal:
```
php artisan serve
```
In another terminal (for assets):
```
npm run dev
```
Open http://127.0.0.1:8000 in your browser.

## 7) Use the UI
- Home (/) shows the Source Names page.
- Enter a source name (e.g., “John Adam Doe”) and submit. This creates a SourceName, preloads pattern rows tailored to the source, and redirects to the Search page.
- The Search page auto-starts processing (it runs in small HTTP steps). You can Pause/Resume. While running, you’ll see:
  - currentPattern
  - patterns searched / total
  - alteregos found so far
  - time elapsed
- On pause, the alter egos found so far are listed.

## Troubleshooting

### “This site can’t be reached” at http://127.0.0.1:8000/
This almost always means the dev server isn’t running on that host/port, or something is blocking the connection.

Quick fixes:
- Start the Laravel dev server (bind explicitly):
  - php artisan serve --host=127.0.0.1 --port=8000
  - If 8000 is busy, try: php artisan serve --host=127.0.0.1 --port=8001 and open http://127.0.0.1:8001/
- Alternatively use PHP’s built-in server from project root:
  - php -S 127.0.0.1:8000 -t public
- Test the server from terminal:
  - curl -I http://127.0.0.1:8000
- Check for port conflicts (replace 8000 if you picked a different port):
  - macOS/Linux: lsof -i :8000
  - Windows (PowerShell): netstat -ano | findstr :8000
  - If another process is using the port, stop it or choose a different port.
- VPN/Firewall: Temporarily disable or allow localhost connections.
- WSL/Docker: Bind to 0.0.0.0 and map the port, then connect to localhost from the host OS:
  - php artisan serve --host=0.0.0.0 --port=8000
  - Then browse to http://localhost:8000 (ensure the container/WSL port is exposed).

Notes:
- APP_URL in .env doesn’t start the server; it only affects URL generation.
- Ensure you’re in the project directory when running commands, and that composer install completed without errors.

### Data reset
- If you switch databases, run a full reset:
```
php artisan migrate:fresh
php artisan tokens:seed
php artisan words:import resources/token_words
php artisan patterns:generate
```

### UI/Assets
- If assets don’t render, ensure `npm run dev` is running (or `npm run build` for a one-off build).

### Importing words
- If words:import fails, ensure the folder exists and is structured like `resources/token_words/<token_type>/{ok,fun,boring}.txt`.

### Build token files from source folders
- If you’d rather build token files from your own “altego” source folders first, there’s a helper:
```
php artisan token_words:build --save --dest=resources/token_words
```
Then re-run the seed/import steps.

---

The rest of this README contains the default Laravel information below.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).


---

## Principal Review & Roadmap
For a full architecture review, refactor proposals, and a prioritized roadmap, see:
- docs/principal_review.md

## Anagram Groups and Backfill
Recent updates introduced anagram grouping and default deduplication in matching.

- Run migrations (required):
  - php artisan migrate
- If you already have words loaded, backfill anagram groups:
  - php artisan words:backfill-anagram-groups
- Alternatively, re-import word lists (anagram groups will be linked during import):
  - php artisan words:import resources/token_words

## Configuration: phrases-per-step cap (optional)
To keep each runStep responsive, you can cap how many phrases a single pattern emits per HTTP step. By default it is unlimited.

- In your .env, set for example:
```
PHRASES_PER_STEP_CAP=100
```
- Or leave it unset/0 to disable the cap (unlimited).

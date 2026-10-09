# LearnTrack

LearnTrack is a backend service for medical schools and teaching hospitals. Educators ask an AI assistant such as Claude which learners in their groups are behind and why, and act on the answer in the same conversation, through an MCP server that sits next to a REST and GraphQL API.

## Local setup

Needs Docker, PHP 8.5 with Composer, and Node 24.

```
cp .env.example .env
composer install && npm ci
php artisan key:generate
docker compose up -d            # app on http://localhost:8080, MySQL 8 on port 3306
php artisan migrate --seed      # demo data
npm run dev                     # Vite, for the three pages
```

The app container serves the assets built into its image. After a frontend change, run `docker compose up -d --build`. For hot reload, run the app on the host with `php artisan serve` (http://localhost:8000) next to `npm run dev`.

Tests run against MySQL, in the `learntrack_test` database that compose creates. Copy the test settings once with `cp .env.testing.example .env.testing`. Larastan needs a PHP `memory_limit` of at least 256M. The checks to run before every pull request are listed in [CLAUDE.md](CLAUDE.md).

## Documentation

The design, the API and MCP contracts, the ADRs and the tickets are in [docs/](docs/).

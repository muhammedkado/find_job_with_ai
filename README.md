# Find Job with AI

Upload a CV, get it parsed into a structured profile by Gemini, edit it, then see it scored against real job postings pulled from the JSearch API.

## Live demo

**https://findjob.mkado.dev**

No account needed. Click "Try with sample CV" for a zero-cost walkthrough, or upload a real PDF to see the actual Gemini parsing. Both Gemini and job-search calls share a daily budget across every visitor — once it's used up for the day, the app automatically falls back to sample data instead of erroring out.

## How it works

1. **Upload** — a PDF is parsed locally (`smalot/pdfparser`), then Gemini extracts a structured profile (name, experience, skills, projects, ...). The file itself is never stored.
2. **Profile** — the parsed profile is fully editable. Each experience/project/summary field has an "Enhance with AI" button that rewrites it via Gemini.
3. **Matches** — your skills and experience are sent along with live job postings (JSearch API) to Gemini, which scores each posting's compatibility and explains why.

## Tech stack

- Laravel 10, SQLite (no real persistence needed — this app is stateless)
- [Gemini API](https://aistudio.google.com/apikey) via `amrachraf6699/laravel-gemini-ai`
- [JSearch API](https://rapidapi.com/letscrape-6bRBa3QguO5/api/jsearch) (RapidAPI) for live job postings — optional, falls back to sample jobs if unset
- Blade + [Alpine.js](https://alpinejs.dev) + Tailwind CSS v4 (built with Vite) — no SPA framework, no separate JSON API

## Local setup

```sh
git clone https://github.com/muhammedkado/find_job_with_ai.git
cd find_job_with_ai
composer install
npm install
cp .env.example .env
php artisan key:generate
```

`.env.example` defaults to SQLite — no database server needed:

```sh
touch database/database.sqlite
php artisan migrate
```

Add your keys to `.env` (both optional — the app works with neither, using sample data throughout):

```
GEMINI_API_KEY=      # https://aistudio.google.com/apikey
RAPIDAPI_KEY=         # https://rapidapi.com/letscrape-6bRBa3QguO5/api/jsearch
```

Run it:

```sh
npm run build   # or `npm run dev` while working on the frontend
php artisan serve
```

## Endpoints

The wizard talks to itself over these session-based (CSRF-protected) routes — this isn't a public JSON API:

| Route | What it does |
|---|---|
| `POST /wizard/upload-cv` | `{cv: <file>}` or `{sample: true}` → parsed profile |
| `POST /wizard/enhance` | `{text, section}` → AI-rewritten text |
| `POST /wizard/jobs` | candidate profile → scored job matches (or sample matches) |
| `GET /wizard/jobsearch` | raw JSearch passthrough (or sample jobs) |

## Demo-safety design

This app calls two paid APIs from public, unauthenticated endpoints, so it's built to fail safe rather than fail expensive:

- **Shared daily budget** (`app/Services/DemoBudget.php`) — a simple date-keyed cache counter caps total Gemini and JSearch calls per day (`DEMO_GEMINI_DAILY_BUDGET` / `DEMO_JSEARCH_DAILY_BUDGET`). Once spent, requests get sample data instead of a paid API call.
- **Per-IP throttle** (`throttle:ai` in `RouteServiceProvider`) — on top of the shared budget, one IP can't burn through the whole day's quota alone.
- **"Try with sample CV" and job-search-without-a-key both cost nothing** — they never touch Gemini or JSearch, so the demo is fully explorable even with a $0 budget.
- **JSearch responses are cached 24h** per query, so repeated searches don't re-spend the budget.

## Notes on the code

This app previously had no frontend at all (the root route rendered Laravel's stock welcome page while `find_job_with_ai`'s actual features were three unauthenticated JSON endpoints), a hardcoded `gemini-1.5-pro` model reference, a rate limiter that was defined but never wired up, and a bug in the enhance endpoint that treated a plain string API response as an array. All of that is fixed here; see `app/Http/Controllers/CVController.php` and `JobSearchController.php`.

**Laravel version note:** this app runs on Laravel 10, which is past its security-support window — `composer audit` currently reports advisories against the framework itself with no 10.x patch available (fixed only in 12.60+/13.x). A framework upgrade is recommended before this handles meaningful traffic; it wasn't done here to avoid an unreviewed, high-risk major-version bump alongside this rebuild.

## License

MIT.

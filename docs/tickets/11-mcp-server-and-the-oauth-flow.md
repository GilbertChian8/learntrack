# 11: MCP server and the OAuth flow

**Estimate:** 3 days | **TDD item:** 11 | **ADRs:** ADR-001, ADR-002, ADR-003, ADR-017 | **Depends on:** 03 | **Phase:** 2

## Goal

Claude can connect: discovery, dynamic client registration, login, consent, token, and a working `POST /mcp` that answers `initialize` and an empty `tools/list`, all on one Laravel application. The three web pages exist as Inertia pages. The housekeeping command exists. Tools come in tickets 12 and 13.

## Read first

- TDD: Request path and routing (the whole table and the Proxies paragraph), OAuth and web pages (the table and the lifetimes), MCP tools (the paragraph above the table and the Rules), item 11, Security and access, Testing strategy (OAuth flow check).
- mcp-tools.md: section 1 (Server) and section 2 (Conventions).
- ADR-002, ADR-003, ADR-013, ADR-014, ADR-017.

## Allowed paths

`app/Mcp/Servers/EducatorServer.php`, `app/Mcp/Tools/BaseTool.php`, `app/Mcp/Schemas/**`, `routes/ai.php`, `routes/oauth.php`, `routes/web.php`, `app/Http/Controllers/Web/**`, `app/Http/Middleware/{TrustProxies,EnsureEducatorOnConsent}.php` (or the equivalent in `bootstrap/app.php`), `app/Console/Commands/Housekeeping.php`, `app/Providers/AppServiceProvider.php` (Passport view and MCP registration only), `config/mcp.php`, `config/passport.php`, `resources/js/pages/mcp/**`, `resources/js/components/**` (only what the two pages need), `resources/views/app.blade.php`, `bootstrap/app.php`, `compose.yaml`, `.env.example`, `tests/Feature/Mcp/**`, `tests/Feature/OAuth/**`, `tests/Feature/Web/**`, `tests/Feature/Console/**`, `composer.json` and `composer.lock` (laravel/mcp only).

## Steps

1. Install laravel/mcp. If the installed release does not support Laravel 13 or PHP 8.5, stop and raise it. Create `EducatorServer` with the name `LearnTrack Educator`, version `1.0.0`, the instructions text from mcp-tools.md section 1, and an empty tools list. Register `Mcp::web('/mcp', EducatorServer::class)->middleware(['auth:api', 'role:educator'])` and `Mcp::oauthRoutes()` in `routes/ai.php`. Stateless: no session, every call carries the token.
2. Passport for the Claude clients: the authorization code grant with PKCE for public clients (dynamic registration creates public clients), the lifetimes from ticket 03, and the discovery documents from `Mcp::oauthRoutes()`. Call `Passport::ignoreRoutes()` and register in `routes/oauth.php` only the Passport routes this system uses: `GET`, `POST` and `DELETE /oauth/authorize` (web middleware, `auth`, and the educator rule from step 4) and `POST /oauth/token`. None of Passport's token, client or scope management routes exist. v1 defines no OAuth scopes; the consent page lists what the server can do as fixed text.
3. The consent page: `Passport::authorizationView(fn ($parameters) => Inertia::render('mcp/Consent', [...]))` with the client name, the user's name, the three capabilities (read the progress of your groups, assign content, change due dates), `authToken` and `state`. The approve and deny buttons are plain HTML forms posting to `POST /oauth/authorize` and `DELETE /oauth/authorize` (with the CSRF field and `_method`), not Inertia requests, because Passport answers with a redirect to the client's external redirect URI, which an Inertia request cannot follow.
4. A learner on any `/oauth/authorize` request (GET, POST, DELETE) gets the `mcp/Message` page with HTTP 403 and the text "Only educators can connect an AI assistant.", and no authorization code is ever issued. Implement it as a middleware on the authorize routes registered in step 2.
5. `mcp/Message.tsx`: a title, a text, and an optional sign-out form. It is the TDD's error page, also used for notices. `GET /` for a signed-in user renders it with "You are signed in as {name}. Return to your AI assistant to finish connecting." and a sign-out button; a guest is redirected to `/login`. Style all three pages the same way (Tailwind, the starter kit components); no product UI beyond that.
6. Proxies: trust the addresses in `TRUSTED_PROXIES` (comma-separated CIDRs; `10.0.0.0/16` in AWS) for `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Port`, so generated URLs are https behind the ALB and behind a local tunnel. `APP_URL` must match the public URL; document that in `.env.example`.
7. The tool conventions, so tickets 12 and 13 can start in parallel: an abstract `App\Mcp\Tools\BaseTool` (extending laravel/mcp's `Tool`) that builds one PHP array and returns it as structured content plus a text summary, and maps `NotFoundException` to the `<Subject> not found.` tool error; and the shared schema pieces in `app/Mcp/Schemas` (`learner`, `reason`, `counts`, `assignment_stats`, `assignment`) matching mcp-tools.md section 2. Check how the installed laravel/mcp release attaches a text item to a structured response and use that; do not serialize the array twice by hand. A test with a throwaway tool covers the base class.
8. `app:housekeeping`: runs `passport:purge`, deletes expired rows from `cache` and `cache_locks`, and deletes sessions older than the session lifetime. Every statement is safe to repeat. Exit code 0 on success, non-zero on any failure (the scheduler alarm depends on it).
9. Local checks, written down in the PR: the MCP Inspector runs the full flow against `http://localhost:8080/mcp` (discovery, registration, login, consent, token, `tools/list`); Claude Code connects with `claude mcp add --transport http learntrack http://localhost:8080/mcp` and authenticates; Claude Desktop connects through a `cloudflared tunnel --url http://localhost:8080` URL with `APP_URL` set to the tunnel URL.

## Acceptance tests

- `GET /.well-known/oauth-protected-resource` returns JSON whose `resource` is `<APP_URL>/mcp` and whose `authorization_servers` names this host; `GET /.well-known/oauth-authorization-server` lists `authorization_endpoint`, `token_endpoint`, `registration_endpoint` and `S256` under `code_challenge_methods_supported`. With `X-Forwarded-Proto: https` from a trusted address every URL in both documents starts with `https://`; from an untrusted address the header is ignored.
- `POST /mcp` without a token: 401 with a `WWW-Authenticate` header that points at the resource metadata. With a learner's token: 403 `forbidden` in the contract shape. With an educator's token: `initialize` succeeds with the server name and instructions, and `tools/list` returns an empty list.
- The full flow in one feature test: `POST /oauth/register` creates a public client; a signed-in educator opens `GET /oauth/authorize` with a PKCE challenge and sees the consent page (Inertia assertion on the component and the client name); approving redirects to the redirect URI with `code` and `state`; `POST /oauth/token` with the verifier returns an access token that expires in 1 hour and a refresh token; the access token works on `POST /mcp`; the refresh token returns a new pair.
- Denying redirects with `error=access_denied` and no token is issued.
- A signed-in learner on `GET /oauth/authorize` gets the Message component with 403 and the exact text; `oauth_auth_codes` stays empty. A learner's `POST /oauth/authorize` answers 403.
- `GET /` as a guest redirects to `/login`; signed in it renders the Message component with the user's name and no email anywhere in the page props.
- `app:housekeeping` removes expired and revoked tokens and expired cache rows, leaves live rows, exits 0, and running it twice changes nothing the second time.
- `php artisan route:list` shows `/mcp` (POST), the two discovery routes, `/oauth/authorize` (GET, POST, DELETE), `/oauth/token`, `/oauth/register`, `/login`, `/logout`, `/`, `/up` and nothing else: no Passport management routes, nothing from the starter kit.
- Manual, recorded in the PR: Inspector screenshot of a successful `tools/list` after OAuth; Claude Code `/mcp` showing the server connected.

## Out of scope

Any tool (12, 13), rate limits on the OAuth routes and the MCP endpoint (14), the README's connection guide (14).

## PR checklist

- [ ] Title `11: MCP server and the OAuth flow`
- [ ] Consent and deny are plain forms; the external redirect works in a browser
- [ ] No session use on `/mcp`
- [ ] Learner cannot obtain a code or call `/mcp`, both tested
- [ ] All checks green, including `npm run types`
- [ ] Documents changed, if any, listed with the reason

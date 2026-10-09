# ADR-001: Backend framework

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

LearnTrack is a backend only: a REST API, one GraphQL query, an MCP server that AI clients reach over HTTPS with OAuth 2.1, and two web pages (login and consent). Three things make it different from a plain CRUD API. Every interface must apply one authorization rule (requirements 1 and 8). The MCP server must speak OAuth 2.1 exactly the way Claude Code, Claude Desktop and the Claude app expect it (401, discovery documents, browser login, consent, token). The behind rule must live in one place (requirement 6). The team's backend language is PHP, and local development must run with docker compose (app and MySQL).

## Decision required

Which framework builds the application?

## Alternatives evaluated

### Option 1: Laravel (PHP)

**Pros**

- Policies, Passport (OAuth 2.1 server with PKCE) and laravel/mcp are first-party and made to work together
- Mcp::oauthRoutes() publishes the discovery and client registration endpoints Claude needs, and the consent screen is one published view
- The same auth:api guard protects the MCP route, so one Policy is checked the same way from REST, GraphQL and MCP
- Eloquent, validation, rate limiting, the database cache and session drivers and Pest are built in

**Cons**

- Each request boots the framework, a few milliseconds, which is fine at about 1 request per second per task
- Passport and laravel/mcp are dependencies we must keep current as the MCP specification moves

### Option 2: Symfony (PHP)

**Pros**

- Mature and well documented
- API Platform can give REST and GraphQL from one resource definition

**Cons**

- No first-party OAuth 2.1 server or MCP server: both are third-party bundles with their own release pace
- The wiring between the OAuth server and the MCP endpoint is ours to build and test
- More code for the same result

### Option 3: Node.js (TypeScript) with the official MCP SDK

**Pros**

- The reference MCP SDK, first to follow specification changes

**Cons**

- The OAuth server, the ORM, the policy layer and validation are separate libraries to pick and wire together
- Nothing ties the OAuth flow to the MCP server for us
- A second language next to the team's PHP

## Decision

- Option 1, Laravel.
- The hard part of this system is not the CRUD, it is the OAuth 2.1 handshake between Claude and our server. Laravel is the only option where that handshake comes ready: Passport issues the tokens, laravel/mcp publishes the discovery routes and the consent screen, and one guard protects the MCP route. We build two pages, not a protocol.
- One Policy class per model is checked the same way from a controller, a GraphQL resolver and an MCP tool, because all three run in one process (ADR-002). That is how requirement 8 is met by construction.
- Performance does not decide this: at our load the time goes to the database, and all three options pass the 300 ms and 1 s targets.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

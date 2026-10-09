# ADR-003: Authentication for MCP and the APIs

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

The MCP server is reached by Claude Code, Claude Desktop and the Claude app over HTTPS. Every tool call must run as one educator, so the Policy can check which groups they teach (requirements 1 and 8). laravel/mcp supports three ways to authenticate: OAuth 2.1 through Passport, a bearer token through Sanctum, or custom middleware. The REST API and GraphQL also need tokens, and requirement 1 says the system issues its tokens itself. There is no product frontend in v1, so there is no screen where a user could create a token by hand.

## Decision required

How does Claude prove which educator it acts for, and how do API clients authenticate?

## Alternatives evaluated

### Option 1: OAuth 2.1 with Passport (authorization code with PKCE), and Passport tokens for the APIs

**Pros**

- The flow the Claude clients run on their own: 401, discovery, browser login, consent, token
- The educator logs in with their own password and sees what Claude will be allowed to do before approving
- Tokens expire and can be revoked
- The API login endpoint issues a Passport personal access token, so the whole system has one guard and one token table

**Cons**

- We host and style a login page and a consent page
- We keep the signing keys in Secrets Manager and purge expired tokens daily

### Option 2: Static bearer token (Sanctum) for MCP

**Pros**

- Simplest to build
- Works in Claude Code with a header setting

**Cons**

- The educator must create a token somewhere and paste it into the client, and v1 has no screen for that
- The Claude app's connector flow expects OAuth
- A pasted token never expires unless we build revocation

### Option 3: One API key per institution

**Pros**

- One key to configure

**Cons**

- The server cannot tell which educator is calling, so the Policy cannot work
- One leaked key opens every group of the institution

## Decision

- Option 1.
- The MCP specification names OAuth 2.1 as its authorization method, and the Claude clients implement exactly that flow. With Passport the whole flow is one Mcp::oauthRoutes() call and one published view, so the protocol is not ours to get wrong.
- The token carries the educator, which is what every Policy check needs. No tool takes a user id as input, so no tool can be asked to act as someone else.
- The consent page is also a safety feature: the educator reads "read your groups' progress, assign content, change due dates" before Claude can act.
- REST and GraphQL clients log in with email and password and receive a Passport personal access token, so one auth:api guard protects every interface and the Policies see the same user object everywhere.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

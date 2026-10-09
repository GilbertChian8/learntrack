# ADR-002: One application or a separate MCP service

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

The system has three interfaces: REST, GraphQL and MCP. Requirement 8 says every MCP tool must apply the same authorization as the API, and requirement 6 says the behind rule lives in one place. The usual shape for an MCP server at a company with an existing API is a separate small service that calls that API. Here the API and the MCP server are new and built together by one team.

## Decision required

Does the MCP server run inside the Laravel application, or as its own service that calls the REST API?

## Alternatives evaluated

### Option 1: One Laravel application serves REST, GraphQL and MCP

**Pros**

- The MCP tools call the same action and query classes and the same Policies as the controllers
- No network hop, no second token, no contract to keep in sync
- One deploy, one uptime chain, one container in docker compose

**Cons**

- One deploy for every interface, so a bad MCP release also restarts the REST API
- MCP traffic and API traffic share the same tasks

### Option 2: A separate MCP service that calls the REST API over HTTP

**Pros**

- The MCP server can be released and scaled on its own
- The usual shape when the API already exists

**Cons**

- Two authentication layers: Claude's OAuth token to the MCP service, then a token from the MCP service to the API on behalf of the educator
- The MCP service must mirror the API's errors and reasons
- One more service in the request path lowers the uptime math and doubles the Terraform

### Option 3: A separate MCP service that reads the database directly

**Pros**

- No second token

**Cons**

- Two code bases implement the same Policies and the same behind rule, which is exactly what requirement 6 forbids

## Decision

- Option 1.
- Requirements 6 and 8 ask for one rule applied everywhere. In one process that is one class called three ways. In two services it is a contract to keep in sync, and the first drift is a leak.
- The MCP server is small: seven tools that each call one query or one action. It does not need its own scaling or release cycle.
- Option 2 is the upgrade path if MCP traffic ever needs its own release cycle. The tools are thin wrappers over the action and query classes, so moving them out later is a move, not a rewrite.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

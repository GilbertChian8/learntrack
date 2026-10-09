# ADR-017: Web pages, Blade or Inertia with React

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

v1 has three web pages: the login page, the OAuth consent page and an error page. They are the only screens anyone sees, and they open in a browser from the Claude app during the OAuth flow. Nothing in the requirements asks for a product frontend, but a later release will add educator screens, and a group dashboard on the GraphQL query is the first candidate. The team's frontend stack is React with TypeScript.

## Decision required

How are the web pages built: Blade templates, or Inertia with React?

## Alternatives evaluated

### Option 1: Blade templates with one CSS file

**Pros**

- No Node in the image and no build step
- Passport's consent view is Blade by default, so the OAuth pages need no adapter
- Three forms that POST and redirect need no interactivity

**Cons**

- A second UI stack the day the product gets a real screen, so the pages are rewritten then
- Built in a stack the team does not use day to day, so styling and form handling are by hand
- Validation errors and form state are hand-written in every page

### Option 2: Inertia with React (TypeScript) and Tailwind, from the Laravel React starter kit

**Pros**

- The same stack any later screen will use, so Release 2 adds pages, not a framework
- The starter kit ships accessible login pages, form handling and validation display, trimmed to what we need
- Vite builds the assets into the image as static files, so nothing in the request path or the uptime math changes
- One codebase and one deploy, with the routes, Policies and sessions unchanged

**Cons**

- Node and a Vite build stage in the Docker image and in CI, about a minute per build
- The three pages are React (TypeScript) and need scripting enabled in the browser
- The starter kit brings pages we must remove (register, password reset, profile)
- Passport's consent view becomes an Inertia page, returned from a closure in Passport::authorizationView

### Option 3: A separate React SPA that calls the API

**Pros**

- Full separation between frontend and backend

**Cons**

- A second deployable with its own hosting, CORS and token handling
- The OAuth consent page cannot leave the Passport flow without extra work
- Far too much for three pages

## Decision

- Option 2, Inertia with React.
- The pages are small, but they are the start of the product's UI. Building them in the stack the next screens will use means a later release adds pages instead of replacing a framework.
- Nothing in the request path changes: Vite output is static files served by the same container, so the ALB, the tasks and the uptime math stay as they are.
- The starter kit's login page and form handling pay back the build stage they cost. Scope stays at the three pages; no dashboard in v1.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

# ADR-011: Public entry point

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

All traffic must use HTTPS. OAuth discovery requires the MCP endpoint, the metadata documents and the login page to sit on one stable HTTPS origin. Claude connects from the internet. We own a domain, so an ACM certificate is free. The ALB SLA is 99.99%, and CloudFront's is 99.9%.

## Decision required

What is the public entry point?

## Alternatives evaluated

### Option 1: Route 53, then an ALB with an ACM certificate on our domain, HTTP redirected to HTTPS

**Pros**

- TLS ends at the ALB with a free certificate
- One hostname serves the API, GraphQL, MCP and the OAuth pages, which is what discovery needs
- 99.99% in the chain

**Cons**

- No edge caching or edge WAF
- The ALB must forward X-Forwarded-Proto and the app must trust it, or the discovery URLs come out as http

### Option 2: CloudFront in front of the ALB

**Pros**

- An edge network, Shield Standard and an optional WAF

**Cons**

- CloudFront's 99.9% SLA drops the chain to about 99.83%, below target
- Nothing to cache, because every answer is per user
- A second place where headers and caching can go wrong

### Option 3: API Gateway in front of the service

**Pros**

- Managed throttling and authorizers

**Cons**

- Per-request pricing on an API that is called all day
- A 99.95% SLA
- The OAuth pages are HTML, which is not API Gateway's shape

## Decision

- Option 1.
- The 99.9% target leaves about 2.6 hours a year for our own mistakes. Spending 0.1% of availability on an edge network with no content to cache is the wrong trade.
- One origin on one certificate is exactly what the OAuth discovery documents need: the MCP endpoint, the metadata documents and the login page share one host.
- If the Laravel rate limits ever need an edge layer, a WAF rate rule attaches straight to the ALB.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

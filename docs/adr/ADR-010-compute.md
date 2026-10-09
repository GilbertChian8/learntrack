# ADR-010: Application compute

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

One stateless Laravel container serves everything. Load is small and steady: about 1 write per second plus reads. The service must run 24 hours a day for the 99.9% target, which needs two copies in two AZs and a compute link of 99.99% in the uptime chain. Local development is docker compose, so the same image should run in both places.

## Decision required

What runs the application container in AWS?

## Alternatives evaluated

### Option 1: ECS on Fargate, 2 tasks across 2 AZs behind an ALB

**Pros**

- Runs our Docker image unchanged
- A 99.99% SLA
- Rolling deploys and a deployment circuit breaker
- No instances to patch

**Cons**

- A higher price per vCPU than EC2
- A Fargate task is not a place for a cron process, so background work needs its own answer (ADR-014)

### Option 2: Laravel on Lambda (Laravel Vapor or Bref) behind API Gateway

**Pros**

- Pay per request, scales to zero

**Cons**

- Our API is never idle during the day, so per-request pricing loses its advantage
- API Gateway and Lambda each have a 99.95% SLA, so with RDS the chain is about 99.85%, under target
- Cold starts work against the 1 s MCP target
- Vapor is a subscription, and Bref changes how the app boots, so local and cloud differ

### Option 3: EC2 with an auto scaling group

**Pros**

- The cheapest per vCPU when instances run full

**Cons**

- Our two tasks are small, so instances would sit mostly empty
- We would patch them ourselves

### Option 4: AWS App Runner

**Pros**

- The simplest container service

**Cons**

- Not on AWS's published SLA list at the time of writing, so it cannot be a link in a chain that has to prove 99.9%
- It hides the VPC, ALB and proxy header settings the OAuth flow and the rate limits depend on

## Decision

- Option 1.
- The uptime chain decides it: ALB 99.99% × ECS 99.99% × RDS Multi-AZ 99.95% ≈ 99.93%, and only ECS among the managed options keeps a 99.99% link.
- The image that runs in docker compose runs in Fargate without change, so "works locally" means "works in AWS".
- Two small tasks is the right size, so Fargate's higher unit price costs a few dollars a month, which is not a decision.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

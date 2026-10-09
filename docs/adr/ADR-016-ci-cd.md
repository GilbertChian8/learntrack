# ADR-016: CI/CD pipeline

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

Deployment must be automated. The pipeline must build and test the Laravel image (Pest, Pint, Larastan), push it to ECR, run Terraform, run migrations before each deploy, and reach AWS without a stored key, because no credential may live in source code. Terraform defines the ECS service and the pipeline has the new image, and the two must not fight over the task definition.

## Decision required

Which tool runs the pipeline, and who writes the ECS task definition on a deploy?

## Alternatives evaluated

### Option 1: GitHub Actions with OIDC

**Pros**

- Lives next to the code
- OIDC gives the workflow a short-lived AWS role, so no AWS key is stored anywhere
- Ready-made steps for ECR, Terraform and ECS
- The runners run our docker compose tests unchanged
- The free minutes cover a pipeline this size

**Cons**

- Tied to GitHub
- The YAML grows with every step

### Option 2: AWS CodePipeline and CodeBuild

**Pros**

- Everything inside AWS and IAM

**Cons**

- Slow feedback and weaker pull request integration
- Billing per build minute
- Less familiar to most developers

### Option 3: GitLab CI

**Pros**

- A strong environments model

**Cons**

- A lower free tier
- The repository lives on GitHub

## Decision

- Option 1.
- OIDC is the strongest match to "no credentials in source code": the pipeline holds no secret, it is trusted by the repository and the branch.
- The task definition rule: the pipeline registers a new ECS task definition revision with the image tagged by the git SHA. Terraform owns the shape (CPU, memory, environment, secrets) and ignores task_definition after creation. ECS then shows which commit runs, the deployment circuit breaker rolls back a bad revision by itself, and a manual rollback is one command to the previous revision.
- The pipeline shape (pull request runs checks and plan, main deploys, tags mark releases) and the migration step are in the TDD.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

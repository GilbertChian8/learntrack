# ADR-015: Infrastructure as code

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

All infrastructure must be defined as code, and deployment must be automated with no manual step beyond the initial credentials (operability). The stack is a VPC, an ALB, ECS, RDS, Secrets Manager, Route 53, ECR, CloudWatch and EventBridge Scheduler.

## Decision required

Which tool defines the AWS infrastructure?

## Alternatives evaluated

### Option 1: Terraform

**Pros**

- The plan step shows every change before it is applied
- HCL maps one to one to AWS resources
- Well-covered modules for VPC, ALB, ECS and RDS
- The state is the inventory of what exists

**Cons**

- One more language
- The state bucket is created once by hand, which is the one manual step the requirements allow

### Option 2: AWS CDK (TypeScript or Python)

**Pros**

- Fewer lines
- Constructs wire ALB, ECS and RDS together

**Cons**

- Compiles to CloudFormation, so slow deploys and hard-to-read diffs
- Hides resources behind constructs
- AWS only
- Adds a Node or Python toolchain to a PHP repository

### Option 3: CloudFormation

**Pros**

- Native, no extra tool

**Cons**

- Slow feedback and painful rollbacks when a stack fails

## Decision

- Option 1.
- For a PHP application, Terraform keeps the infrastructure in a language made for it, with no second runtime in the repository.
- The plan output is the review step for infrastructure: every ALB rule, security group and secret shows up as a diff before it exists.
- The state bucket bootstrap is the single manual step the requirements allow.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

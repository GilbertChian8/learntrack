# ADR-012: How private subnets reach the outside

Status: Ratified 09/10/2026 | Blueprint: [PAS-001](../blueprint.md)

## Context

The tasks and the database sit in private subnets with no public address. The tasks must reach ECR to pull the image, Secrets Manager to read secrets at start, and CloudWatch Logs. In v1 nothing calls the internet. Two items on the out-of-scope list, messaging learners (email, push) and single sign-on with the school's identity provider, both do, and both would sit in the login or the assignment path.

## Decision required

How do the private subnets reach AWS services today, and the internet later?

## Alternatives evaluated

### Option 1: One NAT gateway per AZ, plus the free S3 gateway endpoint

**Pros**

- Any destination works, including the identity providers and email services on the out-of-scope list, with no network change
- The S3 gateway endpoint keeps image layers off the NAT

**Cons**

- About $65 a month for two gateways plus $0.045 per GB
- A route to the whole internet exists, so egress is limited by security group rules, not by a wall

### Option 2: VPC interface endpoints only, no NAT

**Pros**

- No internet route at all, which suits a system that never calls out

**Cons**

- One endpoint per AWS service (ecr.api, ecr.dkr, logs, secretsmanager) at about the same monthly cost as two NAT gateways
- The first outside call, single sign-on or email, forces a network redesign

### Option 3: Tasks in public subnets with public IPs and a strict security group

**Pros**

- No NAT cost

**Cons**

- Every task is addressable from the internet and a security group is the only wall
- Wrong shape for learner data

### Option 4: A single NAT gateway

**Pros**

- Half the cost of option 1

**Cons**

- Once single sign-on exists, login depends on outbound calls, and one NAT in one AZ becomes a single point of failure in the request path

## Decision

- Option 1.
- Nothing calls out today, but the two most likely next features do, and this is the only option that needs no network change for them.
- One per AZ, because outbound will be in the login path once single sign-on exists, and the 99.9% target does not allow a single-AZ dependency there.
- The S3 gateway endpoint is free and removes the only heavy outbound traffic, the image layers.
- Ratified by: Gilbert Lavensky Chan (09/10/2026)

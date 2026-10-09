# 05: Terraform: compute, schedule and pipeline

**Estimate:** 3 days | **TDD item:** 5 | **ADRs:** ADR-010, ADR-011, ADR-014, ADR-016 | **Depends on:** 04 | **Phase:** 1, lane C

## Goal

The application runs on ECS Fargate behind the ALB with HTTPS on the domain, the daily housekeeping task is scheduled, the alarms exist, and GitHub Actions builds, migrates and deploys on every push to `main`, with a plan on every pull request and a manual destroy workflow.

## Read first

- TDD: Request path and routing, Architecture diagram wires (9 to 15), item 5, Failure modes, Launch plan (Rollback, Monitoring).
- ADR-010, ADR-011, ADR-014, ADR-016.

## Allowed paths

`infra/env/**`, `infra/README.md`, `.github/workflows/**`, `scripts/deploy/**`.

## Steps

1. ECS: a cluster with Container Insights off (cost), a task execution role (ECR pull, CloudWatch logs, read the four secrets) and a task role (nothing yet; the app calls no AWS API at runtime). A task definition `learntrack-<env>-app`: one container from the ECR image, port 8080, `cpu` and `memory` from variables, environment from a map (`APP_ENV=production`, `APP_URL=https://<domain>`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `MYSQL_ATTR_SSL_CA` path, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `LOG_CHANNEL=stderr`, `TRUSTED_PROXIES=10.0.0.0/16`), secrets from Secrets Manager ARNs (`DB_PASSWORD`, `APP_KEY`, `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY`), `awslogs` log driver. The image tag in Terraform is a placeholder; the pipeline registers the real revisions, so the service has `lifecycle { ignore_changes = [task_definition] }`.
2. A second task definition `learntrack-<env>-housekeeping` from the same image with the command `php artisan app:housekeeping` (the command is ticket 11; until it merges the scheduled run fails and the alarm proves itself).
3. Service: desired count from the variable, two private subnets, the `app` security group, no public IP, rolling deployment with minimum healthy 100 and maximum 200, deployment circuit breaker with rollback on, health check grace period 60 s, attached to the target group.
4. ALB in the public subnets with the `alb` security group: a target group (`ip` type, port 8080, health check `GET /up`, 30 s interval, deregistration delay 30 s), an HTTPS listener with the ACM certificate and a modern TLS policy forwarding to the target group, an HTTP listener that redirects to HTTPS with 301. A Route 53 alias record for the domain to the ALB.
5. CloudWatch: the log group `/learntrack/<env>/app` with 30 day retention; an SNS topic with an email subscription (`alarm_email`); alarms on ALB 5xx rate above 5% over 5 minutes, unhealthy target count above 0, running task count below the desired count, target response time p95 above 1 s, RDS CPU above 80%, RDS free storage below 2 GB, RDS connections above 80% of the class maximum, and a failed housekeeping run (an EventBridge rule on ECS task state change for the housekeeping family with a non-zero exit code, sending to the topic).
6. EventBridge Scheduler: a schedule at 03:00 UTC daily that runs the housekeeping task definition on the cluster (Fargate, private subnets, `app` security group) with its own role.
7. `scripts/deploy/register-task-definition.sh`: takes the family and the image URI, reads the current task definition, replaces the image, registers the new revision, prints its ARN. `scripts/deploy/run-migration.sh`: runs the migration task with the new revision (`php artisan migrate --force`), waits for it to stop, exits with the container's exit code and prints its logs.
8. `.github/workflows/deploy.yml` on push to `main`: OIDC assume the deploy role; build the image and push it to ECR tagged with the git SHA; `terraform init` and `terraform apply -auto-approve -var-file=prod.tfvars` in `infra/env`; register the app and housekeeping revisions; run the migration task and stop on failure; update the service to the new revision; `aws ecs wait services-stable`; print the URL. Concurrency group `deploy` so two pushes never overlap.
9. `.github/workflows/plan.yml` on pull requests that touch `infra/**`: `terraform plan -var-file=prod.tfvars -no-color` and post the plan as a pull request comment (update the same comment on new pushes).
10. `.github/workflows/destroy.yml`, `workflow_dispatch` with an input that must equal `destroy`: scale the service to 0, then `terraform destroy -auto-approve`. The bootstrap resources stay.
11. `infra/README.md`: the pipeline steps, how to roll back (`aws ecs update-service` to the previous revision; one command, documented), how to run destroy and apply.

## Acceptance tests

- `terraform validate` and `fmt -check` green in CI; `plan.yml` posts a plan on a pull request that touches `infra/`.
- `actionlint` passes on every workflow file (add it to the CI job).
- After `terraform apply`: `curl -sI http://<domain>/up` answers `301` to `https://`, and `curl -s https://<domain>/up` answers `200` with a valid certificate (outputs in the PR).
- Two tasks run in two different AZs (`aws ecs describe-tasks` output in the PR); the ALB target group shows two healthy targets.
- A push to `main` runs `deploy.yml` end to end: new revision registered, migration task exit code 0, service stable. The run link is in the PR.
- A deliberately broken image (health check fails) is rolled back by the circuit breaker; the previous revision keeps serving. One run demonstrated and linked.
- The scheduled housekeeping task runs at 03:00 UTC and, while the command does not exist yet, the failure alarm sends an email.
- `destroy.yml` followed by `deploy.yml` rebuilds the environment with no manual step beyond the bootstrap.

## Out of scope

The housekeeping command itself (ticket 11), the application's rate limits and log redaction (14), the load measurement (15).

## PR checklist

- [ ] Title `05: Terraform: compute, schedule and pipeline`
- [ ] Service ignores `task_definition` changes; the pipeline owns revisions
- [ ] No long-lived AWS keys anywhere; OIDC only
- [ ] All checks green, workflow runs linked
- [ ] Documents changed, if any, listed with the reason

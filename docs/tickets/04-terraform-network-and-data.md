# 04: Terraform: network and data

**Estimate:** 3 days | **TDD item:** 4 | **ADRs:** ADR-007, ADR-010, ADR-012, ADR-015 | **Depends on:** 01 | **Phase:** 1, lane C

## Goal

The AWS foundation as Terraform: the one-time bootstrap (state, OIDC, deploy role, ECR), the VPC across two AZs, the security groups, RDS MySQL Multi-AZ, the secrets, and DNS with a certificate. After this ticket, `terraform plan` in `infra/env` is clean against a real account and no secret value exists in the state.

## Read first

- TDD: Request path and routing (Region), Architecture diagram wires, item 4, Security and access, Failure modes.
- ADR-007, ADR-010, ADR-011, ADR-012, ADR-013, ADR-015.
- Blueprint: the architecture section.

## Allowed paths

`infra/**`, `.gitignore`, `.github/workflows/ci.yml` (a `terraform fmt` and `validate` job only).

## Steps

1. Layout: `infra/bootstrap/` (applied once by hand, local state committed as instructions, never as a file) and `infra/env/` (the environment, S3 backend). Terraform 1.12 or later, AWS provider 6.x, `random` and `tls` providers. Pin versions in `versions.tf`. The AWS account is shared with another project: every resource name starts with `learntrack-` (path-style names, the secrets and ticket 05's log group, start with `learntrack/` or `/learntrack/`), and every resource carries `Project=learntrack` and `Environment` through provider default tags, in bootstrap (`Environment=bootstrap`) and env.
2. `infra/bootstrap/`: the state bucket (versioned, encrypted, public access blocked, S3 native locking with `use_lockfile`), the GitHub OIDC provider (it may already exist in the shared account: the variable `github_oidc_provider_arn`, empty by default, creates it when empty and reuses that ARN when set), the deploy role trusted only by this repository's `main` branch with the permissions the pipeline needs, a read-only plan role trusted only by pull requests (it reads the state and every resource, and writes only the state lock file `*.tflock`), a permissions boundary policy `learntrack-boundary` that every role the deploy role creates must carry (so the deploy role cannot make itself an admin), the ECR repository (scan on push, keep the last 10 images), and the Route 53 hosted zone for `domain_name` (so a destroy and apply of the environment never changes the nameservers at the registrar). Outputs: both role ARNs, the boundary policy ARN, bucket name, repository URL, hosted zone id and nameservers. `infra/README.md` lists the exact bootstrap commands.
3. `infra/env/variables.tf`: `environment` (default `prod`), `region` (default `eu-central-1`), `domain_name`, `hosted_zone_id`, `alarm_email`, `db_instance_class` (default `db.t4g.micro`), `app_cpu` 256, `app_memory` 512, `desired_count` 2, `skip_final_snapshot` (default true, this environment is rebuilt from code). Variables are not committed: `infra/env/prod.tfvars.example` holds placeholder values (the domain name is a variable, never a literal in the repository), `prod.tfvars` is gitignored, and the pipeline (ticket 05) passes the values as `TF_VAR_*` from GitHub repository variables.
4. Network: VPC `10.0.0.0/16`, two public subnets (ALB, NAT gateways) and two private subnets (tasks, database) across two AZs, an internet gateway, one NAT gateway per AZ with its own route table for that AZ's private subnet, and an S3 gateway endpoint attached to both private route tables.
5. Security groups: `alb` accepts 80 and 443 from `0.0.0.0/0`; `app` accepts 8080 from `alb` only; `db` accepts 3306 from `app` only. Every group allows all egress.
6. RDS: MySQL 8.4, Multi-AZ, storage encrypted, automated backups 7 days, a parameter group with `require_secure_transport = 1`, in the private subnets, not publicly accessible, deletion protection off (the destroy workflow is the off switch), final snapshot by variable.
7. Secrets with no value in the state: the master password, `APP_KEY` (`base64:` plus 32 random bytes) and the Passport key pair are generated as ephemeral values and written with write-only arguments (`password_wo` on the instance, `secret_string_wo` plus `secret_string_wo_version` on the secret versions). Four secrets in Secrets Manager: `learntrack/<env>/db-password`, `app-key`, `passport-private-key`, `passport-public-key`. Outputs expose ARNs only.
8. DNS: an ACM certificate for `domain_name`, validated by DNS records written into the bootstrap's hosted zone (looked up by `hosted_zone_id`, a variable). The alias record to the ALB is ticket 05.
9. CI: a separate job named `terraform` in `ci.yml` (the `checks` job stays as it is, the `main` ruleset requires its name) that runs `terraform fmt -check -recursive` and `terraform validate` (with `-backend=false`) in both directories on every pull request.
10. `infra/README.md`: bootstrap steps, the variables, how to run plan and apply locally, the cost per hour of this environment, from AWS list prices for its region.

## Acceptance tests

- `terraform fmt -check -recursive infra` and `terraform validate` pass in CI for `infra/bootstrap` and `infra/env`.
- After a manual bootstrap and `terraform apply` in `infra/env` (done once by the ticket owner, screenshots or outputs pasted in the PR): a second `terraform plan` shows no changes.
- `terraform state pull | grep -c "BEGIN RSA PRIVATE KEY"` prints `0`, and the same for the database password and `APP_KEY` values (`grep -c "base64:"` prints `0`).
- The database is not publicly accessible and its security group has one ingress rule, from the app group, on 3306 (`aws ec2 describe-security-groups` output in the PR).
- The app security group's only ingress is 8080 from the ALB group.
- `terraform destroy` leaves nothing behind except the bootstrap resources (`aws resourcegroupstaggingapi get-resources` filtered on the `Project=learntrack` tag returns only bootstrap resources), and the hosted zone's nameservers are the same before and after.

## Out of scope

ECS, the ALB listeners and target group, CloudWatch alarms, the scheduler, the pipeline (ticket 05).

## PR checklist

- [ ] Title `04: Terraform: network and data`
- [ ] Every resource tagged `Project=learntrack` and `Environment` through provider default tags (`Environment=bootstrap` in the bootstrap, `<env>` in the environment)
- [ ] No secret value in state, checked as above
- [ ] fmt and validate green in CI
- [ ] Documents changed, if any, listed with the reason

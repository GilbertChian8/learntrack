# Infrastructure

Terraform for the AWS environment. Two folders:

- `bootstrap/`: applied once by hand. The state bucket, the GitHub OIDC provider, the two GitHub roles, the permissions boundary, the ECR repository, the Route 53 hosted zone and the alarm topic with its email subscription. These stay when the environment is destroyed, so images are kept, the nameservers never change and the alarm email is confirmed only once.
- `env/`: the environment. Network, security groups, RDS MySQL, the four secrets, the certificate, ECS on Fargate, the load balancer, the alarms and the daily housekeeping schedule. State in the bootstrap's bucket. The pipeline applies it (see Pipeline).

Needs Terraform 1.12 or later and AWS credentials with admin rights for the first apply.

## Names and tags

- Every resource name starts with `learntrack-`. Path-style names start with `learntrack/` (the secrets) or `/learntrack/` (the log group). The deploy role's permissions are scoped by these names.
- Every resource has the tags `Project=learntrack` and `Environment` (`bootstrap` for the bootstrap, `prod` for the environment), set by the provider's default tags.

## GitHub roles

Two roles, assumed with GitHub OIDC. No AWS key is stored anywhere.

- `learntrack-deploy`: only for workflows on `main` (deploy, destroy). Its sessions last up to 2 hours, because a first deploy or a rollback can take more than an hour. It pushes images, writes the state and applies. It changes IAM roles, logs, RDS, ECS, load balancers, alarms, schedules, rules and secrets only when their names start with `learntrack-` (or `learntrack/`, `/learntrack/`). EC2 network resources and ACM certificates have generated ids, and ECS task definitions are registered without a resource name, so it can change any of these. It cannot change itself or the plan role, and it cannot read secret values.
- The deploy role cannot grant more than the boundary. It creates, changes and deletes `learntrack-*` roles only when they carry the permissions boundary `learntrack-boundary`, and it cannot remove a boundary or change the boundary policy. Roles get inline policies only; the deploy role cannot attach managed policies. The boundary allows ECR pull, logs on `/learntrack/*`, reading `learntrack/*` secrets, running `learntrack-*` tasks and passing `learntrack-*` roles to ECS tasks. Nothing else, so no role the pipeline makes can manage IAM.
- `learntrack-plan`: only for pull requests (plan). Read-only: it reads the state and refreshes every resource, and it can write only the state lock file (`*.tflock`). A pull request branch never holds write access to the environment.

## Variables

Variable values are not committed. Copy an example file, fill it in, and keep it local (`*.tfvars` is gitignored). The pipeline passes the environment's values as `TF_VAR_*` from GitHub repository variables (see GitHub variables). The bootstrap's values, the alarm email among them, stay local.

Bootstrap (`bootstrap/terraform.tfvars.example`):

| Variable | Default | Meaning |
|---|---|---|
| `region` | `eu-central-1` | Region of the bucket, the repository and the alarm topic. The environment uses the same region |
| `domain_name` | none | The domain the app runs on. The hosted zone is for this name |
| `github_subject_prefix` | none | The OIDC subject prefix of the repository the GitHub roles trust, from `gh api repos/<owner>/<name>/actions/oidc/customization/sub --jq .sub_claim_prefix`. With GitHub's immutable subjects it holds the owner and repository ids, `repo:<owner>@<id>/<name>@<id>` |
| `alarm_email` | none | Gets the alarms of every environment. Confirm it once from the AWS email |

Environment (`env/prod.tfvars.example`):

| Variable | Default | Meaning |
|---|---|---|
| `environment` | `prod` | Used in every name |
| `region` | `eu-central-1` | Region of the environment. Same as the bootstrap, because the alarm topic is there |
| `domain_name` | none | Same as the bootstrap |
| `hosted_zone_id` | none | The bootstrap output `hosted_zone_id` |
| `db_instance_class` | `db.t4g.micro` | RDS instance class |
| `db_max_connections` | `60` | `max_connections` of `db_instance_class` (about 60 on 1 GiB classes). The connections alarm fires at 80%. Change it with the class |
| `app_cpu`, `app_memory` | `512`, `1024` | Size of the app, migration and housekeeping tasks |
| `desired_count` | `2` | Number of app tasks |
| `skip_final_snapshot` | `true` | The environment is rebuilt from code, so no snapshot on destroy |

## Bootstrap

Run once per AWS account, with admin credentials:

```
cd infra/bootstrap
cp terraform.tfvars.example terraform.tfvars   # fill in the values
terraform init
terraform apply
terraform output
```

Then:

1. Delegate `domain_name` to the 4 `name_servers`. For a subdomain, add 4 NS records with that name at the parent domain's DNS provider. For a whole domain, set them at the registrar. The parent domain's DNS provider must allow NS records on a subdomain. If it does not, move the parent domain's DNS to a provider that does, or give the app a domain of its own.
2. Before the first apply in `env/`, check the delegation and the CAA records. The certificate cannot be validated without them. The first name above `domain_name` that has CAA records decides: it must allow `amazon.com`. No CAA record at any level is fine.
   ```
   dig NS <domain_name> +short        # the 4 name_servers
   dig CAA <name> +short              # each name above domain_name, up to the registered domain
   ```
3. Confirm the alarm email from the AWS message within 48 hours. AWS deletes an unconfirmed subscription after that, and the next bootstrap apply creates it again and sends a new message. Once confirmed, it stays when the environment is destroyed.

The bootstrap state is local (`infra/bootstrap/terraform.tfstate`, gitignored). Keep a copy outside the repository. If it is lost, write the same `terraform.tfvars` and import the resources:

```
terraform import aws_s3_bucket.state learntrack-tfstate-<account id>
terraform import aws_s3_bucket_versioning.state learntrack-tfstate-<account id>
terraform import aws_s3_bucket_server_side_encryption_configuration.state learntrack-tfstate-<account id>
terraform import aws_s3_bucket_public_access_block.state learntrack-tfstate-<account id>
terraform import aws_iam_openid_connect_provider.github <provider arn>
terraform import aws_iam_role.deploy learntrack-deploy
terraform import aws_iam_role_policy.deploy learntrack-deploy:learntrack-deploy
terraform import aws_iam_role.plan learntrack-plan
terraform import aws_iam_role_policy.plan learntrack-plan:learntrack-plan
terraform import aws_iam_policy.boundary arn:aws:iam::<account id>:policy/learntrack-boundary
terraform import aws_ecr_repository.app learntrack-app
terraform import aws_ecr_lifecycle_policy.app learntrack-app
terraform import aws_route53_zone.main <hosted zone id>
terraform import aws_sns_topic.alarms arn:aws:sns:<region>:<account id>:learntrack-alarms
terraform import aws_sns_topic_policy.alarms arn:aws:sns:<region>:<account id>:learntrack-alarms
terraform import aws_sns_topic_subscription.alarm_email <subscription arn>
```

## Plan and apply the environment

```
cd infra/env
cp prod.tfvars.example prod.tfvars             # fill in the values
terraform init -backend-config="bucket=$(terraform -chdir=../bootstrap output -raw state_bucket)"
terraform plan -var-file=prod.tfvars
terraform apply -var-file=prod.tfvars
```

The pipeline applies on every push to `main` (see Pipeline). A local apply works too, but its task definitions use the image tag `placeholder`, which does not exist: the app starts only after the pipeline registers a revision with a real image.

The first apply takes about 20 minutes, most of it RDS Multi-AZ. The environment runs in the bootstrap's region: its alarms send to the bootstrap's topic, and an alarm or an EventBridge rule can only send to a topic in its own region. The backend block expects the state bucket in `eu-central-1`. If the bootstrap runs in another region, set the same `region` in both folders and add `-backend-config="region=<region>"`. The workflows always pass the region.

After `terraform destroy`, `aws resourcegroupstaggingapi get-resources --tag-filters Key=Project,Values=learntrack` lists the bootstrap resources and the ECS task definition revisions. Revisions stay registered after a destroy (Terraform's become inactive, the pipeline's stay active); they cost nothing, and the next deploy registers new ones. The tagging index is eventually consistent: right after a destroy it can still list deleted resources. Describe each one it lists; NotFound or a deleted state means it is gone.

A second environment needs its own variables, its own state key (`-backend-config="key=env/<environment>/terraform.tfstate"`) and its own deploy and plan roles.

## GitHub variables

The workflows read these repository variables (Settings, Secrets and variables, Actions, Variables). There are no GitHub secrets: AWS access is OIDC only.

| Variable | Used by | Value |
|---|---|---|
| `AWS_REGION` | all | The bootstrap's `region` |
| `AWS_DEPLOY_ROLE_ARN` | deploy, destroy | Bootstrap output `deploy_role_arn` |
| `AWS_PLAN_ROLE_ARN` | plan | Bootstrap output `plan_role_arn` |
| `TF_STATE_BUCKET` | all | Bootstrap output `state_bucket` |
| `DOMAIN_NAME` | all | The bootstrap's `domain_name` |
| `HOSTED_ZONE_ID` | all | Bootstrap output `hosted_zone_id` |

GitHub stores variable names in capitals, so the workflows map them to Terraform's names:

```
TF_VAR_region:         ${{ vars.AWS_REGION }}
TF_VAR_domain_name:    ${{ vars.DOMAIN_NAME }}
TF_VAR_hosted_zone_id: ${{ vars.HOSTED_ZONE_ID }}
```

`terraform init` gets `-backend-config="bucket=$TF_STATE_BUCKET"` and `-backend-config="region=$AWS_REGION"`. Every other variable keeps its default. The ECR repository is `learntrack-app`; the registry comes from the ECR login step.

## Pipeline

- `ci.yml`, every pull request: the `checks` job (PHP and frontend) and the `terraform` job (`fmt -check` and `validate` in both folders, actionlint on the workflows, shellcheck on `scripts/deploy`).
- `plan.yml`, pull requests that change `infra/**`: assumes `learntrack-plan`, runs `terraform plan` and posts it as one pull request comment, updated on every push. Pull requests from forks are skipped, because they get no OIDC token.
- `deploy.yml`, every push to `main`, or by hand from `main`. Never two at once (concurrency group `deploy`, shared with destroy):
  1. Assume `learntrack-deploy`.
  2. Build the image and push it to ECR as `learntrack-app:<git SHA>`.
  3. `terraform apply` in `infra/env`.
  4. Register a new revision of `learntrack-<env>-app` with the new image (`scripts/deploy/register-task-definition.sh`).
  5. Run `php artisan migrate --force` as a one-off task with that revision (`scripts/deploy/run-migration.sh`). It prints the task's logs. A failed migration stops the run, and the service keeps its revision.
  6. Move the service to the new app revision and wait until it is stable (`scripts/deploy/update-service.sh`). The run fails when the deployment circuit breaker rolled the revision back.
  7. Register a new revision of `learntrack-<env>-housekeeping` with the new image and move the daily schedule to it (`scripts/deploy/update-schedule.sh`). After a failed migration or a rollback the run has stopped, so the daily task stays on the last image that passed.
  8. Print the URL and both new revisions in the run summary.
- `destroy.yml`, by hand from `main`: see Destroy and rebuild.

Terraform owns the shape of the task definitions: size, environment, secrets and logs. The pipeline copies the latest revision and changes only the image, so ECS shows which commit runs. The pipeline also chooses the revision that runs: the service and the schedule ignore Terraform's revision. Only a new environment starts on Terraform's `placeholder` image, until its first deploy moves the service and the schedule. The housekeeping task runs every day at 03:00 UTC. A run that exits non-zero or fails to start sends an email to the alarm topic, and so does a schedule that cannot start the task. A run that exits non-zero sends a short JSON message with the exit code, the stop code, the stop time, the task, its log group and stream prefix, and ECS's report of the container, with its own reason when ECS gives one (for example `OutOfMemoryError`). A task that fails to start wrote no logs, so its message has the stop time, the task and ECS's full report of the task, with the reason.

The image trusts the RDS certificate authorities from `docker/rds-global-bundle.pem`, a copy of https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem from October 2026. Refresh it when AWS adds roots for a new region or announces new RDS certificate authorities.

## Roll back

Roll back to the app revision of the last good deploy. Each `deploy.yml` run prints its two revisions in the run summary. Move the service with one command:

```
aws ecs update-service --cluster learntrack-prod --service learntrack-prod-app --task-definition <app revision ARN from the good run>
```

`aws ecs wait services-stable --cluster learntrack-prod --services learntrack-prod-app` waits for the rollback. To see which image a revision runs:

```
aws ecs describe-task-definition --task-definition learntrack-prod-app:<revision> --query 'taskDefinition.containerDefinitions[0].image' --output text
```

To roll back the daily task too: `scripts/deploy/update-schedule.sh learntrack-prod-housekeeping <housekeeping revision ARN from the good run>`. Migrations are expand-only, so the old revisions run on the new schema. The next push to `main` deploys again.

The deployment circuit breaker rolls back by itself when a new revision fails the `/up` health check.

## Destroy and rebuild

To stop paying for the environment, run `destroy.yml` from `main` (Actions, destroy, Run workflow) and type `destroy`. It scales the service to 0, then runs `terraform destroy`. The bootstrap stays: the state bucket, the roles, the images, the hosted zone and the alarm topic with its confirmed email.

To rebuild, run `deploy.yml` from `main` (Actions, deploy, Run workflow), or push to `main`. Nothing else is needed. This deploy takes longer than the others, because RDS Multi-AZ takes about 20 minutes to create.

Deploy and destroy share one concurrency group, and GitHub keeps only one waiting run per group: a newer deploy replaces a destroy that waits behind a running deploy, and the reverse.

## Secrets

Terraform generates the database password, `APP_KEY` and the Passport key pair as ephemeral values and writes them with write-only arguments. They are never in the plan or the state. Outputs show ARNs only.

To rotate one, raise its version in `env/secrets.tf` and apply:

- `db_password_version`: the RDS master password and the `db-password` secret, together.
- `app_key_version`: `APP_KEY`. Sessions and encrypted values from the old key stop working.
- `passport_key_version`: both Passport keys, together. Every token signed with the old key stops working.

Do not replace one secret version on its own (`-replace`): its value would no longer match the database or the other key. If the database and the secret ever disagree after a failed apply, raise `db_password_version`.

Check that no secret is in the state:

```
export AWS_REGION=<region>                                 # the region of both folders
terraform state pull | grep -c "BEGIN RSA PRIVATE KEY"     # 0
terraform state pull | grep -c "base64:"                   # 0
terraform state pull | grep -cF "$(aws secretsmanager get-secret-value --secret-id learntrack/prod/db-password --query SecretString --output text)"   # 0
```

## Cost per hour

For `env/` only, in `eu-central-1`. On-demand prices from the AWS Price List files for `eu-central-1`, October 2026.

| Item | Per hour | Note |
|---|---|---|
| 2 NAT gateways | $0.104 | $0.052 each, plus $0.052 per GB processed |
| 2 public IPv4 addresses (NAT) | $0.010 | $0.005 each |
| RDS db.t4g.micro MySQL, Multi-AZ | $0.037 | |
| RDS storage, 20 GB gp3, Multi-AZ | $0.008 | $0.274 per GB a month |
| Secrets Manager, 4 secrets | $0.002 | $0.40 per secret a month |
| Application Load Balancer | $0.027 | plus $0.008 per LCU-hour used |
| 2 public IPv4 addresses (load balancer) | $0.010 | $0.005 each |
| 2 Fargate tasks, 0.5 vCPU and 1 GB each | $0.057 | $0.04656 per vCPU-hour, $0.00511 per GB-hour |
| CloudWatch, 11 alarm metrics | $0.002 | $0.10 per alarm metric a month, plus $0.63 per GB of logs |
| **Total** | **about $0.26** | about $187 a month if left running |

The migration task on each deploy and the daily housekeeping task run for about a minute each, so they cost almost nothing. The bootstrap costs a few cents a day when the environment is down: the hosted zone, the state bucket and the stored images.

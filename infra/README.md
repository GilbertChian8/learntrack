# Infrastructure

Terraform for the AWS environment. Two folders:

- `bootstrap/`: applied once by hand. The state bucket, the GitHub OIDC provider, the two GitHub roles, the permissions boundary, the ECR repository, the Route 53 hosted zone and the alarm topic with its email subscription. These stay when the environment is destroyed, so images are kept, the nameservers never change and the alarm email is confirmed only once.
- `env/`: the environment. Network, security groups, RDS MySQL, the four secrets and the certificate. State in the bootstrap's bucket. Ticket 05 adds ECS, the load balancer, the alarms and the pipeline.

Needs Terraform 1.12 or later and AWS credentials with admin rights for the first apply.

## Names and tags

- Every resource name starts with `learntrack-`. Path-style names start with `learntrack/` (the secrets) or `/learntrack/` (the log group, ticket 05). The deploy role's permissions are scoped by these names.
- Every resource has the tags `Project=learntrack` and `Environment` (`bootstrap` for the bootstrap, `prod` for the environment), set by the provider's default tags.

## GitHub roles

Two roles, assumed with GitHub OIDC. No AWS key is stored anywhere.

- `learntrack-deploy`: only for workflows on `main` (deploy, destroy). It pushes images, writes the state and applies. It changes IAM roles, logs, RDS, ECS, load balancers, alarms, schedules, rules and secrets only when their names start with `learntrack-` (or `learntrack/`, `/learntrack/`). EC2 network resources and ACM certificates have generated ids, and ECS task definitions are registered without a resource name, so it can change any of these. It cannot change itself or the plan role, and it cannot read secret values.
- The deploy role cannot grant more than the boundary. It creates, changes and deletes `learntrack-*` roles only when they carry the permissions boundary `learntrack-boundary`, and it cannot remove a boundary or change the boundary policy. Roles get inline policies only; the deploy role cannot attach managed policies. The boundary allows ECR pull, logs on `/learntrack/*`, reading `learntrack/*` secrets, running `learntrack-*` tasks and passing `learntrack-*` roles to ECS tasks. Nothing else, so no role the pipeline makes can manage IAM.
- `learntrack-plan`: only for pull requests (plan). Read-only: it reads the state and refreshes every resource, and it can write only the state lock file (`*.tflock`). A pull request branch never holds write access to the environment.

## Variables

Variable values are not committed. Copy an example file, fill it in, and keep it local (`*.tfvars` is gitignored). The pipeline (ticket 05) passes the environment's values as `TF_VAR_*` from GitHub repository variables. The bootstrap's values, the alarm email among them, stay local.

Bootstrap (`bootstrap/terraform.tfvars.example`):

| Variable | Default | Meaning |
|---|---|---|
| `region` | `eu-central-1` | Region of the bucket, the repository and the alarm topic. The environment uses the same region |
| `domain_name` | none | The domain the app runs on. The hosted zone is for this name |
| `github_repository` | none | `owner/name` of the repository the GitHub roles trust |
| `alarm_email` | none | Gets the alarms of every environment. Confirm it once from the AWS email |

Environment (`env/prod.tfvars.example`):

| Variable | Default | Meaning |
|---|---|---|
| `environment` | `prod` | Used in every name |
| `region` | `eu-central-1` | Region of the environment. Same as the bootstrap, because the alarm topic is there |
| `domain_name` | none | Same as the bootstrap |
| `hosted_zone_id` | none | The bootstrap output `hosted_zone_id` |
| `db_instance_class` | `db.t4g.micro` | RDS instance class |
| `app_cpu`, `app_memory` | `512`, `1024` | Task size (ticket 05) |
| `desired_count` | `2` | Number of app tasks (ticket 05) |
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

The first apply takes about 20 minutes, most of it RDS Multi-AZ. The environment runs in the bootstrap's region: its alarms send to the bootstrap's topic, and an alarm or an EventBridge rule can only send to a topic in its own region. The backend block expects the state bucket in `eu-central-1`. If the bootstrap runs in another region, set the same `region` in both folders and add `-backend-config="region=<region>"`.

A second environment needs its own variables, its own state key (`-backend-config="key=env/<environment>/terraform.tfstate"`) and its own deploy and plan roles.

## Secrets

Terraform generates the database password, `APP_KEY` and the Passport key pair as ephemeral values and writes them with write-only arguments. They are never in the plan or the state. Outputs show ARNs only.

To rotate one, raise its version in `env/secrets.tf` and apply:

- `db_password_version`: the RDS master password and the `db-password` secret, together.
- `app_key_version`: `APP_KEY`. Sessions and encrypted values from the old key stop working.
- `passport_key_version`: both Passport keys, together. Every token signed with the old key stops working.

Do not replace one secret version on its own (`-replace`): its value would no longer match the database or the other key. If the database and the secret ever disagree after a failed apply, raise `db_password_version`.

Check that no secret is in the state:

```
export AWS_REGION=eu-central-1
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
| **Total** | **about $0.16** | about $117 a month if left running |

The bootstrap costs a few cents a day when the environment is down: the hosted zone, the state bucket and the stored images. Ticket 05 adds the load balancer and two Fargate tasks.

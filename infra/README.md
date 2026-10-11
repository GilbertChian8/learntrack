# Infrastructure

Terraform for the AWS environment. Two folders:

- `bootstrap/`: applied once by hand. The state bucket, the GitHub OIDC provider, the deploy role, the ECR repository and the Route 53 hosted zone. These stay when the environment is destroyed, so images are kept and the nameservers never change.
- `env/`: the environment. Network, security groups, RDS MySQL, the four secrets and the certificate. State in the bootstrap's bucket. Ticket 05 adds ECS, the load balancer, the alarms and the pipeline.

Needs Terraform 1.12 or later and AWS credentials with admin rights for the first apply.

## Shared account

The AWS account is shared with another project.

- Every resource name starts with `learntrack-`. Path-style names start with `learntrack/` (the secrets) or `/learntrack/` (the log group, ticket 05).
- Every resource has the tags `Project=learntrack` and `Environment` (`bootstrap` for the bootstrap, `prod` for the environment), set by the provider's default tags.
- The account may already have a GitHub OIDC provider. AWS allows only one per URL. Find it with `aws iam list-open-id-connect-providers` and set `github_oidc_provider_arn` to reuse it. Leave it empty to create one.
- The deploy role changes IAM roles, logs, RDS, ECS, load balancers, alarms, topics, schedules and rules only when their names start with `learntrack-` (or `/learntrack/`). EC2 network resources and ACM certificates have generated ids, and ECS task definitions are registered without a resource name, so the role can change any of these.

## Variables

Variable values are not committed. Copy an example file, fill it in, and keep it local (`*.tfvars` is gitignored). The pipeline (ticket 05) passes the same values as `TF_VAR_*` from GitHub repository variables.

Bootstrap (`bootstrap/terraform.tfvars.example`):

| Variable | Default | Meaning |
|---|---|---|
| `region` | `eu-central-1` | Region of the bucket, the role and the repository |
| `domain_name` | none | The domain the app runs on. The hosted zone is for this name |
| `github_repository` | none | `owner/name` of the repository the deploy role trusts |
| `github_oidc_provider_arn` | empty | Empty creates the OIDC provider, an ARN reuses it |

Environment (`env/prod.tfvars.example`):

| Variable | Default | Meaning |
|---|---|---|
| `environment` | `prod` | Used in every name |
| `region` | `eu-central-1` | Region of the environment |
| `domain_name` | none | Same as the bootstrap |
| `hosted_zone_id` | none | The bootstrap output `hosted_zone_id` |
| `alarm_email` | none | Gets the alarms (ticket 05) |
| `db_instance_class` | `db.t4g.micro` | RDS instance class |
| `app_cpu`, `app_memory` | `256`, `512` | Task size (ticket 05) |
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

Then set the nameservers at your domain registrar to the `name_servers` output. The certificate in `env/` cannot be validated before this.

The bootstrap state is local (`infra/bootstrap/terraform.tfstate`, gitignored). Keep a copy outside the repository. If it is lost, write the same `terraform.tfvars` and import the resources:

```
terraform import aws_s3_bucket.state learntrack-tfstate-<account id>
terraform import aws_s3_bucket_versioning.state learntrack-tfstate-<account id>
terraform import aws_s3_bucket_server_side_encryption_configuration.state learntrack-tfstate-<account id>
terraform import aws_s3_bucket_public_access_block.state learntrack-tfstate-<account id>
terraform import 'aws_iam_openid_connect_provider.github[0]' <provider arn>   # only if the bootstrap created it
terraform import aws_iam_role.deploy learntrack-deploy
terraform import aws_iam_role_policy.deploy learntrack-deploy:learntrack-deploy
terraform import aws_ecr_repository.app learntrack-app
terraform import aws_ecr_lifecycle_policy.app learntrack-app
terraform import aws_route53_zone.main <hosted zone id>
```

## Plan and apply the environment

```
cd infra/env
cp prod.tfvars.example prod.tfvars             # fill in the values
terraform init -backend-config="bucket=$(terraform -chdir=../bootstrap output -raw state_bucket)"
terraform plan -var-file=prod.tfvars
terraform apply -var-file=prod.tfvars
```

The first apply takes about 20 minutes, most of it RDS Multi-AZ. The backend block expects the state bucket in `eu-central-1`. If the bootstrap runs in another region, add `-backend-config="region=<region>"`.

A second environment needs its own variables, its own state key (`-backend-config="key=env/<environment>/terraform.tfstate"`) and its own OIDC role.

## Secrets

Terraform generates the database password, `APP_KEY` and the Passport key pair as ephemeral values and writes them with write-only arguments. They are never in the plan or the state. Outputs show ARNs only.

To rotate one, raise its version in `env/secrets.tf` and apply:

- `db_password_version`: the RDS master password and the `db-password` secret, together.
- `app_key_version`: `APP_KEY`. Sessions and encrypted values from the old key stop working.
- `passport_key_version`: both Passport keys, together. Every token signed with the old key stops working.

Do not replace one secret version on its own (`-replace`): its value would no longer match the database or the other key. If the database and the secret ever disagree after a failed apply, raise `db_password_version`.

Check that no secret is in the state:

```
terraform state pull | grep -c "BEGIN RSA PRIVATE KEY"     # 0
terraform state pull | grep -c "base64:"                   # 0
terraform state pull | grep -cF "$(aws secretsmanager get-secret-value --secret-id learntrack/prod/db-password --query SecretString --output text)"   # 0
```

## Cost per hour

For `env/` only, in `eu-central-1`. The NAT line is the blueprint's figure (ADR-012). The other lines are AWS list prices, rounded. Check the AWS pricing pages before you rely on them.

| Item | Per hour | Note |
|---|---|---|
| 2 NAT gateways | $0.089 | $65 a month (blueprint), plus $0.045 per GB |
| 2 public IPv4 addresses (NAT) | $0.010 | $0.005 each |
| RDS db.t4g.micro, Multi-AZ | about $0.038 | twice the single-AZ price |
| RDS storage, 20 GB gp3, Multi-AZ | about $0.007 | |
| Secrets Manager, 4 secrets | $0.002 | $0.40 per secret a month |
| **Total** | **about $0.15** | about $107 a month if left running |

The bootstrap costs a few cents a day when the environment is down: the hosted zone ($0.50 a month), the state bucket and the stored images. Ticket 05 adds the load balancer and two Fargate tasks.

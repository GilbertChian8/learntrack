data "aws_iam_policy_document" "read" {
  statement {
    sid       = "StateBucket"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.state.arn]
  }

  statement {
    sid       = "StateRead"
    actions   = ["s3:GetObject"]
    resources = ["${aws_s3_bucket.state.arn}/*"]
  }

  statement {
    sid = "Read"
    actions = [
      "acm:DescribeCertificate",
      "acm:GetCertificate",
      "acm:ListCertificates",
      "acm:ListTagsForCertificate",
      "cloudwatch:Describe*",
      "cloudwatch:List*",
      "ec2:Describe*",
      "ecs:Describe*",
      "ecs:List*",
      "elasticloadbalancing:Describe*",
      "events:Describe*",
      "events:List*",
      "iam:Get*",
      "iam:List*",
      "logs:Describe*",
      "logs:List*",
      "rds:Describe*",
      "rds:List*",
      "route53:Get*",
      "route53:List*",
      "scheduler:Get*",
      "scheduler:List*",
      "sns:Get*",
      "sns:List*",
    ]
    resources = ["*"]
  }

  statement {
    sid = "SecretsMetadata"
    actions = [
      "secretsmanager:DescribeSecret",
      "secretsmanager:GetResourcePolicy",
      "secretsmanager:ListSecretVersionIds",
    ]
    resources = ["arn:aws:secretsmanager:*:${local.account_id}:secret:learntrack/*"]
  }

  statement {
    sid = "EcrRead"
    actions = [
      "ecr:DescribeImages",
      "ecr:DescribeRepositories",
      "ecr:ListImages",
      "ecr:ListTagsForResource",
    ]
    resources = [aws_ecr_repository.app.arn]
  }
}

data "aws_iam_policy_document" "plan" {
  source_policy_documents = [data.aws_iam_policy_document.read.json]

  statement {
    sid       = "StateLock"
    actions   = ["s3:PutObject", "s3:DeleteObject"]
    resources = ["${aws_s3_bucket.state.arn}/*.tflock"]
  }
}

data "aws_iam_policy_document" "deploy" {
  source_policy_documents = [data.aws_iam_policy_document.read.json]

  statement {
    sid       = "StateWrite"
    actions   = ["s3:PutObject", "s3:DeleteObject"]
    resources = ["${aws_s3_bucket.state.arn}/*"]
  }

  statement {
    sid       = "EcrLogin"
    actions   = ["ecr:GetAuthorizationToken"]
    resources = ["*"]
  }

  statement {
    sid = "EcrPush"
    actions = [
      "ecr:BatchCheckLayerAvailability",
      "ecr:BatchGetImage",
      "ecr:CompleteLayerUpload",
      "ecr:GetDownloadUrlForLayer",
      "ecr:InitiateLayerUpload",
      "ecr:PutImage",
      "ecr:UploadLayerPart",
    ]
    resources = [aws_ecr_repository.app.arn]
  }

  statement {
    sid = "Network"
    actions = [
      "ec2:CreateTags",
      "ec2:DeleteTags",
      "ec2:*Vpc*",
      "ec2:*Subnet*",
      "ec2:*InternetGateway*",
      "ec2:*NatGateway*",
      "ec2:*Address*",
      "ec2:*Route*",
      "ec2:*SecurityGroup*",
    ]
    resources = ["*"]
  }

  statement {
    sid       = "Certificates"
    actions   = ["acm:*"]
    resources = ["*"]
  }

  statement {
    sid       = "DnsRecords"
    actions   = ["route53:ChangeResourceRecordSets"]
    resources = [aws_route53_zone.main.arn]
  }

  statement {
    sid = "Secrets"
    actions = [
      "secretsmanager:CreateSecret",
      "secretsmanager:DeleteSecret",
      "secretsmanager:PutSecretValue",
      "secretsmanager:TagResource",
      "secretsmanager:UntagResource",
      "secretsmanager:UpdateSecret",
      "secretsmanager:UpdateSecretVersionStage",
    ]
    resources = ["arn:aws:secretsmanager:*:${local.account_id}:secret:learntrack/*"]
  }

  statement {
    sid = "NamedResources"
    actions = [
      "cloudwatch:*",
      "ecs:*",
      "elasticloadbalancing:*",
      "events:*",
      "logs:*",
      "rds:*",
      "scheduler:*",
      "sns:*",
    ]
    resources = [
      "arn:aws:cloudwatch:*:${local.account_id}:alarm:learntrack-*",
      "arn:aws:ecs:*:${local.account_id}:cluster/learntrack-*",
      "arn:aws:ecs:*:${local.account_id}:service/learntrack-*/*",
      "arn:aws:ecs:*:${local.account_id}:task/learntrack-*/*",
      "arn:aws:ecs:*:${local.account_id}:task-definition/learntrack-*:*",
      "arn:aws:elasticloadbalancing:*:${local.account_id}:loadbalancer/app/learntrack-*/*",
      "arn:aws:elasticloadbalancing:*:${local.account_id}:listener/app/learntrack-*/*",
      "arn:aws:elasticloadbalancing:*:${local.account_id}:listener-rule/app/learntrack-*/*",
      "arn:aws:elasticloadbalancing:*:${local.account_id}:targetgroup/learntrack-*/*",
      "arn:aws:events:*:${local.account_id}:rule/learntrack-*",
      "arn:aws:logs:*:${local.account_id}:log-group:/learntrack/*",
      "arn:aws:rds:*:${local.account_id}:db:learntrack-*",
      "arn:aws:rds:*:${local.account_id}:og:default:*",
      "arn:aws:rds:*:${local.account_id}:pg:learntrack-*",
      "arn:aws:rds:*:${local.account_id}:snapshot:learntrack-*",
      "arn:aws:rds:*:${local.account_id}:subgrp:learntrack-*",
      "arn:aws:scheduler:*:${local.account_id}:schedule/*/learntrack-*",
      "arn:aws:sns:*:${local.account_id}:learntrack-*",
    ]
  }

  statement {
    sid       = "TaskDefinitions"
    actions   = ["ecs:RegisterTaskDefinition", "ecs:DeregisterTaskDefinition"]
    resources = ["*"]
  }

  statement {
    sid = "EnvironmentRolesWithBoundary"
    actions = [
      "iam:CreateRole",
      "iam:DeleteRole",
      "iam:DeleteRolePolicy",
      "iam:PutRolePermissionsBoundary",
      "iam:PutRolePolicy",
      "iam:UpdateAssumeRolePolicy",
      "iam:UpdateRole",
    ]
    resources = ["arn:aws:iam::${local.account_id}:role/learntrack-*"]

    condition {
      test     = "ArnEquals"
      variable = "iam:PermissionsBoundary"
      values   = [aws_iam_policy.boundary.arn]
    }
  }

  statement {
    sid       = "EnvironmentRoleTags"
    actions   = ["iam:TagRole", "iam:UntagRole"]
    resources = ["arn:aws:iam::${local.account_id}:role/learntrack-*"]
  }

  statement {
    sid       = "PassEnvironmentRoles"
    actions   = ["iam:PassRole"]
    resources = ["arn:aws:iam::${local.account_id}:role/learntrack-*"]

    condition {
      test     = "StringEquals"
      variable = "iam:PassedToService"
      values   = ["ecs-tasks.amazonaws.com", "scheduler.amazonaws.com"]
    }
  }

  statement {
    sid       = "ServiceLinkedRoles"
    actions   = ["iam:CreateServiceLinkedRole"]
    resources = ["*"]

    condition {
      test     = "StringEquals"
      variable = "iam:AWSServiceName"
      values   = ["ecs.amazonaws.com", "elasticloadbalancing.amazonaws.com", "rds.amazonaws.com"]
    }
  }

  statement {
    sid       = "NotTheGitHubRoles"
    effect    = "Deny"
    actions   = ["iam:*"]
    resources = ["arn:aws:iam::${local.account_id}:role/learntrack-deploy", "arn:aws:iam::${local.account_id}:role/learntrack-plan"]
  }

  statement {
    sid    = "NotTheBoundary"
    effect = "Deny"
    actions = [
      "iam:CreatePolicyVersion",
      "iam:DeletePolicy",
      "iam:DeletePolicyVersion",
      "iam:SetDefaultPolicyVersion",
      "iam:TagPolicy",
      "iam:UntagPolicy",
    ]
    resources = [aws_iam_policy.boundary.arn]
  }

  statement {
    sid       = "KeepBoundaries"
    effect    = "Deny"
    actions   = ["iam:DeleteRolePermissionsBoundary"]
    resources = ["*"]
  }
}

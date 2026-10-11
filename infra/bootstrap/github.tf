resource "aws_iam_openid_connect_provider" "github" {
  count = var.github_oidc_provider_arn == "" ? 1 : 0

  url            = "https://token.actions.githubusercontent.com"
  client_id_list = ["sts.amazonaws.com"]
}

locals {
  account_id               = data.aws_caller_identity.current.account_id
  github_oidc_provider_arn = coalesce(var.github_oidc_provider_arn, one(aws_iam_openid_connect_provider.github[*].arn))
  deploy_role_name         = "learntrack-deploy"
}

data "aws_iam_policy_document" "deploy_trust" {
  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [local.github_oidc_provider_arn]
    }

    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }

    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:sub"
      values = [
        "repo:${var.github_repository}:ref:refs/heads/main",
        "repo:${var.github_repository}:pull_request",
      ]
    }
  }
}

resource "aws_iam_role" "deploy" {
  name               = local.deploy_role_name
  assume_role_policy = data.aws_iam_policy_document.deploy_trust.json
}

data "aws_iam_policy_document" "deploy" {
  statement {
    sid       = "StateBucket"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.state.arn]
  }

  statement {
    sid       = "StateObjects"
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
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
      "ecr:DescribeImages",
      "ecr:DescribeRepositories",
      "ecr:GetDownloadUrlForLayer",
      "ecr:InitiateLayerUpload",
      "ecr:ListImages",
      "ecr:PutImage",
      "ecr:UploadLayerPart",
    ]
    resources = [aws_ecr_repository.app.arn]
  }

  statement {
    sid = "Read"
    actions = [
      "cloudwatch:Describe*",
      "cloudwatch:List*",
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
      "secretsmanager:List*",
      "sns:Get*",
      "sns:List*",
    ]
    resources = ["*"]
  }

  statement {
    sid = "Network"
    actions = [
      "ec2:Describe*",
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
      "secretsmanager:DescribeSecret",
      "secretsmanager:GetResourcePolicy",
      "secretsmanager:PutSecretValue",
      "secretsmanager:TagResource",
      "secretsmanager:UntagResource",
      "secretsmanager:UpdateSecret",
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
    sid = "EnvironmentRoles"
    actions = [
      "iam:AttachRolePolicy",
      "iam:CreateRole",
      "iam:DeleteRole",
      "iam:DeleteRolePolicy",
      "iam:DetachRolePolicy",
      "iam:PutRolePolicy",
      "iam:TagRole",
      "iam:UntagRole",
      "iam:UpdateAssumeRolePolicy",
      "iam:UpdateRole",
    ]
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
    sid       = "NotItself"
    effect    = "Deny"
    actions   = ["iam:*"]
    resources = ["arn:aws:iam::${local.account_id}:role/${local.deploy_role_name}"]
  }
}

resource "aws_iam_role_policy" "deploy" {
  name   = "learntrack-deploy"
  role   = aws_iam_role.deploy.id
  policy = data.aws_iam_policy_document.deploy.json
}

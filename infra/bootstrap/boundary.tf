data "aws_iam_policy_document" "boundary" {
  statement {
    sid       = "EcrLogin"
    actions   = ["ecr:GetAuthorizationToken"]
    resources = ["*"]
  }

  statement {
    sid = "EcrPull"
    actions = [
      "ecr:BatchCheckLayerAvailability",
      "ecr:BatchGetImage",
      "ecr:GetDownloadUrlForLayer",
    ]
    resources = [aws_ecr_repository.app.arn]
  }

  statement {
    sid       = "Logs"
    actions   = ["logs:CreateLogStream", "logs:PutLogEvents"]
    resources = ["arn:aws:logs:*:${local.account_id}:log-group:/learntrack/*"]
  }

  statement {
    sid       = "Secrets"
    actions   = ["secretsmanager:GetSecretValue"]
    resources = ["arn:aws:secretsmanager:*:${local.account_id}:secret:learntrack/*"]
  }

  statement {
    sid     = "RunTasks"
    actions = ["ecs:RunTask", "ecs:TagResource"]
    resources = [
      "arn:aws:ecs:*:${local.account_id}:task-definition/learntrack-*:*",
      "arn:aws:ecs:*:${local.account_id}:task/learntrack-*/*",
    ]
  }

  statement {
    sid       = "PassTaskRoles"
    actions   = ["iam:PassRole"]
    resources = ["arn:aws:iam::${local.account_id}:role/learntrack-*"]

    condition {
      test     = "StringEquals"
      variable = "iam:PassedToService"
      values   = ["ecs-tasks.amazonaws.com"]
    }
  }
}

resource "aws_iam_policy" "boundary" {
  name        = "learntrack-boundary"
  description = "The most any role the pipeline creates may do"
  policy      = data.aws_iam_policy_document.boundary.json
}

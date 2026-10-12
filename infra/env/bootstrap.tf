data "aws_iam_policy" "boundary" {
  name = "learntrack-boundary"
}

data "aws_sns_topic" "alarms" {
  name = "learntrack-alarms"
}

data "aws_ecr_repository" "app" {
  name = "learntrack-app"
}

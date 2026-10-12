locals {
  # The pipeline registers the revisions that run, with the image tagged by the git SHA.
  placeholder_image = "${data.aws_ecr_repository.app.repository_url}:placeholder"

  container_environment = [
    for name, value in {
      APP_ENV           = "production"
      APP_URL           = "https://${var.domain_name}"
      DB_HOST           = aws_db_instance.main.address
      DB_DATABASE       = aws_db_instance.main.db_name
      DB_USERNAME       = aws_db_instance.main.username
      MYSQL_ATTR_SSL_CA = "/etc/ssl/certs/rds-global-bundle.pem"
      SESSION_DRIVER    = "database"
      CACHE_STORE       = "database"
      LOG_CHANNEL       = "stderr"
      TRUSTED_PROXIES   = aws_vpc.main.cidr_block
    } : { name = name, value = value }
  ]

  container_secrets = [
    for name, secret in {
      DB_PASSWORD          = "db-password"
      APP_KEY              = "app-key"
      PASSPORT_PRIVATE_KEY = "passport-private-key"
      PASSPORT_PUBLIC_KEY  = "passport-public-key"
    } : { name = name, valueFrom = aws_secretsmanager_secret.app[secret].arn }
  ]
}

resource "aws_ecs_cluster" "main" {
  name = local.name

  setting {
    name  = "containerInsights"
    value = "disabled"
  }
}

resource "aws_cloudwatch_log_group" "app" {
  name              = "/learntrack/${var.environment}/app"
  retention_in_days = 30
}

data "aws_iam_policy_document" "ecs_tasks_trust" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "task_execution" {
  name                 = "${local.name}-task-execution"
  assume_role_policy   = data.aws_iam_policy_document.ecs_tasks_trust.json
  permissions_boundary = data.aws_iam_policy.boundary.arn
}

data "aws_iam_policy_document" "task_execution" {
  statement {
    actions   = ["ecr:GetAuthorizationToken"]
    resources = ["*"]
  }

  statement {
    actions = [
      "ecr:BatchCheckLayerAvailability",
      "ecr:BatchGetImage",
      "ecr:GetDownloadUrlForLayer",
    ]
    resources = [data.aws_ecr_repository.app.arn]
  }

  statement {
    actions   = ["logs:CreateLogStream", "logs:PutLogEvents"]
    resources = ["${aws_cloudwatch_log_group.app.arn}:log-stream:*"]
  }

  statement {
    actions   = ["secretsmanager:GetSecretValue"]
    resources = [for secret in aws_secretsmanager_secret.app : secret.arn]
  }
}

resource "aws_iam_role_policy" "task_execution" {
  name   = "${local.name}-task-execution"
  role   = aws_iam_role.task_execution.id
  policy = data.aws_iam_policy_document.task_execution.json
}

resource "aws_iam_role" "task" {
  name                 = "${local.name}-task"
  assume_role_policy   = data.aws_iam_policy_document.ecs_tasks_trust.json
  permissions_boundary = data.aws_iam_policy.boundary.arn
}

resource "aws_ecs_task_definition" "app" {
  family                   = "${local.name}-app"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.app_cpu
  memory                   = var.app_memory
  execution_role_arn       = aws_iam_role.task_execution.arn
  task_role_arn            = aws_iam_role.task.arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([{
    name         = "app"
    image        = local.placeholder_image
    essential    = true
    portMappings = [{ containerPort = 8080, protocol = "tcp" }]
    environment  = local.container_environment
    secrets      = local.container_secrets
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        awslogs-group         = aws_cloudwatch_log_group.app.name
        awslogs-region        = var.region
        awslogs-stream-prefix = "app"
      }
    }
  }])
}

resource "aws_ecs_task_definition" "housekeeping" {
  family                   = "${local.name}-housekeeping"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.app_cpu
  memory                   = var.app_memory
  execution_role_arn       = aws_iam_role.task_execution.arn
  task_role_arn            = aws_iam_role.task.arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([{
    name        = "housekeeping"
    image       = local.placeholder_image
    essential   = true
    command     = ["php", "artisan", "app:housekeeping"]
    environment = local.container_environment
    secrets     = local.container_secrets
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        awslogs-group         = aws_cloudwatch_log_group.app.name
        awslogs-region        = var.region
        awslogs-stream-prefix = "housekeeping"
      }
    }
  }])
}

resource "aws_ecs_service" "app" {
  name            = "${local.name}-app"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = var.desired_count
  launch_type     = "FARGATE"

  deployment_minimum_healthy_percent = 100
  deployment_maximum_percent         = 200
  health_check_grace_period_seconds  = 60

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  network_configuration {
    subnets          = [for subnet in aws_subnet.private : subnet.id]
    security_groups  = [aws_security_group.app.id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.app.arn
    container_name   = "app"
    container_port   = 8080
  }

  # The target group must belong to the load balancer before the service starts.
  depends_on = [aws_lb_listener.https]

  lifecycle {
    ignore_changes = [task_definition]
  }
}

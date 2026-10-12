locals {
  alarm_topic = [data.aws_sns_topic.alarms.arn]

  rds_alarms = {
    cpu = {
      description = "Database CPU above 80% over 5 minutes."
      metric      = "CPUUtilization"
      statistic   = "Average"
      operator    = "GreaterThanThreshold"
      threshold   = 80
    }
    free-storage = {
      description = "Database free storage below 2 GB."
      metric      = "FreeStorageSpace"
      statistic   = "Minimum"
      operator    = "LessThanThreshold"
      threshold   = 2 * 1024 * 1024 * 1024
    }
    connections = {
      description = "Database connections above 80% of max_connections (${var.db_max_connections})."
      metric      = "DatabaseConnections"
      statistic   = "Maximum"
      operator    = "GreaterThanThreshold"
      threshold   = floor(var.db_max_connections * 0.8)
    }
  }
}

resource "aws_cloudwatch_metric_alarm" "alb_5xx" {
  alarm_name          = "${local.name}-alb-5xx"
  alarm_description   = "More than 5% of requests answered 5xx over 5 minutes."
  comparison_operator = "GreaterThanThreshold"
  threshold           = 5
  evaluation_periods  = 1
  treat_missing_data  = "notBreaching"
  alarm_actions       = local.alarm_topic

  metric_query {
    id          = "rate"
    expression  = "IF(errors > 0, 100 * errors / MAX([requests, errors]), 0)"
    label       = "5xx rate (%)"
    return_data = true
  }

  metric_query {
    id         = "errors"
    expression = "FILL(elb5xx, 0) + FILL(target5xx, 0)"
  }

  metric_query {
    id         = "requests"
    expression = "FILL(requestcount, 0)"
  }

  dynamic "metric_query" {
    for_each = {
      elb5xx       = "HTTPCode_ELB_5XX_Count"
      target5xx    = "HTTPCode_Target_5XX_Count"
      requestcount = "RequestCount"
    }

    content {
      id = metric_query.key

      metric {
        namespace   = "AWS/ApplicationELB"
        metric_name = metric_query.value
        dimensions  = { LoadBalancer = aws_lb.main.arn_suffix }
        stat        = "Sum"
        period      = 300
      }
    }
  }
}

resource "aws_cloudwatch_metric_alarm" "unhealthy_targets" {
  alarm_name          = "${local.name}-unhealthy-targets"
  alarm_description   = "At least one app task fails the /up health check."
  namespace           = "AWS/ApplicationELB"
  metric_name         = "UnHealthyHostCount"
  dimensions          = { LoadBalancer = aws_lb.main.arn_suffix, TargetGroup = aws_lb_target_group.app.arn_suffix }
  statistic           = "Maximum"
  period              = 60
  evaluation_periods  = 1
  comparison_operator = "GreaterThanThreshold"
  threshold           = 0
  treat_missing_data  = "notBreaching"
  alarm_actions       = local.alarm_topic
}

resource "aws_cloudwatch_metric_alarm" "running_tasks" {
  alarm_name          = "${local.name}-running-tasks"
  alarm_description   = "Fewer app tasks running than the desired count (${var.desired_count}) for 5 minutes."
  namespace           = "AWS/ECS"
  metric_name         = "LiveTaskCount"
  dimensions          = { ClusterName = aws_ecs_cluster.main.name, ServiceName = aws_ecs_service.app.name }
  statistic           = "Minimum"
  period              = 60
  evaluation_periods  = 5
  comparison_operator = "LessThanThreshold"
  threshold           = var.desired_count
  # ECS sends no data when no task runs.
  treat_missing_data = "breaching"
  alarm_actions      = local.alarm_topic

  # The first deploy starts the tasks after the apply.
  warm_up_configuration {
    warm_up_period_duration_in_minutes = 15
  }
}

resource "aws_cloudwatch_metric_alarm" "response_time" {
  alarm_name          = "${local.name}-response-time"
  alarm_description   = "Target response time p95 above 1 second over 5 minutes."
  namespace           = "AWS/ApplicationELB"
  metric_name         = "TargetResponseTime"
  dimensions          = { LoadBalancer = aws_lb.main.arn_suffix }
  extended_statistic  = "p95"
  period              = 300
  evaluation_periods  = 1
  comparison_operator = "GreaterThanThreshold"
  threshold           = 1
  treat_missing_data  = "notBreaching"
  alarm_actions       = local.alarm_topic
}

resource "aws_cloudwatch_metric_alarm" "rds" {
  for_each = local.rds_alarms

  alarm_name          = "${local.name}-db-${each.key}"
  alarm_description   = each.value.description
  namespace           = "AWS/RDS"
  metric_name         = each.value.metric
  dimensions          = { DBInstanceIdentifier = aws_db_instance.main.identifier }
  statistic           = each.value.statistic
  period              = 300
  evaluation_periods  = 1
  comparison_operator = each.value.operator
  threshold           = each.value.threshold
  treat_missing_data  = "notBreaching"
  alarm_actions       = local.alarm_topic
}

resource "aws_cloudwatch_event_rule" "housekeeping_failed" {
  name        = "${local.name}-housekeeping-failed"
  description = "A housekeeping task stopped with a non-zero exit code or failed to start."

  event_pattern = jsonencode({
    source      = ["aws.ecs"]
    detail-type = ["ECS Task State Change"]
    detail = {
      clusterArn        = [aws_ecs_cluster.main.arn]
      taskDefinitionArn = [{ prefix = "${aws_ecs_task_definition.housekeeping.arn_without_revision}:" }]
      lastStatus        = ["STOPPED"]
      "$or" = [
        { containers = { exitCode = [{ anything-but = 0 }] } },
        { stopCode = ["TaskFailedToStart"] },
      ]
    }
  })
}

resource "aws_cloudwatch_event_target" "housekeeping_failed" {
  rule = aws_cloudwatch_event_rule.housekeeping_failed.name
  arn  = data.aws_sns_topic.alarms.arn
}

resource "aws_cloudwatch_metric_alarm" "housekeeping_not_started" {
  alarm_name          = "${local.name}-housekeeping-not-started"
  alarm_description   = "The schedule could not start the housekeeping task (RunTask returned an error)."
  namespace           = "AWS/Scheduler"
  metric_name         = "TargetErrorCount"
  dimensions          = { ScheduleGroup = aws_scheduler_schedule.housekeeping.group_name }
  statistic           = "Sum"
  period              = 300
  evaluation_periods  = 1
  comparison_operator = "GreaterThanThreshold"
  threshold           = 0
  treat_missing_data  = "notBreaching"
  alarm_actions       = local.alarm_topic
}

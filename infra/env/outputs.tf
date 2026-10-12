output "certificate_arn" {
  value = aws_acm_certificate_validation.main.certificate_arn
}

output "secret_arns" {
  value = { for name, secret in aws_secretsmanager_secret.app : name => secret.arn }
}

output "url" {
  value = "https://${var.domain_name}"
}

output "cluster" {
  value = aws_ecs_cluster.main.name
}

output "app_service" {
  value = aws_ecs_service.app.name
}

output "app_family" {
  value = aws_ecs_task_definition.app.family
}

output "housekeeping_family" {
  value = aws_ecs_task_definition.housekeeping.family
}

output "task_subnet_ids" {
  value = join(",", [for subnet in aws_subnet.private : subnet.id])
}

output "task_security_group_id" {
  value = aws_security_group.app.id
}

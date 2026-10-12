output "certificate_arn" {
  value = aws_acm_certificate_validation.main.certificate_arn
}

output "secret_arns" {
  value = { for name, secret in aws_secretsmanager_secret.app : name => secret.arn }
}

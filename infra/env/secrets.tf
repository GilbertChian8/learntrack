locals {
  db_password_version  = 1
  app_key_version      = 1
  passport_key_version = 1

  secret_versions = {
    db-password          = local.db_password_version
    app-key              = local.app_key_version
    passport-private-key = local.passport_key_version
    passport-public-key  = local.passport_key_version
  }

  secret_values = {
    db-password          = ephemeral.random_password.db.result
    app-key              = "base64:${ephemeral.random_bytes.app_key.base64}"
    passport-private-key = ephemeral.tls_private_key.passport.private_key_pem
    passport-public-key  = ephemeral.tls_private_key.passport.public_key_pem
  }
}

ephemeral "random_password" "db" {
  length  = 32
  special = false
}

ephemeral "random_bytes" "app_key" {
  length = 32
}

ephemeral "tls_private_key" "passport" {
  algorithm = "RSA"
  rsa_bits  = 4096
}

resource "aws_secretsmanager_secret" "app" {
  for_each = local.secret_versions

  name                    = "learntrack/${var.environment}/${each.key}"
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "app" {
  for_each = aws_secretsmanager_secret.app

  secret_id                = each.value.id
  secret_string_wo         = local.secret_values[each.key]
  secret_string_wo_version = local.secret_versions[each.key]

  # Wait for the database, so a failed create does not leave a different password in the secret.
  depends_on = [aws_db_instance.main]
}

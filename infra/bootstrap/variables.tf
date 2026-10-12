variable "region" {
  type    = string
  default = "eu-central-1"
}

variable "domain_name" {
  type = string
}

variable "github_subject_prefix" {
  type        = string
  description = "OIDC subject prefix of the repository the GitHub roles trust: gh api repos/<owner>/<name>/actions/oidc/customization/sub --jq .sub_claim_prefix"

  validation {
    condition     = startswith(var.github_subject_prefix, "repo:")
    error_message = "Use the sub_claim_prefix from the GitHub API, which starts with repo:."
  }
}

variable "alarm_email" {
  type        = string
  description = "Gets the alarms of every environment."
}

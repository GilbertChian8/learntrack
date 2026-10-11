variable "region" {
  type    = string
  default = "eu-central-1"
}

variable "domain_name" {
  type = string
}

variable "github_repository" {
  type        = string
  description = "owner/name of the GitHub repository the deploy role trusts."
}

variable "github_oidc_provider_arn" {
  type        = string
  default     = ""
  description = "Empty creates the GitHub OIDC provider. Set it to reuse one that already exists in the account."
}

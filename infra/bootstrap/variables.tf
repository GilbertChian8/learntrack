variable "region" {
  type    = string
  default = "eu-central-1"
}

variable "domain_name" {
  type = string
}

variable "github_repository" {
  type        = string
  description = "owner/name of the GitHub repository the GitHub roles trust."
}

variable "alarm_email" {
  type        = string
  description = "Gets the alarms of every environment."
}

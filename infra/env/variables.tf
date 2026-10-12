variable "environment" {
  type    = string
  default = "prod"

  validation {
    condition     = can(regex("^[a-z][a-z0-9-]*$", var.environment))
    error_message = "Use lowercase letters, digits and hyphens."
  }
}

variable "region" {
  type    = string
  default = "eu-central-1"
}

variable "domain_name" {
  type = string
}

variable "hosted_zone_id" {
  type        = string
  description = "The bootstrap's hosted zone for domain_name."
}

variable "db_instance_class" {
  type    = string
  default = "db.t4g.micro"
}

variable "db_max_connections" {
  type        = number
  default     = 60
  description = "max_connections of db_instance_class (about 60 on 1 GiB classes). The connections alarm fires at 80%."
}

variable "app_cpu" {
  type    = number
  default = 512
}

variable "app_memory" {
  type    = number
  default = 1024
}

variable "desired_count" {
  type    = number
  default = 2
}

variable "skip_final_snapshot" {
  type        = bool
  default     = true
  description = "This environment is rebuilt from code, so no final snapshot by default."
}

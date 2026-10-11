resource "aws_security_group" "alb" {
  name        = "${local.name}-alb"
  description = "Load balancer: HTTP and HTTPS from the internet"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${local.name}-alb"
  }
}

resource "aws_vpc_security_group_ingress_rule" "alb" {
  for_each = toset(["80", "443"])

  security_group_id = aws_security_group.alb.id
  ip_protocol       = "tcp"
  from_port         = tonumber(each.key)
  to_port           = tonumber(each.key)
  cidr_ipv4         = "0.0.0.0/0"
}

resource "aws_security_group" "app" {
  name        = "${local.name}-app"
  description = "App tasks: port 8080 from the load balancer only"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${local.name}-app"
  }
}

resource "aws_vpc_security_group_ingress_rule" "app" {
  security_group_id            = aws_security_group.app.id
  ip_protocol                  = "tcp"
  from_port                    = 8080
  to_port                      = 8080
  referenced_security_group_id = aws_security_group.alb.id
}

resource "aws_security_group" "db" {
  name        = "${local.name}-db"
  description = "Database: MySQL from the app tasks only"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${local.name}-db"
  }
}

resource "aws_vpc_security_group_ingress_rule" "db" {
  security_group_id            = aws_security_group.db.id
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
  referenced_security_group_id = aws_security_group.app.id
}

resource "aws_vpc_security_group_egress_rule" "all" {
  for_each = {
    alb = aws_security_group.alb.id
    app = aws_security_group.app.id
    db  = aws_security_group.db.id
  }

  security_group_id = each.value
  ip_protocol       = "-1"
  cidr_ipv4         = "0.0.0.0/0"
}

# Peyra's production storage: one private S3 bucket and the one IAM user the application signs in
# as. Run from the operator's own machine, with their own AWS profile - never by CI, never by an
# agent. docs/production.md, « Le compartiment S3 (Terraform) », gives the whole sequence.
#
# The state stays local (terraform.tfstate, gitignored): it holds the IAM user's secret key.
terraform {
  required_version = ">= 1.5.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }
}

# No key in any file: the profile names credentials kept in the operator's ~/.aws.
provider "aws" {
  profile = var.aws_profile
  region  = var.aws_region

  default_tags {
    tags = {
      Application = "Peyra"
      ManagedBy   = "Terraform"
    }
  }
}

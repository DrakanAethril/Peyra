# Descriptions and error messages are what the operator reads (`terraform plan` asks for a missing
# variable with its description): French, like every text a person reads.

variable "aws_profile" {
  description = "Le profil AWS de votre machine (~/.aws/config) avec lequel Terraform se connecte ; il doit pouvoir créer un compartiment S3, un utilisateur IAM et ses clés."
  type        = string

  validation {
    condition     = trimspace(var.aws_profile) != ""
    error_message = "Nommez le profil AWS à utiliser (aws_profile)."
  }
}

variable "aws_region" {
  description = "La région du compartiment, et la valeur de S3_REGION (Paris : eu-west-3)."
  type        = string
  default     = "eu-west-3"

  validation {
    condition     = can(regex("^[a-z]{2}(-[a-z]+)+-[0-9]$", var.aws_region))
    error_message = "Une région s'écrit comme eu-west-3."
  }
}

variable "bucket_name" {
  description = "Le nom du compartiment, unique dans tout AWS, et la valeur de S3_BUCKET."
  type        = string

  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$", var.bucket_name)) && !strcontains(var.bucket_name, "--")
    error_message = "Un nom de compartiment fait 3 à 63 caractères : minuscules, chiffres et tirets simples, sans tiret au début ni à la fin."
  }

  validation {
    condition     = var.bucket_name != "exemple-peyra-documents"
    error_message = "« exemple-peyra-documents » est l'exemple de terraform.tfvars.example : choisissez le vrai nom."
  }
}

variable "prefix" {
  description = "La valeur de S3_PREFIX : vide pour un compartiment propre à Peyra (le cas ici), sinon un dossier terminé par « / » auquel l'utilisateur IAM est borné."
  type        = string
  default     = ""

  validation {
    condition     = var.prefix == "" || can(regex("^[a-z0-9][a-z0-9-]*/$", var.prefix))
    error_message = "Le préfixe est vide, ou fait de minuscules, de chiffres et de tirets, terminé par « / » (peyra/)."
  }
}

variable "iam_user_name" {
  description = "L'utilisateur IAM avec lequel Peyra se connecte à S3 ; ses clés sont S3_ACCESS_KEY_ID et S3_SECRET_ACCESS_KEY."
  type        = string
  default     = "peyra-storage"
}

variable "noncurrent_version_days" {
  description = "Pendant combien de jours un fichier supprimé ou remplacé reste récupérable (sa version précédente) avant que S3 ne l'efface."
  type        = number
  default     = 90

  validation {
    condition     = var.noncurrent_version_days >= 1 && floor(var.noncurrent_version_days) == var.noncurrent_version_days
    error_message = "Un nombre entier de jours, au moins 1."
  }
}

variable "incomplete_upload_days" {
  description = "Pendant combien de jours les morceaux d'un envoi jamais terminé (au-delà de 16 Mo, un fichier part en plusieurs morceaux) restent avant que S3 ne les efface."
  type        = number
  default     = 7

  validation {
    condition     = var.incomplete_upload_days >= 1 && floor(var.incomplete_upload_days) == var.incomplete_upload_days
    error_message = "Un nombre entier de jours, au moins 1."
  }
}

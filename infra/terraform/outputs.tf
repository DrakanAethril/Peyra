# Named after the variables of .env.prod.local they fill (.env.prod.local.example, « peyra/storage »).
# `terraform output -raw env_prod_local` prints the whole block, ready to paste. Descriptions are
# read by the operator: French.

output "PEYRA_STORAGE" {
  description = "Le stockage où Peyra écrit."
  value       = "s3"
}

output "S3_BUCKET" {
  description = "Le nom du compartiment."
  value       = aws_s3_bucket.documents.bucket
}

output "S3_PREFIX" {
  description = "Le dossier du compartiment sous lequel Peyra écrit (vide : tout le compartiment)."
  value       = var.prefix
}

output "S3_REGION" {
  description = "La région du compartiment."
  value       = var.aws_region
}

output "S3_ACCESS_KEY_ID" {
  description = "L'identifiant de clé de l'utilisateur IAM."
  value       = aws_iam_access_key.storage.id
}

output "S3_SECRET_ACCESS_KEY" {
  description = "La clé secrète de l'utilisateur IAM : terraform output -raw S3_SECRET_ACCESS_KEY."
  value       = aws_iam_access_key.storage.secret
  sensitive   = true
}

output "S3_ENDPOINT" {
  description = "Vide : AWS lui-même (une adresse ne sert que pour un autre service compatible S3)."
  value       = ""
}

output "env_prod_local" {
  description = "Le bloc « peyra/storage » de .env.prod.local, clé secrète comprise : terraform output -raw env_prod_local."
  sensitive   = true
  value       = <<-EOT
    PEYRA_STORAGE=s3
    S3_BUCKET=${aws_s3_bucket.documents.bucket}
    S3_PREFIX=${var.prefix}
    S3_REGION=${var.aws_region}
    S3_ACCESS_KEY_ID=${aws_iam_access_key.storage.id}
    S3_SECRET_ACCESS_KEY=${aws_iam_access_key.storage.secret}
    S3_ENDPOINT=
  EOT
}

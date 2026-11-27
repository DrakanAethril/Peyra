# The dossiers' files: pièces, generated documents (App\Service\Shared\Storage\DossierFiles).
# Private through and through - no public access, no CloudFront, no website: every file reaches a
# person through the application (a Voter, then a stream or a short-lived signed address).
resource "aws_s3_bucket" "documents" {
  bucket = var.bucket_name

  # A `terraform destroy` or a renamed bucket would empty and delete every dossier's files: refused.
  # To really delete the bucket, remove this block first, on purpose.
  lifecycle {
    prevent_destroy = true
  }
}

# ACLs disabled: the bucket owns every object, and access is the IAM policy alone. The application
# sends no ACL (S3ClientFactory), which is all such a bucket accepts besides bucket-owner-full-control.
resource "aws_s3_bucket_ownership_controls" "documents" {
  bucket = aws_s3_bucket.documents.id

  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_public_access_block" "documents" {
  bucket = aws_s3_bucket.documents.id

  block_public_acls       = true
  ignore_public_acls      = true
  block_public_policy     = true
  restrict_public_buckets = true
}

# Encrypted at rest with keys S3 manages (SSE-S3): no KMS key to pay for or to grant.
resource "aws_s3_bucket_server_side_encryption_configuration" "documents" {
  bucket = aws_s3_bucket.documents.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

# A deleted or replaced file keeps its previous version: the safety net of a mistaken gesture or a
# bug, for noncurrent_version_days.
resource "aws_s3_bucket_versioning" "documents" {
  bucket = aws_s3_bucket.documents.id

  versioning_configuration {
    status = "Enabled"
  }
}

# Only what is no longer current: S3 never expires a file the application still holds. Peyra's keys
# are random and never rewritten, so a noncurrent version is a deleted file's.
resource "aws_s3_bucket_lifecycle_configuration" "documents" {
  bucket = aws_s3_bucket.documents.id

  rule {
    id     = "old-versions"
    status = "Enabled"

    filter {}

    noncurrent_version_expiration {
      noncurrent_days = var.noncurrent_version_days
    }

    # The delete marker left once a deleted file's last version is gone.
    expiration {
      expired_object_delete_marker = true
    }
  }

  rule {
    id     = "incomplete-uploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = var.incomplete_upload_days
    }
  }

  # A lifecycle rule on noncurrent versions needs versioning in place.
  depends_on = [aws_s3_bucket_versioning.documents]
}

# HTTPS only, for everybody, the bucket's owner included.
data "aws_iam_policy_document" "bucket" {
  statement {
    sid     = "RefuseUnencryptedTransport"
    effect  = "Deny"
    actions = ["s3:*"]
    resources = [
      aws_s3_bucket.documents.arn,
      "${aws_s3_bucket.documents.arn}/*",
    ]

    principals {
      type        = "*"
      identifiers = ["*"]
    }

    condition {
      test     = "Bool"
      variable = "aws:SecureTransport"
      values   = ["false"]
    }
  }
}

resource "aws_s3_bucket_policy" "documents" {
  bucket = aws_s3_bucket.documents.id
  policy = data.aws_iam_policy_document.bucket.json

  # S3 refuses a bucket policy while the public access block is being written.
  depends_on = [aws_s3_bucket_public_access_block.documents]
}

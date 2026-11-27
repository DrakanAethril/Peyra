# The one identity Peyra signs in to S3 as (S3_ACCESS_KEY_ID / S3_SECRET_ACCESS_KEY).
#
# Its policy is exactly what the application does on the bucket - read, write, check, size, list a
# dossier's prefix, copy a dossier, delete - and S3BucketPolicyTest (tests/Service/Shared/Storage/)
# compares the actions below with the requests DossierFiles really sends: change one, change both.
# Not granted, on purpose: s3:DeleteObjectVersion (it would empty the versioning's safety net),
# s3:PutObjectAcl / s3:GetObjectAcl (ACLs are disabled, the client sends none), any bucket setting.
resource "aws_iam_user" "storage" {
  name = var.iam_user_name
  path = "/peyra/"
}

resource "aws_iam_access_key" "storage" {
  user = aws_iam_user.storage.name
}

locals {
  # Mind the shape of the ARNs: s3:ListBucket is an action on the BUCKET (its ARN, no path), the
  # others act on OBJECTS (bucket/key). A path on the bucket's ARN matches no bucket action at all.
  objects_arn = "${aws_s3_bucket.documents.arn}/${var.prefix}*"
}

data "aws_iam_policy_document" "storage" {
  # Listing a dossier's files, to copy a dossier (distribution, restore points). Also what makes S3
  # answer 404 rather than 403 for a file that does not exist.
  statement {
    sid       = "ListDossierFiles"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.documents.arn]

    dynamic "condition" {
      for_each = var.prefix == "" ? [] : [var.prefix]

      content {
        test     = "StringLike"
        variable = "s3:prefix"
        values   = ["${condition.value}*"]
      }
    }
  }

  # GetObject: read, stream, check, size (HeadObject), the source of a copy.
  # PutObject: write (in parts above 16 MB: the same right), the target of a copy.
  # DeleteObject: a delete marker on a versioned bucket - the version stays recoverable.
  statement {
    sid       = "ReadWriteDossierFiles"
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
    resources = [local.objects_arn]
  }
}

resource "aws_iam_user_policy" "storage" {
  name   = "peyra-documents"
  user   = aws_iam_user.storage.name
  policy = data.aws_iam_policy_document.storage.json
}

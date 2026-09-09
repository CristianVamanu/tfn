"""Cloudflare R2 client (S3-compatible). Used by future upload features
(avatars, marketplace product files) - not wired into any route yet.

Requires these env vars to be set (see .env.example):
  R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET_NAME
"""
import os

import boto3
from botocore.config import Config

_client = None


def _get_client():
    global _client
    if _client is not None:
        return _client

    account_id = os.getenv("R2_ACCOUNT_ID")
    access_key = os.getenv("R2_ACCESS_KEY_ID")
    secret_key = os.getenv("R2_SECRET_ACCESS_KEY")
    if not all([account_id, access_key, secret_key]):
        raise RuntimeError(
            "R2 storage not configured - set R2_ACCOUNT_ID, R2_ACCESS_KEY_ID "
            "and R2_SECRET_ACCESS_KEY in the environment."
        )

    _client = boto3.client(
        "s3",
        endpoint_url=f"https://{account_id}.r2.cloudflarestorage.com",
        aws_access_key_id=access_key,
        aws_secret_access_key=secret_key,
        config=Config(signature_version="s3v4"),
        region_name="auto",
    )
    return _client


def _bucket() -> str:
    bucket = os.getenv("R2_BUCKET_NAME")
    if not bucket:
        raise RuntimeError("R2_BUCKET_NAME is not set.")
    return bucket


def upload_bytes(data: bytes, key: str, content_type: str = "application/octet-stream") -> None:
    _get_client().put_object(Bucket=_bucket(), Key=key, Body=data, ContentType=content_type)


def upload_file(local_path: str, key: str, content_type: str = "application/octet-stream") -> None:
    _get_client().upload_file(local_path, _bucket(), key, ExtraArgs={"ContentType": content_type})


def delete_object(key: str) -> None:
    _get_client().delete_object(Bucket=_bucket(), Key=key)


def presigned_get_url(key: str, expires_in: int = 3600) -> str:
    return _get_client().generate_presigned_url(
        "get_object", Params={"Bucket": _bucket(), "Key": key}, ExpiresIn=expires_in
    )


def presigned_put_url(key: str, content_type: str = "application/octet-stream", expires_in: int = 900) -> str:
    return _get_client().generate_presigned_url(
        "put_object",
        Params={"Bucket": _bucket(), "Key": key, "ContentType": content_type},
        ExpiresIn=expires_in,
    )

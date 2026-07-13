#!/usr/bin/env python3
"""Cross-language golden-vector test for SSP Kiosk HMAC signing."""

from __future__ import annotations

import hashlib
import hmac
import json
import unittest

# Generated from PHP KioskSecurityService::buildCanonicalPayload + signPayload
GOLDEN_UUID = "550e8400-e29b-41d4-a716-446655440000"
GOLDEN_TIMESTAMP = "1700000000"
GOLDEN_NONCE = "11111111-2222-3333-4444-555555555555"
GOLDEN_METHOD = "POST"
GOLDEN_PATH = "/kiosk/heartbeat"
GOLDEN_BODY = b'{"device_fingerprint":"abc123"}'
GOLDEN_SECRET = "test-secret-key-for-golden-vector"
GOLDEN_SIGNATURE = "97f8556f7ecf7b9da0764c4f96d8d130970ed89ccc82e6103d92e7e6ec7093dd"


def build_canonical(
    kiosk_uuid: str,
    timestamp: str,
    nonce: str,
    method: str,
    path: str,
    body_bytes: bytes,
) -> str:
    body_hash = hashlib.sha256(body_bytes).hexdigest()
    path_value = "/" + path.lstrip("/")
    return "\n".join([kiosk_uuid, timestamp, nonce, method.upper(), path_value, body_hash])


def sign(canonical: str, secret: str) -> str:
    return hmac.new(secret.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256).hexdigest()


class SigningGoldenVectorTest(unittest.TestCase):
    def test_empty_body_hash_matches_php(self) -> None:
        empty_hash = hashlib.sha256(b"").hexdigest()
        self.assertEqual(
            empty_hash,
            "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
        )

    def test_canonical_payload_matches_php(self) -> None:
        canonical = build_canonical(
            GOLDEN_UUID,
            GOLDEN_TIMESTAMP,
            GOLDEN_NONCE,
            GOLDEN_METHOD,
            GOLDEN_PATH,
            GOLDEN_BODY,
        )
        expected_body_hash = hashlib.sha256(GOLDEN_BODY).hexdigest()
        expected = "\n".join(
            [
                GOLDEN_UUID,
                GOLDEN_TIMESTAMP,
                GOLDEN_NONCE,
                "POST",
                "/kiosk/heartbeat",
                expected_body_hash,
            ]
        )
        self.assertEqual(canonical, expected)

    def test_signature_matches_php(self) -> None:
        canonical = build_canonical(
            GOLDEN_UUID,
            GOLDEN_TIMESTAMP,
            GOLDEN_NONCE,
            GOLDEN_METHOD,
            GOLDEN_PATH,
            GOLDEN_BODY,
        )
        signature = sign(canonical, GOLDEN_SECRET)
        self.assertEqual(signature, GOLDEN_SIGNATURE)


if __name__ == "__main__":
    unittest.main()

from datetime import UTC, datetime, timedelta
from uuid import UUID

import jwt
from pwdlib import PasswordHash

from app.core.config import get_settings

_password_hash = PasswordHash.recommended()
_ALGORITHM = "HS256"


def hash_password(password: str) -> str:
    return _password_hash.hash(password)


def verify_password(password: str, password_hash: str) -> bool:
    return _password_hash.verify(password, password_hash)


def create_access_token(*, subject: UUID, expires_minutes: int | None = None) -> str:
    settings = get_settings()
    expires = datetime.now(UTC) + timedelta(
        minutes=expires_minutes or settings.access_token_expire_minutes
    )
    payload = {"sub": str(subject), "iat": datetime.now(UTC), "exp": expires}
    return jwt.encode(payload, settings.jwt_secret, algorithm=_ALGORITHM)


def decode_access_token(token: str) -> UUID:
    settings = get_settings()
    payload = jwt.decode(token, settings.jwt_secret, algorithms=[_ALGORITHM])
    subject = payload.get("sub")
    if not isinstance(subject, str):
        raise ValueError("Invalid token subject")
    return UUID(subject)

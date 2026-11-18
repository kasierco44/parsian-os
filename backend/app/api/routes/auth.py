from datetime import UTC, datetime, timedelta
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from jwt import InvalidTokenError
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.security import create_access_token, decode_access_token, hash_password, verify_password
from app.db.session import get_db
from app.models.identity import User
from app.schemas.auth import LoginRequest, PasswordChangeRequest, TokenRead
from app.schemas.identity import UserRead

router = APIRouter(prefix="/auth", tags=["authentication"])
_bearer = HTTPBearer(auto_error=False)
MAX_FAILED_LOGINS = 5
LOCKOUT_MINUTES = 15


def _authenticated_user(
    credentials: HTTPAuthorizationCredentials | None,
    db: Session,
    *,
    allow_password_change: bool = False,
) -> User:
    unauthorized = HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Invalid or missing authentication credentials",
        headers={"WWW-Authenticate": "Bearer"},
    )
    if credentials is None or credentials.scheme.lower() != "bearer":
        raise unauthorized
    try:
        user_id: UUID = decode_access_token(credentials.credentials)
    except (InvalidTokenError, ValueError, TypeError):
        raise unauthorized from None
    user = db.scalar(select(User).where(User.id == user_id))
    if user is None or not user.is_active:
        raise unauthorized
    if user.must_change_password and not allow_password_change:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Password change required",
            headers={"X-Password-Change-Required": "true"},
        )
    return user


def get_current_user(
    credentials: HTTPAuthorizationCredentials | None = Depends(_bearer),
    db: Session = Depends(get_db),
) -> User:
    return _authenticated_user(credentials, db)


def get_password_change_user(
    credentials: HTTPAuthorizationCredentials | None = Depends(_bearer),
    db: Session = Depends(get_db),
) -> User:
    return _authenticated_user(credentials, db, allow_password_change=True)


@router.post("/login", response_model=TokenRead, summary="Exchange credentials for an access token")
def login(payload: LoginRequest, db: Session = Depends(get_db)) -> TokenRead:
    user = db.scalar(select(User).where(User.username == payload.username))
    now = datetime.now(UTC)
    if user is None:
        # Keep response generic to avoid disclosing whether an account exists.
        raise HTTPException(status_code=401, detail="Incorrect username or password")
    if user.locked_until is not None:
        locked_until = user.locked_until
        if locked_until.tzinfo is None:
            locked_until = locked_until.replace(tzinfo=UTC)
        if locked_until > now:
            raise HTTPException(status_code=423, detail="Account temporarily locked")
        user.locked_until = None
        user.failed_login_attempts = 0
    if not user.is_active or not verify_password(payload.password, user.password_hash):
        user.failed_login_attempts += 1
        if user.failed_login_attempts >= MAX_FAILED_LOGINS:
            user.locked_until = now + timedelta(minutes=LOCKOUT_MINUTES)
            user.failed_login_attempts = 0
        db.commit()
        raise HTTPException(status_code=401, detail="Incorrect username or password")
    user.failed_login_attempts = 0
    user.locked_until = None
    db.commit()
    return TokenRead(access_token=create_access_token(subject=user.id))


@router.post("/password/change", response_model=UserRead, summary="Change the current user's password")
def change_password(
    payload: PasswordChangeRequest,
    user: User = Depends(get_password_change_user),
    db: Session = Depends(get_db),
) -> User:
    if not verify_password(payload.current_password, user.password_hash):
        raise HTTPException(status_code=400, detail="Current password is incorrect")
    if payload.current_password == payload.new_password:
        raise HTTPException(status_code=422, detail="New password must differ from current password")
    user.password_hash = hash_password(payload.new_password)
    user.must_change_password = False
    user.failed_login_attempts = 0
    user.locked_until = None
    db.commit()
    db.refresh(user)
    return user


@router.get("/me", response_model=UserRead, summary="Get the authenticated user")
def read_current_user(user: User = Depends(get_current_user)) -> User:
    return user

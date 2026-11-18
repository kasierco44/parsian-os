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
from app.services.audit import record_audit_event

router = APIRouter(prefix="/auth", tags=["authentication"])
_bearer = HTTPBearer(auto_error=False)


def _resolve_user(credentials: HTTPAuthorizationCredentials | None, db: Session, allow_password_change: bool = False) -> User:
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
        raise HTTPException(status_code=403, detail="password change required")
    return user


def get_current_user(credentials: HTTPAuthorizationCredentials | None = Depends(_bearer), db: Session = Depends(get_db)) -> User:
    return _resolve_user(credentials, db, allow_password_change=False)


def get_current_user_for_password_change(credentials: HTTPAuthorizationCredentials | None = Depends(_bearer), db: Session = Depends(get_db)) -> User:
    return _resolve_user(credentials, db, allow_password_change=True)


@router.post("/login", response_model=TokenRead, summary="Exchange credentials for an access token")
def login(payload: LoginRequest, db: Session = Depends(get_db)) -> TokenRead:
    user = db.scalar(select(User).where(User.username == payload.username))
    if user is None or not user.is_active or not verify_password(payload.password, user.password_hash):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Incorrect username or password", headers={"WWW-Authenticate": "Bearer"})
    return TokenRead(access_token=create_access_token(subject=user.id))


@router.get("/me", response_model=UserRead, summary="Get the authenticated user")
def read_current_user(user: User = Depends(get_current_user)) -> User:
    return user


@router.post("/password/change", response_model=UserRead, summary="Change the current user's password")
def change_password(payload: PasswordChangeRequest, user: User = Depends(get_current_user_for_password_change), db: Session = Depends(get_db)) -> User:
    if not verify_password(payload.current_password, user.password_hash):
        raise HTTPException(status_code=400, detail="رمز فعلی صحیح نیست.")
    user.password_hash = hash_password(payload.new_password)
    user.must_change_password = False
    record_audit_event(db, action="password_changed", entity_type="user", actor_user_id=user.id, entity_id=str(user.id))
    db.commit()
    db.refresh(user)
    return user

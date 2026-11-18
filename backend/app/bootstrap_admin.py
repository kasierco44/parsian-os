import os
import sys

from sqlalchemy import select

from app.core.security import hash_password
from app.db.session import get_session_factory
from app.models.identity import Role, User


def main() -> int:
    username = os.getenv("BOOTSTRAP_ADMIN_USERNAME", "").strip()
    password = os.getenv("BOOTSTRAP_ADMIN_PASSWORD", "")
    full_name = os.getenv("BOOTSTRAP_ADMIN_NAME", "مدیر سامانه").strip() or "مدیر سامانه"
    if not username and not password:
        print("Bootstrap admin skipped; no bootstrap credentials configured.")
        return 0
    if not username or len(password) < 12:
        print("BOOTSTRAP_ADMIN_USERNAME and a password of at least 12 characters are required.", file=sys.stderr)
        return 2

    session = get_session_factory()()
    try:
        existing = session.scalar(select(User).where(User.username == username))
        if existing is not None:
            print("Bootstrap admin already exists; no changes made.")
            return 0
        role = session.scalar(select(Role).where(Role.code == "manager"))
        if role is None:
            role = Role(code="manager", name_fa="مدیر", description="مدیریت سامانه و کاتالوگ", is_system=True)
            session.add(role)
            session.flush()
        user = User(
            username=username,
            full_name=full_name,
            password_hash=hash_password(password),
            is_active=True,
            is_superuser=True,
        )
        user.roles.append(role)
        session.add(user)
        session.commit()
        print("Bootstrap administrator created successfully.")
        return 0
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


if __name__ == "__main__":
    raise SystemExit(main())

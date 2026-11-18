"""Create the first PARSIAN OS superuser and seed the initial RBAC catalog.

Run from backend/: python -m scripts.bootstrap_admin
Required environment variables: BOOTSTRAP_ADMIN_USERNAME,
BOOTSTRAP_ADMIN_FULL_NAME, BOOTSTRAP_ADMIN_PASSWORD.
Optional: BOOTSTRAP_ADMIN_EMAIL.
This command is intentionally not exposed as an HTTP endpoint.
"""
import os
import sys

from sqlalchemy import select

from app.core.security import hash_password
from app.db.session import get_session_factory
from app.models.identity import Permission, Role, User

ROLE_SEEDS = {
    "admin": ("مدیر", "مدیریت کامل سامانه"),
    "accountant": ("حسابدار", "دسترسی‌های مالی و انبار"),
    "seller": ("فروشنده", "مدیریت مشتریان و فروش"),
    "visitor": ("ویزیتور", "ثبت پیگیری و سفارش"),
    "customer": ("مشتری", "دسترسی محدود مشتری"),
}
PERMISSION_SEEDS = {
    "users.read": "مشاهده کاربران",
    "users.create": "ایجاد کاربران",
    "users.update": "ویرایش کاربران",
    "roles.read": "مشاهده نقش‌ها",
    "roles.manage": "مدیریت نقش‌ها و مجوزها",
    "customers.read": "مشاهده مشتریان",
    "customers.write": "ثبت و ویرایش مشتریان",
    "sales.read": "مشاهده فروش",
    "sales.write": "ثبت فروش",
    "accounting.read": "مشاهده امور مالی",
    "accounting.write": "ثبت امور مالی",
    "warehouse.read": "مشاهده انبار",
    "warehouse.write": "مدیریت انبار",
}


def main() -> int:
    username = os.getenv("BOOTSTRAP_ADMIN_USERNAME", "").strip()
    full_name = os.getenv("BOOTSTRAP_ADMIN_FULL_NAME", "").strip()
    password = os.getenv("BOOTSTRAP_ADMIN_PASSWORD", "")
    email = os.getenv("BOOTSTRAP_ADMIN_EMAIL") or None
    if not username or not full_name or len(password) < 12:
        print(
            "Set BOOTSTRAP_ADMIN_USERNAME, BOOTSTRAP_ADMIN_FULL_NAME, "
            "and BOOTSTRAP_ADMIN_PASSWORD (at least 12 characters).",
            file=sys.stderr,
        )
        return 2

    SessionLocal = get_session_factory()
    with SessionLocal() as db:
        if db.scalar(select(User.id).where(User.is_superuser.is_(True)).limit(1)):
            print("A superuser already exists; bootstrap refused.")
            return 1

        try:
            roles: dict[str, Role] = {}
            for code, (name_fa, description) in ROLE_SEEDS.items():
                role = db.scalar(select(Role).where(Role.code == code))
                if role is None:
                    role = Role(code=code, name_fa=name_fa, description=description, is_system=True)
                    db.add(role)
                roles[code] = role

            permissions: dict[str, Permission] = {}
            for code, description in PERMISSION_SEEDS.items():
                permission = db.scalar(select(Permission).where(Permission.code == code))
                if permission is None:
                    permission = Permission(code=code, description=description)
                    db.add(permission)
                permissions[code] = permission

            db.flush()
            roles["admin"].permissions = list(permissions.values())
            admin = User(
                username=username,
                email=email,
                full_name=full_name,
                password_hash=hash_password(password),
                is_active=True,
                is_superuser=True,
                must_change_password=True,
                roles=[roles["admin"]],
            )
            db.add(admin)
            db.commit()
            print(f"Initial superuser '{username}' created. Change the password after first login.")
            return 0
        except Exception:
            db.rollback()
            raise


if __name__ == "__main__":
    raise SystemExit(main())

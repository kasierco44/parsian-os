import hashlib
import os
import re
import uuid
from pathlib import Path
from uuid import UUID

from fastapi import APIRouter, Depends, Header, HTTPException, Query, Request, Response, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.routes.auth import get_current_user
from app.db.session import get_db
from app.models.identity import User
from app.models.product import CatalogState, Product, ProductMedia
from app.schemas.product import CatalogManifest, ProductCreate, ProductMediaRead, ProductRead, ProductUpdate
from app.services.audit import record_audit_event
from app.services.replication import record_replication_event

router = APIRouter(prefix="/catalog", tags=["product catalog"])
ALLOWED_MEDIA = {"image/jpeg", "image/png", "image/webp", "application/pdf"}
MAX_MEDIA_BYTES = 12 * 1024 * 1024


def _is_manager(user: User) -> bool:
    return user.is_superuser or any(role.code in {"manager", "admin", "owner"} for role in user.roles)


def _next_catalog_version(db: Session) -> int:
    state = db.scalar(select(CatalogState).where(CatalogState.id == 1).with_for_update())
    if state is None:
        state = CatalogState(id=1, version=0)
        db.add(state)
        db.flush()
    state.version += 1
    return state.version


def _product_payload(product: Product) -> dict:
    return {
        "sku": product.sku, "name": product.name, "category": product.category,
        "vehicle_model": product.vehicle_model, "description": product.description,
        "unit_price": product.unit_price, "cost_price": product.cost_price,
        "track_inventory": product.track_inventory, "is_active": product.is_active,
        "catalog_version": product.catalog_version,
    }


def _media_read(media: ProductMedia) -> ProductMediaRead:
    return ProductMediaRead(
        id=media.id, product_id=media.product_id, file_name=media.file_name,
        mime_type=media.mime_type, size_bytes=media.size_bytes,
        checksum=media.checksum_sha256, version=media.media_version,
        download_url=f"/catalog/media/{media.id}/download",
    )


@router.get("/manifest", response_model=CatalogManifest)
def catalog_manifest(since_version: int = Query(default=0, ge=0), user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    state = db.get(CatalogState, 1)
    current_version = state.version if state else 0
    products = db.scalars(select(Product).where(Product.catalog_version > since_version).order_by(Product.name)).all()
    media = db.scalars(select(ProductMedia).where(ProductMedia.is_active.is_(True), ProductMedia.catalog_version > since_version).order_by(ProductMedia.created_at)).all()
    return CatalogManifest(version=current_version, products=products, media=[_media_read(item) for item in media])


@router.post("/products", response_model=ProductRead, status_code=status.HTTP_201_CREATED)
def create_product(payload: ProductCreate, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _is_manager(user):
        raise HTTPException(status_code=403, detail="فقط مدیر مجاز به مدیریت کاتالوگ است.")
    version = _next_catalog_version(db)
    product = Product(**payload.model_dump(), catalog_version=version, replication_version=1)
    db.add(product)
    db.flush()
    record_replication_event(db, entity_type="product", entity_id=str(product.id), version=product.replication_version, payload=_product_payload(product))
    record_audit_event(db, action="catalog_product_created", entity_type="product", actor_user_id=user.id, entity_id=str(product.id), details={"sku": product.sku, "catalog_version": version})
    db.commit()
    db.refresh(product)
    return product


@router.patch("/products/{product_id}", response_model=ProductRead)
def update_product(product_id: UUID, payload: ProductUpdate, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _is_manager(user):
        raise HTTPException(status_code=403, detail="فقط مدیر مجاز به مدیریت کاتالوگ است.")
    product = db.get(Product, product_id)
    if product is None:
        raise HTTPException(status_code=404, detail="محصول پیدا نشد.")
    changes = payload.model_dump(exclude_unset=True)
    version = _next_catalog_version(db)
    for key, value in changes.items():
        setattr(product, key, value)
    product.catalog_version = version
    product.replication_version += 1
    record_replication_event(db, entity_type="product", entity_id=str(product.id), version=product.replication_version, payload=_product_payload(product), deleted=not product.is_active)
    record_audit_event(db, action="catalog_product_updated", entity_type="product", actor_user_id=user.id, entity_id=str(product.id), details={"catalog_version": version, "fields": sorted(changes)})
    db.commit()
    db.refresh(product)
    return product


@router.post("/products/{product_id}/media", response_model=ProductMediaRead, status_code=status.HTTP_201_CREATED)
async def upload_product_media(product_id: UUID, request: Request, user: User = Depends(get_current_user), db: Session = Depends(get_db), x_file_name: str = Header(default="product-image")):
    if not _is_manager(user):
        raise HTTPException(status_code=403, detail="فقط مدیر مجاز به بارگذاری تصویر محصول است.")
    product = db.get(Product, product_id)
    if product is None:
        raise HTTPException(status_code=404, detail="محصول پیدا نشد.")
    mime_type = request.headers.get("content-type", "").split(";")[0].lower()
    if mime_type not in ALLOWED_MEDIA:
        raise HTTPException(status_code=415, detail="نوع فایل پشتیبانی نمی‌شود.")
    body = await request.body()
    if not body or len(body) > MAX_MEDIA_BYTES:
        raise HTTPException(status_code=413, detail="حجم فایل خالی یا بیشتر از ۱۲ مگابایت است.")
    safe_name = re.sub(r"[^A-Za-z0-9._-]", "_", x_file_name)[:160] or "product-image"
    media_id = uuid.uuid4()
    checksum = hashlib.sha256(body).hexdigest()
    root = Path(os.getenv("CATALOG_MEDIA_ROOT", "/var/lib/parsian/catalog")).resolve()
    root.mkdir(parents=True, exist_ok=True)
    storage_key = f"{product_id}/{media_id}"
    target = (root / storage_key).resolve()
    if root not in target.parents:
        raise HTTPException(status_code=400, detail="مسیر فایل نامعتبر است.")
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_suffix(".upload")
    temporary.write_bytes(body)
    temporary.replace(target)

    version = _next_catalog_version(db)
    product.catalog_version = version
    product.replication_version += 1
    media = ProductMedia(id=media_id, product_id=product.id, storage_key=storage_key, file_name=safe_name, mime_type=mime_type, size_bytes=len(body), checksum_sha256=checksum, media_version=1, catalog_version=version)
    db.add(media)
    db.flush()
    record_replication_event(db, entity_type="product", entity_id=str(product.id), version=product.replication_version, payload=_product_payload(product))
    record_replication_event(db, entity_type="product_media", entity_id=str(media.id), version=media.media_version, payload={
        "product_id": str(product.id), "storage_key": storage_key, "file_name": safe_name,
        "mime_type": mime_type, "size_bytes": len(body), "checksum_sha256": checksum,
        "media_version": media.media_version, "catalog_version": version,
    })
    record_audit_event(db, action="catalog_media_uploaded", entity_type="product_media", actor_user_id=user.id, entity_id=str(media.id), details={"product_id": str(product.id), "sha256": checksum, "bytes": len(body)})
    db.commit()
    return _media_read(media)


@router.get("/media/{media_id}/download")
def download_product_media(media_id: UUID, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    media = db.get(ProductMedia, media_id)
    if media is None or not media.is_active:
        raise HTTPException(status_code=404, detail="فایل پیدا نشد.")
    root = Path(os.getenv("CATALOG_MEDIA_ROOT", "/var/lib/parsian/catalog")).resolve()
    target = (root / media.storage_key).resolve()
    if root not in target.parents or not target.is_file():
        raise HTTPException(status_code=404, detail="فایل در مخزن خصوصی موجود نیست.")
    return Response(content=target.read_bytes(), media_type=media.mime_type, headers={"Content-Disposition": f'inline; filename="{media.file_name}"', "ETag": f'"{media.checksum_sha256}"', "Cache-Control": "private, max-age=86400"})

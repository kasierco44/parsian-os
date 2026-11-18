from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import or_, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.core.audit import record_audit
from app.core.authorization import require_permission
from app.db.session import get_db
from app.models.product import Product, ProductPrice
from app.models.identity import User
from app.schemas.product import ProductCreate, ProductPriceCreate, ProductPriceRead, ProductRead, ProductUpdate

router = APIRouter(prefix="/products", tags=["products"])


@router.get("", response_model=list[ProductRead], summary="Search product catalog")
def list_products(
    q: str | None = Query(default=None, max_length=120),
    category: str | None = Query(default=None, max_length=60),
    is_active: bool | None = True,
    limit: int = Query(default=50, ge=1, le=200),
    offset: int = Query(default=0, ge=0),
    actor: User = Depends(require_permission("products.read")),
    db: Session = Depends(get_db),
) -> list[Product]:
    statement = select(Product).order_by(Product.name).limit(limit).offset(offset)
    if q:
        term = f"%{q.strip()}%"
        statement = statement.where(or_(Product.name.ilike(term), Product.sku.ilike(term), Product.brand.ilike(term)))
    if category:
        statement = statement.where(Product.category == category)
    if is_active is not None:
        statement = statement.where(Product.is_active.is_(is_active))
    return list(db.scalars(statement).all())


@router.post("", response_model=ProductRead, status_code=status.HTTP_201_CREATED)
def create_product(
    payload: ProductCreate,
    actor: User = Depends(require_permission("products.write")),
    db: Session = Depends(get_db),
) -> Product:
    product = Product(**payload.model_dump())
    db.add(product)
    try:
        db.flush()
        record_audit(db, action="product.created", entity_type="product", entity_id=product.id,
                     actor_user_id=actor.id, details={"sku": product.sku, "name": product.name})
        db.commit()
        db.refresh(product)
        return product
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="Product SKU already exists") from None


@router.get("/{product_id}", response_model=ProductRead)
def get_product(
    product_id: UUID,
    actor: User = Depends(require_permission("products.read")),
    db: Session = Depends(get_db),
) -> Product:
    product = db.get(Product, product_id)
    if product is None:
        raise HTTPException(status_code=404, detail="Product not found")
    return product


@router.patch("/{product_id}", response_model=ProductRead)
def update_product(
    product_id: UUID,
    payload: ProductUpdate,
    actor: User = Depends(require_permission("products.write")),
    db: Session = Depends(get_db),
) -> Product:
    product = db.get(Product, product_id)
    if product is None:
        raise HTTPException(status_code=404, detail="Product not found")
    changes = payload.model_dump(exclude_unset=True, exclude_none=True)
    for field, value in changes.items():
        setattr(product, field, value)
    record_audit(db, action="product.updated", entity_type="product", entity_id=product.id,
                 actor_user_id=actor.id, details={"changed_fields": sorted(changes)})
    db.commit()
    db.refresh(product)
    return product


@router.get("/{product_id}/prices", response_model=list[ProductPriceRead])
def list_product_prices(
    product_id: UUID,
    actor: User = Depends(require_permission("products.read")),
    db: Session = Depends(get_db),
) -> list[ProductPrice]:
    if db.get(Product, product_id) is None:
        raise HTTPException(status_code=404, detail="Product not found")
    statement = select(ProductPrice).where(ProductPrice.product_id == product_id).order_by(ProductPrice.valid_from.desc())
    return list(db.scalars(statement).all())


@router.post("/{product_id}/prices", response_model=ProductPriceRead, status_code=status.HTTP_201_CREATED)
def create_product_price(
    product_id: UUID,
    payload: ProductPriceCreate,
    actor: User = Depends(require_permission("products.write")),
    db: Session = Depends(get_db),
) -> ProductPrice:
    if db.get(Product, product_id) is None:
        raise HTTPException(status_code=404, detail="Product not found")
    price = ProductPrice(product_id=product_id, **payload.model_dump())
    db.add(price)
    db.flush()
    record_audit(db, action="product.price.created", entity_type="product_price", entity_id=price.id,
                 actor_user_id=actor.id, details={"product_id": str(product_id), "price_type": price.price_type,
                                                  "amount": str(price.amount), "currency": price.currency})
    db.commit()
    db.refresh(price)
    return price

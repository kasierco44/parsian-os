from datetime import date, datetime, time, timedelta, timezone
from uuid import UUID, uuid4

from fastapi import APIRouter, Depends, Header, HTTPException, Query, status
from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session, selectinload

from app.api.routes.auth import get_current_user
from app.db.session import get_db
from app.models.accounting import AccountingAccount
from app.models.customer import Customer
from app.models.identity import User
from app.models.product import Product
from app.models.sales import (
    Cheque, CommissionAccrual, CommissionTier, InventoryBalance, InventoryMovement,
    JournalEntry, JournalLine, PaymentReceipt, SalesInvoice, SalesOrder, SalesOrderLine,
)
from app.schemas.sales import (
    ChequeDecision, ChequeRead, CommissionAccrualRead, CommissionTierIn, CommissionTierRead, InventoryAdjustment,
    InventoryBalanceRead, InvoiceRead, OrderDecision, PaymentCreate, PaymentRead,
    SalesOrderCreate, SalesOrderRead, OpeningBalanceCreate, ManualCashEntryCreate,
)
from app.services.audit import record_audit_event

router = APIRouter(prefix="/sales", tags=["sales cycle"])


def _roles(user: User) -> set[str]:
    return {role.code for role in user.roles}


def _manager(user: User) -> bool:
    return user.is_superuser or bool(_roles(user) & {"manager", "admin", "owner"})


def _accounting(user: User) -> bool:
    return _manager(user) or "accountant" in _roles(user)


def _can_view_order(user: User, order: SalesOrder) -> bool:
    return _manager(user) or "accountant" in _roles(user) or order.seller_user_id == user.id


def _number(prefix: str) -> str:
    return f"{prefix}-{datetime.now(timezone.utc):%Y%m%d}-{uuid4().hex[:10].upper()}"


def _journal(db: Session, *, source_type: str, source_id: str, description: str, lines: list[dict]) -> JournalEntry:
    debit = sum(item["debit"] for item in lines)
    credit = sum(item["credit"] for item in lines)
    if debit != credit or debit <= 0:
        raise ValueError("Journal entry must be balanced and non-zero.")
    entry = JournalEntry(entry_number=_number("JE"), source_type=source_type, source_id=source_id, description=description)
    db.add(entry)
    db.flush()
    for item in lines:
        db.add(JournalLine(entry_id=entry.id, **item))
    return entry


def _invoice_status(invoice: SalesInvoice) -> str:
    if invoice.paid_amount >= invoice.total_amount:
        return "paid"
    if invoice.paid_amount > 0:
        return "partial"
    return "unpaid"


def _record_collection(db: Session, *, receipt: PaymentReceipt, invoice: SalesInvoice, actor: User, payment_account: str) -> None:
    invoice.paid_amount += receipt.amount
    if invoice.paid_amount > invoice.total_amount:
        raise HTTPException(status_code=409, detail="مبلغ وصول از مانده فاکتور بیشتر است.")
    invoice.status = _invoice_status(invoice)
    _journal(db, source_type="receipt", source_id=str(receipt.id), description=f"وصول فاکتور {invoice.invoice_number}", lines=[
        {"account_code": payment_account, "debit": receipt.amount, "credit": 0, "customer_id": invoice.customer_id, "seller_user_id": invoice.seller_user_id},
        {"account_code": "ACCOUNTS_RECEIVABLE", "debit": 0, "credit": receipt.amount, "customer_id": invoice.customer_id, "seller_user_id": invoice.seller_user_id},
    ])
    _accrue_commission(db, receipt=receipt, invoice=invoice)


def _calculate_progressive_commission(already_collected: int, amount: int, tiers: list[CommissionTier]) -> tuple[int, int]:
    """Return marginal commission and the effective blended rate in basis points."""
    end_total = already_collected + amount
    commission = 0
    ordered = sorted((tier for tier in tiers if tier.is_active), key=lambda tier: tier.min_collected_amount)
    for index, tier in enumerate(ordered):
        start = max(already_collected, tier.min_collected_amount)
        next_threshold = ordered[index + 1].min_collected_amount if index + 1 < len(ordered) else end_total
        end = min(end_total, next_threshold)
        if end > start:
            commission += ((end - start) * tier.rate_bps) // 10000
    effective_rate = (commission * 10000) // amount if amount else 0
    return commission, effective_rate


def _accrue_commission(db: Session, *, receipt: PaymentReceipt, invoice: SalesInvoice) -> None:
    # Serialize monthly tier calculations per seller. Without this row lock,
    # two concurrent receipts on different invoices could read the same
    # monthly total and both calculate commission from the same tier range.
    seller = db.scalar(select(User).where(User.id == invoice.seller_user_id).with_for_update())
    if seller is None:
        raise HTTPException(status_code=409, detail="فروشنده فاکتور دیگر معتبر نیست.")
    collected_at = datetime.now(timezone.utc)
    month_start = collected_at.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    already = db.scalar(select(func.coalesce(func.sum(CommissionAccrual.collected_amount), 0)).where(
        CommissionAccrual.seller_user_id == invoice.seller_user_id,
        CommissionAccrual.created_at >= month_start,
    )) or 0
    tiers = db.scalars(select(CommissionTier).where(CommissionTier.is_active.is_(True)).order_by(CommissionTier.min_collected_amount)).all()
    commission, rate = _calculate_progressive_commission(already, receipt.amount, tiers)
    db.add(CommissionAccrual(
        receipt_id=receipt.id, seller_user_id=invoice.seller_user_id,
        collected_amount=receipt.amount, rate_bps=rate, commission_amount=commission,
    ))
    if commission:
        _journal(db, source_type="commission", source_id=str(receipt.id), description=f"پورسانت وصول فاکتور {invoice.invoice_number}", lines=[
            {"account_code": "SALES_COMMISSION_EXPENSE", "debit": commission, "credit": 0, "customer_id": invoice.customer_id, "seller_user_id": invoice.seller_user_id},
            {"account_code": "COMMISSION_PAYABLE", "debit": 0, "credit": commission, "customer_id": invoice.customer_id, "seller_user_id": invoice.seller_user_id},
        ])


@router.post("/orders", response_model=SalesOrderRead, status_code=status.HTTP_201_CREATED)
def create_order(payload: SalesOrderCreate, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    customer = db.get(Customer, payload.customer_id)
    if customer is None or not customer.is_active:
        raise HTTPException(status_code=404, detail="مشتری فعال پیدا نشد.")
    if not _manager(user) and customer.assigned_to_user_id != user.id:
        raise HTTPException(status_code=403, detail="این مشتری به شما تخصیص داده نشده است.")
    order = SalesOrder(
        order_number=_number("SO"), customer_id=customer.id, seller_user_id=user.id,
        status="draft", note=payload.note,
    )
    subtotal = 0
    for item in payload.lines:
        product = db.get(Product, item.product_id)
        if product is None or not product.is_active:
            raise HTTPException(status_code=422, detail="یکی از محصولات وجود ندارد یا غیرفعال است.")
        price = product.unit_price if item.unit_price is None else item.unit_price
        if price is None:
            raise HTTPException(status_code=422, detail=f"برای محصول {product.name} قیمت فروش تعریف نشده است.")
        gross = item.quantity * price
        if item.discount_amount > gross:
            raise HTTPException(status_code=422, detail="تخفیف قلم نمی‌تواند از مبلغ ناخالص بیشتر باشد.")
        line_total = gross - item.discount_amount
        subtotal += gross
        order.lines.append(SalesOrderLine(
            product_id=product.id, sku_snapshot=product.sku, name_snapshot=product.name,
            quantity=item.quantity, unit_price=price,
            discount_amount=item.discount_amount, line_total=line_total,
        ))
    order.subtotal = subtotal
    order.discount_amount = sum(line.discount_amount for line in order.lines)
    order.total_amount = subtotal - order.discount_amount
    if order.total_amount <= 0:
        raise HTTPException(status_code=422, detail="مبلغ نهایی سفارش باید بیشتر از صفر باشد.")
    db.add(order)
    db.flush()
    record_audit_event(db, action="sales_order_created", entity_type="sales_order", actor_user_id=user.id, entity_id=str(order.id), details={"total_amount": order.total_amount, "line_count": len(order.lines)})
    db.commit()
    return db.scalar(select(SalesOrder).options(selectinload(SalesOrder.lines)).where(SalesOrder.id == order.id))


@router.post("/orders/{order_id}/submit", response_model=SalesOrderRead)
def submit_order(order_id: UUID, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    order = db.scalar(select(SalesOrder).options(selectinload(SalesOrder.lines)).where(SalesOrder.id == order_id).with_for_update())
    if order is None or not _can_view_order(user, order):
        raise HTTPException(status_code=404, detail="سفارش پیدا نشد.")
    if order.status != "draft":
        raise HTTPException(status_code=409, detail="فقط سفارش پیش‌نویس قابل ارسال برای تأیید است.")
    order.status = "pending_approval"
    record_audit_event(db, action="sales_order_submitted", entity_type="sales_order", actor_user_id=user.id, entity_id=str(order.id))
    db.commit()
    return order


@router.get("/orders", response_model=list[SalesOrderRead])
def list_orders(status_filter: str | None = Query(default=None, alias="status"), user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    statement = select(SalesOrder).options(selectinload(SalesOrder.lines)).order_by(SalesOrder.created_at.desc())
    if not _accounting(user):
        statement = statement.where(SalesOrder.seller_user_id == user.id)
    if status_filter:
        statement = statement.where(SalesOrder.status == status_filter)
    return db.scalars(statement.limit(200)).all()


@router.post("/orders/{order_id}/approve", response_model=SalesOrderRead)
def approve_order(order_id: UUID, payload: OrderDecision, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _manager(user):
        raise HTTPException(status_code=403, detail="تأیید سفارش فقط برای مدیر مجاز است.")
    order = db.scalar(select(SalesOrder).options(selectinload(SalesOrder.lines)).where(SalesOrder.id == order_id).with_for_update())
    if order is None:
        raise HTTPException(status_code=404, detail="سفارش پیدا نشد.")
    if order.status != "pending_approval":
        raise HTTPException(status_code=409, detail="وضعیت سفارش برای تأیید معتبر نیست.")
    order.status = "approved"
    order.approved_by_user_id = user.id
    order.approved_at = datetime.now(timezone.utc)
    if payload.note:
        order.note = (order.note or "") + "\nیادداشت تأیید مدیر: " + payload.note
    record_audit_event(db, action="sales_order_approved", entity_type="sales_order", actor_user_id=user.id, entity_id=str(order.id))
    db.commit()
    return order


@router.post("/orders/{order_id}/reject", response_model=SalesOrderRead)
def reject_order(order_id: UUID, payload: OrderDecision, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _manager(user):
        raise HTTPException(status_code=403, detail="رد سفارش فقط برای مدیر مجاز است.")
    order = db.scalar(select(SalesOrder).options(selectinload(SalesOrder.lines)).where(SalesOrder.id == order_id).with_for_update())
    if order is None:
        raise HTTPException(status_code=404, detail="سفارش پیدا نشد.")
    if order.status != "pending_approval":
        raise HTTPException(status_code=409, detail="فقط سفارش در انتظار بررسی قابل رد است.")
    order.status = "rejected"
    order.note = (order.note or "") + "\nعلت رد: " + (payload.note or "اعلام نشده")
    record_audit_event(db, action="sales_order_rejected", entity_type="sales_order", actor_user_id=user.id, entity_id=str(order.id), details={"reason": payload.note})
    db.commit()
    return order


@router.post("/orders/{order_id}/invoice", response_model=InvoiceRead, status_code=status.HTTP_201_CREATED)
def issue_invoice(order_id: UUID, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _manager(user):
        raise HTTPException(status_code=403, detail="صدور فاکتور قطعی فقط برای مدیر مجاز است.")
    order = db.scalar(select(SalesOrder).options(selectinload(SalesOrder.lines)).where(SalesOrder.id == order_id).with_for_update())
    if order is None:
        raise HTTPException(status_code=404, detail="سفارش پیدا نشد.")
    existing = db.scalar(select(SalesInvoice).where(SalesInvoice.order_id == order.id))
    if existing:
        return existing
    if order.status != "approved":
        raise HTTPException(status_code=409, detail="ابتدا باید سفارش تأیید مدیر را دریافت کند.")
    customer = db.get(Customer, order.customer_id)
    if customer is None or not customer.is_active:
        raise HTTPException(status_code=409, detail="مشتری سفارش غیرفعال شده است؛ امکان صدور فاکتور وجود ندارد.")
    invoice = SalesInvoice(
        invoice_number=_number("INV"), order_id=order.id, customer_id=order.customer_id,
        seller_user_id=order.seller_user_id, total_amount=order.total_amount,
        paid_amount=0, status="unpaid",
    )
    db.add(invoice)
    db.flush()
    journal_lines = [
        {"account_code": "ACCOUNTS_RECEIVABLE", "debit": invoice.total_amount, "credit": 0, "customer_id": invoice.customer_id, "seller_user_id": invoice.seller_user_id},
        {"account_code": "SALES_REVENUE", "debit": 0, "credit": invoice.total_amount, "customer_id": invoice.customer_id, "seller_user_id": invoice.seller_user_id},
    ]
    for line in order.lines:
        product = db.get(Product, line.product_id)
        if product is None or not product.is_active:
            raise HTTPException(status_code=409, detail="محصول سفارش حذف یا غیرفعال شده است.")
        if product.track_inventory:
            if product.cost_price is None:
                raise HTTPException(status_code=409, detail=f"بهای تمام‌شده محصول {product.name} تعریف نشده است.")
            balance = db.scalar(select(InventoryBalance).where(InventoryBalance.product_id == product.id).with_for_update())
            if balance is None or balance.quantity_on_hand < line.quantity:
                raise HTTPException(status_code=409, detail=f"موجودی کافی برای محصول {product.name} وجود ندارد.")
            balance.quantity_on_hand -= line.quantity
            db.add(InventoryMovement(product_id=product.id, invoice_id=invoice.id, movement_type="sale", quantity_delta=-line.quantity, reference=invoice.invoice_number))
            if product.cost_price is not None:
                cost = product.cost_price * line.quantity
                journal_lines.extend([
                    {"account_code": "COST_OF_GOODS_SOLD", "debit": cost, "credit": 0, "customer_id": invoice.customer_id, "seller_user_id": invoice.seller_user_id},
                    {"account_code": "INVENTORY_ASSET", "debit": 0, "credit": cost, "customer_id": invoice.customer_id, "seller_user_id": invoice.seller_user_id},
                ])
    _journal(db, source_type="invoice", source_id=str(invoice.id), description=f"صدور فاکتور {invoice.invoice_number}", lines=journal_lines)
    order.status = "invoiced"
    record_audit_event(db, action="sales_invoice_issued", entity_type="sales_invoice", actor_user_id=user.id, entity_id=str(invoice.id), details={"invoice_number": invoice.invoice_number, "order_id": str(order.id)})
    db.commit()
    db.refresh(invoice)
    return invoice


@router.get("/invoices", response_model=list[InvoiceRead])
def list_invoices(user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    statement = select(SalesInvoice).order_by(SalesInvoice.issued_at.desc())
    if not _accounting(user):
        statement = statement.where(SalesInvoice.seller_user_id == user.id)
    return db.scalars(statement.limit(300)).all()


@router.post("/invoices/{invoice_id}/receipts", response_model=PaymentRead, status_code=status.HTTP_201_CREATED)
def create_receipt(invoice_id: UUID, payload: PaymentCreate, idempotency_key: str = Header(..., alias="Idempotency-Key", min_length=12, max_length=120), user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    invoice = db.scalar(select(SalesInvoice).where(SalesInvoice.id == invoice_id).with_for_update())
    if invoice is None:
        raise HTTPException(status_code=404, detail="فاکتور پیدا نشد.")
    if not _manager(user) and invoice.seller_user_id != user.id and "accountant" not in _roles(user):
        raise HTTPException(status_code=403, detail="به این فاکتور دسترسی ندارید.")
    existing = db.scalar(select(PaymentReceipt).where(PaymentReceipt.idempotency_key == idempotency_key))
    if existing is not None:
        if existing.invoice_id != invoice.id or existing.amount != payload.amount or existing.method != payload.method:
            raise HTTPException(status_code=409, detail="این کلید تکرار برای یک درخواست دیگر استفاده شده است.")
        return existing
    pending_total = db.scalar(select(func.coalesce(func.sum(PaymentReceipt.amount), 0)).where(PaymentReceipt.invoice_id == invoice.id, PaymentReceipt.status.in_({"pending", "cleared"}))) or 0
    if payload.amount > invoice.total_amount - pending_total:
        raise HTTPException(status_code=409, detail="مبلغ دریافت از مانده فاکتور بیشتر است.")
    if payload.method == "cheque" and not all([payload.bank_name, payload.cheque_number, payload.due_date, payload.drawer_name]):
        raise HTTPException(status_code=422, detail="برای ثبت چک، بانک، شماره، تاریخ سررسید و صادرکننده الزامی است.")
    receipt = PaymentReceipt(
        receipt_number=_number("RCPT"), idempotency_key=idempotency_key, invoice_id=invoice.id, amount=payload.amount,
        method=payload.method, status="pending" if payload.method == "cheque" else "cleared",
        reference=payload.reference, received_by_user_id=user.id,
    )
    db.add(receipt)
    db.flush()
    if payload.method == "cheque":
        db.add(Cheque(receipt_id=receipt.id, bank_name=payload.bank_name, cheque_number=payload.cheque_number, due_date=payload.due_date, drawer_name=payload.drawer_name, status="received"))
    else:
        _record_collection(db, receipt=receipt, invoice=invoice, actor=user, payment_account="CASH" if payload.method == "cash" else "BANK")
    record_audit_event(db, action="payment_receipt_created", entity_type="payment_receipt", actor_user_id=user.id, entity_id=str(receipt.id), details={"invoice_id": str(invoice.id), "amount": receipt.amount, "method": receipt.method, "status": receipt.status})
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        duplicate = db.scalar(select(PaymentReceipt).where(PaymentReceipt.idempotency_key == idempotency_key))
        if duplicate is not None and duplicate.invoice_id == invoice.id and duplicate.amount == payload.amount and duplicate.method == payload.method:
            return duplicate
        raise HTTPException(status_code=409, detail="درخواست دریافت تکراری یا متعارض است.") from None
    db.refresh(receipt)
    return receipt


@router.post("/cheques/{cheque_id}/clear", response_model=PaymentRead)
def clear_cheque(cheque_id: UUID, payload: ChequeDecision, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="تأیید وصول چک فقط برای مدیر یا حسابدار مجاز است.")
    cheque = db.scalar(select(Cheque).where(Cheque.id == cheque_id).with_for_update())
    if cheque is None:
        raise HTTPException(status_code=404, detail="چک پیدا نشد.")
    if cheque.status != "received":
        raise HTTPException(status_code=409, detail="وضعیت چک برای وصول معتبر نیست.")
    receipt = db.get(PaymentReceipt, cheque.receipt_id)
    invoice = db.scalar(select(SalesInvoice).where(SalesInvoice.id == receipt.invoice_id).with_for_update())
    cheque.status = "cleared"
    cheque.cleared_at = datetime.now(timezone.utc)
    receipt.status = "cleared"
    _record_collection(db, receipt=receipt, invoice=invoice, actor=user, payment_account="BANK")
    record_audit_event(db, action="cheque_cleared", entity_type="cheque", actor_user_id=user.id, entity_id=str(cheque.id), details={"receipt_id": str(receipt.id), "note": payload.note})
    db.commit()
    db.refresh(receipt)
    return receipt


@router.post("/cheques/{cheque_id}/bounce", response_model=PaymentRead)
def bounce_cheque(cheque_id: UUID, payload: ChequeDecision, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="ثبت برگشت چک فقط برای مدیر یا حسابدار مجاز است.")
    cheque = db.scalar(select(Cheque).where(Cheque.id == cheque_id).with_for_update())
    if cheque is None:
        raise HTTPException(status_code=404, detail="چک پیدا نشد.")
    if cheque.status != "received":
        raise HTTPException(status_code=409, detail="فقط چک دریافت‌شده قابل ثبت به‌عنوان برگشتی است.")
    receipt = db.get(PaymentReceipt, cheque.receipt_id)
    cheque.status = "bounced"
    receipt.status = "bounced"
    record_audit_event(db, action="cheque_bounced", entity_type="cheque", actor_user_id=user.id, entity_id=str(cheque.id), details={"note": payload.note})
    db.commit()
    db.refresh(receipt)
    return receipt


@router.get("/commission/summary")
def commission_summary(user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    month_start = datetime.now(timezone.utc).replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    statement = select(
        CommissionAccrual.seller_user_id,
        func.coalesce(func.sum(CommissionAccrual.collected_amount), 0).label("collected_amount"),
        func.coalesce(func.sum(CommissionAccrual.commission_amount), 0).label("commission_amount"),
    ).where(CommissionAccrual.created_at >= month_start).group_by(CommissionAccrual.seller_user_id)
    if not _accounting(user):
        statement = statement.where(CommissionAccrual.seller_user_id == user.id)
    rows = db.execute(statement).all()
    return [
        {"seller_user_id": str(row.seller_user_id), "collected_amount": int(row.collected_amount),
         "commission_amount": int(row.commission_amount)}
        for row in rows
    ]


@router.get("/commission/tiers", response_model=list[CommissionTierRead])
def list_commission_tiers(user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="دسترسی به تنظیمات پورسانت مجاز نیست.")
    return db.scalars(select(CommissionTier).order_by(CommissionTier.min_collected_amount)).all()


@router.post("/commission/tiers", response_model=CommissionTierRead, status_code=status.HTTP_201_CREATED)
def create_commission_tier(payload: CommissionTierIn, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _manager(user):
        raise HTTPException(status_code=403, detail="تنظیم پلکان پورسانت فقط برای مدیر مجاز است.")
    tier = db.scalar(select(CommissionTier).where(CommissionTier.min_collected_amount == payload.min_collected_amount))
    if tier is None:
        tier = CommissionTier(**payload.model_dump())
        db.add(tier)
    else:
        tier.rate_bps = payload.rate_bps
        tier.is_active = payload.is_active
    record_audit_event(db, action="commission_tier_configured", entity_type="commission_tier", actor_user_id=user.id, entity_id=str(tier.id), details=payload.model_dump())
    db.commit()
    db.refresh(tier)
    return tier


@router.post("/inventory/adjustments", response_model=InventoryBalanceRead)
def adjust_inventory(payload: InventoryAdjustment, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="اصلاح موجودی فقط برای مدیر یا حسابدار مجاز است.")
    product = db.get(Product, payload.product_id)
    if product is None:
        raise HTTPException(status_code=404, detail="محصول پیدا نشد.")
    balance = db.scalar(select(InventoryBalance).where(InventoryBalance.product_id == product.id).with_for_update())
    if balance is None:
        balance = InventoryBalance(product_id=product.id, quantity_on_hand=0)
        db.add(balance)
        db.flush()
    if balance.quantity_on_hand + payload.quantity_delta < 0:
        raise HTTPException(status_code=409, detail="موجودی نمی‌تواند منفی شود.")
    if product.cost_price is None:
        raise HTTPException(status_code=409, detail="پیش از اصلاح انبار، بهای تمام‌شده محصول را در کاتالوگ ثبت کنید.")
    balance.quantity_on_hand += payload.quantity_delta
    db.add(InventoryMovement(product_id=product.id, movement_type="adjustment", quantity_delta=payload.quantity_delta, reference=payload.reason))
    if product.cost_price is not None:
        valuation = abs(payload.quantity_delta) * product.cost_price
        if valuation:
            if payload.quantity_delta > 0:
                lines = [
                    {"account_code": "INVENTORY_ASSET", "debit": valuation, "credit": 0, "customer_id": None, "seller_user_id": None},
                    {"account_code": "INVENTORY_ADJUSTMENT", "debit": 0, "credit": valuation, "customer_id": None, "seller_user_id": None},
                ]
            else:
                lines = [
                    {"account_code": "INVENTORY_SHRINKAGE", "debit": valuation, "credit": 0, "customer_id": None, "seller_user_id": None},
                    {"account_code": "INVENTORY_ASSET", "debit": 0, "credit": valuation, "customer_id": None, "seller_user_id": None},
                ]
            _journal(db, source_type="inventory_adjustment", source_id=str(product.id) + ":" + uuid4().hex[:8], description="اصلاح موجودی: " + payload.reason, lines=lines)
    record_audit_event(db, action="inventory_adjusted", entity_type="product", actor_user_id=user.id, entity_id=str(product.id), details={"delta": payload.quantity_delta, "reason": payload.reason})
    db.commit()
    db.refresh(balance)
    return balance


@router.get("/inventory/balances", response_model=list[InventoryBalanceRead])
def list_inventory(user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="مشاهده موجودی فقط برای مدیر یا حسابدار مجاز است.")
    return db.scalars(select(InventoryBalance).order_by(InventoryBalance.updated_at.desc()).limit(500)).all()


@router.get("/invoices/{invoice_id}/receipts", response_model=list[PaymentRead])
def list_invoice_receipts(invoice_id: UUID, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    invoice = db.get(SalesInvoice, invoice_id)
    if invoice is None:
        raise HTTPException(status_code=404, detail="فاکتور پیدا نشد.")
    if not _manager(user) and invoice.seller_user_id != user.id and "accountant" not in _roles(user):
        raise HTTPException(status_code=403, detail="به این فاکتور دسترسی ندارید.")
    return db.scalars(select(PaymentReceipt).where(PaymentReceipt.invoice_id == invoice.id).order_by(PaymentReceipt.received_at)).all()


@router.get("/cheques", response_model=list[ChequeRead])
def list_cheques(status_filter: str | None = Query(default=None, alias="status"), user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    statement = (
        select(Cheque)
        .join(PaymentReceipt, PaymentReceipt.id == Cheque.receipt_id)
        .join(SalesInvoice, SalesInvoice.id == PaymentReceipt.invoice_id)
        .order_by(Cheque.due_date.asc())
        .limit(500)
    )
    if not _accounting(user):
        statement = statement.where(SalesInvoice.seller_user_id == user.id)
    if status_filter:
        statement = statement.where(Cheque.status == status_filter)
    return db.scalars(statement).all()


@router.get("/commission/accruals", response_model=list[CommissionAccrualRead])
def list_commission_accruals(seller_user_id: UUID | None = None, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    statement = select(CommissionAccrual).order_by(CommissionAccrual.created_at.desc()).limit(500)
    if _accounting(user):
        if seller_user_id is not None:
            statement = statement.where(CommissionAccrual.seller_user_id == seller_user_id)
    else:
        if seller_user_id is not None and seller_user_id != user.id:
            raise HTTPException(status_code=403, detail="مشاهده پورسانت سایر فروشندگان مجاز نیست.")
        statement = statement.where(CommissionAccrual.seller_user_id == user.id)
    return db.scalars(statement).all()

@router.get("/accounting/trial-balance")
def trial_balance(
    from_date: date | None = Query(default=None, description="ابتدای بازه، شامل روز انتخاب‌شده"),
    to_date: date | None = Query(default=None, description="انتهای بازه، شامل روز انتخاب‌شده"),
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="گزارش تراز آزمایشی فقط برای مدیر یا حسابدار مجاز است.")
    statement = select(
        JournalLine.account_code,
        func.coalesce(func.sum(JournalLine.debit), 0).label("debit"),
        func.coalesce(func.sum(JournalLine.credit), 0).label("credit"),
    ).join(JournalEntry, JournalEntry.id == JournalLine.entry_id)
    if from_date:
        statement = statement.where(JournalEntry.posted_at >= from_date)
    if to_date:
        statement = statement.where(JournalEntry.posted_at <= to_date)
    statement = statement.group_by(JournalLine.account_code).order_by(JournalLine.account_code)
    rows = db.execute(statement).all()
    result = [
        {"account_code": row.account_code, "debit": int(row.debit), "credit": int(row.credit),
         "net_debit": int(row.debit) - int(row.credit)}
        for row in rows
    ]
    return {
        "accounts": result,
        "total_debit": sum(item["debit"] for item in result),
        "total_credit": sum(item["credit"] for item in result),
        "is_balanced": sum(item["debit"] for item in result) == sum(item["credit"] for item in result),
    }


@router.get("/accounting/profit-loss")
def profit_loss(
    from_date: datetime | None = None,
    to_date: datetime | None = None,
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="گزارش سود و زیان فقط برای مدیر یا حسابدار مجاز است.")
    statement = select(
        JournalLine.account_code, AccountingAccount.name, AccountingAccount.account_type,
        func.coalesce(func.sum(JournalLine.debit), 0).label("debit"),
        func.coalesce(func.sum(JournalLine.credit), 0).label("credit"),
    ).join(JournalEntry, JournalEntry.id == JournalLine.entry_id).join(
        AccountingAccount, AccountingAccount.code == JournalLine.account_code
    ).where(AccountingAccount.account_type.in_({"revenue", "expense"}))
    if from_date:
        statement = statement.where(JournalEntry.posted_at >= from_date)
    if to_date:
        statement = statement.where(JournalEntry.posted_at <= to_date)
    rows = db.execute(statement.group_by(
        JournalLine.account_code, AccountingAccount.name, AccountingAccount.account_type
    )).all()
    accounts = {
        row.account_code: {
            "name": row.name, "account_type": row.account_type,
            "debit": int(row.debit), "credit": int(row.credit),
            "net_amount": int(row.credit - row.debit) if row.account_type == "revenue"
                          else int(row.debit - row.credit),
        } for row in rows
    }
    revenue = sum(item["net_amount"] for item in accounts.values() if item["account_type"] == "revenue")
    expenses = sum(item["net_amount"] for item in accounts.values() if item["account_type"] == "expense")
    return {
        "revenue": revenue,
        "expenses": expenses,
        "net_profit": revenue - expenses,
        "currency_unit": "configured_company_base_unit",
        "accounts": accounts,
        "note": "گزارش بر مبنای تمام سرفصل‌های درآمد و هزینه و اسناد ثبت‌شده در دفتر روزنامه است؛ اسناد ثبت‌نشده در آن منعکس نمی‌شوند.",
    }


@router.get("/accounting/customer-ledger/{customer_id}")
def customer_ledger(
    customer_id: UUID,
    from_date: datetime | None = None,
    to_date: datetime | None = None,
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    if not _accounting(user):
        customer = db.get(Customer, customer_id)
        if customer is None or customer.assigned_to_user_id != user.id:
            raise HTTPException(status_code=403, detail="مشاهده گردش حساب این مشتری مجاز نیست.")
    elif db.get(Customer, customer_id) is None:
        raise HTTPException(status_code=404, detail="مشتری پیدا نشد.")
    statement = select(JournalLine, JournalEntry).join(
        JournalEntry, JournalEntry.id == JournalLine.entry_id
    ).where(
        JournalLine.customer_id == customer_id,
        JournalLine.account_code == "ACCOUNTS_RECEIVABLE",
    ).order_by(
        JournalEntry.posted_at.asc(), JournalEntry.entry_number.asc()
    )
    if from_date:
        statement = statement.where(JournalEntry.posted_at >= from_date)
    if to_date:
        statement = statement.where(JournalEntry.posted_at <= to_date)
    rows = db.execute(statement).all()
    balance = 0
    entries = []
    for line, entry in rows:
        balance += line.debit - line.credit
        entries.append({
            "entry_number": entry.entry_number, "date": entry.posted_at,
            "description": entry.description, "account_code": line.account_code,
            "debit": line.debit, "credit": line.credit, "running_balance": balance,
        })
    return {"customer_id": str(customer_id), "entries": entries, "closing_balance": balance}




def _post_manual_cash_entry(
    db: Session, user: User, payload: ManualCashEntryCreate, *,
    source_type: str, account_type: str,
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="ثبت اسناد مالی فقط برای مدیر یا حسابدار مجاز است.")
    target = db.scalar(select(AccountingAccount).where(
        AccountingAccount.code == payload.account_code,
        AccountingAccount.is_active.is_(True),
        AccountingAccount.account_type == account_type,
    ))
    if target is None:
        raise HTTPException(status_code=422, detail="سرفصل انتخاب‌شده فعال نیست یا از نوع موردنیاز نیست.")
    if db.scalar(select(AccountingAccount.code).where(AccountingAccount.parent_code == target.code)):
        raise HTTPException(status_code=422, detail="ثبت مستقیم روی حساب گروه مجاز نیست؛ حساب معین را انتخاب کنید.")
    cash_account = db.scalar(select(AccountingAccount).where(
        AccountingAccount.code == payload.payment_account,
        AccountingAccount.is_active.is_(True),
        AccountingAccount.account_type == "asset",
    ))
    if cash_account is None:
        raise HTTPException(status_code=422, detail="حساب صندوق یا بانک انتخاب‌شده فعال نیست.")
    description = payload.description.strip()
    if payload.reference:
        description = f"{description} | مرجع: {payload.reference.strip()}"
    if source_type == "operating_expense":
        lines = [
            {"account_code": target.code, "debit": payload.amount, "credit": 0},
            {"account_code": cash_account.code, "debit": 0, "credit": payload.amount},
        ]
    else:
        lines = [
            {"account_code": cash_account.code, "debit": payload.amount, "credit": 0},
            {"account_code": target.code, "debit": 0, "credit": payload.amount},
        ]
    entry = _journal(
        db, source_type=source_type, source_id=str(uuid4()),
        description=description, lines=lines,
    )
    entry.posted_at = datetime.combine(payload.entry_date, time.min, tzinfo=timezone.utc)
    record_audit_event(
        db, action=f"accounting_{source_type}_posted", entity_type="journal_entry",
        actor_user_id=user.id, entity_id=str(entry.id),
        details={"amount": payload.amount, "account_code": target.code,
                 "payment_account": cash_account.code, "entry_date": payload.entry_date.isoformat()},
    )
    db.commit()
    return {
        "entry_id": str(entry.id), "entry_number": entry.entry_number,
        "source_type": source_type, "amount": payload.amount,
        "account_code": target.code, "payment_account": cash_account.code,
        "entry_date": payload.entry_date, "status": "posted",
    }


@router.post("/accounting/expenses", status_code=status.HTTP_201_CREATED)
def post_operating_expense(
    payload: ManualCashEntryCreate,
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    return _post_manual_cash_entry(
        db, user, payload, source_type="operating_expense", account_type="expense"
    )


@router.post("/accounting/other-income", status_code=status.HTTP_201_CREATED)
def post_other_income(
    payload: ManualCashEntryCreate,
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    return _post_manual_cash_entry(
        db, user, payload, source_type="other_income", account_type="revenue"
    )


@router.get("/accounting/chart-of-accounts")
def list_chart_of_accounts(user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="مشاهده سرفصل حساب‌ها فقط برای مدیر یا حسابدار مجاز است.")
    rows = db.scalars(
        select(AccountingAccount).order_by(AccountingAccount.code)
    ).all()
    return [
        {
            "code": row.code, "name": row.name, "account_type": row.account_type,
            "normal_balance": row.normal_balance, "parent_code": row.parent_code,
            "is_system": row.is_system, "is_active": row.is_active,
        }
        for row in rows
    ]


@router.post("/accounting/chart-of-accounts", status_code=status.HTTP_201_CREATED)
def create_chart_account(
    payload: dict,
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    if not _manager(user):
        raise HTTPException(status_code=403, detail="ایجاد سرفصل حساب فقط برای مدیر مجاز است.")
    code = str(payload.get("code", "")).strip().upper()
    name = str(payload.get("name", "")).strip()
    account_type = payload.get("account_type")
    normal_balance = payload.get("normal_balance")
    parent_code = payload.get("parent_code")
    if not code or len(code) > 40 or not name or len(name) > 160:
        raise HTTPException(status_code=422, detail="کد و نام حساب معتبر و الزامی است.")
    if account_type not in {"asset", "liability", "equity", "revenue", "expense"}:
        raise HTTPException(status_code=422, detail="نوع حساب معتبر نیست.")
    if normal_balance not in {"debit", "credit"}:
        raise HTTPException(status_code=422, detail="ماهیت حساب باید بدهکار یا بستانکار باشد.")
    if db.scalar(select(AccountingAccount).where(AccountingAccount.code == code)):
        raise HTTPException(status_code=409, detail="کد حساب از قبل ثبت شده است.")
    if parent_code and db.scalar(select(AccountingAccount).where(AccountingAccount.code == parent_code)) is None:
        raise HTTPException(status_code=422, detail="حساب مادر پیدا نشد.")
    account = AccountingAccount(
        code=code, name=name, account_type=account_type,
        normal_balance=normal_balance, parent_code=parent_code,
        is_system=False, is_active=True,
    )
    db.add(account)
    record_audit_event(
        db, action="accounting_account_created", entity_type="accounting_account",
        actor_user_id=user.id, entity_id=code,
        details={"name": name, "account_type": account_type, "parent_code": parent_code},
    )
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="کد حساب تکراری یا حساب مادر نامعتبر است.") from None
    db.refresh(account)
    return {
        "code": account.code, "name": account.name, "account_type": account.account_type,
        "normal_balance": account.normal_balance, "parent_code": account.parent_code,
        "is_system": account.is_system, "is_active": account.is_active,
    }


@router.post("/accounting/opening-balance", status_code=status.HTTP_201_CREATED)
def post_opening_balance(
    payload: OpeningBalanceCreate,
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    if not _manager(user):
        raise HTTPException(status_code=403, detail="ثبت مانده افتتاحیه فقط برای مدیر مجاز است.")
    if db.scalar(select(JournalEntry).where(JournalEntry.source_type == "opening_balance")):
        raise HTTPException(status_code=409, detail="مانده افتتاحیه قبلاً ثبت شده است؛ برای اصلاح باید سند اصلاحی ثبت شود.")
    codes = [line.account_code for line in payload.lines]
    if len(codes) != len(set(codes)):
        raise HTTPException(status_code=422, detail="هر سرفصل در سند افتتاحیه فقط یک بار مجاز است.")
    total_debit = sum(line.debit for line in payload.lines)
    total_credit = sum(line.credit for line in payload.lines)
    if total_debit <= 0 or total_debit != total_credit:
        raise HTTPException(status_code=422, detail="جمع بدهکار و بستانکار باید برابر و بیشتر از صفر باشد.")
    accounts = db.scalars(
        select(AccountingAccount).where(
            AccountingAccount.code.in_(codes), AccountingAccount.is_active.is_(True)
        )
    ).all()
    account_map = {account.code: account for account in accounts}
    if len(account_map) != len(codes):
        raise HTTPException(status_code=422, detail="یک یا چند سرفصل وجود ندارد یا غیرفعال است.")
    parent_codes = {account.parent_code for account in accounts if account.parent_code}
    if any(code in parent_codes for code in codes):
        raise HTTPException(status_code=422, detail="ثبت مستقیم روی حساب گروه مجاز نیست؛ از حساب معین استفاده کنید.")
    for line in payload.lines:
        account = account_map[line.account_code]
        if (line.debit > 0) == (line.credit > 0):
            raise HTTPException(status_code=422, detail="هر ردیف باید دقیقاً یک مبلغ غیرصفر در ستون بدهکار یا بستانکار داشته باشد.")
        # Contra balances are valid in double-entry accounting (for example,
        # a customer credit balance or an overdrawn bank account). Do not
        # reject a line solely because it opposes the account's normal balance.
    entry = _journal(
        db, source_type="opening_balance", source_id="initial",
        description=payload.description,
        lines=[
            {"account_code": line.account_code, "debit": line.debit, "credit": line.credit}
            for line in payload.lines
        ],
    )
    entry.posted_at = datetime.combine(payload.opening_date, datetime.min.time(), tzinfo=timezone.utc)
    record_audit_event(
        db, action="accounting_opening_balance_posted", entity_type="journal_entry",
        actor_user_id=user.id, entity_id=str(entry.id),
        details={"opening_date": payload.opening_date.isoformat(), "total_debit": total_debit, "line_count": len(payload.lines)},
    )
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="مانده افتتاحیه همزمان توسط کاربر دیگری ثبت شده است.") from None
    return {
        "entry_id": str(entry.id), "entry_number": entry.entry_number,
        "opening_date": payload.opening_date, "total_debit": total_debit,
        "total_credit": total_credit, "status": "posted",
    }


@router.get("/accounting/account-ledger/{account_code}")
def get_account_ledger(
    account_code: str,
    from_date: datetime | None = None,
    to_date: datetime | None = None,
    user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="مشاهده دفتر حساب فقط برای مدیر یا حسابدار مجاز است.")
    account = db.scalar(select(AccountingAccount).where(
        AccountingAccount.code == account_code,
        AccountingAccount.is_active.is_(True),
    ))
    if account is None:
        raise HTTPException(status_code=404, detail="سرفصل فعال پیدا نشد.")
    if db.scalar(select(AccountingAccount.code).where(AccountingAccount.parent_code == account.code)):
        raise HTTPException(status_code=422, detail="برای مشاهده گردش، حساب معین را انتخاب کنید نه حساب گروه.")
    if from_date and to_date and from_date > to_date:
        raise HTTPException(status_code=422, detail="تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.")
    base = select(JournalLine, JournalEntry).join(
        JournalEntry, JournalEntry.id == JournalLine.entry_id
    ).where(JournalLine.account_code == account.code)
    start_at = datetime.combine(from_date, time.min, tzinfo=timezone.utc) if from_date else None
    end_exclusive = (
        datetime.combine(to_date + timedelta(days=1), time.min, tzinfo=timezone.utc)
        if to_date else None
    )
    opening = 0
    if start_at:
        prior = db.execute(base.where(JournalEntry.posted_at < start_at)).all()
        opening = sum(line.debit - line.credit for line, _entry in prior)
    statement = base
    if start_at:
        statement = statement.where(JournalEntry.posted_at >= start_at)
    if end_exclusive:
        statement = statement.where(JournalEntry.posted_at < end_exclusive)
    rows = db.execute(statement.order_by(
        JournalEntry.posted_at.asc(), JournalEntry.entry_number.asc()
    )).all()
    balance = opening
    entries = []
    for line, entry in rows:
        balance += line.debit - line.credit
        entries.append({
            "entry_number": entry.entry_number, "date": entry.posted_at,
            "description": entry.description, "debit": line.debit,
            "credit": line.credit, "running_balance": balance,
        })
    return {
        "account_code": account.code, "account_name": account.name,
        "account_type": account.account_type, "normal_balance": account.normal_balance,
        "opening_balance": opening, "entries": entries, "closing_balance": balance,
    }

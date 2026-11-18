from datetime import datetime, timezone
from uuid import UUID, uuid4

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import func, select
from sqlalchemy.orm import Session, selectinload

from app.api.routes.auth import get_current_user
from app.db.session import get_db
from app.models.customer import Customer
from app.models.identity import User
from app.models.product import Product
from app.models.sales import (
    Cheque, CommissionAccrual, CommissionTier, InventoryBalance, InventoryMovement,
    JournalEntry, JournalLine, PaymentReceipt, SalesInvoice, SalesOrder, SalesOrderLine,
)
from app.schemas.sales import (
    ChequeDecision, CommissionTierIn, CommissionTierRead, InventoryAdjustment,
    InventoryBalanceRead, InvoiceRead, OrderDecision, PaymentCreate, PaymentRead,
    SalesOrderCreate, SalesOrderRead,
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
    return _manager(user) or order.seller_user_id == user.id


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


def _accrue_commission(db: Session, *, receipt: PaymentReceipt, invoice: SalesInvoice) -> None:
    already = db.scalar(select(func.coalesce(func.sum(CommissionAccrual.collected_amount), 0)).where(
        CommissionAccrual.seller_user_id == invoice.seller_user_id
    )) or 0
    tiers = db.scalars(select(CommissionTier).where(CommissionTier.is_active.is_(True)).order_by(CommissionTier.min_collected_amount)).all()
    rate = 0
    for tier in tiers:
        if already + receipt.amount >= tier.min_collected_amount:
            rate = tier.rate_bps
        else:
            break
    commission = (receipt.amount * rate) // 10000
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
    if not _manager(user):
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
        if product is None:
            raise HTTPException(status_code=409, detail="محصول سفارش دیگر موجود نیست.")
        if product.track_inventory:
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


@router.post("/invoices/{invoice_id}/receipts", response_model=PaymentRead, status_code=status.HTTP_201_CREATED)
def create_receipt(invoice_id: UUID, payload: PaymentCreate, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    invoice = db.scalar(select(SalesInvoice).where(SalesInvoice.id == invoice_id).with_for_update())
    if invoice is None:
        raise HTTPException(status_code=404, detail="فاکتور پیدا نشد.")
    if not _manager(user) and invoice.seller_user_id != user.id and "accountant" not in _roles(user):
        raise HTTPException(status_code=403, detail="به این فاکتور دسترسی ندارید.")
    pending_total = db.scalar(select(func.coalesce(func.sum(PaymentReceipt.amount), 0)).where(PaymentReceipt.invoice_id == invoice.id, PaymentReceipt.status.in_({"pending", "cleared"}))) or 0
    if payload.amount > invoice.total_amount - pending_total:
        raise HTTPException(status_code=409, detail="مبلغ دریافت از مانده فاکتور بیشتر است.")
    if payload.method == "cheque" and not all([payload.bank_name, payload.cheque_number, payload.due_date, payload.drawer_name]):
        raise HTTPException(status_code=422, detail="برای ثبت چک، بانک، شماره، تاریخ سررسید و صادرکننده الزامی است.")
    receipt = PaymentReceipt(
        receipt_number=_number("RCPT"), invoice_id=invoice.id, amount=payload.amount,
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
    db.commit()
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
    balance.quantity_on_hand += payload.quantity_delta
    db.add(InventoryMovement(product_id=product.id, movement_type="adjustment", quantity_delta=payload.quantity_delta, reference=payload.reason))
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

from datetime import date, datetime, time, timezone
from uuid import UUID, uuid4

from fastapi import APIRouter, Depends, Header, HTTPException, Query, status
from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.api.routes.auth import get_current_user
from app.db.session import get_db
from app.models.accounting import AccountingAccount
from app.models.identity import User
from app.models.sales import JournalEntry, JournalLine
from app.models.suppliers import Supplier, SupplierBill, SupplierPayment
from app.schemas.suppliers import SupplierBillCreate, SupplierCreate, SupplierPaymentCreate
from app.services.audit import record_audit_event

router = APIRouter(prefix="/suppliers", tags=["supplier accounting"])


def _roles(user: User) -> set[str]:
    return {role.code for role in user.roles}


def _manager(user: User) -> bool:
    return user.is_superuser or bool(_roles(user) & {"manager", "admin", "owner"})


def _accounting(user: User) -> bool:
    return _manager(user) or "accountant" in _roles(user)


def _number(prefix: str) -> str:
    return f"{prefix}-{datetime.now(timezone.utc):%Y%m%d}-{uuid4().hex[:10].upper()}"


def _journal(db: Session, *, source_type: str, source_id: str, description: str, lines: list[dict]) -> JournalEntry:
    debit = sum(line["debit"] for line in lines)
    credit = sum(line["credit"] for line in lines)
    if debit <= 0 or debit != credit:
        raise ValueError("Journal entry must be balanced and non-zero.")
    entry = JournalEntry(
        entry_number=_number("JE"), source_type=source_type,
        source_id=source_id, description=description,
    )
    db.add(entry)
    db.flush()
    for line in lines:
        db.add(JournalLine(entry_id=entry.id, **line))
    return entry


def _leaf_account(db: Session, code: str, allowed_types: set[str]) -> AccountingAccount:
    account = db.scalar(select(AccountingAccount).where(
        AccountingAccount.code == code, AccountingAccount.is_active.is_(True),
        AccountingAccount.account_type.in_(allowed_types),
    ))
    if account is None:
        raise HTTPException(status_code=422, detail="سرفصل حساب انتخاب‌شده فعال یا از نوع مجاز نیست.")
    if db.scalar(select(AccountingAccount.code).where(AccountingAccount.parent_code == code)):
        raise HTTPException(status_code=422, detail="ثبت مستقیم روی حساب گروه مجاز نیست؛ حساب معین را انتخاب کنید.")
    return account


def _bill_paid(db: Session, bill_id: UUID) -> int:
    return int(db.scalar(select(func.coalesce(func.sum(SupplierPayment.amount), 0)).where(
        SupplierPayment.bill_id == bill_id,
        SupplierPayment.status.in_({"issued", "cleared"}),
    )) or 0)


def _bill_read(db: Session, bill: SupplierBill) -> dict:
    paid = _bill_paid(db, bill.id)
    return {
        "id": str(bill.id), "bill_number": bill.bill_number,
        "supplier_id": str(bill.supplier_id), "supplier_invoice_number": bill.supplier_invoice_number,
        "amount": bill.amount, "paid_amount": paid,
        "outstanding_amount": max(0, bill.amount - paid),
        "account_code": bill.account_code, "bill_date": bill.bill_date,
        "due_date": bill.due_date, "description": bill.description,
        "status": "paid" if paid >= bill.amount else ("partial" if paid else "unpaid"),
    }


@router.post("", status_code=status.HTTP_201_CREATED)
def create_supplier(
    payload: SupplierCreate, user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="ثبت تأمین‌کننده فقط برای مدیر یا حسابدار مجاز است.")
    supplier = Supplier(**payload.model_dump())
    db.add(supplier)
    record_audit_event(db, action="supplier_created", entity_type="supplier",
                       actor_user_id=user.id, entity_id=payload.code)
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="کد تأمین‌کننده تکراری است.") from None
    db.refresh(supplier)
    return {"id": str(supplier.id), "code": supplier.code, "name": supplier.name,
            "phone": supplier.phone, "tax_id": supplier.tax_id, "address": supplier.address,
            "notes": supplier.notes, "is_active": supplier.is_active}


@router.get("")
def list_suppliers(
    active_only: bool = True, user: User = Depends(get_current_user),
    db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="مشاهده فهرست تأمین‌کنندگان فقط برای مدیر یا حسابدار مجاز است.")
    statement = select(Supplier).order_by(Supplier.name)
    if active_only:
        statement = statement.where(Supplier.is_active.is_(True))
    rows = db.scalars(statement.limit(1000)).all()
    result = []
    for supplier in rows:
        bills = db.scalars(select(SupplierBill).where(SupplierBill.supplier_id == supplier.id)).all()
        outstanding = sum(max(0, bill.amount - _bill_paid(db, bill.id)) for bill in bills)
        cheque_due = int(db.scalar(select(func.coalesce(func.sum(SupplierPayment.amount), 0)).where(
            SupplierPayment.supplier_id == supplier.id, SupplierPayment.status == "issued",
        )) or 0)
        result.append({
            "id": str(supplier.id), "code": supplier.code, "name": supplier.name,
            "phone": supplier.phone, "is_active": supplier.is_active,
            "bill_outstanding": outstanding, "issued_cheques": cheque_due,
            "total_payable_exposure": outstanding + cheque_due,
        })
    return result


@router.post("/bills", status_code=status.HTTP_201_CREATED)
def create_supplier_bill(
    payload: SupplierBillCreate,
    idempotency_key: str = Header(..., alias="Idempotency-Key", min_length=12, max_length=120),
    user: User = Depends(get_current_user), db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="ثبت بدهی تأمین‌کننده فقط برای مدیر یا حسابدار مجاز است.")
    existing = db.scalar(select(SupplierBill).where(SupplierBill.idempotency_key == idempotency_key))
    if existing:
        if (existing.supplier_id != payload.supplier_id or existing.amount != payload.amount
                or existing.account_code != payload.account_code):
            raise HTTPException(status_code=409, detail="کلید تکرار برای سند دیگری استفاده شده است.")
        return _bill_read(db, existing)
    supplier = db.scalar(select(Supplier).where(Supplier.id == payload.supplier_id, Supplier.is_active.is_(True)).with_for_update())
    if supplier is None:
        raise HTTPException(status_code=404, detail="تأمین‌کننده فعال پیدا نشد.")
    if payload.due_date < payload.bill_date:
        raise HTTPException(status_code=422, detail="سررسید بدهی نمی‌تواند قبل از تاریخ فاکتور باشد.")
    charge = _leaf_account(db, payload.account_code, {"asset", "expense"})
    payable = _leaf_account(db, "ACCOUNTS_PAYABLE", {"liability"})
    bill = SupplierBill(
        bill_number=_number("PB"), idempotency_key=idempotency_key,
        supplier_id=supplier.id, supplier_invoice_number=payload.supplier_invoice_number,
        amount=payload.amount, account_code=charge.code, bill_date=payload.bill_date,
        due_date=payload.due_date, description=payload.description.strip(),
        created_by_user_id=user.id,
    )
    db.add(bill)
    db.flush()
    entry = _journal(db, source_type="supplier_bill", source_id=str(bill.id),
        description=f"ثبت بدهی تأمین‌کننده {supplier.name} - {bill.bill_number}",
        lines=[
            {"account_code": charge.code, "debit": bill.amount, "credit": 0},
            {"account_code": payable.code, "debit": 0, "credit": bill.amount, "supplier_id": supplier.id},
        ])
    entry.posted_at = datetime.combine(payload.bill_date, time.min, tzinfo=timezone.utc)
    record_audit_event(db, action="supplier_bill_posted", entity_type="supplier_bill",
        actor_user_id=user.id, entity_id=str(bill.id),
        details={"supplier_id": str(supplier.id), "amount": bill.amount, "due_date": bill.due_date.isoformat()})
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        duplicate = db.scalar(select(SupplierBill).where(SupplierBill.idempotency_key == idempotency_key))
        if duplicate:
            return _bill_read(db, duplicate)
        raise HTTPException(status_code=409, detail="سند بدهی تکراری یا متعارض است.") from None
    return _bill_read(db, bill)


@router.get("/bills")
def list_supplier_bills(
    supplier_id: UUID | None = None, status_filter: str | None = Query(default=None, alias="status"),
    user: User = Depends(get_current_user), db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="مشاهده بدهی تأمین‌کنندگان فقط برای مدیر یا حسابدار مجاز است.")
    statement = select(SupplierBill).order_by(SupplierBill.due_date, SupplierBill.created_at)
    if supplier_id:
        statement = statement.where(SupplierBill.supplier_id == supplier_id)
    rows = db.scalars(statement.limit(1000)).all()
    result = [_bill_read(db, bill) for bill in rows]
    if status_filter:
        result = [item for item in result if item["status"] == status_filter]
    return result


@router.post("/payments", status_code=status.HTTP_201_CREATED)
def pay_supplier(
    payload: SupplierPaymentCreate,
    idempotency_key: str = Header(..., alias="Idempotency-Key", min_length=12, max_length=120),
    user: User = Depends(get_current_user), db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="پرداخت بدهی تأمین‌کننده فقط برای مدیر یا حسابدار مجاز است.")
    existing = db.scalar(select(SupplierPayment).where(SupplierPayment.idempotency_key == idempotency_key))
    if existing:
        if existing.bill_id != payload.bill_id or existing.amount != payload.amount or existing.method != payload.method:
            raise HTTPException(status_code=409, detail="کلید تکرار برای پرداخت دیگری استفاده شده است.")
        return {"id": str(existing.id), "payment_number": existing.payment_number,
                "bill_id": str(existing.bill_id), "amount": existing.amount,
                "method": existing.method, "status": existing.status, "due_date": existing.due_date}
    bill = db.scalar(select(SupplierBill).where(SupplierBill.id == payload.bill_id).with_for_update())
    if bill is None:
        raise HTTPException(status_code=404, detail="سند بدهی پیدا نشد.")
    supplier = db.scalar(select(Supplier).where(Supplier.id == bill.supplier_id).with_for_update())
    if supplier is None or not supplier.is_active:
        raise HTTPException(status_code=409, detail="تأمین‌کننده فعال نیست.")
    if payload.amount > bill.amount - _bill_paid(db, bill.id):
        raise HTTPException(status_code=409, detail="مبلغ پرداخت از مانده بدهی بیشتر است.")
    if payload.method == "cheque":
        if not payload.due_date or not payload.bank_name or not payload.cheque_number:
            raise HTTPException(status_code=422, detail="برای چک پرداختی، بانک، شماره چک و سررسید الزامی است.")
        if payload.due_date < payload.payment_date:
            raise HTTPException(status_code=422, detail="سررسید چک نمی‌تواند قبل از تاریخ صدور باشد.")
        payment_account = _leaf_account(db, "CHEQUES_PAYABLE", {"liability"})
        status_value = "issued"
    else:
        if payload.due_date or payload.bank_name or payload.cheque_number:
            raise HTTPException(status_code=422, detail="اطلاعات چک فقط برای روش پرداخت چکی مجاز است.")
        payment_account = _leaf_account(db, "CASH" if payload.method == "cash" else "BANK", {"asset"})
        status_value = "cleared"
    payable = _leaf_account(db, "ACCOUNTS_PAYABLE", {"liability"})
    payment = SupplierPayment(
        payment_number=_number("SP"), idempotency_key=idempotency_key,
        bill_id=bill.id, supplier_id=supplier.id, amount=payload.amount,
        method=payload.method, status=status_value, payment_date=payload.payment_date,
        due_date=payload.due_date, bank_name=payload.bank_name,
        cheque_number=payload.cheque_number, reference=payload.reference,
        created_by_user_id=user.id,
    )
    db.add(payment)
    db.flush()
    entry = _journal(db, source_type="supplier_payment", source_id=str(payment.id),
        description=f"پرداخت بدهی {supplier.name} - {payment.payment_number}",
        lines=[
            {"account_code": payable.code, "debit": payment.amount, "credit": 0, "supplier_id": supplier.id},
            {"account_code": payment_account.code, "debit": 0, "credit": payment.amount, **({"supplier_id": supplier.id} if payment.method == "cheque" else {})},
        ])
    entry.posted_at = datetime.combine(payload.payment_date, time.min, tzinfo=timezone.utc)
    record_audit_event(db, action="supplier_payment_posted", entity_type="supplier_payment",
        actor_user_id=user.id, entity_id=str(payment.id),
        details={"supplier_id": str(supplier.id), "amount": payment.amount, "method": payment.method, "status": status_value})
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        duplicate = db.scalar(select(SupplierPayment).where(SupplierPayment.idempotency_key == idempotency_key))
        if duplicate:
            return {"id": str(duplicate.id), "payment_number": duplicate.payment_number,
                    "bill_id": str(duplicate.bill_id), "amount": duplicate.amount,
                    "method": duplicate.method, "status": duplicate.status, "due_date": duplicate.due_date}
        raise HTTPException(status_code=409, detail="پرداخت تکراری یا متعارض است.") from None
    return {"id": str(payment.id), "payment_number": payment.payment_number,
            "bill_id": str(payment.bill_id), "amount": payment.amount,
            "method": payment.method, "status": payment.status, "due_date": payment.due_date}


@router.post("/payments/{payment_id}/clear")
def clear_supplier_cheque(
    payment_id: UUID, user: User = Depends(get_current_user), db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="تأیید پاس‌شدن چک فقط برای مدیر یا حسابدار مجاز است.")
    payment = db.scalar(select(SupplierPayment).where(SupplierPayment.id == payment_id).with_for_update())
    if payment is None:
        raise HTTPException(status_code=404, detail="پرداخت پیدا نشد.")
    if payment.method != "cheque" or payment.status != "issued":
        raise HTTPException(status_code=409, detail="فقط چک صادرشده و در انتظار وصول قابل تسویه است.")
    supplier = db.get(Supplier, payment.supplier_id)
    cheque_liability = _leaf_account(db, "CHEQUES_PAYABLE", {"liability"})
    bank = _leaf_account(db, "BANK", {"asset"})
    entry = _journal(db, source_type="supplier_cheque_cleared", source_id=str(payment.id),
        description=f"پاس‌شدن چک تأمین‌کننده {supplier.name} - {payment.cheque_number}",
        lines=[
            {"account_code": cheque_liability.code, "debit": payment.amount, "credit": 0, "supplier_id": supplier.id},
            {"account_code": bank.code, "debit": 0, "credit": payment.amount},
        ])
    payment.status = "cleared"
    payment.cleared_at = datetime.now(timezone.utc)
    record_audit_event(db, action="supplier_cheque_cleared", entity_type="supplier_payment",
        actor_user_id=user.id, entity_id=str(payment.id))
    db.commit()
    return {"id": str(payment.id), "status": payment.status, "journal_entry_id": str(entry.id)}


@router.post("/payments/{payment_id}/bounce")
def bounce_supplier_cheque(
    payment_id: UUID, user: User = Depends(get_current_user), db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="ثبت برگشت چک فقط برای مدیر یا حسابدار مجاز است.")
    payment = db.scalar(select(SupplierPayment).where(SupplierPayment.id == payment_id).with_for_update())
    if payment is None:
        raise HTTPException(status_code=404, detail="پرداخت پیدا نشد.")
    if payment.method != "cheque" or payment.status != "issued":
        raise HTTPException(status_code=409, detail="فقط چک صادرشده قابل ثبت به‌عنوان برگشتی است.")
    supplier = db.get(Supplier, payment.supplier_id)
    cheque_liability = _leaf_account(db, "CHEQUES_PAYABLE", {"liability"})
    payable = _leaf_account(db, "ACCOUNTS_PAYABLE", {"liability"})
    entry = _journal(db, source_type="supplier_cheque_bounced", source_id=str(payment.id),
        description=f"برگشت چک تأمین‌کننده {supplier.name} - {payment.cheque_number}",
        lines=[
            {"account_code": cheque_liability.code, "debit": payment.amount, "credit": 0, "supplier_id": supplier.id},
            {"account_code": payable.code, "debit": 0, "credit": payment.amount, "supplier_id": supplier.id},
        ])
    payment.status = "bounced"
    record_audit_event(db, action="supplier_cheque_bounced", entity_type="supplier_payment",
        actor_user_id=user.id, entity_id=str(payment.id))
    db.commit()
    return {"id": str(payment.id), "status": payment.status, "journal_entry_id": str(entry.id)}


@router.get("/payments")
def list_supplier_payments(
    supplier_id: UUID | None = None, status_filter: str | None = Query(default=None, alias="status"),
    user: User = Depends(get_current_user), db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="مشاهده پرداخت‌های تأمین‌کنندگان فقط برای مدیر یا حسابدار مجاز است.")
    statement = select(SupplierPayment).order_by(SupplierPayment.payment_date.desc(), SupplierPayment.created_at.desc())
    if supplier_id:
        statement = statement.where(SupplierPayment.supplier_id == supplier_id)
    if status_filter:
        statement = statement.where(SupplierPayment.status == status_filter)
    rows = db.scalars(statement.limit(1000)).all()
    return [{
        "id": str(item.id), "payment_number": item.payment_number,
        "supplier_id": str(item.supplier_id), "bill_id": str(item.bill_id),
        "amount": item.amount, "method": item.method, "status": item.status,
        "payment_date": item.payment_date, "due_date": item.due_date,
        "bank_name": item.bank_name, "cheque_number": item.cheque_number,
        "reference": item.reference, "cleared_at": item.cleared_at,
    } for item in rows]


@router.get("/{supplier_id}/ledger")
def supplier_ledger(
    supplier_id: UUID, from_date: date | None = None, to_date: date | None = None,
    user: User = Depends(get_current_user), db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="مشاهده دفتر تأمین‌کننده فقط برای مدیر یا حسابدار مجاز است.")
    supplier = db.get(Supplier, supplier_id)
    if supplier is None:
        raise HTTPException(status_code=404, detail="تأمین‌کننده پیدا نشد.")
    if from_date and to_date and from_date > to_date:
        raise HTTPException(status_code=422, detail="ابتدای بازه نباید بعد از انتهای بازه باشد.")
    base = select(JournalLine, JournalEntry).join(
        JournalEntry, JournalEntry.id == JournalLine.entry_id
    ).where(JournalLine.supplier_id == supplier.id)
    opening_balance = 0
    if from_date:
        start_at = datetime.combine(from_date, time.min, tzinfo=timezone.utc)
        opening_balance = int(db.scalar(select(func.coalesce(func.sum(JournalLine.credit - JournalLine.debit), 0)).join(
            JournalEntry, JournalEntry.id == JournalLine.entry_id
        ).where(JournalLine.supplier_id == supplier.id, JournalEntry.posted_at < start_at)) or 0)
        base = base.where(JournalEntry.posted_at >= start_at)
    if to_date:
        end_exclusive = datetime.combine(date.fromordinal(to_date.toordinal() + 1), time.min, tzinfo=timezone.utc)
        base = base.where(JournalEntry.posted_at < end_exclusive)
    rows = db.execute(base.order_by(JournalEntry.posted_at.asc(), JournalEntry.entry_number.asc())).all()
    balance = opening_balance
    entries = []
    for line, entry in rows:
        balance += line.credit - line.debit
        entries.append({
            "entry_number": entry.entry_number, "date": entry.posted_at,
            "description": entry.description, "account_code": line.account_code,
            "debit": line.debit, "credit": line.credit, "payable_running_balance": balance,
        })
    return {"supplier_id": str(supplier.id), "supplier_code": supplier.code,
            "supplier_name": supplier.name, "opening_payable_balance": opening_balance,
            "entries": entries, "closing_payable_balance": balance}


@router.get("/reports/cash-flow")
def supplier_cash_flow(
    from_date: date | None = None, to_date: date | None = None,
    forecast_days: int = Query(default=90, ge=1, le=365),
    user: User = Depends(get_current_user), db: Session = Depends(get_db),
):
    if not _accounting(user):
        raise HTTPException(status_code=403, detail="گزارش جریان نقدینگی فقط برای مدیر یا حسابدار مجاز است.")
    today = date.today()
    if from_date and to_date and from_date > to_date:
        raise HTTPException(status_code=422, detail="ابتدای بازه نباید بعد از انتهای بازه باشد.")
    history = select(
        func.date(JournalEntry.posted_at).label("flow_date"),
        func.coalesce(func.sum(JournalLine.credit - JournalLine.debit), 0).label("net_cash_flow"),
    ).join(JournalEntry, JournalEntry.id == JournalLine.entry_id).where(
        JournalLine.account_code.in_({"CASH", "BANK"})
    )
    if from_date:
        history = history.where(JournalEntry.posted_at >= datetime.combine(from_date, time.min, tzinfo=timezone.utc))
    if to_date:
        history = history.where(JournalEntry.posted_at < datetime.combine(date.fromordinal(to_date.toordinal() + 1), time.min, tzinfo=timezone.utc))
    history_rows = db.execute(history.group_by(func.date(JournalEntry.posted_at)).order_by(func.date(JournalEntry.posted_at))).all()
    horizon = date.fromordinal(today.toordinal() + forecast_days)
    bills = db.scalars(select(SupplierBill).where(SupplierBill.due_date >= today, SupplierBill.due_date <= horizon).order_by(SupplierBill.due_date)).all()
    forecast = {}
    for bill in bills:
        remaining = max(0, bill.amount - _bill_paid(db, bill.id))
        if remaining:
            key = bill.due_date.isoformat()
            forecast[key] = forecast.get(key, 0) + remaining
    cheques = db.scalars(select(SupplierPayment).where(
        SupplierPayment.method == "cheque", SupplierPayment.status == "issued",
        SupplierPayment.due_date >= today, SupplierPayment.due_date <= horizon,
    ).order_by(SupplierPayment.due_date)).all()
    for cheque in cheques:
        key = cheque.due_date.isoformat()
        forecast[key] = forecast.get(key, 0) + cheque.amount
    return {
        "historical_cash_flow": [
            {"date": str(row.flow_date), "net_cash_flow": int(row.net_cash_flow)}
            for row in history_rows
        ],
        "forecast_days": forecast_days,
        "forecast_payments": [
            {"due_date": key, "expected_outflow": amount}
            for key, amount in sorted(forecast.items())
        ],
        "forecast_total": sum(forecast.values()),
        "overdue_supplier_bills": sum(
            max(0, bill.amount - _bill_paid(db, bill.id))
            for bill in db.scalars(select(SupplierBill).where(SupplierBill.due_date < today)).all()
        ),
        "outstanding_issued_cheques": sum(cheque.amount for cheque in db.scalars(
            select(SupplierPayment).where(SupplierPayment.method == "cheque", SupplierPayment.status == "issued")
        ).all()),
        "note": "پیش‌بینی بر اساس سررسید بدهی‌های ثبت‌شده و چک‌های صادرشده است؛ این گزارش تعهدات ثبت‌نشده و جریان‌های نقدی خارج از دفاتر را پوشش نمی‌دهد.",
    }

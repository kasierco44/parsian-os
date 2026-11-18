FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN addgroup --system app && adduser --system --ingroup app app \
    && mkdir -p /var/lib/parsian/catalog \
    && chown -R app:app /var/lib/parsian
COPY requirements.txt ./requirements.txt
RUN pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY migrations ./migrations
COPY alembic.ini ./alembic.ini
USER app
EXPOSE 8000

CMD ["sh", "-c", "alembic upgrade head && python -m app.bootstrap_admin && uvicorn app.main:app --host 0.0.0.0 --port 8000"]

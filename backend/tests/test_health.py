from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_liveness_returns_alive() -> None:
    response = client.get("/api/v1/health/live")
    assert response.status_code == 200
    assert response.json() == {"status": "alive"}


def test_version_endpoint_returns_service_metadata() -> None:
    response = client.get("/api/v1/system/version")
    assert response.status_code == 200
    body = response.json()
    assert body["name"] == "PARSIAN OS API"
    assert body["version"] == "0.1.0"

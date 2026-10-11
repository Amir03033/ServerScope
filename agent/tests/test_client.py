import pytest
import requests

from serverscope_agent.client import ServerScopeClient


def test_client_requires_https():
    with pytest.raises(ValueError, match="HTTPS"):
        ServerScopeClient(
            base_url="http://localhost",
            token="test-token",
        )


def test_client_requires_url(monkeypatch):
    monkeypatch.delenv("SERVERSCOPE_URL", raising=False)
    monkeypatch.delenv("SERVERSCOPE_TOKEN", raising=False)

    with pytest.raises(ValueError, match="SERVERSCOPE_URL"):
        ServerScopeClient(
            base_url="",
            token="test-token",
        )


def test_client_requires_token(monkeypatch):
    monkeypatch.delenv("SERVERSCOPE_TOKEN", raising=False)

    with pytest.raises(ValueError, match="SERVERSCOPE_TOKEN"):
        ServerScopeClient(
            base_url="https://example.test",
            token="",
        )


def test_client_sends_metrics(monkeypatch):
    client = ServerScopeClient(
        base_url="https://example.test",
        token="test-token",
    )

    class FakeResponse:
        def raise_for_status(self):
            pass

        def json(self):
            return {"message": "Meting opgeslagen."}

    captured = {}

    def fake_post(url, **kwargs):
        captured["url"] = url
        captured.update(kwargs)
        return FakeResponse()

    monkeypatch.setattr(client.session, "post", fake_post)

    result = client.send_metrics({"cpu_percent": 25.0})

    assert result["message"] == "Meting opgeslagen."
    assert captured["url"] == (
        "https://example.test/api/agent/metrics"
    )
    assert captured["json"] == {"cpu_percent": 25.0}
    assert captured["timeout"] == 10.0
    assert client.session.headers["Authorization"] == "Bearer test-token"
    assert client.session.headers["Accept"] == "application/json"


def test_client_hides_network_errors(monkeypatch):
    client = ServerScopeClient(
        base_url="https://example.test",
        token="test-token",
    )

    def fake_post(*args, **kwargs):
        raise requests.ConnectionError("sensitive internal details")

    monkeypatch.setattr(client.session, "post", fake_post)

    with pytest.raises(RuntimeError) as error:
        client.send_metrics({"cpu_percent": 25.0})

    assert str(error.value) == (
        "Could not send metrics to ServerScope."
    )
    assert "sensitive internal details" not in str(error.value)

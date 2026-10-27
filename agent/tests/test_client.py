import pytest
import requests

from serverscope_agent.client import ServerScopeClient


def create_client():
    return ServerScopeClient(
        base_url="https://example.test",
        token="test-token",
    )


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
    client = create_client()

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

    metrics = {
        "collected_at": "2026-10-11T12:00:00+00:00",
        "cpu_percent": 25.0,
        "agent_version": "0.1.0",
    }

    result = client.send_metrics(metrics)

    assert result == {"message": "Meting opgeslagen."}
    assert captured["url"] == (
        "https://example.test/api/agent/metrics"
    )
    assert captured["json"] == metrics
    assert captured["timeout"] == 10.0
    assert client.session.headers["Authorization"] == "Bearer test-token"
    assert client.session.headers["Accept"] == "application/json"
    assert client.session.headers["Content-Type"] == "application/json"


@pytest.mark.parametrize("status_code", [401, 409, 422, 500])
def test_client_hides_http_errors(monkeypatch, status_code):
    client = create_client()

    class FakeResponse:
        def raise_for_status(self):
            raise requests.HTTPError(
                f"Sensitive server details: status {status_code}"
            )

    def fake_post(*args, **kwargs):
        return FakeResponse()

    monkeypatch.setattr(client.session, "post", fake_post)

    with pytest.raises(RuntimeError) as error:
        client.send_metrics({"cpu_percent": 25.0})

    assert str(error.value) == (
        "Could not send metrics to ServerScope."
    )
    assert "Sensitive server details" not in str(error.value)


def test_client_hides_network_errors(monkeypatch):
    client = create_client()

    def fake_post(*args, **kwargs):
        raise requests.ConnectionError("sensitive internal details")

    monkeypatch.setattr(client.session, "post", fake_post)

    with pytest.raises(RuntimeError) as error:
        client.send_metrics({"cpu_percent": 25.0})

    assert str(error.value) == (
        "Could not send metrics to ServerScope."
    )
    assert "sensitive internal details" not in str(error.value)


def test_client_handles_invalid_json(monkeypatch):
    client = create_client()

    class FakeResponse:
        def raise_for_status(self):
            pass

        def json(self):
            raise requests.exceptions.JSONDecodeError(
                "Invalid JSON",
                "not-json",
                0,
            )

    monkeypatch.setattr(
        client.session,
        "post",
        lambda *args, **kwargs: FakeResponse(),
    )

    with pytest.raises(RuntimeError) as error:
        client.send_metrics({"cpu_percent": 25.0})

    assert str(error.value) == (
        "ServerScope returned an invalid JSON response."
    )


@pytest.mark.parametrize("unexpected_result", [None, [], "unexpected"])
def test_client_rejects_unexpected_response_format(
    monkeypatch,
    unexpected_result,
):
    client = create_client()

    class FakeResponse:
        def raise_for_status(self):
            pass

        def json(self):
            return unexpected_result

    monkeypatch.setattr(
        client.session,
        "post",
        lambda *args, **kwargs: FakeResponse(),
    )

    with pytest.raises(RuntimeError) as error:
        client.send_metrics({"cpu_percent": 25.0})

    assert str(error.value) == (
        "ServerScope returned an unexpected response."
    )


class _ErrorResponse:
    def __init__(self, status, body=None):
        self.status_code = status
        self._body = body or {}

    def raise_for_status(self):
        raise requests.HTTPError(response=self)

    def json(self):
        return self._body


@pytest.mark.parametrize(
    "status, body, expected",
    [
        (401, {}, "HTTP 401, token rejected"),
        (422, {"message": "x", "errors": {"services.0.name": ["bad"], "collected_at": ["old"]}}, "HTTP 422, rejected fields: collected_at, services.0.name"),
        (500, {}, "HTTP 500"),
    ],
)
def test_failed_submissions_have_a_safe_reason(monkeypatch, status, body, expected):
    from serverscope_agent.client import SubmissionError

    client = create_client()
    monkeypatch.setattr(client.session, "post", lambda url, **kwargs: _ErrorResponse(status, body))

    with pytest.raises(SubmissionError) as error:
        client.send_metrics({})

    assert error.value.reason.startswith(expected)
    assert "test-token" not in error.value.reason

import pytest

from serverscope_agent.config import load_config


def test_load_config_with_valid_values(monkeypatch):
    monkeypatch.setenv(
        "SERVERSCOPE_URL",
        "https://monitor.example.test/",
    )
    monkeypatch.setenv("SERVERSCOPE_TOKEN", "test-token")
    monkeypatch.setenv("SERVERSCOPE_INTERVAL", "30")

    config = load_config()

    assert config.base_url == "https://monitor.example.test"
    assert config.token == "test-token"
    assert config.interval_seconds == 30


def test_load_config_requires_url(monkeypatch):
    monkeypatch.delenv("SERVERSCOPE_URL", raising=False)
    monkeypatch.setenv("SERVERSCOPE_TOKEN", "test-token")

    with pytest.raises(ValueError, match="SERVERSCOPE_URL"):
        load_config()


def test_load_config_requires_token(monkeypatch):
    monkeypatch.setenv("SERVERSCOPE_URL", "https://monitor.example.test")
    monkeypatch.delenv("SERVERSCOPE_TOKEN", raising=False)

    with pytest.raises(ValueError, match="SERVERSCOPE_TOKEN"):
        load_config()


def test_load_config_rejects_http(monkeypatch):
    monkeypatch.setenv("SERVERSCOPE_URL", "http://monitor.example.test")
    monkeypatch.setenv("SERVERSCOPE_TOKEN", "test-token")

    with pytest.raises(ValueError, match="HTTPS"):
        load_config()


def test_load_config_rejects_invalid_interval(monkeypatch):
    monkeypatch.setenv("SERVERSCOPE_URL", "https://monitor.example.test")
    monkeypatch.setenv("SERVERSCOPE_TOKEN", "test-token")
    monkeypatch.setenv("SERVERSCOPE_INTERVAL", "5")

    with pytest.raises(ValueError, match="at least 10"):
        load_config()


def test_load_config_rejects_non_numeric_interval(monkeypatch):
    monkeypatch.setenv("SERVERSCOPE_URL", "https://monitor.example.test")
    monkeypatch.setenv("SERVERSCOPE_TOKEN", "test-token")
    monkeypatch.setenv("SERVERSCOPE_INTERVAL", "abc")

    with pytest.raises(ValueError, match="must be an integer"):
        load_config()


def test_load_config_rejects_url_with_credentials(monkeypatch):
    monkeypatch.setenv(
        "SERVERSCOPE_URL",
        "https://user:password@monitor.example.test",
    )
    monkeypatch.setenv("SERVERSCOPE_TOKEN", "test-token")

    with pytest.raises(ValueError, match="valid HTTPS"):
        load_config()

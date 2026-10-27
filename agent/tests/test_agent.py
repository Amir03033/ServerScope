from unittest.mock import patch

import pytest

from serverscope_agent.agent import run_once, run_forever


def test_run_once_collects_and_sends_metrics():
    fake_metrics = {
        "cpu_percent": 25.0,
        "agent_version": "0.1.0",
    }

    class FakeClient:
        def __init__(self):
            self.received = None

        def send_metrics(self, metrics):
            self.received = metrics
            return {"message": "Meting opgeslagen."}

    client = FakeClient()

    with patch(
        "serverscope_agent.agent.collect_metrics",
        return_value=fake_metrics,
    ):
        result = run_once(client)

    assert client.received == fake_metrics
    assert result == {"message": "Meting opgeslagen."}


def test_run_forever_rejects_too_short_interval():
    with pytest.raises(ValueError, match="at least 10"):
        run_forever(object(), interval_seconds=5)


def test_run_forever_logs_safe_error_and_continues(
    caplog,
):
    sensitive_message = "private token or internal server details"

    with (
        patch(
            "serverscope_agent.agent.run_once",
            side_effect=RuntimeError(sensitive_message),
        ),
        patch(
            "serverscope_agent.agent.time.sleep",
            side_effect=KeyboardInterrupt,
        ),
    ):
        with pytest.raises(KeyboardInterrupt):
            run_forever(
                object(),
                interval_seconds=10,
            )

    assert "Metrics submission failed." in caplog.text
    assert sensitive_message not in caplog.text


def test_run_once_adds_services_only_when_configured():
    class FakeClient:
        def send_metrics(self, metrics):
            self.received = metrics
            return {}

    client = FakeClient()

    with patch("serverscope_agent.agent.collect_metrics", return_value={"cpu_percent": 1.0}), patch(
        "serverscope_agent.agent.check_services",
        return_value=[{"name": "nginx", "state": "running", "detail": "active/running"}],
    ) as mock_check:
        run_once(client)
        assert "services" not in client.received
        mock_check.assert_not_called()

        run_once(client, ("nginx",))
        assert client.received["services"][0]["state"] == "running"


def test_run_forever_logs_the_safe_reason(caplog):
    from serverscope_agent.client import SubmissionError

    with (
        patch("serverscope_agent.agent.run_once", side_effect=SubmissionError("HTTP 401, token rejected")),
        patch("serverscope_agent.agent.time.sleep", side_effect=KeyboardInterrupt),
    ):
        with pytest.raises(KeyboardInterrupt):
            run_forever(object(), interval_seconds=10)

    assert "Metrics submission failed: HTTP 401, token rejected" in caplog.text

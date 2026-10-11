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

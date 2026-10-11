import os

import requests


class ServerScopeClient:
    """HTTPS client for sending metrics to ServerScope."""

    def __init__(
        self,
        base_url: str | None = None,
        token: str | None = None,
        timeout: float = 10.0,
    ) -> None:
        self.base_url = (
            base_url or os.environ.get("SERVERSCOPE_URL", "")
        ).rstrip("/")

        self.token = token or os.environ.get("SERVERSCOPE_TOKEN", "")
        self.timeout = timeout

        if not self.base_url:
            raise ValueError("SERVERSCOPE_URL is not configured.")

        if not self.base_url.startswith("https://"):
            raise ValueError("ServerScope API must use HTTPS.")

        if not self.token:
            raise ValueError("SERVERSCOPE_TOKEN is not configured.")

        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": "ServerScope-Agent/0.1.0",
        })

    def send_metrics(self, metrics: dict) -> dict:
        """Send one metrics payload and return the JSON response."""

        try:
            response = self.session.post(
                f"{self.base_url}/api/agent/metrics",
                json=metrics,
                timeout=self.timeout,
            )
            response.raise_for_status()
        except requests.RequestException:
            # Do not include the original exception: it might contain
            # request details or sensitive information.
            raise RuntimeError(
                "Could not send metrics to ServerScope."
            ) from None

        try:
            result = response.json()
        except requests.exceptions.JSONDecodeError:
            raise RuntimeError(
                "ServerScope returned an invalid JSON response."
            ) from None

        if not isinstance(result, dict):
            raise RuntimeError(
                "ServerScope returned an unexpected response."
            )

        return result

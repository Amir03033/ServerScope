import os

import requests


class SubmissionError(RuntimeError):
    """A failed submission with a reason that is safe to log.

    The reason only contains the HTTP status and, for validation errors,
    the names of the rejected fields; never tokens or response bodies.
    """

    def __init__(self, reason: str):
        super().__init__("Could not send metrics to ServerScope.")
        self.reason = reason


def _safe_reason(response) -> str:
    status = getattr(response, "status_code", None)

    if not isinstance(status, int):
        return "HTTP error"

    hints = {
        401: "token rejected; check SERVERSCOPE_TOKEN for this ServerScope URL",
        403: "access denied",
        404: "endpoint not found; check SERVERSCOPE_URL",
        409: "a sample for this timestamp already exists",
        429: "rate limited; increase SERVERSCOPE_INTERVAL",
    }

    if status == 422:
        try:
            fields = sorted((response.json().get("errors") or {}).keys())[:5]
        except (ValueError, AttributeError):
            fields = []

        return f"HTTP 422, rejected fields: {', '.join(fields) or 'unknown'}"

    return f"HTTP {status}" + (f", {hints[status]}" if status in hints else "")


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
            "User-Agent": "ServerScope-Agent/0.2.0",
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
        except requests.HTTPError:
            raise SubmissionError(_safe_reason(response)) from None
        except requests.Timeout:
            raise SubmissionError("time-out") from None
        except requests.ConnectionError:
            raise SubmissionError("connection failed (DNS, TLS or network)") from None
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

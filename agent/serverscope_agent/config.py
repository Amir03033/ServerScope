import os
from dataclasses import dataclass
from urllib.parse import urlparse

from serverscope_agent.services import parse_services


@dataclass(frozen=True)
class AgentConfig:
    base_url: str
    token: str
    interval_seconds: int = 60
    services: tuple = ()


def load_config() -> AgentConfig:
    """Load and validate agent configuration without exposing secrets."""

    base_url = os.environ.get("SERVERSCOPE_URL", "").strip().rstrip("/")
    token = os.environ.get("SERVERSCOPE_TOKEN", "").strip()
    interval_raw = os.environ.get("SERVERSCOPE_INTERVAL", "60").strip()

    if not base_url:
        raise ValueError("SERVERSCOPE_URL is not configured.")

    parsed_url = urlparse(base_url)

    if (
        parsed_url.scheme != "https"
        or not parsed_url.hostname
        or parsed_url.username
        or parsed_url.password
        or parsed_url.query
        or parsed_url.fragment
    ):
        raise ValueError("SERVERSCOPE_URL must be a valid HTTPS base URL.")

    if not token:
        raise ValueError("SERVERSCOPE_TOKEN is not configured.")

    try:
        interval_seconds = int(interval_raw)
    except ValueError:
        raise ValueError(
            "SERVERSCOPE_INTERVAL must be an integer."
        ) from None

    if interval_seconds < 10:
        raise ValueError(
            "SERVERSCOPE_INTERVAL must be at least 10 seconds."
        )

    return AgentConfig(
        base_url=base_url,
        token=token,
        interval_seconds=interval_seconds,
        services=tuple(parse_services(os.environ.get("SERVERSCOPE_SERVICES", ""))),
    )

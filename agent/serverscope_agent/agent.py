import logging
import time
from pathlib import Path

from dotenv import load_dotenv

from serverscope_agent.client import ServerScopeClient
from serverscope_agent.collector import collect_metrics
from serverscope_agent.config import load_config


ENV_FILE = Path(__file__).resolve().parents[1] / ".env"
load_dotenv(ENV_FILE)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)

logger = logging.getLogger("serverscope-agent")


def run_once(client: ServerScopeClient) -> dict:
    """Collect and submit one set of system metrics."""
    metrics = collect_metrics()
    result = client.send_metrics(metrics)

    logger.info("Metrics submitted successfully.")

    return result


def run_forever(
    client: ServerScopeClient,
    interval_seconds: int = 60,
) -> None:
    """Continuously collect and submit metrics."""
    if interval_seconds < 10:
        raise ValueError("Interval must be at least 10 seconds.")

    logger.info("ServerScope agent started.")

    while True:
        try:
            run_once(client)
        except Exception:
            logger.error("Metrics submission failed.")

        time.sleep(interval_seconds)


def main() -> None:
    """Load configuration and start the agent."""
    config = load_config()

    client = ServerScopeClient(
        base_url=config.base_url,
        token=config.token,
    )

    run_forever(
        client,
        interval_seconds=config.interval_seconds,
    )


if __name__ == "__main__":
    main()

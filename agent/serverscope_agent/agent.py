import logging
import os
import time
from pathlib import Path

from dotenv import load_dotenv

from serverscope_agent.client import ServerScopeClient, SubmissionError
from serverscope_agent.collector import collect_metrics
from serverscope_agent.config import load_config
from serverscope_agent.services import check_services


def _env_file() -> Path:
    """<install dir>/.env, e.g. /opt/serverscope-agent/.env.

    The package is imported through /opt/serverscope-agent/serverscope_agent,
    which install.sh 0.3.0+ makes a symlink to releases/<version>/. The path
    is taken as imported (not resolved), so the symlink is not followed.
    Each release directory also holds a .env symlink to ../../.env, which is
    what agents up to 0.2.0 (that resolve the path) find.
    """
    unresolved = Path(os.path.abspath(__file__)).parents[1] / ".env"

    if unresolved.exists():
        return unresolved

    return Path(__file__).resolve().parents[1] / ".env"


ENV_FILE = _env_file()
load_dotenv(ENV_FILE)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)

logger = logging.getLogger("serverscope-agent")


def run_once(client: ServerScopeClient, services: tuple = ()) -> dict:
    """Collect and submit one set of system metrics."""
    metrics = collect_metrics()

    # Only sent when services are configured, so the server never
    # concludes that unlisted services are missing.
    if services:
        metrics["services"] = check_services(list(services))
    result = client.send_metrics(metrics)

    logger.info("Metrics submitted successfully.")

    return result


def run_forever(
    client: ServerScopeClient,
    interval_seconds: int = 60,
    services: tuple = (),
) -> None:
    """Continuously collect and submit metrics."""
    if interval_seconds < 10:
        raise ValueError("Interval must be at least 10 seconds.")

    logger.info("ServerScope agent started.")

    while True:
        try:
            run_once(client, services)
        except SubmissionError as error:
            # The reason never contains secrets (see SubmissionError).
            logger.error("Metrics submission failed: %s", error.reason)
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
        services=config.services,
    )


if __name__ == "__main__":
    main()

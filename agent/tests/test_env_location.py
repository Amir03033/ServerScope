"""The installer (install.sh) writes the configuration to
<install dir>/.env next to the serverscope_agent package, e.g.
/opt/serverscope-agent/.env. These tests fail as soon as the agent looks
for its configuration anywhere else."""

import os
import shutil
import subprocess
import sys
from pathlib import Path

import serverscope_agent
from serverscope_agent import agent

PACKAGE_DIR = Path(serverscope_agent.__file__).resolve().parent


def test_env_file_is_next_to_the_package():
    assert agent.ENV_FILE == PACKAGE_DIR.parent / ".env"


def test_installed_layout_reads_its_own_env_file(tmp_path):
    install_dir = tmp_path / "serverscope-agent"
    shutil.copytree(PACKAGE_DIR, install_dir / "serverscope_agent", ignore=shutil.ignore_patterns("__pycache__"))
    # Same format as install.sh: no quotes, no spaces around '='.
    (install_dir / ".env").write_text(
        "SERVERSCOPE_URL=https://monitor.example.test\n"
        "SERVERSCOPE_TOKEN=" + "ab" * 32 + "\n"
        "SERVERSCOPE_INTERVAL=45\n"
    )

    env = {key: value for key, value in os.environ.items() if not key.startswith("SERVERSCOPE_")}
    env["PYTHONPATH"] = str(install_dir)
    script = (
        "from serverscope_agent import agent\n"
        "from serverscope_agent.config import load_config\n"
        "c = load_config()\n"
        "print(agent.ENV_FILE, c.base_url, c.token == 'ab' * 32, c.interval_seconds)\n"
    )

    # Run from an unrelated directory so a .env in the working directory
    # cannot be picked up by accident.
    result = subprocess.run([sys.executable, "-c", script], cwd=tmp_path, env=env, capture_output=True, text=True, check=True)

    assert result.stdout.split() == [str(install_dir / ".env"), "https://monitor.example.test", "True", "45"]


def test_environment_variables_take_precedence_over_the_env_file(tmp_path):
    install_dir = tmp_path / "serverscope-agent"
    shutil.copytree(PACKAGE_DIR, install_dir / "serverscope_agent", ignore=shutil.ignore_patterns("__pycache__"))
    (install_dir / ".env").write_text("SERVERSCOPE_URL=https://from-file.example.test\nSERVERSCOPE_TOKEN=" + "cd" * 32 + "\n")

    env = {key: value for key, value in os.environ.items() if not key.startswith("SERVERSCOPE_")}
    env.update({"PYTHONPATH": str(install_dir), "SERVERSCOPE_URL": "https://from-env.example.test"})
    script = "from serverscope_agent import agent\nfrom serverscope_agent.config import load_config\nprint(load_config().base_url)\n"

    result = subprocess.run([sys.executable, "-c", script], cwd=tmp_path, env=env, capture_output=True, text=True, check=True)

    assert result.stdout.strip() == "https://from-env.example.test"

import subprocess
from types import SimpleNamespace
from unittest.mock import patch

from serverscope_agent.services import check_services, parse_services


def test_parse_services_keeps_valid_unique_names():
    assert parse_services(" nginx, mysql ,nginx,php8.3-fpm, bad name;rm -rf /,redis-server@main ") == [
        "nginx",
        "mysql",
        "php8.3-fpm",
        "redis-server@main",
    ]
    assert parse_services("") == []


def _show(output):
    return SimpleNamespace(stdout=output, returncode=0)


@patch("serverscope_agent.services.shutil.which", return_value="/usr/bin/systemctl")
@patch("serverscope_agent.services.subprocess.run")
def test_systemd_states_are_mapped(mock_run, mock_which):
    mock_run.side_effect = [
        _show("LoadState=loaded\nActiveState=active\nSubState=running\n"),
        _show("LoadState=loaded\nActiveState=failed\nSubState=failed\n"),
        _show("LoadState=not-found\nActiveState=inactive\nSubState=dead\n"),
        _show("LoadState=loaded\nActiveState=inactive\nSubState=dead\n"),
        subprocess.TimeoutExpired(cmd="systemctl", timeout=3),
    ]

    result = check_services(["nginx", "mysql", "redis", "apache2", "slow"])

    assert [r["state"] for r in result] == ["running", "failed", "not_found", "stopped", "unknown"]
    assert result[0]["detail"] == "active/running"

    # Fixed argument list, never a shell.
    args, kwargs = mock_run.call_args_list[0]
    assert args[0][:3] == ["/usr/bin/systemctl", "show", "nginx"]
    assert "shell" not in kwargs
    assert kwargs["timeout"] == 3


@patch("serverscope_agent.services.shutil.which", return_value=None)
@patch("serverscope_agent.services.psutil.process_iter")
def test_process_fallback_without_systemd(mock_iter, mock_which):
    mock_iter.return_value = [SimpleNamespace(info={"name": "nginx"})]

    result = check_services(["nginx.service", "mysqld"])

    assert result[0]["state"] == "running"
    assert result[1]["state"] == "stopped"

from unittest.mock import patch
from types import SimpleNamespace

from serverscope_agent.collector import collect_metrics


@patch("serverscope_agent.collector.psutil.disk_partitions", return_value=[])
@patch("serverscope_agent.collector.psutil.getloadavg", return_value=(0.75, 0.5, 0.25))
@patch("serverscope_agent.collector.psutil.cpu_percent", return_value=42.5)
@patch(
    "serverscope_agent.collector.psutil.virtual_memory",
    return_value=SimpleNamespace(
        total=8000,
        used=4000,
        available=4000,
    ),
)
@patch(
    "serverscope_agent.collector.psutil.swap_memory",
    return_value=SimpleNamespace(total=1000, used=250),
)
@patch(
    "serverscope_agent.collector.psutil.net_io_counters",
    return_value=SimpleNamespace(bytes_recv=125000, bytes_sent=85000),
)
@patch("serverscope_agent.collector.psutil.boot_time", return_value=1000)
@patch("serverscope_agent.collector.time.time", return_value=1100)
def test_collect_metrics_returns_expected_values(
    mock_time,
    mock_boot_time,
    mock_network,
    mock_swap,
    mock_memory,
    mock_cpu,
    mock_load,
    mock_partitions,
):
    result = collect_metrics()

    assert result["cpu_percent"] == 42.5
    assert result["load_1"] == 0.75
    assert result["memory_total_bytes"] == 8000
    assert result["memory_used_bytes"] == 4000
    assert result["swap_used_bytes"] == 250
    assert result["network_rx_bytes"] == 125000
    assert result["network_tx_bytes"] == 85000
    assert result["uptime_seconds"] == 100
    assert result["disks"] == []
    assert result["agent_version"] == "0.1.0"
    assert result["collected_at"].endswith("+00:00")

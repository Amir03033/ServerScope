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
    assert result["agent_version"] == "0.3.1"
    assert len(result["agent_checksum"]) == 64
    assert result["collected_at"].endswith("+00:00")


@patch("serverscope_agent.collector.psutil.disk_partitions", return_value=[])
@patch(
    "serverscope_agent.collector.psutil.net_io_counters",
    return_value=SimpleNamespace(bytes_recv=1, bytes_sent=2, errin=3, errout=4, dropin=5, dropout=6),
)
@patch(
    "serverscope_agent.collector.psutil.disk_io_counters",
    return_value=SimpleNamespace(read_bytes=1000, write_bytes=2000),
)
@patch("serverscope_agent.collector.psutil.pids", return_value=[1, 2, 3])
@patch("serverscope_agent.collector.psutil.cpu_percent", return_value=1.0)
def test_collect_metrics_includes_extended_counters(mock_cpu, mock_pids, mock_disk_io, mock_network, mock_partitions):
    result = collect_metrics()

    assert result["network_errors_in"] == 3
    assert result["network_drops_out"] == 6
    assert result["disk_read_bytes"] == 1000
    assert result["disk_write_bytes"] == 2000
    assert result["process_count"] == 3


@patch("serverscope_agent.collector.psutil.disk_partitions", return_value=[])
@patch("serverscope_agent.collector.psutil.disk_io_counters", side_effect=RuntimeError("no disks"))
@patch("serverscope_agent.collector.psutil.cpu_percent", return_value=1.0)
def test_missing_counters_are_sent_as_null(mock_cpu, mock_disk_io, mock_partitions):
    result = collect_metrics()

    assert result["disk_read_bytes"] is None
    assert result["disk_write_bytes"] is None


@patch("serverscope_agent.collector.psutil.disk_partitions", return_value=[])
@patch("serverscope_agent.collector.psutil.cpu_count", return_value=8)
@patch("serverscope_agent.collector.psutil.cpu_percent", return_value=1.0)
def test_collect_metrics_reports_logical_cpu_count(mock_cpu, mock_count, mock_partitions):
    result = collect_metrics()

    assert result["cpu_count"] == 8
    mock_count.assert_called_once_with(logical=True)


@patch("serverscope_agent.collector.psutil.disk_partitions", return_value=[])
@patch("serverscope_agent.collector.psutil.cpu_count", return_value=None)
@patch("serverscope_agent.collector.psutil.cpu_percent", return_value=1.0)
def test_unknown_cpu_count_is_sent_as_null(mock_cpu, mock_count, mock_partitions):
    assert collect_metrics()["cpu_count"] is None

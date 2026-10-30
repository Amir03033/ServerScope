"""Test helper for scripts/e2e/update-test.sh: plays a COMPROMISED
ServerScope server. Terminates TLS and forwards every request to the
local ServerScope, except what the test puts in <evil dir>:

  offer               raw JSON body returned for GET /api/agent/update
  files/<url path>    returned instead of that download

Every request is logged as "METHOD path" to <evil dir>/requests.log, so a
test can prove that the updater made no request at all.

Usage: python3 evil_proxy.py <listen port> <backend port> <cert> <key> <evil dir>
"""

import http.client
import os
import ssl
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

LISTEN, BACKEND, CERT, KEY, EVIL = int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5]
LOCK = threading.Lock()
HOP = {"connection", "keep-alive", "transfer-encoding", "te", "trailer", "upgrade", "proxy-authorization", "proxy-authenticate"}


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def _send(self, status, body, content_type):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

    def _handle(self):
        path = urlsplit(self.path).path
        with LOCK, open(os.path.join(EVIL, "requests.log"), "a") as log:
            log.write(f"{self.command} {path}\n")

        offer = os.path.join(EVIL, "offer")
        if self.command == "GET" and path == "/api/agent/update" and os.path.isfile(offer):
            return self._send(200, open(offer, "rb").read(), "application/json")

        replacement = os.path.normpath(os.path.join(EVIL, "files", path.lstrip("/")))
        if replacement.startswith(os.path.join(EVIL, "files") + os.sep) and os.path.isfile(replacement):
            return self._send(200, open(replacement, "rb").read(), "application/octet-stream")

        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else None
        headers = {k: v for k, v in self.headers.items() if k.lower() not in HOP}
        connection = http.client.HTTPConnection("127.0.0.1", BACKEND, timeout=60)
        connection.request(self.command, self.path, body=body, headers=headers)
        response = connection.getresponse()
        data = response.read()
        self.send_response(response.status)
        for key, value in response.getheaders():
            if key.lower() not in HOP and key.lower() != "content-length":
                self.send_header(key, value)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(data)
        self.close_connection = True
        connection.close()

    do_GET = do_POST = do_HEAD = do_PUT = do_DELETE = _handle


def main():
    os.makedirs(os.path.join(EVIL, "files"), exist_ok=True)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(CERT, KEY)
    server = ThreadingHTTPServer(("127.0.0.1", LISTEN), Handler)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()


if __name__ == "__main__":
    main()

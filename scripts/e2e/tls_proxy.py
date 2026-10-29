"""Test helper: terminates TLS on a local port and forwards plain TCP to
the PHP development server. Only for scripts/e2e/install-test.sh."""

import socket
import ssl
import sys
import threading


def pipe(source, target):
    try:
        while True:
            data = source.recv(65536)
            if not data:
                break
            target.sendall(data)
    except OSError:
        pass
    finally:
        # End TLS properly (close_notify): OpenSSL 3 clients treat a bare
        # TCP close as an error.
        if isinstance(target, ssl.SSLSocket):
            try:
                target.unwrap()
            except (ssl.SSLError, OSError, ValueError):
                pass
        for sock in (source, target):
            try:
                sock.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass


def main():
    listen_port, backend_port, cert, key = int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], sys.argv[4]
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(cert, key)
    server = socket.create_server(("127.0.0.1", listen_port), reuse_port=False)
    while True:
        client, _ = server.accept()
        try:
            tls = context.wrap_socket(client, server_side=True)
        except (ssl.SSLError, OSError):
            client.close()
            continue
        backend = socket.create_connection(("127.0.0.1", backend_port))
        threading.Thread(target=pipe, args=(tls, backend), daemon=True).start()
        threading.Thread(target=pipe, args=(backend, tls), daemon=True).start()


if __name__ == "__main__":
    main()

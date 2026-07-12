import socket

targets = [
    ("1.1.1.1", 443),
    ("8.8.8.8", 53),
    ("91.189.91.81", 80),
    ("91.189.92.23", 80),
    ("185.125.190.81", 80),
    ("52.58.199.22", 80),
]

for host, port in targets:
    try:
        sock = socket.create_connection((host, port), timeout=3)
        sock.close()
        print(f"{host}:{port} OK")
    except Exception as exc:
        print(f"{host}:{port} FAIL {type(exc).__name__}: {exc}")

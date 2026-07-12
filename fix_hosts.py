from pathlib import Path

p = Path("/etc/hosts")
text = p.read_text()
block = (
    "\n"
    "91.189.91.81 nova.clouds.archive.ubuntu.com\n"
    "91.189.91.81 archive.ubuntu.com\n"
    "91.189.91.81 security.ubuntu.com\n"
    "52.58.199.22 nginx.org\n"
)

if block not in text:
    p.write_text(text + block)

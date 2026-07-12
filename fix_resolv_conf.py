from pathlib import Path

Path("/etc/resolv.conf").write_text(
    "\n".join(
        [
            "nameserver 163.44.76.148",
            "nameserver 157.7.180.133",
            "search myvps.jp",
            "options edns0 trust-ad",
            "",
        ]
    )
)

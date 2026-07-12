# SSH Connection Rules

This project uses SSH to operate the initial VPS deployment environment.

## Target

- Host: `loose.bz`
- Port: `22224`
- User: `codex`

## Basic Command

Use a private key stored outside this repository.

```bash
ssh -i <path-to-private-key> -p 22224 codex@loose.bz
```

For local machine-specific paths, use `docs.local/`.
`docs.local/` is intentionally excluded from Git.

## Key Rules

- Do not place private keys in this repository.
- Do not commit private keys, passwords, or production `.env` files.
- Keep private keys under the user's SSH directory, such as `~/.ssh/`.
- Register only public keys in `/home/codex/.ssh/authorized_keys` on the server.
- Use the `codex` user for project operations.
- Use `sudo` only for system-level operations such as nginx, certbot, or PostgreSQL administration.

## Project Paths On Server

- App root: `/home/codex/1g1a/web`
- Public root: `/home/codex/1g1a/web/public`
- Server env file: `/home/codex/1g1a/web/config/db.env`

## Related Docs

- `docs/deployment-vps.md`
- `docs/local-docker-db.md`

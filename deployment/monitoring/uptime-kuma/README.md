# Uptime Kuma for Procynia

## Purpose

Uptime Kuma is used for uptime monitoring of Procynia and critical dependencies. The service runs as its own Docker container and is designed to move between Azure, Google Cloud, AWS, and on-premise environments without changing the compose file.

## Architecture

User or alert source
-> HTTPS reverse proxy
-> Uptime Kuma on `127.0.0.1:3001`
-> persistent Docker volume for monitor data and configuration

## Why It Is Vendor-Neutral

- The same `compose.yaml` is used in every environment.
- The cloud platform only provides a VM or server, disk, DNS, and firewall.
- Kuma data lives in a Docker volume and must be included in backups.

## Installation

1. Install Docker Engine and Docker Compose on the server.
2. Copy this folder to the server.
3. Copy `.env.example` to `.env` and set the timezone and public host.
4. Start the service with `docker compose up -d`.
5. Put a reverse proxy and HTTPS in front of the service.
6. Open Uptime Kuma through the chosen domain name.
7. Create the first admin user inside Uptime Kuma.

## First Monitors

- Procynia web
- Procynia health endpoint
- Procynia admin login
- Eventual webhook endpoints
- Critical external dependencies

## Alerting

Configure alerting manually in Uptime Kuma after the first admin user is created. Typical channels can be email, Teams, Slack, or another chosen operations channel.

## Backup

The `uptime-kuma-data` Docker volume must be included in backup. It contains monitors, history, alerts, and Kuma configuration.

## Update

Standard update flow:

```bash
docker compose pull
docker compose up -d --force-recreate
```

## Security

- Do not expose port 3001 directly to the public internet.
- Use a reverse proxy with HTTPS.
- Use a dedicated subdomain such as `status.example.com`.
- Restrict access with firewall rules where possible.
- Do not store secrets in the repository.

## Moving Between Environments

To move from Azure to AWS, Google Cloud, or on-premise, move `compose.yaml`, `.env`, and the backup of the Docker volume. DNS then points to the new server. The Uptime Kuma setup itself stays unchanged.

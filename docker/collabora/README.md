# Collabora Online for DocStudio: installation on a server

[Collabora Online](https://www.collaboraonline.com/) Development Edition (CODE) with Docker Compose, as the office
server of DocStudio (WOPI). CODE is the free development edition (it shows a notice to the users); Collabora sells a
supported edition for production.

> **WARNING: commands to run on a server, as root.** A mistake can stop Dolibarr or other sites of the server, or open
> them to anybody. Only do it if you know what you are doing, after a backup of the configuration, or have it done by
> a qualified system administrator. Given as they are, without any warranty.
>
> **Every value of this page is an EXAMPLE**: replace it with the one of your server.

Read first [the common page](../README.md): requirements, why a subdomain in HTTPS, access of the office server to
Dolibarr (`.htaccess`), setup of DocStudio, common troubleshooting.

| Example value | What it is | Where it is used |
|---|---|---|
| `office.example.com` | Subdomain of the office server | DNS, certificate, reverse proxy, DocStudio setup |
| `https://dolibarr\.example\.com:443` | Address of Dolibarr allowed to use the office server (`CO_ALIASGROUP`) | `.env` |
| `/srv/collabora` | Folder of the installation (outside of any web root) | All commands |
| `compose.sample.yml` | Sample of the Docker Compose file, copied to `compose.yml` and adapted | Steps 1 and 2 |
| `9980` | Local port of the container (`CO_PORT`) | `.env`, reverse proxy |
| `172.30.1.0/24` | Network of the container | `compose.yml`, access rules of Dolibarr, DocStudio setup |
| `proof_key` | Key that signs the calls of the office server | Step 1 |

Tested with DocStudio: Collabora Online CODE 26.04.6.1.

## 1. Files and proof key

In a folder outside of any web root (here `/srv/collabora`):

```bash
mkdir -p /srv/collabora && cd /srv/collabora
# copy compose.sample.yml and .env.example of this folder here (or create them with their content)
cp compose.sample.yml compose.yml
cp .env.example .env
chmod 600 .env
# set CO_ALIASGROUP in .env: the address of your Dolibarr, dots escaped, with its port
```

**Proof key.** The image of Collabora has no key to sign its calls: without it, DocStudio cannot check that the calls
come from your office server. Create it once, readable by the user 1001 of the image:

```bash
cd /srv/collabora
ssh-keygen -t rsa -N "" -m PEM -f proof_key && rm -f proof_key.pub
chown 1001:1001 proof_key && chmod 400 proof_key
head -1 proof_key                     # -----BEGIN RSA PRIVATE KEY-----
```

Keep this file (backups): a new key is refused by Dolibarr until its **Test** button is clicked.

**Only your Dolibarr.** `CO_ALIASGROUP` (`aliasgroup1` of the container) is the list of the WOPI hosts allowed:
Collabora refuses to open the files of any other site. It is a regular expression: dots escaped (`\.`), scheme and
port included (`https://dolibarr\.example\.com:443`). Several addresses of Dolibarr: `aliasgroup2`, `aliasgroup3`...
in `compose.yml`. No filter in the proxy is needed, unlike ONLYOFFICE and Euro-Office.

## 2. Network of the container

In `compose.yml`, `networks > default > ipam > subnet` fixes the range of the container. Choose a range that is free on
the server (`docker network ls`, `docker network inspect <name>`, `ip route`). It must stay the same: Dolibarr allows
the calls of the office server from this range.

## 3. Start

```bash
cd /srv/collabora
docker compose up -d
docker compose logs -f                # wait for « Ready to accept connections », then Ctrl+C
curl -s http://127.0.0.1:9980/hosting/discovery | grep -c proof-key   # 1
docker network inspect docstudio-collabora_default -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
```

The network is named after `name:` of `compose.yml` (`docstudio-collabora_default`), not after the folder.

The admin console stays off: no `username` / `password` in `compose.yml`, and the proxy below refuses it.

## 4. Reverse proxy (HTTPS)

Create the subdomain `office.example.com` with its certificate. With Plesk, put it in a subscription other than the
one of Dolibarr (a subdomain with hosting is needed for the certificate and the directives).

Then send it to the container. With nginx (with Plesk: the subdomain, « Apache & nginx Settings », « Additional nginx
directives »):

```nginx
# Admin console of Collabora: never from outside
location ~ ^/(browser/dist/admin|cool/adminws) {
    return 403;
}

location ~ ^/ {
    proxy_pass http://127.0.0.1:9980;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 3600s;
    client_max_body_size 100m;
}
```

- `location ~ ^/` (a regular expression) and not `location /`: Plesk already has a `location /`, a second one is refused
  (« duplicate location »).
- `Upgrade` and `Connection`: the WebSocket of the editor. Without them, the editor stays on « Loading ».
- `--o:ssl.termination=true` in `compose.yml`: the proxy does the HTTPS, Collabora builds its links in HTTPS.

Check from any computer: `https://office.example.com/hosting/discovery` gives an XML with a `<proof-key ...>` tag.

## 5. Dolibarr and DocStudio

See [the common page](../README.md): `<Files "wopi.php">` when Dolibarr is behind a password of the web server, with
the range of step 2, then the setup of DocStudio (address `https://office.example.com`, Test button).

## Troubleshooting

See also [the common troubleshooting](../README.md#common-troubleshooting).

| What you see | Cause | What to do |
|---|---|---|
| « Unauthorized WOPI host » | Address of Dolibarr not in `CO_ALIASGROUP` | The address the office server calls (scheme, host, port), dots escaped, then `docker compose up -d` |
| Test of DocStudio: calls not signed | No `proof_key`, or not readable by the user 1001 | Step 1, `chown 1001:1001 proof_key` |
| Container stopping at start | `proof_key` unreadable, or configuration refused | `docker compose logs` |

## Maintenance

```bash
cd /srv/collabora
docker compose logs -f                      # logs (Ctrl+C to quit)
docker compose ps                           # state
```

- **Update:** the image is pinned by its digest (Collabora also publishes version tags, `collabora/code:X.Y.Z.W` can
  be used instead). Put the new digest or tag in `compose.yml`, then `docker compose pull && docker compose up -d`.
  Keep `proof_key`.
- **Backup:** `compose.yml`, `.env` and `proof_key` (private).

# Euro-Office for DocStudio: installation on a server

[Euro-Office Docs](https://github.com/Euro-Office) with Docker Compose, as the office server of DocStudio (WOPI).

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
| `dolibarr.example.com` | Address of Dolibarr, as the users open it | WOPISrc filter of the proxy |
| `/srv/eurooffice` | Folder of the installation (outside of any web root) | All commands |
| `compose.sample.yml` | Sample of the Docker Compose file, copied to `compose.yml` and adapted | Steps 1 and 2 |
| `9982` | Local port of the container (`EO_PORT`) | `.env`, reverse proxy |
| `172.30.0.0/24` | Network of the container | `compose.yml`, access rules of Dolibarr, DocStudio setup |
| `JWT_SECRET` | Secret of the API of the office server | `.env` |

Tested with DocStudio: Euro-Office 9.3.7 (image of 4.5 GB).

## 1. Files

In a folder outside of any web root (with Plesk, for example a folder next to `httpdocs` of the subdomain; here
`/srv/eurooffice`):

```bash
mkdir -p /srv/eurooffice && cd /srv/eurooffice
# copy compose.sample.yml and .env.example of this folder here (or create them with their content)
cp compose.sample.yml compose.yml
cp .env.example .env
sed -i "s/^JWT_SECRET=.*/JWT_SECRET=$(openssl rand -hex 32)/" .env
chmod 600 .env
grep -c '^JWT_SECRET=.\{64\}$' .env    # must print 1
```

The keys of the office server (proof keys) are created in `Data/` next to `compose.yml` on the first start.

## 2. Network of the container

In `compose.yml`, `networks > default > ipam > subnet` fixes the range of the container. Choose a range that is free on
the server (`docker network ls`, `docker network inspect <name>`, `ip route`). It must stay the same: Dolibarr allows
the calls of the office server from this range.

## 3. Start

```bash
cd /srv/eurooffice
docker compose up -d
docker compose ps                     # wait for "healthy": 1 to 5 minutes on the first start
curl -s http://127.0.0.1:9982/healthcheck                            # true
curl -s http://127.0.0.1:9982/hosting/discovery | grep -c proof-key  # 1
ls -la Data/                          # wopi_private.key and wopi_public.key
docker network inspect docstudio-eurooffice_default -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
```

The network is named after `name:` of `compose.yml` (`docstudio-eurooffice_default`), not after the folder.

The welcome page of the office server offers to start a test example: do not start it, it is a small file manager
open to anybody who knows the address.

## 4. Reverse proxy (HTTPS)

Create the subdomain `office.example.com` with its certificate. With Plesk, put it in a subscription other than the
one of Dolibarr (a subdomain with hosting is needed for the certificate and the directives).

Then send it to the container. With nginx (with Plesk: the subdomain, « Apache & nginx Settings », « Additional nginx
directives »), the WOPI block **first**, then the general block:

```nginx
# WOPI: only for the files of our Dolibarr (replace dolibarr\.example\.com, keep the \ before the dots)
location ~ ^/hosting/wopi/ {
    if ($args ~* "wopisrc=.*wopisrc=") { return 403; }
    if ($arg_wopisrc !~* "^https(%3A|:)(%2F|/){2}dolibarr\.example\.com(%2F|/)") { return 403; }
    proxy_pass http://127.0.0.1:9982;
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

location ~ ^/ {
    proxy_pass http://127.0.0.1:9982;
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
- `X-Forwarded-Proto`: the office server builds its links in HTTPS.
- The WOPI block: without it, any site can use your office server for its own files (WOPI is not protected by the
  JWT secret, which only covers the other API of the office server). It refuses a `WOPISrc` that is not your Dolibarr,
  a missing one, and a doubled one (a trick to fool the filter).

Checks from any computer:

- `https://office.example.com/hosting/discovery`: an XML with a `<proof-key ...>` tag.
- `https://office.example.com/hosting/wopi/word/edit?WOPISrc=https%3A%2F%2Fexample.org%2F`: error 403.

## 5. Dolibarr and DocStudio

See [the common page](../README.md): `<Files "wopi.php">` when Dolibarr is behind a password of the web server, with
the range of step 2, then the setup of DocStudio (address `https://office.example.com`, Test button).

The file is saved when the editor is closed (not by Ctrl+S).

## Troubleshooting

See also [the common troubleshooting](../README.md#common-troubleshooting).

| What you see | Cause | What to do |
|---|---|---|
| Error 403 when opening the editor | `WOPISrc` filter with another address of Dolibarr | Address of the filter = the one the users open, `https`, dots escaped |
| Container restarting, « Starting nginx ... fail! » in the logs | Folder mounted on `/var/log/euro-office` | Do not mount the logs, read them with `docker compose logs` |
| Office server cannot reach Dolibarr on a private address | Private addresses refused by default | Public address of Dolibarr, or `ALLOW_PRIVATE_IP_ADDRESS: "true"` in `compose.yml` (lets the office server call private addresses: only on a closed network) |

## Maintenance

```bash
cd /srv/eurooffice
docker compose logs -f                      # logs (Ctrl+C to quit)
docker compose ps                           # state
```

- **Update:** Euro-Office only publishes the tag `latest`, the image is pinned by its digest. Put the new digest in
  `compose.yml`, then `docker compose pull && docker compose up -d`. Keep `Data/`: new keys are refused by Dolibarr
  until its **Test** button is clicked (or one hour later).
- **Cache of the documents:** removed by the office server 24 hours after the last use. To empty it now (open
  documents saved first):

  ```bash
  docker compose exec documentserver documentserver-prepare4shutdown.sh
  docker compose up -d --force-recreate
  ```

  `documentserver-flush-cache.sh` does something else: browsers load again the scripts of the editor (after an
  update).
- **Backup:** `compose.yml`, `.env` and `Data/` (keys and secrets). `.env` and `Data/` are private.
- **JWT secret:** do not change it without reason. Open editions are lost on the restart, nothing else.

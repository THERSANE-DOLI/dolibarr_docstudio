# Office servers for DocStudio

DocStudio opens and edits the Office and OpenDocument files of Dolibarr in the browser with an office server (WOPI),
saved in place with the rights of each user. This folder holds samples to install one of them with Docker Compose:

| Folder | Office server | Notes |
|---|---|---|
| [eurooffice/](eurooffice/README.md) | Euro-Office Docs | European fork of ONLYOFFICE, same editor |
| [onlyoffice/](onlyoffice/README.md) | ONLYOFFICE Docs Community | Community Edition: 20 simultaneous connections at most |
| [collabora/](collabora/README.md) | Collabora Online (CODE) | Editor of LibreOffice; CODE is the development edition, a support offer exists for production |

One office server is enough: DocStudio uses the one given in its setup.

> **WARNING: commands to run on a server, as root.**
> They change the web server, the reverse proxy, the access rules of Dolibarr and the Docker containers of the
> machine. A mistake can stop Dolibarr or other sites of the server, or open them to anybody. Only do it if you know
> what you are doing, after a backup of the configuration, or have it done by a qualified system administrator.
> These instructions are given as they are, without any warranty.

> **Every value of these pages is an EXAMPLE** (`office.example.com`, `dolibarr.example.com`, folders, ports, networks,
> secrets). Replace them with the ones of your server before running anything.

## Common requirements

- Linux server with Docker and Docker Compose v2 (`docker compose`, else `docker-compose` with a dash).
- 4 GB of free memory and 2 cores at least, a few GB of disk for the image.
- A subdomain for the office server, in HTTPS with a valid certificate (Let's Encrypt is fine).
- DocStudio on Dolibarr, Dolibarr in HTTPS.

**Why a subdomain in HTTPS:** the browser of each user loads the editor directly from the office server (a frame
inside Dolibarr, plus a WebSocket). The office server must therefore be reachable by all the users, wherever they
work, and in HTTPS: a frame in HTTP inside Dolibarr in HTTPS is blocked by the browser. Its own subdomain is the
cleanest setup: the editor does not share the origin of Dolibarr.

**Why not a list of IP addresses of the users:** the users who work from home, travel or use a phone have changing
addresses. The protection is the restriction of the office server to your Dolibarr (WOPISrc filter of the proxy, or
the alias group of Collabora) plus the tokens and signatures of DocStudio. A list of addresses can be added in the
proxy only if all the users come from known addresses (office, VPN).

**Ports:** each container only listens on `127.0.0.1` (port 9982, 9981 or 9980). The ports 80 and 443 of the server
stay to its web server, which sends the subdomain to the container (reverse proxy).

## Access of the office server to Dolibarr

The office server reads and saves the files by calling `custom/docstudio/wopi.php` of Dolibarr, **without the
session nor the password of the user** (a token valid 4 hours and the signature of the office server protect the
call). When the office server is on the same server as Dolibarr, Dolibarr sees the call coming from the network of
the container (the fixed `subnet` of `compose.yml`), else from the public address of the Docker server.

**Dolibarr behind a password of the web server** (`.htaccess` with `AuthType Basic`, Plesk « Password-protected
directories »...): the calls of the office server are refused with an error 401 (the editor shows « Invalid access
token »). Let `wopi.php` through for the network of the container only, the rest of Dolibarr keeps its password. With
Apache, in the `.htaccess` of Dolibarr or, with Plesk, in « Additional Apache directives » (HTTP and HTTPS) of the
domain of Dolibarr (replace the range with the one of your `compose.yml`):

```apache
<Files "wopi.php">
    <RequireAny>
        Require ip 172.30.0.0/24
        Require valid-user
    </RequireAny>
</Files>
```

## Setup of DocStudio

Setup > Modules > DocStudio, « Office server (optional) »:

- « Address of the office server »: `https://office.example.com`.
- « Address of Dolibarr seen by the office server »: empty, unless the office server reaches Dolibarr by another
  address than the users.
- « Addresses allowed to call Dolibarr »: the range of the container (same server) or the address of the Docker
  server, as Dolibarr sees it (access log of Dolibarr, calls to `wopi.php`).
- Save, then **Test**: « Office server reached » and « Signature of the office server checked ».

Then open an Office file of Dolibarr with « Edit with DocStudio », change it, close the editor and check that the file
is saved.

## Common troubleshooting

| What you see | Cause | What to do |
|---|---|---|
| Empty frame, error of mixed content in the console | Office server in HTTP | HTTPS with a valid certificate |
| Editor stuck on « Loading » | WebSocket not let through by the proxy | Headers `Upgrade` and `Connection` in the proxy |
| « Invalid access token » / 401 of a few KB (an HTML page) in the access log of Dolibarr | Password of the web server in front of Dolibarr | `<Files "wopi.php">` above |
| « Invalid access token » / 401 with an empty body | Keys of the office server changed (container recreated without its keys), or tokens revoked or expired | **Test** button of DocStudio (reads the keys again), open the file again |
| « Resource not found or user unauthorized » (404) on an attachment of inbox | Mail of a protected mailbox: only the session of its user may read it | Normal: the office server is not offered for them (DocStudio 0.19.3 and more) |
| Calls of the office server refused after `docker compose down` / `up` | Range of the network changed | Fixed `subnet` in `compose.yml` |
| Office server cannot reach Dolibarr | Dolibarr only reachable by a private address, refused by the office server | Public address of Dolibarr (see the README of the office server for the private addresses) |

Dolibarr writes the refusals of `wopi.php` (address not allowed, bad signature) in its log when the module Logs is on
(level Warning).

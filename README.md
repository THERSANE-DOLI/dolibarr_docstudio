# DocStudio for [Dolibarr ERP & CRM](https://www.dolibarr.org)

View the documents of Dolibarr without leaving it.

## Features

- File associations: for each file type, the administrator chooses what opens it from the magnifier of the lists of
  documents (Documents tabs, Documents block of the cards, ECM): a viewer of DocStudio, the preview of Dolibarr or no
  preview. He may leave some choices to the users, on their user card.
- The viewer opens in the preview dialog of Dolibarr (Ctrl+click: in a new tab).
- Built-in viewers, nothing else to install:
  - PDF (pdf.js, embedded: pages, text selection, zoom, search, thumbnails, print);
  - images (PNG, JPEG, GIF, WebP, BMP, AVIF, SVG);
  - CSV and TSV as a sortable table;
  - text files (TXT, LOG, JSON, XML, HTML source, EML source...).
- PDF editor, without any server: texts (fonts of the setup), drawing, highlight (also straight lines), images,
  pages (rotate, delete, reorder, split into several files), crop and redaction (pages redacted turned into images, result checked). Saved as a copy or instead of the
  original, with the permission to change the documents of the object.
- Office server (optional): Collabora Online, OnlyOffice or Euro-Office (WOPI) open and edit the Office and
  OpenDocument files in place, signature of their calls checked.
- Office installed on the computer (optional): « Open in Excel, Word, PowerPoint » by a signed address valid on
  that computer only (small WebDAV server `dav.php`), saved in place.
- Same permissions as the download: a user who cannot download a file cannot view it.
- Other modules can add viewers for other file types, and sources of files other than the documents of Dolibarr.

## Security

- Every request checks the permissions again on the server, like `document.php` (`dol_check_secure_access_document()`).
- Paths with `..`, absolute paths, hidden files and files outside of the data directory are refused.
- External users need the permission « View the documents of their own thirdparty with DocStudio », and only see
  the documents of their own thirdparty (documents without a known owner, like the ECM, are refused to them).
- The content of a file is always sent as a download of unknown type with a sandbox policy: opening its URL directly
  never runs it. It can only be read by a page of the same site.
- The viewer page has a strict Content Security Policy: no request outside of Dolibarr, nothing from the file can run
  (no JavaScript of PDF files, no XFA forms, no active links, SVG shown as an image only, text never rendered as HTML).

## For developers

Declare a viewer in the hook `docstudioRegisterViewers` (context `docstudio`), `$object` being the registry:

```php
public function docstudioRegisterViewers($parameters, &$object, &$action, $hookmanager)
{
	$object->registerViewer(array(
		'code' => 'mymodule_dwg',
		'label' => $langs->trans('MyViewer'),
		'extensions' => array('dwg'),
		'script' => '/mymodule/js/dwgviewer.js',	// JavaScript module
		'priority' => 60,
	));
	return 0;
}
```

then register its implementation in the script:

```js
window.DocStudio.registerViewer('mymodule_dwg', {
	async mount(ctx) {
		const buffer = await ctx.file.arrayBuffer();
		// ... fill ctx.container with DOM methods (never innerHTML with content of the file)
		return { unmount() {} };
	}
});
```

Sources of files: hook `docstudioRegisterProviders`, `$object->registerProvider('code', 'MyProviderClass', '/mymodule/class/myprovider.class.php')`
with a class extending `DocStudioFileProvider` (see its documentation: the provider checks the permissions in `load()`).

JavaScript hooks (when the JavaScript context of Dolibarr is available, Dolibarr 24 or module BackportDolContext):

- `await Dolibarr.executeHookAwait('docstudio:beforeOpen', ctx)`: change `ctx.viewer` (among `ctx.viewers`) or set
  `ctx.cancel = true` and `ctx.reason`;
- `Dolibarr.executeHook('docstudio:opened', {source, file, viewer})`.

## Requirements

Dolibarr 23 or more, PHP 7.4 or more.

## Installation

- Zip: `module_docstudio-X.Y.Z.zip` of the
  [GitHub releases](https://github.com/THERSANE-DOLI/dolibarr_docstudio/releases/latest), deployed in Setup > Modules >
  Deploy an external module.
- Git deployment (Plesk, `git pull`...): clone the repository in `htdocs/custom/docstudio` and follow the branch that
  fits the instance:

| Branch | What it holds | Use it for |
|---|---|---|
| `stable` | the last published version (the one of the latest release) | **production** (recommended) |
| `X.Y` (`0.19`...) | one published series and its fixes only | a production that must stay on a series |
| `develop` | the work in progress, with the tests | development only |

`stable` and `X.Y` only go forward, a `git pull` is always enough. They also hold `test/` and `build/` (left out of the
zip): not used by the module, they can be removed or refused by the web server. As after any update of a module,
disable and enable the module again when its version changed, so that its database updates run.

## Office server in production

The office server is optional. The browser of the user loads the editor directly from the office server (frame and
WebSocket): the server must be reachable by the users, in HTTPS with a valid certificate (a frame in HTTP inside
Dolibarr in HTTPS is blocked by the browser). Give it its own subdomain (`office.example.com`): it is the cleanest
setup, the editor does not share the origin of Dolibarr.

Euro-Office with Docker Compose (`docker/eurooffice/`, 4 GB of memory and 2 cores at least, image of 4.5 GB):

1. On the server, in a folder outside of the web root (with Plesk: the folder of the subdomain,
   `/var/www/vhosts/office.example.com/`, next to `httpdocs`), create `compose.yml` with the content of
   `docker/eurooffice/compose.yml` and `.env` with the one of `.env.example`, then `chmod 600 .env` and set
   `JWT_SECRET` (`openssl rand -hex 32`). The keys go in `dockerdata/Data` next to them.
2. `docker compose up -d`, then `docker compose ps` until the state is `healthy` (about 1 minute on the first start).
   The container only listens on `127.0.0.1:9982`.
3. Create the subdomain with its certificate (Let's Encrypt) and send it to `http://127.0.0.1:9982` with a reverse
   proxy that lets the WebSockets go through. With Plesk: the subdomain in its own subscription (not the one of
   Dolibarr), SSL/TLS certificate, then Docker > Docker Proxy Rules (or additional nginx directives with
   `proxy_pass`, `proxy_http_version 1.1`, the headers `Upgrade`, `Connection "upgrade"`, `Host` and
   `X-Forwarded-Proto`).
   Without more, any site can use this office server for its own files (WOPI is not covered by the JWT secret):
   refuse in the proxy the WOPI pages whose `WOPISrc` is not your Dolibarr, before the general rule (nginx):

   ```nginx
   location ~ ^/hosting/wopi/ {
       if ($args ~* "wopisrc=.*wopisrc=") { return 403; }
       if ($arg_wopisrc !~* "^https(%3A|:)(%2F|/){2}dolibarr\.example\.com(%2F|/)") { return 403; }
       # same proxy_pass and headers as the general rule
   }
   ```

4. Check `https://office.example.com/hosting/discovery`: it must give an XML with a `proof-key`.
5. In Setup > Modules > DocStudio, « Address of the office server »: `https://office.example.com`, then the Test button.
   « Addresses allowed to call Dolibarr »: the address the office server calls Dolibarr from, as Dolibarr sees it
   (often the public IP of the Docker server).
6. Ghostscript (compression of PDF): add `gs` to `$dolibarr_main_restrict_os_commands` in `conf/conf.php`.

The folder `dockerdata/Data` holds the keys that sign the calls of the office server (proof keys): keep it on updates, else the
calls signed with a new key are refused until Dolibarr reads the office server again (Test button of the setup, or
one hour later). Update: change the digest of the image in `compose.yml`,
then `docker compose pull && docker compose up -d`.

## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Embedded libraries

- pdf.js 5.4.624 (Mozilla, Apache 2.0), in `vendor/pdfjs`.
- pdf-lib 1.17.1 (Andrew Dillon, MIT), in `vendor/pdflib`.
- @pdf-lib/fontkit 1.1.1 (Devon Govett, Andrew Dillon, MIT), in `vendor/fontkit`.
- Fabric.js 7.4.0 (Fabric.js contributors, MIT), in `vendor/fabric`.
- @silurus/ooxml 0.89.8 (Yuki Yokotani, MIT), in `vendor/ooxml` (its own third-party notices in
  `vendor/ooxml/THIRD_PARTY_NOTICES.md`).
- Tabler Icons 3.50.0 (Pawel Kuna, MIT), some icons in `img/docstudio-icons.svg`.

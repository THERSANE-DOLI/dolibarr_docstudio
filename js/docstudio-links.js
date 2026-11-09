/* Copyright (C) 2026		John BOTELLA				<john.botella@thersane.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Pages of Dolibarr: adds « Open with DocStudio » next to the download links of document.php (Documents tabs,
 * Documents block of the cards, ECM) for the file types a viewer can show, and opens the viewer in a dialog.
 * The link only carries modulepart, file and entity, read from the link of Dolibarr: the viewer checks the
 * permissions again on the server. Ctrl+click or middle click opens the viewer in a new tab.
 */
(function () {
	'use strict';

	const dataEl = document.getElementById('docstudio-links-config');
	if (!dataEl) {
		return;
	}
	let cfg;
	try {
		cfg = JSON.parse(dataEl.textContent);
	} catch (e) {
		return;
	}
	const extensions = new Set(cfg.extensions || []);
	let dialog = null;

	/**
	 * @param {string} name File name or path
	 * @returns {string}
	 */
	function extensionOf(name) {
		const base = name.split('/').pop();
		const pos = base.lastIndexOf('.');
		return pos > 0 ? base.slice(pos + 1).toLowerCase() : '';
	}

	/**
	 * @returns {HTMLDialogElement}
	 */
	function getDialog() {
		if (dialog) {
			return dialog;
		}
		dialog = document.createElement('dialog');
		dialog.className = 'docstudio-dialog';
		dialog.setAttribute('aria-label', cfg.label);
		const bar = document.createElement('div');
		bar.className = 'docstudio-dialog-bar';
		const newTab = document.createElement('a');
		newTab.className = 'docstudio-dialog-newtab';
		newTab.target = '_blank';
		newTab.rel = 'noopener';
		newTab.textContent = cfg.newTabLabel;
		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'docstudio-dialog-close';
		close.textContent = '×';
		close.title = cfg.closeLabel;
		close.setAttribute('aria-label', cfg.closeLabel);
		close.addEventListener('click', function () {
			dialog.close();
		});
		bar.append(newTab, close);
		const frame = document.createElement('iframe');
		frame.className = 'docstudio-dialog-frame';
		frame.title = cfg.label;
		frame.setAttribute('referrerpolicy', 'no-referrer');
		dialog.append(bar, frame);
		dialog.addEventListener('close', function () {
			frame.src = 'about:blank';
		});
		dialog.addEventListener('click', function (e) {
			if (e.target === dialog) {
				dialog.close();
			}
		});
		document.body.appendChild(dialog);

		// Escape pressed inside the viewer: only a message of the viewer frame itself is accepted
		window.addEventListener('message', function (e) {
			if (e.origin === window.location.origin && e.source === frame.contentWindow && e.data && e.data.type === 'docstudio:close') {
				dialog.close();
			}
		});
		return dialog;
	}

	/**
	 * @param {string} url Viewer URL
	 */
	function openDialog(url) {
		const dlg = getDialog();
		dlg.querySelector('.docstudio-dialog-newtab').href = url;
		dlg.querySelector('.docstudio-dialog-frame').src = url + '&embed=1';
		dlg.showModal();
	}

	/**
	 * Add the links in a part of the page
	 * @param {ParentNode} root
	 */
	function scan(root) {
		root.querySelectorAll('a[href*="document.php?"]').forEach(function (link) {
			if (link.dataset.docstudio || link.classList.contains('documentpreview') || link.classList.contains('pictopreview')) {
				return;
			}
			link.dataset.docstudio = '1';
			// One link per line of the list, after the name of the file
			const line = link.closest('tr, li, .ecmjqft');
			if (!line || line.querySelector('.docstudio-open')) {
				return;
			}
			let params;
			try {
				const url = new URL(link.getAttribute('href'), window.location.href);
				// Only document.php of Dolibarr itself (not the Documents tabs like societe/document.php)
				if (url.origin !== window.location.origin || url.pathname !== cfg.documentPath) {
					return;
				}
				params = url.searchParams;
			} catch (e) {
				return;
			}
			const modulepart = params.get('modulepart') || '';
			const file = params.get('file') || '';
			// Public links (hashp) and unknown types are left alone
			if (!/^[A-Za-z0-9_]+$/.test(modulepart) || file === '' || params.get('hashp') || !extensions.has(extensionOf(file))) {
				return;
			}
			const query = new URLSearchParams({ source: 'document', modulepart: modulepart, file: file });
			if (params.get('entity')) {
				query.set('entity', params.get('entity'));
			}
			const viewUrl = cfg.viewUrl + '?' + query.toString();

			const open = document.createElement('a');
			open.className = 'docstudio-open paddingright marginleftonly';
			open.href = viewUrl;
			open.title = cfg.label;
			open.setAttribute('aria-label', cfg.label);
			const icon = document.createElement('span');
			icon.className = 'fas fa-eye docstudio-open-icon';
			open.appendChild(icon);
			open.addEventListener('click', function (e) {
				if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0 || typeof HTMLDialogElement === 'undefined') {
					return;
				}
				e.preventDefault();
				openDialog(viewUrl);
			});
			link.after(open);
		});
	}

	function start() {
		scan(document);
		// Content added in ajax by Dolibarr (hook initNewContent of the JavaScript context)
		if (window.Dolibarr && typeof window.Dolibarr.on === 'function') {
			window.Dolibarr.on('initNewContent', function () {
				scan(document);
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();

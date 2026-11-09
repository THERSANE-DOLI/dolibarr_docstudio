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
 * Pages of Dolibarr: lists of documents (Documents tabs, Documents block of the cards, ECM). For each file type, the
 * handler chosen by the administrator (or by the user when allowed) opens the file: preview of Dolibarr, a viewer of
 * DocStudio, or nothing. One magnifier per line, the one of Dolibarr when it exists.
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
	const handlers = cfg.handlers || {};

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
	 * File of a link to document.php of Dolibarr itself, null for anything else (other pages, other sites, public links)
	 * @param {HTMLAnchorElement} link
	 * @returns {{modulepart: string, file: string, entity: string, extension: string}|null}
	 */
	function fileOfLink(link) {
		let params;
		try {
			const url = new URL(link.getAttribute('href'), window.location.href);
			if (url.origin !== window.location.origin || url.pathname !== cfg.documentPath) {
				return null;
			}
			params = url.searchParams;
		} catch (e) {
			return null;
		}
		const modulepart = params.get('modulepart') || '';
		const file = params.get('file') || '';
		if (!/^[A-Za-z0-9_]+$/.test(modulepart) || file === '' || params.get('hashp')) {
			return null;
		}
		return { modulepart: modulepart, file: file, entity: params.get('entity') || '', extension: extensionOf(file) };
	}

	/**
	 * @param {Object} info Result of fileOfLink()
	 * @param {string} viewer Code of the viewer
	 * @returns {string}
	 */
	function viewUrl(info, viewer) {
		const query = new URLSearchParams({ source: 'document', modulepart: info.modulepart, file: info.file, viewer: viewer });
		if (info.entity) {
			query.set('entity', info.entity);
		}
		return cfg.viewUrl + '?' + query.toString();
	}

	/**
	 * Open the viewer in the preview dialog of Dolibarr (so that everything bound to it, like the unalterable log of
	 * the previews of invoices, still works), or in a new tab when there is no dialog (phone layout)
	 * @param {string} url
	 * @param {string} title
	 */
	function openViewer(url, title) {
		if (typeof window.document_preview === 'function' && window.jQuery && window.jQuery.fn && window.jQuery.fn.dialog) {
			window.document_preview(url + '&embed=1', 'text/html', title);
		} else {
			window.open(url, '_blank', 'noopener');
		}
	}

	/**
	 * Apply the handler of each file of a part of the page:
	 * - preview of Dolibarr: nothing changes;
	 * - none: the magnifier of Dolibarr is hidden;
	 * - a viewer of DocStudio: the magnifier of Dolibarr opens it, or DocStudio adds its magnifier when Dolibarr has none.
	 * @param {ParentNode} root
	 */
	function scan(root) {
		root.querySelectorAll('a[href*="document.php?"]').forEach(function (link) {
			if (link.dataset.docstudio) {
				return;
			}
			link.dataset.docstudio = '1';
			const info = fileOfLink(link);
			const handler = info ? handlers[info.extension] : null;
			if (!handler || handler === cfg.dolibarrHandler) {
				return;
			}

			// Magnifier of Dolibarr: its href and type are changed, the click is still handled by Dolibarr
			if (link.classList.contains('documentpreview')) {
				if (handler === cfg.noneHandler) {
					link.style.display = 'none';
					return;
				}
				// Dolibarr opens this href in its dialog (embed: viewer without its own window controls)
				link.setAttribute('href', viewUrl(info, handler) + '&embed=1');
				link.setAttribute('mime', 'text/html');
				link.title = cfg.label;
				link.dataset.title = link.dataset.title || info.file.split('/').pop();
				return;
			}
			if (handler === cfg.noneHandler) {
				return;
			}

			// Name of the file: DocStudio adds its magnifier when the line has none of Dolibarr
			const line = link.closest('tr, li, .ecmjqft');
			if (!line || line.querySelector('a.documentpreview, .docstudio-open')) {
				return;
			}
			const url = viewUrl(info, handler);
			const open = document.createElement('a');
			open.className = 'docstudio-open paddingright marginleftonly';
			open.href = url;
			open.target = '_blank';
			open.rel = 'noopener';
			open.title = cfg.label;
			open.setAttribute('aria-label', cfg.label);
			const icon = document.createElement('span');
			icon.className = 'fa fa-search-plus pictofixedwidth docstudio-open-icon';
			open.appendChild(icon);
			open.addEventListener('click', function (e) {
				if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) {
					return;
				}
				e.preventDefault();
				openViewer(url, info.file.split('/').pop());
			});
			link.after(open);
		});
	}

	// Escape pressed inside the viewer closes the preview dialog of Dolibarr. Only a message of a frame of this site
	// is accepted, and it can only close the dialog.
	window.addEventListener('message', function (e) {
		if (e.origin !== window.location.origin || !e.data || e.data.type !== 'docstudio:close') {
			return;
		}
		const frames = document.querySelectorAll('#dialogforpopup object, #dialogforpopup iframe');
		const fromPreview = Array.prototype.some.call(frames, function (frame) {
			return frame.contentWindow === e.source;
		});
		if (fromPreview && window.jQuery) {
			window.jQuery('#dialogforpopup').dialog('close');
		}
	});

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

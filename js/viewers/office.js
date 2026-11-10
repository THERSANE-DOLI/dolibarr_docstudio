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
 * Office viewer (lot 3): Word (docx), PowerPoint (pptx) and Excel (xlsx) drawn by @silurus/ooxml (parsers in
 * Rust/WASM, Canvas renderer, MIT, vendor/ooxml). Read only.
 * Safety: the document is only drawn (no script, macro, form nor active content runs), its links are not followed,
 * no font is downloaded (Google Fonts off; the content security policy only allows the server of Dolibarr anyway).
 */

const KINDS = { docx: 'docx', pptx: 'pptx', xlsx: 'xlsx' };

window.DocStudio.registerViewer('office', {
	async mount(ctx) {
		const t = ctx.t;
		const kind = KINDS[ctx.file.extension];
		const urls = ctx.config.ooxml || {};
		if (!kind || !urls[kind]) {
			throw new Error(t('DocStudioErrorNoViewer'));
		}

		const [lib, blob] = await Promise.all([import(urls[kind]), ctx.file.blob('application/octet-stream')]);
		const buffer = await blob.arrayBuffer();

		const host = document.createElement('div');
		host.className = 'docstudio-office docstudio-office-' + kind;
		ctx.container.appendChild(host);

		// Colors of the theme of DocStudio around the pages
		const css = window.getComputedStyle(document.documentElement);
		const desk = css.getPropertyValue('--ds-bg').trim() || '#e8eaed';

		const pageInfo = document.createElement('span');
		pageInfo.className = 'docstudio-pageinfo';
		const zoomLabel = document.createElement('span');
		zoomLabel.className = 'ds-zoom-label';
		const showScale = function (scale) {
			zoomLabel.textContent = Math.round(scale * 100) + ' %';
		};
		const failed = function (e) {
			console.error(e);
		};

		let viewer;
		const common = {
			enableHyperlinks: false,
			onScaleChange: showScale,
			onError: failed
		};
		if (kind === 'docx') {
			viewer = new lib.DocxScrollViewer(host, Object.assign({
				background: desk,
				onVisiblePageChange: function (top, total) {
					pageInfo.textContent = t('DocStudioPage', top + 1, total);
				}
			}, common));
		} else if (kind === 'pptx') {
			viewer = new lib.PptxScrollViewer(host, Object.assign({
				background: desk,
				// Videos and sounds of the slides stay still: nothing plays by itself
				enableMediaPlayback: false,
				onVisibleSlideChange: function (top, total) {
					pageInfo.textContent = t('DocStudioSlide', top + 1, total);
				}
			}, common));
		} else {
			viewer = new lib.XlsxViewer(host, Object.assign({
				onSheetChange: function (index, total) {
					pageInfo.textContent = t('DocStudioSheet', index + 1, total);
				}
			}, common));
		}

		try {
			await viewer.load(buffer);
		} catch (e) {
			console.error(e);
			viewer.destroy();
			// Password, damaged file, too big: the message of the library is technical, ours says what to do
			throw new Error(t('DocStudioOfficeError'));
		}
		// Fitted to the width but not bigger than 125 % at the opening (a wide screen made the text huge), like the PDF
		if (kind !== 'xlsx' && viewer.getScale() > 1.25) {
			viewer.setScale(1.25);
		}
		showScale(viewer.getScale());

		/* Search */

		const searchInput = document.createElement('input');
		searchInput.type = 'search';
		searchInput.className = 'docstudio-search';
		searchInput.placeholder = t('DocStudioSearch');
		searchInput.setAttribute('aria-label', t('DocStudioSearch'));
		const searchInfo = document.createElement('span');
		searchInfo.className = 'docstudio-pageinfo';
		searchInfo.setAttribute('role', 'status');
		let hits = [];
		let hitIndex = -1;
		let searchTimer = null;

		async function search() {
			const query = searchInput.value.trim();
			if (query.length < 2) {
				viewer.clearFind();
				hits = [];
				hitIndex = -1;
				searchInfo.textContent = '';
				return;
			}
			try {
				hits = await viewer.findText(query);
			} catch (e) {
				console.error(e);
				hits = [];
			}
			hitIndex = hits.length ? 0 : -1;
			searchInfo.textContent = hits.length ? t('DocStudioSearchResults', 1, hits.length) : t('DocStudioSearchNone');
			// Shows the first one (it may be on another page)
			if (hits.length) {
				try {
					await viewer.findNext();
				} catch (e) {
					console.error(e);
				}
			}
		}

		async function nextHit(direction) {
			if (!hits.length) {
				await search();
				return;
			}
			const hit = direction > 0 ? await viewer.findNext() : await viewer.findPrev();
			if (hit) {
				hitIndex = (hitIndex + direction + hits.length) % hits.length;
				searchInfo.textContent = t('DocStudioSearchResults', hitIndex + 1, hits.length);
			}
		}

		searchInput.addEventListener('input', function () {
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(search, 300);
		});
		searchInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				nextHit(e.shiftKey ? -1 : 1);
			}
		});

		/* Toolbar */

		const tools = [
			ctx.utils.iconButton('minus', t('DocStudioZoomOut'), function () {
				viewer.zoomOut();
			}),
			zoomLabel,
			ctx.utils.iconButton('plus', t('DocStudioZoomIn'), function () {
				viewer.zoomIn();
			})
		];
		if (kind !== 'xlsx') {
			tools.push(ctx.utils.iconButton('fit', t('DocStudioZoomFit'), function () {
				viewer.fitWidth();
			}));
		}
		tools.push(
			pageInfo,
			searchInput,
			ctx.utils.iconButton('chevron-up', t('DocStudioSearchPrevious'), function () {
				nextHit(-1);
			}),
			ctx.utils.iconButton('chevron-down', t('DocStudioSearchNext'), function () {
				nextHit(1);
			}),
			searchInfo
		);
		ctx.toolbar.append.apply(ctx.toolbar, tools);

		// Ctrl+F searches in the document (its text is drawn, not in the page for the browser)
		function onKey(e) {
			if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
				e.preventDefault();
				searchInput.focus();
				searchInput.select();
			}
		}
		document.addEventListener('keydown', onKey);

		return {
			unmount() {
				document.removeEventListener('keydown', onKey);
				window.clearTimeout(searchTimer);
				viewer.destroy();
			}
		};
	}
});

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
 * PDF editor (no server needed):
 * - texts of DocStudio: movable, editable until saving, font (standard fonts of PDF or fonts uploaded in the setup),
 *   size, color, bold; written in the pages when saving;
 * - drawing, highlight (on the text, free hand or straight line) and images: editors of pdf.js and straight lines of
 *   DocStudio;
 * - pages: rotate (applied at once), delete, reorder (panel of the pages), split into several files;
 * - crop: visible area of a page (or of every page); the content outside stays in the file;
 * - redaction: every page with a redacted zone is replaced by an image of the page with the zones painted black,
 *   then the result is checked (the text under the zones must be nowhere in the new file).
 * Safety of the display: eval disabled, no XFA, no script, links of the PDF inactive. Texts are plain text only.
 */

const TOOL_NONE = 'none';
const TOOL_TEXT = 'text';
const TOOL_DRAW = 'draw';
const TOOL_HIGHLIGHT = 'highlight';
const TOOL_LINE = 'line';
const TOOL_CHECK = 'check';
const TOOL_CROSS = 'cross';
const TOOL_DOT = 'dot';
const MARK_TOOLS = [TOOL_CHECK, TOOL_CROSS, TOOL_DOT];
// Marks: drawn in a box of 24 x 24 (same drawings as the icons of the editor)
const MARK_PATHS = { check: 'M5 12l5 5l10 -10', cross: 'M18 6l-12 12M6 6l12 12' };
const TOOL_IMAGE = 'image';
const TOOL_REDACT = 'redact';
const TOOL_CROP = 'crop';
const REDACT_DPI = 200;
const SVG_NS = 'http://www.w3.org/2000/svg';
const LINE_HEIGHT = 1.2;
const ASCENT = 0.8;

window.DocStudio.registerViewer('pdfedit', {
	async mount(ctx) {
		const conf = ctx.config.pdfjs;
		const t = ctx.t;
		const pdfjsLib = await import(conf.lib);
		pdfjsLib.GlobalWorkerOptions.workerSrc = conf.worker;
		// The components of the viewer of pdf.js read the library from here
		globalThis.pdfjsLib = pdfjsLib;
		const pdfjsViewer = await import(conf.viewer);
		const PDFLib = await import(ctx.config.pdflib);
		const fonts = ctx.config.fonts || [];

		const docOptions = {
			isEvalSupported: false,
			enableXfa: false,
			cMapUrl: conf.cMapUrl,
			cMapPacked: true,
			standardFontDataUrl: conf.standardFontDataUrl,
			wasmUrl: conf.wasmUrl,
			iccUrl: conf.iccUrl,
			stopAtErrors: false
		};
		let password = null;
		const loadingTask = pdfjsLib.getDocument(Object.assign({ data: new Uint8Array(await ctx.file.arrayBuffer()) }, docOptions));
		loadingTask.onPassword = function (updatePassword) {
			password = window.prompt(t('DocStudioPdfPassword'));
			if (password === null) {
				loadingTask.destroy();
				return;
			}
			updatePassword(password);
		};
		let pdfDocument = await loadingTask.promise;
		const pageCount = pdfDocument.numPages;

		/* State */

		let dirty = false;
		let tool = TOOL_NONE;
		// Objects of DocStudio on the pages, positions in PDF units:
		// redact / crop {rect}, line {p0, p1, color, width}, text {x, y, text, font, size, color, bold}
		const objects = [];
		const order = [];				// original page numbers in the new order
		const deleted = new Set();		// deleted page numbers
		const cuts = new Set();			// page numbers after which the PDF is split
		let cropAll = false;
		let lastId = 0;
		let selectedText = null;
		for (let i = 1; i <= pageCount; i++) {
			order.push(i);
		}

		/* Layout */

		const root = document.createElement('div');
		root.className = 'ds-edit';
		const pagesPanel = document.createElement('aside');
		pagesPanel.className = 'ds-edit-pages';
		pagesPanel.setAttribute('aria-label', t('DocStudioPages'));
		const wrap = document.createElement('div');
		wrap.className = 'ds-edit-wrap';
		const viewerContainer = document.createElement('div');
		viewerContainer.className = 'ds-edit-viewer';
		const viewerEl = document.createElement('div');
		viewerEl.className = 'pdfViewer';
		viewerContainer.appendChild(viewerEl);
		const notice = document.createElement('div');
		notice.className = 'ds-edit-notice';
		notice.hidden = true;
		wrap.append(notice, viewerContainer);
		root.append(pagesPanel, wrap);
		ctx.container.appendChild(root);

		const eventBus = new pdfjsViewer.EventBus();
		const linkService = new pdfjsViewer.PDFLinkService({ eventBus: eventBus, externalLinkEnabled: false });
		const pdfViewer = new pdfjsViewer.PDFViewer({
			container: viewerContainer,
			viewer: viewerEl,
			eventBus: eventBus,
			linkService: linkService,
			annotationMode: pdfjsLib.AnnotationMode.ENABLE,
			annotationEditorMode: pdfjsLib.AnnotationEditorType.NONE,
			enableHighlightFloatingButton: false
		});
		linkService.setViewer(pdfViewer);
		let restore = null;
		eventBus.on('pagesinit', function () {
			if (restore) {
				pdfViewer.currentScale = restore.scale;
				pdfViewer.currentPageNumber = restore.page;
				restore = null;
				return;
			}
			pdfViewer.currentScaleValue = 'page-width';
			if (pdfViewer.currentScale > 1.25) {
				pdfViewer.currentScale = 1.25;
			}
		});

		function setDocument(doc) {
			pdfDocument = doc;
			pdfDocument.annotationStorage.onSetModified = setDirty;
			pdfViewer.setDocument(doc);
			linkService.setDocument(doc, null);
		}

		/**
		 * Show another content in the editor (after a rotation, or to come back after a failed saving).
		 * The page counter of pdf.js is shared by every PDF opened in the page: the viewer is emptied and the old
		 * document closed BEFORE the new one is opened, else the new one would see no page.
		 * @param {Uint8Array} bytes
		 */
		async function reloadDocument(bytes) {
			const state = { page: activePage, scale: pdfViewer.currentScale };
			const old = pdfDocument;
			pdfViewer.setDocument(null);
			linkService.setDocument(null, null);
			await old.destroy();
			const doc = await pdfjsLib.getDocument(Object.assign({ data: bytes, password: password || undefined }, docOptions)).promise;
			restore = state;
			setDocument(doc);
		}

		// The editors of pdf.js are ready only after the pages: the mode of the current tool is applied then
		eventBus.on('annotationeditoruimanager', function () {
			window.setTimeout(function () {
				applyEditorMode();
			}, 0);
		});

		function setDirty() {
			if (!dirty) {
				dirty = true;
				saveButton.disabled = false;
			}
		}

		// Ctrl + wheel zooms the PDF only, not the whole page
		viewerContainer.addEventListener('wheel', function (e) {
			if (!e.ctrlKey && !e.metaKey) {
				return;
			}
			e.preventDefault();
			const factor = e.deltaY < 0 ? 1.1 : 1 / 1.1;
			pdfViewer.currentScale = Math.min(5, Math.max(0.25, pdfViewer.currentScale * factor));
		}, { passive: false });

		/* Fonts */

		const fontFaces = {};

		/**
		 * @param {string} key
		 * @returns {Object} font of the list (the first one when unknown)
		 */
		function fontOf(key) {
			return fonts.find(function (f) {
				return f.key === key;
			}) || fonts[0];
		}

		/**
		 * Load an uploaded font for the display (FontFace from its file, no CSS needed)
		 * @param {Object} font
		 */
		async function loadFontFace(font) {
			if (!font.url || fontFaces[font.key]) {
				return;
			}
			fontFaces[font.key] = true;
			try {
				const regular = new FontFace(font.family, await (await fetch(font.url, { credentials: 'same-origin' })).arrayBuffer(), { weight: '400' });
				document.fonts.add(await regular.load());
				if (font.urlBold) {
					const bold = new FontFace(font.family, await (await fetch(font.urlBold, { credentials: 'same-origin' })).arrayBuffer(), { weight: '700' });
					document.fonts.add(await bold.load());
				}
			} catch (e) {
				console.error(e);
			}
		}

		/* Layer of DocStudio over each page (zones, lines, texts) */

		/**
		 * @param {number} pageNumber
		 * @returns {HTMLElement|null}
		 */
		function layerOf(pageNumber) {
			const pageView = pdfViewer.getPageView(pageNumber - 1);
			if (!pageView || !pageView.div) {
				return null;
			}
			let layer = pageView.div.querySelector(':scope > .ds-zone-layer');
			if (!layer) {
				layer = document.createElement('div');
				layer.className = 'ds-zone-layer';
				layer.dataset.page = String(pageNumber);
				pageView.div.appendChild(layer);
				layer.addEventListener('pointerdown', onLayerPointerDown);
			}
			return layer;
		}

		/** @returns {Object|null} last crop zone */
		function lastCrop() {
			const crops = objects.filter(function (o) {
				return o.kind === 'crop';
			});
			return crops.length ? crops[crops.length - 1] : null;
		}

		/**
		 * Draw the objects of a page
		 * @param {number} pageNumber
		 */
		function drawLayer(pageNumber) {
			const layer = layerOf(pageNumber);
			if (!layer) {
				return;
			}
			const viewport = pdfViewer.getPageView(pageNumber - 1).viewport;
			const editing = layer.querySelector('.ds-text-content:focus');
			if (editing) {
				// Never redraw the text being typed
				return;
			}
			layer.replaceChildren();
			if (deleted.has(pageNumber)) {
				// Deleted page: veil and stamp over the page, with the way back
				const veil = document.createElement('div');
				veil.className = 'ds-deleted';
				const stamp = document.createElement('div');
				stamp.className = 'ds-deleted-stamp';
				stamp.textContent = t('DocStudioPageDeleted');
				const restoreButton = ctx.utils.button(t('DocStudioRestorePage'), t('DocStudioRestorePage'), function () {
					toggleDeleted(pageNumber);
				});
				veil.append(stamp, restoreButton);
				layer.appendChild(veil);
			}
			layer.classList.toggle('ds-zone-active', [TOOL_REDACT, TOOL_CROP, TOOL_TEXT, TOOL_LINE].concat(MARK_TOOLS).includes(tool));
			layer.classList.toggle('ds-text-tool', tool === TOOL_TEXT);

			const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.setAttribute('class', 'ds-lines');
			svg.setAttribute('width', String(viewport.width));
			svg.setAttribute('height', String(viewport.height));
			layer.appendChild(svg);

			const crop = lastCrop();
			objects.forEach(function (o) {
				if (o.page !== pageNumber && !(o.kind === 'crop' && cropAll && o === crop)) {
					return;
				}
				if (o.kind === 'redact' || o.kind === 'crop') {
					const r = viewport.convertToViewportRectangle(o.rect);
					const el = document.createElement('div');
					el.className = 'ds-zone ds-zone-' + o.kind;
					el.style.left = Math.min(r[0], r[2]) + 'px';
					el.style.top = Math.min(r[1], r[3]) + 'px';
					el.style.width = Math.abs(r[2] - r[0]) + 'px';
					el.style.height = Math.abs(r[3] - r[1]) + 'px';
					el.appendChild(removeButton(o));
					layer.appendChild(el);
				} else if (o.kind === 'line') {
					const a = viewport.convertToViewportPoint(o.p0[0], o.p0[1]);
					const b = viewport.convertToViewportPoint(o.p1[0], o.p1[1]);
					const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
					line.setAttribute('x1', String(a[0]));
					line.setAttribute('y1', String(a[1]));
					line.setAttribute('x2', String(b[0]));
					line.setAttribute('y2', String(b[1]));
					line.setAttribute('stroke', o.color);
					line.setAttribute('stroke-width', String(o.width * viewport.scale));
					line.setAttribute('stroke-opacity', '0.4');
					line.setAttribute('class', 'ds-line');
					line.addEventListener('click', function () {
						if (window.confirm(t('DocStudioRemoveZone') + ' ?')) {
							removeObject(o);
						}
					});
					svg.appendChild(line);
				} else if (o.kind === 'text') {
					layer.appendChild(textBox(o, viewport));
				} else if (o.kind === 'mark') {
					const c = viewport.convertToViewportPoint(o.x, o.y);
					const box = o.size * viewport.scale;
					const mark = document.createElement('div');
					mark.className = 'ds-mark';
					mark.style.left = (c[0] - box / 2) + 'px';
					mark.style.top = (c[1] - box / 2) + 'px';
					mark.style.width = box + 'px';
					mark.style.height = box + 'px';
					mark.style.color = o.color;
					mark.appendChild(icon(o.symbol || TOOL_CHECK));
					layer.appendChild(mark);
				}
			});
		}

		/**
		 * @param {Object} o
		 * @returns {HTMLButtonElement}
		 */
		function removeButton(o) {
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'ds-zone-remove';
			remove.textContent = '×';
			remove.title = t('DocStudioRemoveZone');
			remove.addEventListener('pointerdown', function (e) {
				e.stopPropagation();
			});
			remove.addEventListener('click', function (e) {
				e.stopPropagation();
				removeObject(o);
			});
			return remove;
		}

		function redrawAll() {
			for (let i = 1; i <= pdfDocument.numPages; i++) {
				drawLayer(i);
			}
			updateNotice();
		}

		/**
		 * @param {Object} o
		 */
		function removeObject(o) {
			const index = objects.indexOf(o);
			if (index >= 0) {
				objects.splice(index, 1);
				if (selectedText === o) {
					selectedText = null;
				}
				setDirty();
				drawLayer(o.page);
				if (o.kind === 'crop') {
					redrawAll();
				}
				updateNotice();
			}
		}

		eventBus.on('pagerendered', function (e) {
			drawLayer(e.pageNumber);
			cutMark(e.pageNumber);
		});

		/* Texts of DocStudio */

		/**
		 * Box of a text: plain text only (never HTML), moved by its handle
		 * @param {Object} o
		 * @param {Object} viewport
		 * @returns {HTMLElement}
		 */
		function textBox(o, viewport) {
			const font = fontOf(o.font);
			loadFontFace(font);
			const p = viewport.convertToViewportPoint(o.x, o.y);
			const box = document.createElement('div');
			box.className = 'ds-text' + (selectedText === o ? ' ds-text-selected' : '');
			box.style.left = p[0] + 'px';
			box.style.top = p[1] + 'px';
			const content = document.createElement('div');
			content.className = 'ds-text-content';
			content.setAttribute('contenteditable', 'plaintext-only');
			content.spellcheck = true;
			content.textContent = o.text;
			content.dataset.placeholder = t('DocStudioTextPlaceholder');
			content.style.fontFamily = font.css;
			content.style.fontWeight = o.bold ? '700' : '400';
			content.style.fontSize = (o.size * viewport.scale) + 'px';
			content.style.lineHeight = String(LINE_HEIGHT);
			content.style.color = o.color;
			content.addEventListener('focus', function () {
				selectText(o, box);
			});
			content.addEventListener('input', function () {
				o.text = content.innerText.replace(/\n$/, '');
				setDirty();
			});
			content.addEventListener('paste', function (e) {
				// Plain text only
				e.preventDefault();
				const text = (e.clipboardData || window.clipboardData).getData('text/plain');
				document.execCommand('insertText', false, text);
			});
			content.addEventListener('blur', function () {
				if (o.text.trim() === '') {
					window.setTimeout(function () {
						removeObject(o);
					}, 0);
				}
			});
			content.addEventListener('pointerdown', function (e) {
				e.stopPropagation();
			});

			const handle = document.createElement('span');
			handle.className = 'ds-text-move';
			handle.textContent = '✥';
			handle.title = t('DocStudioMove');
			handle.addEventListener('pointerdown', function (e) {
				e.preventDefault();
				e.stopPropagation();
				selectText(o, box);
				const layer = box.parentElement;
				const start = { x: e.clientX, y: e.clientY, left: parseFloat(box.style.left), top: parseFloat(box.style.top) };
				handle.setPointerCapture(e.pointerId);
				function move(ev) {
					box.style.left = (start.left + ev.clientX - start.x) + 'px';
					box.style.top = (start.top + ev.clientY - start.y) + 'px';
				}
				function end() {
					handle.removeEventListener('pointermove', move);
					handle.removeEventListener('pointerup', end);
					const pdf = viewport.convertToPdfPoint(parseFloat(box.style.left), parseFloat(box.style.top));
					o.x = pdf[0];
					o.y = pdf[1];
					setDirty();
					if (layer) {
						drawLayer(o.page);
					}
				}
				handle.addEventListener('pointermove', move);
				handle.addEventListener('pointerup', end);
			});

			box.append(handle, content, removeButton(o));
			return box;
		}

		/**
		 * @param {Object} o Text selected
		 * @param {HTMLElement} box
		 */
		function selectText(o, box) {
			selectedText = o;
			document.querySelectorAll('.ds-text-selected').forEach(function (el) {
				el.classList.remove('ds-text-selected');
			});
			box.classList.add('ds-text-selected');
			fontSelect.value = o.font;
			size.value = String(o.size);
			color.value = o.color;
			boldBox.checked = o.bold;
			showParams();
		}

		/** Apply the controls of the toolbar to the selected text */
		function applyToSelectedText() {
			if (!selectedText) {
				return;
			}
			selectedText.font = fontSelect.value;
			selectedText.size = Number(size.value);
			selectedText.color = color.value;
			selectedText.bold = boldBox.checked && !boldBox.disabled;
			setDirty();
			const layer = layerOf(selectedText.page);
			const content = layer ? layer.querySelector('.ds-text-selected .ds-text-content') : null;
			if (content) {
				const font = fontOf(selectedText.font);
				loadFontFace(font);
				content.style.fontFamily = font.css;
				content.style.fontWeight = selectedText.bold ? '700' : '400';
				content.style.fontSize = (selectedText.size * pdfViewer.getPageView(selectedText.page - 1).viewport.scale) + 'px';
				content.style.color = selectedText.color;
			}
		}

		/* Drawing with the pointer: zones, straight lines, new texts */

		/**
		 * @param {PointerEvent} e
		 */
		function onLayerPointerDown(e) {
			if (e.button !== 0) {
				return;
			}
			const layer = e.currentTarget;
			const pageNumber = Number(layer.dataset.page);
			const viewport = pdfViewer.getPageView(pageNumber - 1).viewport;
			const box = layer.getBoundingClientRect();
			const x0 = e.clientX - box.left;
			const y0 = e.clientY - box.top;

			if (MARK_TOOLS.includes(tool)) {
				e.preventDefault();
				// Click on a check: it is removed (unchecked), elsewhere: a new check centered on the click
				const hit = objects.find(function (o) {
					if (o.kind !== 'mark' || o.page !== pageNumber) {
						return false;
					}
					const c = viewport.convertToViewportPoint(o.x, o.y);
					return Math.abs(c[0] - x0) <= o.size * viewport.scale / 2 && Math.abs(c[1] - y0) <= o.size * viewport.scale / 2;
				});
				if (hit) {
					removeObject(hit);
					return;
				}
				const p = viewport.convertToPdfPoint(x0, y0);
				objects.push({ id: ++lastId, kind: 'mark', symbol: tool, page: pageNumber, x: p[0], y: p[1], size: Number(size.value), color: color.value });
				setDirty();
				drawLayer(pageNumber);
				return;
			}
			if (tool === TOOL_TEXT) {
				e.preventDefault();
				const p = viewport.convertToPdfPoint(x0, y0 - Number(size.value) * viewport.scale * 0.6);
				const o = { id: ++lastId, kind: 'text', page: pageNumber, x: p[0], y: p[1], text: '', font: fontSelect.value, size: Number(size.value), color: color.value, bold: boldBox.checked && !boldBox.disabled };
				objects.push(o);
				selectedText = o;
				drawLayer(pageNumber);
				const content = layer.querySelector('.ds-text-selected .ds-text-content');
				if (content) {
					content.focus();
				}
				return;
			}
			const kind = tool;
			if (kind !== TOOL_REDACT && kind !== TOOL_CROP && kind !== 'line') {
				return;
			}
			e.preventDefault();
			let ghost;
			if (kind === 'line') {
				ghost = document.createElementNS('http://www.w3.org/2000/svg', 'line');
				ghost.setAttribute('stroke', color.value);
				ghost.setAttribute('stroke-width', String(Number(size.value) * viewport.scale));
				ghost.setAttribute('stroke-opacity', '0.4');
				ghost.setAttribute('x1', String(x0));
				ghost.setAttribute('y1', String(y0));
				ghost.setAttribute('x2', String(x0));
				ghost.setAttribute('y2', String(y0));
				layer.querySelector('svg.ds-lines').appendChild(ghost);
			} else {
				ghost = document.createElement('div');
				ghost.className = 'ds-zone ds-zone-' + kind + ' ds-zone-ghost';
				layer.appendChild(ghost);
			}
			layer.setPointerCapture(e.pointerId);
			let x1 = x0;
			let y1 = y0;

			function move(ev) {
				x1 = Math.max(0, Math.min(box.width, ev.clientX - box.left));
				y1 = Math.max(0, Math.min(box.height, ev.clientY - box.top));
				if (kind === 'line') {
					// Straight: horizontal (or vertical) when close to it, any angle with Shift
					if (!ev.shiftKey) {
						if (Math.abs(y1 - y0) < Math.abs(x1 - x0) * 0.15) {
							y1 = y0;
						} else if (Math.abs(x1 - x0) < Math.abs(y1 - y0) * 0.15) {
							x1 = x0;
						}
					}
					ghost.setAttribute('x2', String(x1));
					ghost.setAttribute('y2', String(y1));
					return;
				}
				ghost.style.left = Math.min(x0, x1) + 'px';
				ghost.style.top = Math.min(y0, y1) + 'px';
				ghost.style.width = Math.abs(x1 - x0) + 'px';
				ghost.style.height = Math.abs(y1 - y0) + 'px';
			}

			function end() {
				layer.removeEventListener('pointermove', move);
				layer.removeEventListener('pointerup', end);
				layer.removeEventListener('pointercancel', end);
				ghost.remove();
				if (Math.abs(x1 - x0) < 4 && Math.abs(y1 - y0) < 4) {
					return;
				}
				if (kind === 'line') {
					objects.push({ id: ++lastId, kind: 'line', page: pageNumber, p0: viewport.convertToPdfPoint(x0, y0), p1: viewport.convertToPdfPoint(x1, y1), color: color.value, width: Number(size.value) });
				} else {
					if (Math.abs(x1 - x0) < 4 || Math.abs(y1 - y0) < 4) {
						return;
					}
					const p0 = viewport.convertToPdfPoint(Math.min(x0, x1), Math.min(y0, y1));
					const p1 = viewport.convertToPdfPoint(Math.max(x0, x1), Math.max(y0, y1));
					const rect = [Math.min(p0[0], p1[0]), Math.min(p0[1], p1[1]), Math.max(p0[0], p1[0]), Math.max(p0[1], p1[1])];
					if (kind === TOOL_CROP) {
						for (let i = objects.length - 1; i >= 0; i--) {
							if (objects[i].kind === 'crop' && objects[i].page === pageNumber) {
								objects.splice(i, 1);
							}
						}
					}
					objects.push({ id: ++lastId, kind: kind, page: pageNumber, rect: rect });
				}
				setDirty();
				if (kind === TOOL_CROP) {
					redrawAll();
				} else {
					drawLayer(pageNumber);
					updateNotice();
				}
			}

			layer.addEventListener('pointermove', move);
			layer.addEventListener('pointerup', end);
			layer.addEventListener('pointercancel', end);
		}

		function updateNotice() {
			const texts = [];
			if (tool === TOOL_REDACT || objects.some(function (o) {
				return o.kind === 'redact';
			})) {
				texts.push(t('DocStudioRedactHelp'));
			}
			if (tool === TOOL_CROP || objects.some(function (o) {
				return o.kind === 'crop';
			})) {
				texts.push(t('DocStudioCropHelp'));
			}
			notice.hidden = !texts.length;
			notice.textContent = texts.join(' ');
		}

		document.addEventListener('keydown', onKey);
		function onKey(e) {
			// Delete removes the selected text when it is not being typed
			if ((e.key === 'Delete' || e.key === 'Backspace') && selectedText && !(document.activeElement && document.activeElement.isContentEditable)
				&& !['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement ? document.activeElement.tagName : '')) {
				e.preventDefault();
				removeObject(selectedText);
			}
		}

		/* Pages panel: thumbnails, delete, drag and drop to reorder, split marks */

		const thumbs = {};
		let dragged = null;

		async function drawThumb(pageNumber) {
			const page = await pdfDocument.getPage(pageNumber);
			const base = page.getViewport({ scale: 1 });
			const viewport = page.getViewport({ scale: 120 / Math.max(base.width, base.height * 0.75) });
			const canvas = document.createElement('canvas');
			canvas.width = Math.floor(viewport.width);
			canvas.height = Math.floor(viewport.height);
			await page.render({ canvas: canvas, canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
			thumbs[pageNumber].querySelector('.ds-thumb-image').replaceChildren(canvas);
		}

		async function drawThumbs(list) {
			for (const pageNumber of list) {
				try {
					await drawThumb(pageNumber);
				} catch (e) {
					// Thumbnail not drawn, the number stays
				}
			}
		}

		function refreshThumb(pageNumber) {
			const el = thumbs[pageNumber];
			el.classList.toggle('ds-thumb-deleted', deleted.has(pageNumber));
			const del = el.querySelector('.ds-thumb-delete');
			del.textContent = deleted.has(pageNumber) ? '↺' : '\u{1F5D1}';
			del.title = deleted.has(pageNumber) ? t('DocStudioRestorePage') : t('DocStudioDeletePage');
		}

		/**
		 * Delete a page (kept until saving, shown with a stamp) or keep it again
		 * @param {number} pageNumber
		 */
		function toggleDeleted(pageNumber) {
			if (deleted.has(pageNumber)) {
				deleted.delete(pageNumber);
			} else if (deleted.size < pageCount - 1) {
				deleted.add(pageNumber);
			}
			setDirty();
			refreshThumb(pageNumber);
			drawLayer(pageNumber);
			if (typeof syncDeleteButton === 'function') {
				syncDeleteButton();
			}
		}

		function renderPanel() {
			pagesPanel.replaceChildren();
			const title = document.createElement('div');
			title.className = 'ds-pages-title';
			title.textContent = t('DocStudioPages');
			pagesPanel.appendChild(title);
			order.forEach(function (pageNumber, index) {
				pagesPanel.appendChild(thumbs[pageNumber]);
				if (index < order.length - 1) {
					const cut = document.createElement('button');
					cut.type = 'button';
					cut.className = 'ds-cut' + (cuts.has(pageNumber) ? ' ds-cut-active' : '');
					cut.appendChild(icon('split'));
					cut.title = cuts.has(pageNumber) ? t('DocStudioSplitRemove') : t('DocStudioSplitHere');
					cut.addEventListener('click', function () {
						toggleCut(pageNumber);
					});
					pagesPanel.appendChild(cut);
				}
			});
		}

		/**
		 * @param {number} pageNumber Page after which the PDF is split
		 */
		function toggleCut(pageNumber) {
			if (cuts.has(pageNumber)) {
				cuts.delete(pageNumber);
			} else {
				cuts.add(pageNumber);
			}
			setDirty();
			renderPanel();
			for (let i = 1; i <= pdfDocument.numPages; i++) {
				cutMark(i);
			}
		}

		/**
		 * Scissors in the space under a page of the document (same split as the scissors of the panel)
		 * @param {number} pageNumber
		 */
		function cutMark(pageNumber) {
			const pageView = pdfViewer.getPageView(pageNumber - 1);
			if (!pageView || !pageView.div || pageNumber >= pdfDocument.numPages) {
				return;
			}
			let mark = pageView.div.querySelector(':scope > .ds-page-cut');
			if (!mark) {
				mark = document.createElement('button');
				mark.type = 'button';
				mark.className = 'ds-cut ds-page-cut';
				mark.appendChild(icon('split'));
				const label = document.createElement('span');
				label.className = 'ds-page-cut-label';
				mark.appendChild(label);
				mark.addEventListener('click', function () {
					toggleCut(pageNumber);
				});
				pageView.div.appendChild(mark);
			}
			const active = cuts.has(pageNumber);
			mark.classList.toggle('ds-cut-active', active);
			mark.title = active ? t('DocStudioSplitRemove') : t('DocStudioSplitHere');
			mark.querySelector('.ds-page-cut-label').textContent = active ? t('DocStudioSplitRemove') : t('DocStudioSplitHere');
		}

		for (let i = 1; i <= pageCount; i++) {
			const pageNumber = i;
			const el = document.createElement('div');
			el.className = 'ds-thumb';
			el.draggable = true;
			el.dataset.page = String(pageNumber);
			const image = document.createElement('button');
			image.type = 'button';
			image.className = 'ds-thumb-image';
			image.title = t('DocStudioPage', pageNumber, pageCount);
			image.addEventListener('click', function () {
				if (pageNumber <= pdfViewer.pagesCount) {
					pdfViewer.currentPageNumber = pageNumber;
					setActivePage(pageNumber);
				}
			});
			const bar = document.createElement('div');
			bar.className = 'ds-thumb-bar';
			const label = document.createElement('span');
			label.textContent = String(pageNumber);
			const del = ctx.utils.button('', t('DocStudioDeletePage'), function () {
				toggleDeleted(pageNumber);
			});
			del.classList.add('ds-thumb-delete');
			bar.append(label, del);
			el.append(image, bar);
			el.addEventListener('dragstart', function (e) {
				dragged = pageNumber;
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData('text/plain', String(pageNumber));
				el.classList.add('ds-thumb-dragging');
			});
			el.addEventListener('dragend', function () {
				dragged = null;
				el.classList.remove('ds-thumb-dragging');
			});
			el.addEventListener('dragover', function (e) {
				if (dragged !== null && dragged !== pageNumber) {
					e.preventDefault();
				}
			});
			el.addEventListener('drop', function (e) {
				e.preventDefault();
				if (dragged === null || dragged === pageNumber) {
					return;
				}
				order.splice(order.indexOf(dragged), 1);
				const rect = el.getBoundingClientRect();
				const after = e.clientY > rect.top + rect.height / 2;
				order.splice(order.indexOf(pageNumber) + (after ? 1 : 0), 0, dragged);
				setDirty();
				renderPanel();
			});
			thumbs[pageNumber] = el;
			refreshThumb(pageNumber);
		}
		renderPanel();

		/* Rotation: applied at once to the document (visible everywhere), the annotations are kept */

		let busy = false;

		/* Active page: the one the page actions (rotate, delete) apply to. It follows the scroll (page that fills
		   most of the view), or the page clicked with the Select tool until the scroll changes the page shown. */

		let activePage = 1;
		let targetShown = false;

		function applyActive() {
			for (let i = 1; i <= pdfDocument.numPages; i++) {
				const pageView = pdfViewer.getPageView(i - 1);
				if (pageView && pageView.div) {
					// Frame and badge always there, only their opacity changes (no flash, no layout change)
					let frame = pageView.div.querySelector(':scope > .ds-page-frame');
					if (!frame) {
						frame = document.createElement('div');
						frame.className = 'ds-page-frame';
						frame.setAttribute('aria-hidden', 'true');
						const badge = document.createElement('span');
						badge.className = 'ds-page-badge';
						frame.appendChild(badge);
						pageView.div.appendChild(frame);
					}
					frame.firstChild.textContent = t('DocStudioPage', i, pdfDocument.numPages);
					pageView.div.classList.toggle('ds-page-active', i === activePage);
					pageView.div.classList.toggle('ds-page-target', i === activePage && targetShown);
				}
			}
			Object.keys(thumbs).forEach(function (key) {
				thumbs[key].classList.toggle('ds-thumb-active', Number(key) === activePage);
			});
			if (pageIndicator) {
				pageIndicator.textContent = t('DocStudioPage', activePage, pdfDocument.numPages);
			}
			if (typeof syncDeleteButton === 'function') {
				syncDeleteButton();
			}
		}

		/**
		 * @param {number} pageNumber
		 */
		function setActivePage(pageNumber) {
			activePage = Math.max(1, Math.min(pdfDocument.numPages, pageNumber));
			applyActive();
		}

		/**
		 * @param {boolean} shown Show which page the action will change
		 */
		function showTarget(shown) {
			if (targetShown !== shown) {
				targetShown = shown;
				applyActive();
			}
		}

		eventBus.on('pagechanging', function (e) {
			setActivePage(e.pageNumber);
		});
		eventBus.on('pagerendered', function () {
			applyActive();
		});
		// Click on a page with the Select tool: this page becomes the active one
		viewerContainer.addEventListener('click', function (e) {
			if (tool !== TOOL_NONE) {
				return;
			}
			const pageDiv = e.target.closest('.page[data-page-number]');
			if (pageDiv) {
				setActivePage(Number(pageDiv.dataset.pageNumber));
			}
		});
		let pageIndicator = null;
		let syncDeleteButton = null;
		let lastAnnotated = null;

		/**
		 * @param {number} delta 90 or -90
		 */
		async function rotateCurrentPage(delta) {
			if (busy) {
				return;
			}
			busy = true;
			const pageNumber = activePage;
			try {
				const bytes = await pdfDocument.saveDocument();
				const doc = await PDFLib.PDFDocument.load(bytes, { updateMetadata: false });
				const page = doc.getPage(pageNumber - 1);
				page.setRotation(PDFLib.degrees((page.getRotation().angle + delta + 360) % 360));
				const rotated = await doc.save();
				await reloadDocument(rotated);
				setDirty();
				drawThumbs([pageNumber]);
			} catch (e) {
				console.error(e);
				notice.hidden = false;
				notice.textContent = /encrypt/i.test(String(e && (e.message || e.name))) ? t('DocStudioEncrypted') : t('DocStudioErrorSave');
			} finally {
				busy = false;
			}
		}

		/* Tools: vertical palette (with groups of tools of the same kind), options of the tool in the top bar */


		/**
		 * @param {string} name Icon of the sprite of the page (img/docstudio-icons.svg)
		 * @returns {SVGElement}
		 */
		function icon(name) {
			const svg = document.createElementNS(SVG_NS, 'svg');
			svg.setAttribute('class', 'ds-icon');
			svg.setAttribute('aria-hidden', 'true');
			const use = document.createElementNS(SVG_NS, 'use');
			use.setAttribute('href', '#ds-i-' + name);
			svg.appendChild(use);
			return svg;
		}

		const E = pdfjsLib.AnnotationEditorType;
		const tools = [
			{ code: TOOL_NONE, label: t('DocStudioToolSelect'), icon: 'select', key: 'v', mode: E.NONE },
			{ code: TOOL_TEXT, label: t('DocStudioToolText'), icon: 'text', key: 't', mode: E.NONE },
			{ code: TOOL_DRAW, label: t('DocStudioToolDraw'), icon: 'draw', key: 'b', mode: E.INK, group: 'mark' },
			{ code: TOOL_HIGHLIGHT, label: t('DocStudioToolHighlight'), icon: 'highlight', key: 'h', mode: E.HIGHLIGHT, group: 'mark' },
			{ code: TOOL_LINE, label: t('DocStudioHighlightLine'), icon: 'line', key: 'l', mode: E.NONE, group: 'mark' },
			{ code: TOOL_IMAGE, label: t('DocStudioToolImage'), icon: 'image', key: 'i', mode: E.STAMP },
			{ code: TOOL_CHECK, label: t('DocStudioToolCheck'), icon: 'check', key: 'k', mode: E.NONE, group: 'tick' },
			{ code: TOOL_CROSS, label: t('DocStudioToolCross'), icon: 'cross', key: '', mode: E.NONE, group: 'tick' },
			{ code: TOOL_DOT, label: t('DocStudioToolDot'), icon: 'dot', key: '', mode: E.NONE, group: 'tick' },
			{ code: TOOL_REDACT, label: t('DocStudioToolRedact'), icon: 'redact', key: 'x', mode: E.NONE },
			{ code: TOOL_CROP, label: t('DocStudioToolCrop'), icon: 'crop', key: 'c', mode: E.NONE }
		];
		const toolOf = function (code) {
			return tools.find(function (x) {
				return x.code === code;
			});
		};
		const groupCurrent = { mark: TOOL_DRAW, tick: TOOL_CHECK };
		const slotButtons = {};		// tool code or group => button
		let flyout = null;

		const palette = document.createElement('nav');
		palette.className = 'ds-palette';
		palette.setAttribute('aria-label', t('DocStudioTools'));
		root.insertBefore(palette, wrap);

		/**
		 * @param {Object} def Tool
		 * @returns {string} tooltip with the shortcut
		 */
		function tipOf(def) {
			return def.key ? def.label + ' (' + def.key.toUpperCase() + ')' : def.label;
		}

		function closeFlyout() {
			if (flyout) {
				flyout.remove();
				flyout = null;
			}
		}

		/**
		 * List of the tools of a group, next to its button
		 * @param {string} group
		 * @param {HTMLElement} anchor
		 */
		function openFlyout(group, anchor) {
			closeFlyout();
			flyout = document.createElement('div');
			flyout.className = 'ds-flyout';
			flyout.setAttribute('role', 'menu');
			tools.filter(function (x) {
				return x.group === group;
			}).forEach(function (def) {
				const item = document.createElement('button');
				item.type = 'button';
				item.className = 'ds-flyout-item' + (def.code === tool ? ' docstudio-active' : '');
				item.setAttribute('role', 'menuitem');
				const label = document.createElement('span');
				label.textContent = def.label;
				const key = document.createElement('kbd');
				key.textContent = def.key ? def.key.toUpperCase() : '';
				item.append(icon(def.icon), label, key);
				item.addEventListener('click', function () {
					closeFlyout();
					selectTool(def.code);
				});
				flyout.appendChild(item);
			});
			const box = anchor.getBoundingClientRect();
			const rootBox = root.getBoundingClientRect();
			flyout.style.left = (box.right - rootBox.left + 4) + 'px';
			flyout.style.top = (box.top - rootBox.top) + 'px';
			root.appendChild(flyout);
			const first = flyout.querySelector('.docstudio-active') || flyout.querySelector('button');
			if (first) {
				first.focus();
			}
		}
		document.addEventListener('pointerdown', function (e) {
			if (flyout && !flyout.contains(e.target) && !e.target.closest('.ds-group-more')) {
				closeFlyout();
			}
		});

		/**
		 * @param {Object} def Tool shown by the button
		 * @param {string} slot Tool code or group
		 */
		function paletteButton(def, slot) {
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'ds-palette-button';
			btn.title = tipOf(def);
			btn.setAttribute('aria-label', def.label);
			btn.appendChild(icon(def.icon));
			btn.addEventListener('click', function () {
				selectTool(def.group ? groupCurrent[def.group] : def.code);
			});
			if (def.group) {
				const more = document.createElement('span');
				more.className = 'ds-group-more';
				more.title = t('DocStudioMoreTools');
				more.addEventListener('click', function (e) {
					e.stopPropagation();
					openFlyout(def.group, btn);
				});
				btn.appendChild(more);
				// Right click or long press opens the group too
				btn.addEventListener('contextmenu', function (e) {
					e.preventDefault();
					openFlyout(def.group, btn);
				});
				let pressTimer = null;
				btn.addEventListener('pointerdown', function () {
					pressTimer = window.setTimeout(function () {
						openFlyout(def.group, btn);
					}, 500);
				});
				['pointerup', 'pointerleave'].forEach(function (name) {
					btn.addEventListener(name, function () {
						window.clearTimeout(pressTimer);
					});
				});
			}
			slotButtons[slot] = btn;
			palette.appendChild(btn);
		}

		/**
		 * @param {string} name Icon
		 * @param {string} label
		 * @param {function} onClick
		 */
		function actionButton(name, label, onClick) {
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'ds-palette-button';
			btn.title = label;
			btn.setAttribute('aria-label', label);
			btn.appendChild(icon(name));
			btn.addEventListener('click', onClick);
			palette.appendChild(btn);
			return btn;
		}

		const seenGroups = new Set();
		tools.forEach(function (def) {
			if (!def.group) {
				paletteButton(def, def.code);
			} else if (!seenGroups.has(def.group)) {
				seenGroups.add(def.group);
				paletteButton(toolOf(groupCurrent[def.group]), def.group);
			}
		});
		const paletteSeparator = document.createElement('span');
		paletteSeparator.className = 'ds-palette-separator';
		palette.appendChild(paletteSeparator);
		const rotateLeftButton = actionButton('rotate-left', t('DocStudioRotateLeft'), function () {
			rotateCurrentPage(-90);
		});
		const rotateRightButton = actionButton('rotate-right', t('DocStudioRotateRight'), function () {
			rotateCurrentPage(90);
		});
		const deleteButton = actionButton('delete', t('DocStudioDeletePage'), function () {
			toggleDeleted(activePage);
		});
		// Buttons acting on the active page: their tooltips name it, and the page is shown when the mouse is on them
		syncDeleteButton = function () {
			const isDeleted = deleted.has(activePage);
			deleteButton.classList.toggle('docstudio-active', isDeleted);
			deleteButton.setAttribute('aria-pressed', isDeleted ? 'true' : 'false');
			deleteButton.title = isDeleted ? t('DocStudioRestorePageN', activePage) : t('DocStudioDeletePageN', activePage);
			rotateLeftButton.title = t('DocStudioRotateLeftN', activePage);
			rotateRightButton.title = t('DocStudioRotateRightN', activePage);
			[rotateLeftButton, rotateRightButton, deleteButton].forEach(function (btn) {
				btn.setAttribute('aria-label', btn.title);
			});
		};
		// The 3 buttons in one group: moving from one to the other keeps the preview on, without blinking
		const pageActions = document.createElement('div');
		pageActions.className = 'ds-page-actions';
		palette.insertBefore(pageActions, rotateLeftButton);
		pageActions.append(rotateLeftButton, rotateRightButton, deleteButton);
		pageActions.addEventListener('pointerenter', function () {
			showTarget(true);
		});
		pageActions.addEventListener('pointerleave', function () {
			showTarget(false);
		});
		pageActions.addEventListener('focusin', function () {
			showTarget(true);
		});
		pageActions.addEventListener('focusout', function (e) {
			if (!pageActions.contains(e.relatedTarget)) {
				showTarget(false);
			}
		});

		/* Options of the tool (top bar) */

		const toolName = document.createElement('span');
		toolName.className = 'ds-toolname';
		pageIndicator = document.createElement('span');
		pageIndicator.className = 'ds-page-indicator';

		/**
		 * @param {string} text
		 * @param {HTMLElement} input
		 * @returns {HTMLLabelElement}
		 */
		function labeled(text, input) {
			const label = document.createElement('label');
			label.className = 'ds-option';
			label.append(document.createTextNode(text + ' '), input);
			return label;
		}

		const fontSelect = document.createElement('select');
		fontSelect.className = 'ds-font';
		fonts.forEach(function (f) {
			const option = document.createElement('option');
			option.value = f.key;
			option.textContent = f.label;
			fontSelect.appendChild(option);
		});
		const fontLabel = labeled(t('DocStudioFont'), fontSelect);
		const boldLabel = document.createElement('label');
		boldLabel.className = 'docstudio-check ds-option';
		const boldBox = document.createElement('input');
		boldBox.type = 'checkbox';
		boldLabel.append(boldBox, document.createTextNode(' ' + t('DocStudioBold')));
		const color = document.createElement('input');
		color.type = 'color';
		color.value = '#1f2328';
		color.className = 'ds-color';
		const colorLabel = labeled(t('DocStudioColor'), color);
		const size = document.createElement('input');
		size.type = 'number';
		size.min = '1';
		size.max = '96';
		size.value = '12';
		size.className = 'ds-size';
		const sizeLabel = labeled(t('DocStudioSize'), size);
		const cropAllLabel = document.createElement('label');
		cropAllLabel.className = 'docstudio-check ds-option';
		const cropAllBox = document.createElement('input');
		cropAllBox.type = 'checkbox';
		cropAllBox.addEventListener('change', function () {
			cropAll = cropAllBox.checked;
			setDirty();
			redrawAll();
		});
		cropAllLabel.append(cropAllBox, document.createTextNode(' ' + t('DocStudioCropAll')));
		const undoZone = ctx.utils.button(t('DocStudioUndoZone'), t('DocStudioUndoZone'), function () {
			const last = objects.filter(function (o) {
				return o.kind === TOOL_REDACT || o.kind === TOOL_CROP || o.kind === 'line' || o.kind === 'mark';
			}).pop();
			if (last) {
				removeObject(last);
			}
		});
		const imageButton = ctx.utils.button(t('DocStudioChooseImage'), t('DocStudioChooseImage'), function () {
			eventBus.dispatch('switchannotationeditorparams', { source: null, type: pdfjsLib.AnnotationEditorParamsType.CREATE, value: null });
		});
		const hint = document.createElement('span');
		hint.className = 'docstudio-pageinfo ds-hint';

		function syncBold() {
			const font = fontOf(fontSelect.value);
			const canBold = !font.url || !!font.urlBold;
			boldBox.disabled = !canBold;
			if (!canBold) {
				boldBox.checked = false;
			}
		}

		function dispatchParams() {
			const P = pdfjsLib.AnnotationEditorParamsType;
			if (tool === TOOL_DRAW) {
				eventBus.dispatch('switchannotationeditorparams', { source: null, type: P.INK_COLOR, value: color.value });
				eventBus.dispatch('switchannotationeditorparams', { source: null, type: P.INK_THICKNESS, value: Math.min(20, Number(size.value)) });
			} else if (tool === TOOL_HIGHLIGHT) {
				eventBus.dispatch('switchannotationeditorparams', { source: null, type: P.HIGHLIGHT_COLOR, value: color.value });
			}
		}

		function onParamsChange() {
			if (tool === TOOL_TEXT || (tool === TOOL_NONE && selectedText)) {
				applyToSelectedText();
			}
			dispatchParams();
		}
		[fontSelect, boldBox, color, size].forEach(function (input) {
			input.addEventListener(input.type === 'number' || input.type === 'color' ? 'input' : 'change', function () {
				if (input === fontSelect) {
					syncBold();
				}
				onParamsChange();
			});
		});

		const hints = {};
		hints[TOOL_NONE] = t('DocStudioHintSelect');
		hints[TOOL_TEXT] = t('DocStudioHintText');
		hints[TOOL_DRAW] = t('DocStudioHintDraw');
		hints[TOOL_HIGHLIGHT] = t('DocStudioHintHighlight');
		hints[TOOL_LINE] = t('DocStudioHintLine');
		hints[TOOL_CHECK] = t('DocStudioHintCheck');
		hints[TOOL_CROSS] = t('DocStudioHintCheck');
		hints[TOOL_DOT] = t('DocStudioHintCheck');

		function showParams() {
			const def = toolOf(tool);
			const textParams = tool === TOOL_TEXT || (tool === TOOL_NONE && selectedText !== null);
			toolName.replaceChildren(icon(def.icon), document.createTextNode(' ' + def.label));
			fontLabel.hidden = !textParams;
			boldLabel.hidden = !textParams;
			colorLabel.hidden = !(textParams || [TOOL_DRAW, TOOL_HIGHLIGHT, TOOL_LINE].concat(MARK_TOOLS).includes(tool));
			sizeLabel.hidden = !(textParams || [TOOL_DRAW, TOOL_LINE].concat(MARK_TOOLS).includes(tool));
			cropAllLabel.hidden = tool !== TOOL_CROP;
			undoZone.hidden = ![TOOL_REDACT, TOOL_CROP, TOOL_LINE].concat(MARK_TOOLS).includes(tool);
			imageButton.hidden = tool !== TOOL_IMAGE;
			hint.textContent = textParams && tool === TOOL_NONE ? '' : (hints[tool] || '');
		}

		/** Editor of pdf.js matching the current tool (none for the tools of DocStudio) */
		function applyEditorMode() {
			const mode = toolOf(tool).mode;
			try {
				if (pdfViewer.annotationEditorMode !== mode) {
					pdfViewer.annotationEditorMode = { mode: mode };
				}
			} catch (e) {
				// Editors not ready yet: applied by the event annotationeditoruimanager
			}
		}

		function selectTool(code) {
			const def = toolOf(code);
			const previous = tool;
			tool = code;
			if (def.group) {
				groupCurrent[def.group] = code;
				const btn = slotButtons[def.group];
				btn.replaceChild(icon(def.icon), btn.querySelector('svg'));
				btn.title = tipOf(def);
				btn.setAttribute('aria-label', def.label);
			}
			Object.keys(slotButtons).forEach(function (slot) {
				const active = slot === code || (!!def.group && slot === def.group);
				slotButtons[slot].classList.toggle('docstudio-active', active);
				slotButtons[slot].setAttribute('aria-pressed', active ? 'true' : 'false');
			});
			applyEditorMode();
			if (code !== TOOL_TEXT && code !== TOOL_NONE) {
				selectedText = null;
			}
			if (previous !== code) {
				if (code === TOOL_HIGHLIGHT || code === TOOL_LINE) {
					color.value = '#ffd400';
					size.value = '12';
				} else if (code === TOOL_DRAW) {
					color.value = '#c62828';
					size.value = '3';
				} else if (code === TOOL_TEXT) {
					color.value = '#1f2328';
					size.value = '12';
				} else if (MARK_TOOLS.includes(code) && !MARK_TOOLS.includes(previous)) {
					color.value = '#1f2328';
					size.value = '14';
				}
			}
			showParams();
			window.setTimeout(function () {
				dispatchParams();
				if (code === TOOL_IMAGE && previous !== TOOL_IMAGE) {
					// Opens the choice of the image file
					eventBus.dispatch('switchannotationeditorparams', { source: null, type: pdfjsLib.AnnotationEditorParamsType.CREATE, value: null });
				}
			}, 50);
			redrawAll();
		}

		// Shortcuts of the tools (not while typing)
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeFlyout();
				return;
			}
			const active = document.activeElement;
			if (e.ctrlKey || e.metaKey || e.altKey || (active && (active.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA'].includes(active.tagName)))
				|| (active && active.closest && active.closest('.annotationEditorLayer'))) {
				return;
			}
			const def = tools.find(function (x) {
				return x.key !== '' && x.key === e.key.toLowerCase();
			});
			if (def) {
				e.preventDefault();
				selectTool(def.code);
			}
		});

		ctx.toolbar.append(pageIndicator, toolName, fontLabel, boldLabel, sizeLabel, colorLabel, cropAllLabel, imageButton, undoZone, hint);
		syncBold();

		setDocument(pdfDocument);
		drawThumbs(order.slice());
		selectTool(TOOL_NONE);
		applyActive();

		/* Build the new PDF */

		/**
		 * Text lying under the redaction zones, to check that the new file no longer contains it
		 * @returns {Promise<string[]>}
		 */
		async function textsUnderRedaction() {
			const secrets = [];
			for (const z of objects.filter(function (o) {
				return o.kind === 'redact';
			})) {
				const page = await pdfDocument.getPage(z.page);
				const content = await page.getTextContent();
				content.items.forEach(function (item) {
					if (!item.str || item.str.trim().length < 4) {
						return;
					}
					const x = item.transform[4];
					const y = item.transform[5];
					const w = item.width || 0;
					const h = item.height || Math.abs(item.transform[3]) || 1;
					if (x < z.rect[2] && x + w > z.rect[0] && y < z.rect[3] && y + h > z.rect[1]) {
						secrets.push(item.str.trim());
					}
				});
			}
			// Texts of DocStudio under a zone are hidden too
			objects.filter(function (o) {
				return o.kind === 'text';
			}).forEach(function (o) {
				if (objects.some(function (z) {
					return z.kind === 'redact' && z.page === o.page && o.x < z.rect[2] && o.x > z.rect[0] - 1 && o.y < z.rect[3] + 1 && o.y > z.rect[1];
				})) {
					o.text.split('\n').forEach(function (line) {
						if (line.trim().length >= 4) {
							secrets.push(line.trim());
						}
					});
				}
			});
			return secrets;
		}

		/**
		 * Write the texts and straight lines of DocStudio in the pages
		 * @param {Object} doc pdf-lib document
		 */
		async function writeObjects(doc) {
			const embedded = {};
			const needsFontkit = objects.some(function (o) {
				return o.kind === 'text' && fontOf(o.font).url;
			});
			if (needsFontkit) {
				await loadScript(ctx.config.fontkit);
				doc.registerFontkit(window.fontkit);
			}
			async function embed(fontKey, bold) {
				const font = fontOf(fontKey);
				const key = font.key + (bold ? '-b' : '');
				if (!embedded[key]) {
					if (font.standard) {
						embedded[key] = await doc.embedFont(PDFLib.StandardFonts[bold ? font.standardBold : font.standard]);
					} else {
						const url = bold && font.urlBold ? font.urlBold : font.url;
						const bytes = await (await fetch(url, { credentials: 'same-origin' })).arrayBuffer();
						embedded[key] = await doc.embedFont(bytes, { subset: true });
					}
				}
				return embedded[key];
			}
			for (const o of objects) {
				const page = doc.getPage(o.page - 1);
				if (o.kind === 'line') {
					page.drawLine({
						start: { x: o.p0[0], y: o.p0[1] },
						end: { x: o.p1[0], y: o.p1[1] },
						thickness: o.width,
						color: rgbOf(o.color),
						opacity: 0.4,
						lineCap: PDFLib.LineCapStyle.Butt
					});
				} else if (o.kind === 'mark' && o.symbol === TOOL_DOT) {
					// Filled circle: radius 6 in the box of 24, like the icon
					page.drawCircle({ x: o.x, y: o.y, size: o.size / 4, color: rgbOf(o.color) });
				} else if (o.kind === 'mark') {
					// Vector check or cross, the same as shown: top left corner of its box, direction of the page as shown
					const viewport = (await pdfDocument.getPage(o.page)).getViewport({ scale: 1 });
					const c = viewport.convertToViewportPoint(o.x, o.y);
					const a = viewport.convertToPdfPoint(c[0] - o.size / 2, c[1] - o.size / 2);
					const b = viewport.convertToPdfPoint(c[0] - o.size / 2 + 10, c[1] - o.size / 2);
					page.drawSvgPath(MARK_PATHS[o.symbol] || MARK_PATHS.check, {
						x: a[0],
						y: a[1],
						scale: o.size / 24,
						rotate: PDFLib.degrees(Math.atan2(b[1] - a[1], b[0] - a[0]) * 180 / Math.PI),
						borderColor: rgbOf(o.color),
						borderWidth: 2,
						borderLineCap: PDFLib.LineCapStyle.Round
					});
				} else if (o.kind === 'text' && o.text.trim() !== '') {
					const pdfFont = await embed(o.font, o.bold);
					// Direction of the text as shown (rotated pages): from the viewport of the page at scale 1
					const viewport = (await pdfDocument.getPage(o.page)).getViewport({ scale: 1 });
					const origin = viewport.convertToViewportPoint(o.x, o.y);
					const lines = o.text.split('\n');
					for (let i = 0; i < lines.length; i++) {
						const base = [origin[0], origin[1] + (ASCENT + i * LINE_HEIGHT) * o.size];
						const a = viewport.convertToPdfPoint(base[0], base[1]);
						const b = viewport.convertToPdfPoint(base[0] + 10, base[1]);
						const angle = Math.atan2(b[1] - a[1], b[0] - a[0]) * 180 / Math.PI;
						try {
							page.drawText(lines[i], { x: a[0], y: a[1], size: o.size, font: pdfFont, color: rgbOf(o.color), rotate: PDFLib.degrees(angle) });
						} catch (e) {
							throw new Error(t('DocStudioFontCharsetError', fontOf(o.font).label));
						}
					}
				}
			}
		}

		/**
		 * @param {string} hex #rrggbb
		 * @returns {Object} pdf-lib color
		 */
		function rgbOf(hex) {
			const v = /^#([0-9a-f]{6})$/i.test(hex) ? parseInt(hex.slice(1), 16) : 0;
			return PDFLib.rgb(((v >> 16) & 255) / 255, ((v >> 8) & 255) / 255, (v & 255) / 255);
		}

		/**
		 * Load a script of the module once (fontkit, only when an uploaded font is used)
		 * @param {string} src
		 * @returns {Promise<void>}
		 */
		function loadScript(src) {
			if (window.fontkit) {
				return Promise.resolve();
			}
			return new Promise(function (resolve, reject) {
				const script = document.createElement('script');
				script.src = src;
				script.onload = resolve;
				script.onerror = reject;
				document.head.appendChild(script);
			});
		}

		/**
		 * Image of a page with its redaction zones painted black, size of the visible area of the page, not rotated
		 * @param {Object} renderDoc pdf.js document of the content with the texts and lines written
		 * @param {number} pageNumber
		 * @returns {Promise<Object>}
		 */
		async function redactedImage(renderDoc, pageNumber) {
			const page = await renderDoc.getPage(pageNumber);
			const viewport = page.getViewport({ scale: REDACT_DPI / 72, rotation: 0 });
			const canvas = document.createElement('canvas');
			canvas.width = Math.ceil(viewport.width);
			canvas.height = Math.ceil(viewport.height);
			const context = canvas.getContext('2d');
			context.fillStyle = '#ffffff';
			context.fillRect(0, 0, canvas.width, canvas.height);
			await page.render({ canvas: canvas, canvasContext: context, viewport: viewport, intent: 'print', annotationMode: pdfjsLib.AnnotationMode.ENABLE }).promise;
			context.fillStyle = '#000000';
			objects.filter(function (o) {
				return o.kind === 'redact' && o.page === pageNumber;
			}).forEach(function (o) {
				const r = viewport.convertToViewportRectangle(o.rect);
				context.fillRect(Math.min(r[0], r[2]) - 1, Math.min(r[1], r[3]) - 1, Math.abs(r[2] - r[0]) + 2, Math.abs(r[3] - r[1]) + 2);
			});
			const blob = await new Promise(function (resolve) {
				canvas.toBlob(resolve, 'image/jpeg', 0.92);
			});
			const base = page.getViewport({ scale: 1, rotation: 0 });
			return { bytes: new Uint8Array(await blob.arrayBuffer()), width: base.width, height: base.height, view: page.view };
		}

		/**
		 * @returns {number[][]} page numbers of each file (several when the PDF is split)
		 */
		function parts() {
			const result = [[]];
			order.forEach(function (pageNumber) {
				if (deleted.has(pageNumber)) {
					return;
				}
				result[result.length - 1].push(pageNumber);
				if (cuts.has(pageNumber)) {
					result.push([]);
				}
			});
			return result.filter(function (list) {
				return list.length;
			});
		}

		/**
		 * @param {function(string)} progress
		 * @returns {Promise<{bytes: Uint8Array, redacted: boolean}>} the whole new PDF, pages in the new order
		 */
		async function buildPdf(progress) {
			progress(t('DocStudioPreparing'));
			// Read from the document shown, before any other PDF is opened (shared page counter of pdf.js)
			const secrets = await textsUnderRedaction();
			const annotated = await pdfDocument.saveDocument();
			lastAnnotated = annotated.slice(0);
			let src;
			try {
				src = await PDFLib.PDFDocument.load(annotated, { updateMetadata: false });
			} catch (e) {
				if (e && /encrypt/i.test(String(e.message || e.name))) {
					throw new Error(t('DocStudioEncrypted'));
				}
				throw e;
			}
			const pageTotal = src.getPageCount();

			// Texts and straight lines written in the pages first (so a redaction covers them too)
			if (objects.some(function (o) {
				return o.kind === 'text' || o.kind === 'line' || o.kind === 'mark';
			})) {
				await writeObjects(src);
			}

			const redactPages = new Set(objects.filter(function (o) {
				return o.kind === 'redact';
			}).map(function (o) {
				return o.page;
			}));
			const crop = lastCrop();
			const cropOf = function (pageNumber) {
				const own = objects.find(function (o) {
					return o.kind === 'crop' && o.page === pageNumber;
				});
				return own ? own.rect : (cropAll && crop ? crop.rect : null);
			};
			const finalOrder = order.filter(function (p) {
				return !deleted.has(p);
			});

			src.getPages().forEach(function (page, index) {
				const c = cropOf(index + 1);
				if (c) {
					page.setCropBox(c[0], c[1], c[2] - c[0], c[3] - c[1]);
				}
			});

			let out;
			if (redactPages.size) {
				// New document: nothing of the source comes with it except the pages (no metadata, bookmarks,
				// attachments, form fields nor previous versions), redacted pages replaced by their image
				const written = await src.save();
				const renderDoc = await pdfjsLib.getDocument(Object.assign({ data: written.slice(0) }, docOptions)).promise;
				const clean = await PDFLib.PDFDocument.load(written, { updateMetadata: false });
				out = await PDFLib.PDFDocument.create({ updateMetadata: false });
				for (const pageNumber of finalOrder) {
					if (redactPages.has(pageNumber)) {
						progress(t('DocStudioPreparing') + ' ' + pageNumber + '/' + pageTotal);
						const image = await redactedImage(renderDoc, pageNumber);
						const jpg = await out.embedJpg(image.bytes);
						const page = out.addPage([image.width, image.height]);
						page.drawImage(jpg, { x: 0, y: 0, width: image.width, height: image.height });
						page.setRotation(clean.getPage(pageNumber - 1).getRotation());
						const c = cropOf(pageNumber);
						if (c) {
							page.setCropBox(c[0] - image.view[0], c[1] - image.view[1], c[2] - c[0], c[3] - c[1]);
						}
					} else {
						const copied = await out.copyPages(clean, [pageNumber - 1]);
						out.addPage(copied[0]);
					}
				}
				renderDoc.destroy();
				out.setTitle('');
				out.setAuthor('');
				out.setSubject('');
				out.setKeywords([]);
				out.setCreator('');
				out.setProducer('DocStudio');
			} else {
				out = src;
				const unchanged = finalOrder.length === pageTotal && finalOrder.every(function (p, i) {
					return p === i + 1;
				});
				if (!unchanged) {
					const copies = await src.copyPages(src, finalOrder.map(function (p) {
						return p - 1;
					}));
					for (let i = pageTotal - 1; i >= 0; i--) {
						src.removePage(i);
					}
					copies.forEach(function (page) {
						src.addPage(page);
					});
				}
			}

			const bytes = await out.save({ useObjectStreams: true });
			if (redactPages.size) {
				progress(t('DocStudioPreparing'));
				await checkRedaction(bytes, secrets);
			}
			return { bytes: bytes, redacted: redactPages.size > 0 };
		}

		/**
		 * The text under the redaction zones must be nowhere in the new file (text of the pages, raw content)
		 * @param {Uint8Array} bytes
		 * @param {string[]} secrets
		 */
		async function checkRedaction(bytes, secrets) {
			if (!secrets.length) {
				return;
			}
			const check = await pdfjsLib.getDocument(Object.assign({ data: bytes.slice(0) }, docOptions)).promise;
			let all = '';
			for (let i = 1; i <= check.numPages; i++) {
				const content = await (await check.getPage(i)).getTextContent();
				all += ' ' + content.items.map(function (item) {
					return item.str || '';
				}).join(' ');
			}
			check.destroy();
			const raw = new TextDecoder('latin1').decode(bytes);
			if (secrets.some(function (s) {
				return all.indexOf(s) >= 0 || raw.indexOf(s) >= 0;
			})) {
				throw new Error(t('DocStudioRedactCheckFailed'));
			}
		}

		/**
		 * Files to save: the whole PDF, or one file per part when it is split
		 * @param {Uint8Array} bytes Whole new PDF (pages in the new order)
		 * @returns {Promise<Uint8Array[]>}
		 */
		async function splitFiles(bytes) {
			const list = parts();
			if (list.length < 2) {
				return [bytes];
			}
			const whole = await PDFLib.PDFDocument.load(bytes, { updateMetadata: false });
			const files = [];
			let index = 0;
			for (const part of list) {
				const doc = await PDFLib.PDFDocument.create({ updateMetadata: false });
				const indexes = part.map(function (p, i) {
					return index + i;
				});
				index += part.length;
				(await doc.copyPages(whole, indexes)).forEach(function (page) {
					doc.addPage(page);
				});
				files.push(await doc.save({ useObjectStreams: true }));
			}
			return files;
		}

		/* Save dialog */

		const saveButton = document.getElementById('docstudio-save');
		const dialog = document.createElement('dialog');
		dialog.className = 'ds-dialog';
		const form = document.createElement('form');
		form.method = 'dialog';
		const heading = document.createElement('h2');
		heading.textContent = t('DocStudioSaveAs');
		const copyLabel = document.createElement('label');
		copyLabel.className = 'ds-choice';
		const copyRadio = document.createElement('input');
		copyRadio.type = 'radio';
		copyRadio.name = 'mode';
		copyRadio.value = 'copy';
		copyRadio.checked = true;
		copyLabel.append(copyRadio, document.createTextNode(' ' + t('DocStudioSaveCopy')));
		const nameLabel = document.createElement('label');
		nameLabel.className = 'ds-field';
		nameLabel.textContent = t('DocStudioFileName') + ' ';
		const nameInput = document.createElement('input');
		nameInput.type = 'text';
		nameInput.value = ctx.config.copyName;
		nameInput.maxLength = 200;
		nameLabel.appendChild(nameInput);
		const replaceLabel = document.createElement('label');
		replaceLabel.className = 'ds-choice';
		const replaceRadio = document.createElement('input');
		replaceRadio.type = 'radio';
		replaceRadio.name = 'mode';
		replaceRadio.value = 'replace';
		replaceLabel.append(replaceRadio, document.createTextNode(' ' + t('DocStudioSaveReplace')));
		const replaceWarning = document.createElement('p');
		replaceWarning.className = 'ds-warning';
		replaceWarning.textContent = t('DocStudioSaveReplaceWarning');
		const splitInfo = document.createElement('p');
		splitInfo.className = 'ds-warning';
		const impact = document.createElement('div');
		impact.className = 'ds-impact';
		const status = document.createElement('p');
		status.className = 'ds-status';
		status.setAttribute('role', 'status');
		const overwriteLabel = document.createElement('label');
		overwriteLabel.className = 'ds-choice';
		const overwriteBox = document.createElement('input');
		overwriteBox.type = 'checkbox';
		overwriteLabel.append(overwriteBox, document.createTextNode(' ' + t('DocStudioOverwrite')));
		overwriteLabel.hidden = true;
		const buttons = document.createElement('div');
		buttons.className = 'ds-dialog-buttons';
		const cancel = ctx.utils.button(t('DocStudioCancel'), '', function () {
			dialog.close();
		});
		const confirm = ctx.utils.button(t('DocStudioSave'), '', function () {
			doSave();
		});
		confirm.classList.add('docstudio-primary');
		buttons.append(cancel, confirm);
		form.append(heading, copyLabel, nameLabel, replaceLabel, replaceWarning, splitInfo, impact, overwriteLabel, status, buttons);
		dialog.appendChild(form);
		document.body.appendChild(dialog);

		/**
		 * @returns {string[]} names of the files saved
		 */
		function fileNames() {
			const count = parts().length;
			const name = nameInput.value.trim() || ctx.config.copyName;
			if (count < 2) {
				return [name];
			}
			const base = name.replace(/\.pdf$/i, '');
			const names = [];
			for (let i = 1; i <= count; i++) {
				names.push(base + '-' + i + '.pdf');
			}
			return names;
		}

		function syncDialog() {
			const split = parts().length > 1;
			if (split) {
				copyRadio.checked = true;
			}
			replaceRadio.disabled = split;
			nameInput.disabled = !copyRadio.checked;
			replaceWarning.hidden = !replaceRadio.checked;
			splitInfo.hidden = !split;
			splitInfo.textContent = split ? t('DocStudioSplitParts', parts().length, fileNames().join(', ')) : '';
			if (replaceRadio.checked) {
				overwriteLabel.hidden = true;
			}
		}
		copyRadio.addEventListener('change', syncDialog);
		replaceRadio.addEventListener('change', syncDialog);
		nameInput.addEventListener('input', function () {
			overwriteLabel.hidden = true;
			overwriteBox.checked = false;
			syncDialog();
		});

		saveButton.addEventListener('click', function () {
			if (document.activeElement && document.activeElement.isContentEditable) {
				document.activeElement.blur();
			}
			impact.replaceChildren();
			if (objects.some(function (o) {
				return o.kind === 'redact';
			})) {
				const p = document.createElement('p');
				p.textContent = t('DocStudioRedactImpact');
				impact.appendChild(p);
			}
			if (objects.some(function (o) {
				return o.kind === 'crop';
			})) {
				const p = document.createElement('p');
				p.textContent = t('DocStudioCropImpact');
				impact.appendChild(p);
			}
			status.textContent = '';
			syncDialog();
			dialog.showModal();
		});

		let saving = false;
		async function doSave() {
			if (saving) {
				return;
			}
			saving = true;
			confirm.disabled = true;
			try {
				const built = await buildPdf(function (text) {
					status.textContent = text;
				});
				const files = await splitFiles(built.bytes);
				const names = fileNames();
				const saved = [];
				for (let i = 0; i < files.length; i++) {
					status.textContent = t('DocStudioSaving') + (files.length > 1 ? ' ' + (i + 1) + '/' + files.length : '');
					const result = await window.DocStudio.saveContent(new Blob([files[i]], { type: 'application/pdf' }), {
						mode: (files.length === 1 && replaceRadio.checked) ? 'replace' : 'copy',
						filename: names[i],
						overwrite: overwriteBox.checked
					});
					if (result.result > 0) {
						saved.push(result.filename);
						continue;
					}
					if (result.exists) {
						overwriteLabel.hidden = false;
						status.textContent = t('DocStudioFileExists', result.filename) + (saved.length ? ' ' + t('DocStudioPartsSaved', saved.join(', ')) : '');
					} else {
						status.textContent = (result.error || t('DocStudioErrorSave')) + (saved.length ? ' ' + t('DocStudioPartsSaved', saved.join(', ')) : '');
					}
					if (lastAnnotated) {
						await reloadDocument(lastAnnotated);
					}
					return;
				}
				dirty = false;
				window.location.href = ctx.config.backUrl;
			} catch (e) {
				console.error(e);
				status.textContent = (e && e.message) ? e.message : t('DocStudioErrorSave');
				// Other PDFs were opened to build the file: the document shown is opened again, with the annotations
				if (lastAnnotated) {
					try {
						await reloadDocument(lastAnnotated);
					} catch (e2) {
						console.error(e2);
					}
				}
			} finally {
				saving = false;
				confirm.disabled = false;
			}
		}

		function onBeforeUnload(e) {
			if (dirty) {
				e.preventDefault();
				e.returnValue = t('DocStudioUnsaved');
			}
		}
		window.addEventListener('beforeunload', onBeforeUnload);

		return {
			unmount() {
				window.removeEventListener('beforeunload', onBeforeUnload);
				document.removeEventListener('keydown', onKey);
				dialog.remove();
				pdfViewer.setDocument(null);
				pdfDocument.destroy();
			}
		};
	}
});

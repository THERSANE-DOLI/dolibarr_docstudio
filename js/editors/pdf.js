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
 * - annotations with the editors of pdf.js: text, drawing, highlight, image;
 * - pages: rotate, delete, reorder (panel of the pages, drag and drop);
 * - crop: visible area of a page (or of every page); the content outside stays in the file;
 * - redaction: every page with a redacted zone is replaced by an image of the page with the zones painted black,
 *   then the result is checked (the text under the zones must be nowhere in the new file).
 * Saving: annotations written by pdf.js, then pages, crop and redaction with pdf-lib, then DocStudio.saveContent().
 * Safety of the display: eval disabled, no XFA, no script, links of the PDF inactive.
 */

const TOOL_NONE = 'none';
const TOOL_REDACT = 'redact';
const TOOL_CROP = 'crop';
const REDACT_DPI = 200;

window.DocStudio.registerViewer('pdfedit', {
	async mount(ctx) {
		const conf = ctx.config.pdfjs;
		const t = ctx.t;
		const pdfjsLib = await import(conf.lib);
		pdfjsLib.GlobalWorkerOptions.workerSrc = conf.worker;
		// The components of the viewer of pdf.js read the library from here
		globalThis.pdfjsLib = pdfjsLib;
		const pdfjsViewer = await import(conf.viewer);

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
		const pdfDocument = await loadingTask.promise;
		const pageCount = pdfDocument.numPages;

		/* State */

		let dirty = false;
		let tool = TOOL_NONE;
		const zones = [];				// {id, page (1-based), kind: 'redact'|'crop', rect: [x1, y1, x2, y2] in PDF units}
		const order = [];				// original page numbers in the new order
		const rotation = {};			// page => added rotation (0, 90, 180, 270)
		const deleted = new Set();		// deleted page numbers
		let cropAll = false;
		let zoneId = 0;
		for (let i = 1; i <= pageCount; i++) {
			order.push(i);
			rotation[i] = 0;
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
		pdfViewer.setDocument(pdfDocument);
		linkService.setDocument(pdfDocument, null);
		eventBus.on('pagesinit', function () {
			pdfViewer.currentScaleValue = 'page-width';
			if (pdfViewer.currentScale > 1.25) {
				pdfViewer.currentScale = 1.25;
			}
		});

		function setDirty() {
			if (!dirty) {
				dirty = true;
				saveButton.disabled = false;
			}
		}
		pdfDocument.annotationStorage.onSetModified = setDirty;

		/* Zones (redaction, crop) drawn over the pages */

		/**
		 * @param {number} pageNumber
		 * @returns {HTMLElement|null} layer of the zones of a page, created when needed
		 */
		function zoneLayer(pageNumber) {
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
				layer.addEventListener('pointerdown', startDrawing);
			}
			return layer;
		}

		function drawZones(pageNumber) {
			const layer = zoneLayer(pageNumber);
			if (!layer) {
				return;
			}
			const viewport = pdfViewer.getPageView(pageNumber - 1).viewport;
			layer.replaceChildren();
			layer.classList.toggle('ds-zone-active', tool === TOOL_REDACT || tool === TOOL_CROP);
			zones.filter(function (z) {
				return z.page === pageNumber || (z.kind === 'crop' && cropAll && z.id === lastCropId());
			}).forEach(function (z) {
				const r = viewport.convertToViewportRectangle(z.rect);
				const el = document.createElement('div');
				el.className = 'ds-zone ds-zone-' + z.kind;
				el.style.left = Math.min(r[0], r[2]) + 'px';
				el.style.top = Math.min(r[1], r[3]) + 'px';
				el.style.width = Math.abs(r[2] - r[0]) + 'px';
				el.style.height = Math.abs(r[3] - r[1]) + 'px';
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
					removeZone(z.id);
				});
				el.appendChild(remove);
				layer.appendChild(el);
			});
		}

		function redrawAllZones() {
			for (let i = 1; i <= pageCount; i++) {
				drawZones(i);
			}
			updateNotice();
		}

		/** @returns {number} id of the last crop zone drawn, -1 if none */
		function lastCropId() {
			const crops = zones.filter(function (z) {
				return z.kind === 'crop';
			});
			return crops.length ? crops[crops.length - 1].id : -1;
		}

		function removeZone(id) {
			const index = zones.findIndex(function (z) {
				return z.id === id;
			});
			if (index >= 0) {
				zones.splice(index, 1);
				setDirty();
				redrawAllZones();
			}
		}

		/**
		 * Draw a zone with the pointer on a page
		 * @param {PointerEvent} e
		 */
		function startDrawing(e) {
			if ((tool !== TOOL_REDACT && tool !== TOOL_CROP) || e.button !== 0) {
				return;
			}
			e.preventDefault();
			const layer = e.currentTarget;
			const pageNumber = Number(layer.dataset.page);
			const box = layer.getBoundingClientRect();
			const x0 = e.clientX - box.left;
			const y0 = e.clientY - box.top;
			const ghost = document.createElement('div');
			ghost.className = 'ds-zone ds-zone-' + tool + ' ds-zone-ghost';
			layer.appendChild(ghost);
			layer.setPointerCapture(e.pointerId);
			let x1 = x0;
			let y1 = y0;

			function move(ev) {
				x1 = Math.max(0, Math.min(box.width, ev.clientX - box.left));
				y1 = Math.max(0, Math.min(box.height, ev.clientY - box.top));
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
				if (Math.abs(x1 - x0) < 4 || Math.abs(y1 - y0) < 4) {
					return;
				}
				const viewport = pdfViewer.getPageView(pageNumber - 1).viewport;
				const p0 = viewport.convertToPdfPoint(Math.min(x0, x1), Math.min(y0, y1));
				const p1 = viewport.convertToPdfPoint(Math.max(x0, x1), Math.max(y0, y1));
				const rect = [Math.min(p0[0], p1[0]), Math.min(p0[1], p1[1]), Math.max(p0[0], p1[0]), Math.max(p0[1], p1[1])];
				if (tool === TOOL_CROP) {
					// One crop per page
					for (let i = zones.length - 1; i >= 0; i--) {
						if (zones[i].kind === 'crop' && zones[i].page === pageNumber) {
							zones.splice(i, 1);
						}
					}
				}
				zones.push({ id: ++zoneId, page: pageNumber, kind: tool, rect: rect });
				setDirty();
				redrawAllZones();
			}

			layer.addEventListener('pointermove', move);
			layer.addEventListener('pointerup', end);
			layer.addEventListener('pointercancel', end);
		}

		eventBus.on('pagerendered', function (e) {
			drawZones(e.pageNumber);
		});

		function updateNotice() {
			const texts = [];
			if (tool === TOOL_REDACT || zones.some(function (z) {
				return z.kind === 'redact';
			})) {
				texts.push(t('DocStudioRedactHelp'));
			}
			if (tool === TOOL_CROP || zones.some(function (z) {
				return z.kind === 'crop';
			})) {
				texts.push(t('DocStudioCropHelp'));
			}
			notice.hidden = !texts.length;
			notice.textContent = texts.join(' ');
		}

		/* Pages panel: thumbnails, rotate, delete, drag and drop to reorder */

		const thumbs = {};
		let dragged = null;

		async function drawThumb(pageNumber) {
			const page = await pdfDocument.getPage(pageNumber);
			const base = page.getViewport({ scale: 1 });
			const viewport = page.getViewport({ scale: 120 / base.width });
			const canvas = document.createElement('canvas');
			canvas.width = Math.floor(viewport.width);
			canvas.height = Math.floor(viewport.height);
			await page.render({ canvas: canvas, canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
			thumbs[pageNumber].querySelector('.ds-thumb-image').replaceChildren(canvas);
		}

		function refreshThumb(pageNumber) {
			const el = thumbs[pageNumber];
			el.classList.toggle('ds-thumb-deleted', deleted.has(pageNumber));
			el.querySelector('.ds-thumb-image').style.transform = 'rotate(' + rotation[pageNumber] + 'deg)';
			el.querySelector('.ds-thumb-delete').textContent = deleted.has(pageNumber) ? '↺' : '\u{1F5D1}';
			el.querySelector('.ds-thumb-delete').title = deleted.has(pageNumber) ? t('DocStudioRestorePage') : t('DocStudioDeletePage');
		}

		function renderPanel() {
			pagesPanel.replaceChildren();
			const title = document.createElement('div');
			title.className = 'ds-pages-title';
			title.textContent = t('DocStudioPages');
			pagesPanel.appendChild(title);
			order.forEach(function (pageNumber) {
				pagesPanel.appendChild(thumbs[pageNumber]);
			});
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
				pdfViewer.currentPageNumber = pageNumber;
			});
			const bar = document.createElement('div');
			bar.className = 'ds-thumb-bar';
			const label = document.createElement('span');
			label.textContent = String(pageNumber);
			const rotate = ctx.utils.button('↻', t('DocStudioRotate'), function () {
				rotation[pageNumber] = (rotation[pageNumber] + 90) % 360;
				setDirty();
				refreshThumb(pageNumber);
			});
			const del = ctx.utils.button('', t('DocStudioDeletePage'), function () {
				if (deleted.has(pageNumber)) {
					deleted.delete(pageNumber);
				} else if (deleted.size < pageCount - 1) {
					deleted.add(pageNumber);
				}
				setDirty();
				refreshThumb(pageNumber);
			});
			del.classList.add('ds-thumb-delete');
			bar.append(label, rotate, del);
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
				const from = order.indexOf(dragged);
				order.splice(from, 1);
				const box = el.getBoundingClientRect();
				const after = e.clientY > box.top + box.height / 2;
				order.splice(order.indexOf(pageNumber) + (after ? 1 : 0), 0, dragged);
				setDirty();
				renderPanel();
			});
			thumbs[pageNumber] = el;
			refreshThumb(pageNumber);
		}
		renderPanel();
		(async function () {
			for (let i = 1; i <= pageCount; i++) {
				try {
					await drawThumb(i);
				} catch (e) {
					// Thumbnail not drawn, the number stays
				}
			}
		})();

		/* Toolbar */

		const tools = [
			{ code: TOOL_NONE, label: t('DocStudioToolSelect'), mode: pdfjsLib.AnnotationEditorType.NONE },
			{ code: 'text', label: t('DocStudioToolText'), mode: pdfjsLib.AnnotationEditorType.FREETEXT },
			{ code: 'draw', label: t('DocStudioToolDraw'), mode: pdfjsLib.AnnotationEditorType.INK },
			{ code: 'highlight', label: t('DocStudioToolHighlight'), mode: pdfjsLib.AnnotationEditorType.HIGHLIGHT },
			{ code: 'image', label: t('DocStudioToolImage'), mode: pdfjsLib.AnnotationEditorType.STAMP },
			{ code: TOOL_REDACT, label: t('DocStudioToolRedact'), mode: pdfjsLib.AnnotationEditorType.NONE },
			{ code: TOOL_CROP, label: t('DocStudioToolCrop'), mode: pdfjsLib.AnnotationEditorType.NONE }
		];
		const toolGroup = document.createElement('span');
		toolGroup.className = 'ds-tools';
		toolGroup.setAttribute('role', 'toolbar');
		const toolButtons = {};

		const color = document.createElement('input');
		color.type = 'color';
		color.value = '#c62828';
		color.title = t('DocStudioColor');
		color.className = 'ds-color';
		const size = document.createElement('input');
		size.type = 'range';
		size.min = '1';
		size.max = '40';
		size.value = '12';
		size.title = t('DocStudioSize');
		size.className = 'ds-size';
		const cropAllLabel = document.createElement('label');
		cropAllLabel.className = 'docstudio-check';
		const cropAllBox = document.createElement('input');
		cropAllBox.type = 'checkbox';
		cropAllBox.addEventListener('change', function () {
			cropAll = cropAllBox.checked;
			setDirty();
			redrawAllZones();
		});
		cropAllLabel.append(cropAllBox, document.createTextNode(' ' + t('DocStudioCropAll')));
		const undoZone = ctx.utils.button('↶', t('DocStudioUndoZone'), function () {
			if (zones.length) {
				removeZone(zones[zones.length - 1].id);
			}
		});

		function dispatchParams() {
			const P = pdfjsLib.AnnotationEditorParamsType;
			const value = Number(size.value);
			if (tool === 'text') {
				eventBus.dispatch('switchannotationeditorparams', { source: null, type: P.FREETEXT_COLOR, value: color.value });
				eventBus.dispatch('switchannotationeditorparams', { source: null, type: P.FREETEXT_SIZE, value: Math.max(6, value) });
			} else if (tool === 'draw') {
				eventBus.dispatch('switchannotationeditorparams', { source: null, type: P.INK_COLOR, value: color.value });
				eventBus.dispatch('switchannotationeditorparams', { source: null, type: P.INK_THICKNESS, value: value });
			} else if (tool === 'highlight') {
				eventBus.dispatch('switchannotationeditorparams', { source: null, type: P.HIGHLIGHT_COLOR, value: color.value });
			}
		}
		color.addEventListener('input', dispatchParams);
		size.addEventListener('input', dispatchParams);

		function selectTool(code) {
			const def = tools.find(function (x) {
				return x.code === code;
			});
			tool = code;
			Object.keys(toolButtons).forEach(function (key) {
				toolButtons[key].classList.toggle('docstudio-active', key === code);
				toolButtons[key].setAttribute('aria-pressed', key === code ? 'true' : 'false');
			});
			try {
				pdfViewer.annotationEditorMode = { mode: def.mode };
			} catch (e) {
				console.error(e);
			}
			color.hidden = !['text', 'draw', 'highlight'].includes(code);
			size.hidden = !['text', 'draw'].includes(code);
			cropAllLabel.hidden = code !== TOOL_CROP;
			undoZone.hidden = code !== TOOL_REDACT && code !== TOOL_CROP;
			if (code === 'highlight' && color.value === '#c62828') {
				color.value = '#ffff98';
			}
			window.setTimeout(function () {
				dispatchParams();
				if (code === 'image') {
					// Opens the choice of the image file
					eventBus.dispatch('switchannotationeditorparams', { source: null, type: pdfjsLib.AnnotationEditorParamsType.CREATE, value: null });
				}
			}, 50);
			redrawAllZones();
		}

		tools.forEach(function (def) {
			const btn = ctx.utils.button(def.label, def.label, function () {
				selectTool(def.code);
			});
			toolButtons[def.code] = btn;
			toolGroup.appendChild(btn);
		});
		ctx.toolbar.append(toolGroup, color, size, cropAllLabel, undoZone);
		selectTool(TOOL_NONE);

		/* Build the new PDF */

		/**
		 * Text lying under the redaction zones, to check that the new file no longer contains it
		 * @returns {Promise<string[]>}
		 */
		async function textsUnderRedaction() {
			const secrets = [];
			for (const z of zones.filter(function (x) {
				return x.kind === 'redact';
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
			return secrets;
		}

		/**
		 * Image of a page with its redaction zones painted black, size of the visible area of the page, not rotated
		 * @param {Object} renderDoc pdf.js document of the annotated content
		 * @param {number} pageNumber
		 * @returns {Promise<{bytes: Uint8Array, width: number, height: number}>}
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
			zones.filter(function (z) {
				return z.kind === 'redact' && z.page === pageNumber;
			}).forEach(function (z) {
				const r = viewport.convertToViewportRectangle(z.rect);
				context.fillRect(Math.min(r[0], r[2]) - 1, Math.min(r[1], r[3]) - 1, Math.abs(r[2] - r[0]) + 2, Math.abs(r[3] - r[1]) + 2);
			});
			const blob = await new Promise(function (resolve) {
				canvas.toBlob(resolve, 'image/jpeg', 0.92);
			});
			const base = page.getViewport({ scale: 1, rotation: 0 });
			return { bytes: new Uint8Array(await blob.arrayBuffer()), width: base.width, height: base.height, view: page.view };
		}

		/**
		 * @param {function(string)} progress
		 * @returns {Promise<Uint8Array>}
		 */
		async function buildPdf(progress) {
			const PDFLib = await import(ctx.config.pdflib);
			progress(t('DocStudioPreparing'));
			const annotated = await pdfDocument.saveDocument();
			let src;
			try {
				src = await PDFLib.PDFDocument.load(annotated, { updateMetadata: false });
			} catch (e) {
				if (e && /encrypt/i.test(String(e.message || e.name))) {
					throw new Error(t('DocStudioEncrypted'));
				}
				throw e;
			}

			const redactPages = new Set(zones.filter(function (z) {
				return z.kind === 'redact';
			}).map(function (z) {
				return z.page;
			}));
			const cropOf = function (pageNumber) {
				const own = zones.find(function (z) {
					return z.kind === 'crop' && z.page === pageNumber;
				});
				if (own) {
					return own.rect;
				}
				if (cropAll) {
					const last = zones.find(function (z) {
						return z.id === lastCropId();
					});
					return last ? last.rect : null;
				}
				return null;
			};
			const finalOrder = order.filter(function (p) {
				return !deleted.has(p);
			});

			// Rotation and crop on the pages of the source
			src.getPages().forEach(function (page, index) {
				const pageNumber = index + 1;
				if (rotation[pageNumber]) {
					page.setRotation(PDFLib.degrees((page.getRotation().angle + rotation[pageNumber]) % 360));
				}
				const crop = cropOf(pageNumber);
				if (crop) {
					page.setCropBox(crop[0], crop[1], crop[2] - crop[0], crop[3] - crop[1]);
				}
			});

			let out;
			if (redactPages.size) {
				// New document: nothing of the source comes with it except the pages (no metadata, bookmarks,
				// attachments, form fields nor previous versions), redacted pages replaced by their image
				const renderDoc = await pdfjsLib.getDocument(Object.assign({ data: annotated.slice(0), password: password || undefined }, docOptions)).promise;
				out = await PDFLib.PDFDocument.create({ updateMetadata: false });
				for (const pageNumber of finalOrder) {
					if (redactPages.has(pageNumber)) {
						progress(t('DocStudioPreparing') + ' ' + pageNumber + '/' + pageCount);
						const image = await redactedImage(renderDoc, pageNumber);
						const jpg = await out.embedJpg(image.bytes);
						const page = out.addPage([image.width, image.height]);
						page.drawImage(jpg, { x: 0, y: 0, width: image.width, height: image.height });
						const srcPage = src.getPage(pageNumber - 1);
						page.setRotation(srcPage.getRotation());
						const crop = cropOf(pageNumber);
						if (crop) {
							// Zone in the units of the page, from the origin of its visible area
							page.setCropBox(crop[0] - image.view[0], crop[1] - image.view[1], crop[2] - crop[0], crop[3] - crop[1]);
						}
					} else {
						const copied = await out.copyPages(src, [pageNumber - 1]);
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
				const unchangedOrder = finalOrder.length === pageCount && finalOrder.every(function (p, i) {
					return p === i + 1;
				});
				if (!unchangedOrder) {
					const copies = await src.copyPages(src, finalOrder.map(function (p) {
						return p - 1;
					}));
					for (let i = pageCount - 1; i >= 0; i--) {
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
				await checkRedaction(bytes, await textsUnderRedaction());
			}
			return bytes;
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
			const found = secrets.filter(function (s) {
				return all.indexOf(s) >= 0 || raw.indexOf(s) >= 0;
			});
			if (found.length) {
				throw new Error(t('DocStudioRedactCheckFailed'));
			}
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
		form.append(heading, copyLabel, nameLabel, replaceLabel, replaceWarning, impact, overwriteLabel, status, buttons);
		dialog.appendChild(form);
		document.body.appendChild(dialog);

		function syncDialog() {
			nameInput.disabled = !copyRadio.checked;
			replaceWarning.hidden = !replaceRadio.checked;
			if (replaceRadio.checked) {
				overwriteLabel.hidden = true;
			}
		}
		copyRadio.addEventListener('change', syncDialog);
		replaceRadio.addEventListener('change', syncDialog);
		nameInput.addEventListener('input', function () {
			overwriteLabel.hidden = true;
			overwriteBox.checked = false;
		});

		saveButton.addEventListener('click', function () {
			impact.replaceChildren();
			if (zones.some(function (z) {
				return z.kind === 'redact';
			})) {
				const p = document.createElement('p');
				p.textContent = t('DocStudioRedactImpact');
				impact.appendChild(p);
			}
			if (zones.some(function (z) {
				return z.kind === 'crop';
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
				const bytes = await buildPdf(function (text) {
					status.textContent = text;
				});
				status.textContent = t('DocStudioSaving');
				const result = await window.DocStudio.saveContent(new Blob([bytes], { type: 'application/pdf' }), {
					mode: replaceRadio.checked ? 'replace' : 'copy',
					filename: nameInput.value,
					overwrite: overwriteBox.checked
				});
				if (result.result > 0) {
					dirty = false;
					window.location.href = ctx.config.backUrl;
					return;
				}
				if (result.exists) {
					overwriteLabel.hidden = false;
					status.textContent = t('DocStudioFileExists', result.filename);
				} else {
					status.textContent = result.error || t('DocStudioErrorSave');
				}
			} catch (e) {
				console.error(e);
				status.textContent = (e && e.message) ? e.message : t('DocStudioErrorSave');
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
				dialog.remove();
				pdfViewer.setDocument(null);
				pdfDocument.destroy();
			}
		};
	}
});

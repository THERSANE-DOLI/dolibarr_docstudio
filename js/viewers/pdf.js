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
 * PDF viewer with pdf.js (embedded, no request outside of Dolibarr).
 * Safety: eval disabled, no XFA forms, no annotation layer (links, forms and scripts of the PDF are not active),
 * only the drawing of the pages and their text (for selection and copy).
 * Pages are drawn when they come into view, again after a zoom.
 */

const ZOOM_STEPS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4];

window.DocStudio.registerViewer('pdf', {
	async mount(ctx) {
		const conf = ctx.config.pdfjs;
		const pdfjsLib = await import(conf.lib);
		pdfjsLib.GlobalWorkerOptions.workerSrc = conf.worker;

		const data = new Uint8Array(await ctx.file.arrayBuffer());
		const task = pdfjsLib.getDocument({
			data: data,
			isEvalSupported: false,
			enableXfa: false,
			cMapUrl: conf.cMapUrl,
			cMapPacked: true,
			standardFontDataUrl: conf.standardFontDataUrl,
			wasmUrl: conf.wasmUrl,
			iccUrl: conf.iccUrl,
			stopAtErrors: false
		});
		task.onPassword = function (updatePassword) {
			const password = window.prompt(ctx.t('DocStudioPdfPassword'));
			if (password === null) {
				task.destroy();
				return;
			}
			updatePassword(password);
		};
		const doc = await task.promise;

		const pagesEl = document.createElement('div');
		pagesEl.className = 'docstudio-pdf-pages';
		ctx.container.appendChild(pagesEl);

		const scroller = ctx.container.closest('.docstudio-main') || ctx.container;
		const pages = [];
		// 'auto': fit to the width without going over 125 %, 'fit': fit to the width, or a fixed scale
		let zoom = 'auto';
		let renderToken = 0;

		// Sizes of every page first (cheap), drawing later when visible
		for (let i = 1; i <= doc.numPages; i++) {
			const page = await doc.getPage(i);
			const wrapper = document.createElement('div');
			wrapper.className = 'docstudio-pdf-page';
			wrapper.dataset.page = String(i);
			pagesEl.appendChild(wrapper);
			pages.push({ page: page, wrapper: wrapper, drawn: -1, task: null, textLayer: null });
		}

		const pageInfo = document.createElement('span');
		pageInfo.className = 'docstudio-pageinfo';

		/**
		 * @returns {number} scale of the pages for the current zoom
		 */
		function currentScale() {
			if (typeof zoom === 'number') {
				return zoom;
			}
			const base = pages[0].page.getViewport({ scale: 1 });
			const available = Math.max(200, scroller.clientWidth - 32);
			return Math.min(zoom === 'auto' ? 1.25 : 4, available / base.width);
		}

		function layout() {
			const scale = currentScale();
			renderToken++;
			pages.forEach(function (p) {
				const viewport = p.page.getViewport({ scale: scale });
				p.wrapper.style.width = Math.floor(viewport.width) + 'px';
				p.wrapper.style.height = Math.floor(viewport.height) + 'px';
				p.drawn = -1;
			});
			pages.forEach(function (p) {
				if (isVisible(p.wrapper)) {
					draw(p);
				}
			});
		}

		/**
		 * @param {HTMLElement} el
		 * @returns {boolean}
		 */
		function isVisible(el) {
			const box = el.getBoundingClientRect();
			const view = scroller.getBoundingClientRect();
			return box.bottom >= view.top - 400 && box.top <= view.bottom + 400;
		}

		/**
		 * Draw a page (canvas + text layer) at the current scale
		 * @param {Object} p
		 */
		async function draw(p) {
			const token = renderToken;
			if (p.drawn === token) {
				return;
			}
			p.drawn = token;
			if (p.task) {
				p.task.cancel();
			}
			if (p.textLayer) {
				p.textLayer.cancel();
			}
			const scale = currentScale();
			const viewport = p.page.getViewport({ scale: scale });
			const ratio = window.devicePixelRatio || 1;

			const canvas = document.createElement('canvas');
			canvas.width = Math.floor(viewport.width * ratio);
			canvas.height = Math.floor(viewport.height * ratio);
			canvas.style.width = Math.floor(viewport.width) + 'px';
			canvas.style.height = Math.floor(viewport.height) + 'px';
			const textDiv = document.createElement('div');
			textDiv.className = 'textLayer';

			p.task = p.page.render({
				canvas: canvas,
				canvasContext: canvas.getContext('2d'),
				viewport: viewport,
				transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null,
				annotationMode: pdfjsLib.AnnotationMode.ENABLE
			});
			try {
				await p.task.promise;
			} catch (e) {
				if (!(e instanceof pdfjsLib.RenderingCancelledException)) {
					console.error(e);
				}
				return;
			}
			if (token !== renderToken) {
				return;
			}
			p.wrapper.replaceChildren(canvas, textDiv);
			p.wrapper.style.setProperty('--scale-factor', String(viewport.scale));
			p.wrapper.style.setProperty('--user-unit', String(p.page.userUnit || 1));
			p.wrapper.style.setProperty('--total-scale-factor', 'calc(var(--scale-factor) * var(--user-unit))');
			p.textLayer = new pdfjsLib.TextLayer({ textContentSource: p.page.streamTextContent(), container: textDiv, viewport: viewport });
			p.textLayer.render().catch(function () {
				// Text not selectable on this page, the drawing stays
			});
		}

		function updatePageInfo() {
			const view = scroller.getBoundingClientRect();
			let currentPage = 1;
			for (const p of pages) {
				if (p.wrapper.getBoundingClientRect().top <= view.top + view.height / 3) {
					currentPage = Number(p.wrapper.dataset.page);
				}
			}
			pageInfo.textContent = ctx.t('DocStudioPage', currentPage, doc.numPages);
		}

		let scrollTimer = null;
		function onScroll() {
			updatePageInfo();
			if (scrollTimer) {
				return;
			}
			scrollTimer = window.setTimeout(function () {
				scrollTimer = null;
				pages.forEach(function (p) {
					if (isVisible(p.wrapper)) {
						draw(p);
					}
				});
			}, 80);
		}

		/**
		 * @param {number} direction 1 or -1
		 */
		function step(direction) {
			const scale = currentScale();
			let next = scale;
			if (direction > 0) {
				next = ZOOM_STEPS.find(function (s) {
					return s > scale + 0.01;
				}) || ZOOM_STEPS[ZOOM_STEPS.length - 1];
			} else {
				next = ZOOM_STEPS.slice().reverse().find(function (s) {
					return s < scale - 0.01;
				}) || ZOOM_STEPS[0];
			}
			zoom = next;
			layout();
		}

		ctx.toolbar.append(
			ctx.utils.button('−', ctx.t('DocStudioZoomOut'), function () {
				step(-1);
			}),
			ctx.utils.button('+', ctx.t('DocStudioZoomIn'), function () {
				step(1);
			}),
			ctx.utils.button('↔', ctx.t('DocStudioZoomFit'), function () {
				zoom = 'fit';
				layout();
			}),
			pageInfo
		);

		let resizeTimer = null;
		function onResize() {
			window.clearTimeout(resizeTimer);
			resizeTimer = window.setTimeout(function () {
				if (typeof zoom !== 'number') {
					layout();
				}
			}, 150);
		}

		scroller.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', onResize);
		layout();
		updatePageInfo();

		return {
			unmount() {
				scroller.removeEventListener('scroll', onScroll);
				window.removeEventListener('resize', onResize);
				pages.forEach(function (p) {
					if (p.task) {
						p.task.cancel();
					}
				});
				doc.destroy();
			}
		};
	}
});

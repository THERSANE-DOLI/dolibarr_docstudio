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
 * only the drawing of the pages and their text (for selection, copy and search).
 * Pages are drawn when they come into view, again after a zoom.
 * Tools: zoom, search in the text (Ctrl+F), thumbnails, print (pages drawn as images, nothing of the PDF runs).
 */

const ZOOM_STEPS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4];
const THUMB_WIDTH = 140;
const PRINT_SCALE = 2;

/**
 * Text for search: lowercase, without accents, spaces reduced
 * @param {string} text
 * @returns {string}
 */
function normalize(text) {
	return String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ');
}

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

		const layoutEl = document.createElement('div');
		layoutEl.className = 'docstudio-pdf-layout';
		const sidebar = document.createElement('nav');
		sidebar.className = 'docstudio-pdf-thumbs';
		sidebar.hidden = true;
		sidebar.setAttribute('aria-label', ctx.t('DocStudioThumbnails'));
		const pagesEl = document.createElement('div');
		pagesEl.className = 'docstudio-pdf-pages';
		layoutEl.append(sidebar, pagesEl);
		ctx.container.appendChild(layoutEl);

		const scroller = ctx.container.closest('.docstudio-main') || ctx.container;
		const pages = [];
		// 'auto': fit to the width without going over 125 %, 'fit': fit to the width, or a fixed scale
		let zoom = 'auto';
		let renderToken = 0;
		let query = '';

		// Sizes of every page first (cheap), drawing later when visible
		for (let i = 1; i <= doc.numPages; i++) {
			const page = await doc.getPage(i);
			const wrapper = document.createElement('div');
			wrapper.className = 'docstudio-pdf-page';
			wrapper.dataset.page = String(i);
			pagesEl.appendChild(wrapper);
			pages.push({ page: page, wrapper: wrapper, drawn: -1, task: null, textLayer: null, text: null, thumb: null });
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
			const available = Math.max(200, pagesEl.clientWidth - 32);
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
			drawVisible();
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

		function drawVisible() {
			pages.forEach(function (p) {
				if (isVisible(p.wrapper)) {
					draw(p);
				}
			});
		}

		/**
		 * Mark the spans of the text layer of a page that contain the searched text
		 * @param {Object} p
		 */
		function highlight(p) {
			const spans = p.wrapper.querySelectorAll('.textLayer span');
			spans.forEach(function (span) {
				span.classList.toggle('docstudio-hit', query !== '' && normalize(span.textContent).indexOf(query) >= 0);
			});
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
			p.textLayer.render().then(function () {
				highlight(p);
			}).catch(function () {
				// Text not selectable on this page, the drawing stays
			});
		}

		/**
		 * @returns {number} page shown at the top of the view
		 */
		function currentPage() {
			const view = scroller.getBoundingClientRect();
			let current = 1;
			for (const p of pages) {
				if (p.wrapper.getBoundingClientRect().top <= view.top + view.height / 3) {
					current = Number(p.wrapper.dataset.page);
				}
			}
			return current;
		}

		/**
		 * @param {number} number Page number
		 */
		function goToPage(number) {
			const p = pages[number - 1];
			if (p) {
				scroller.scrollTop += p.wrapper.getBoundingClientRect().top - scroller.getBoundingClientRect().top - 8;
			}
		}

		function updatePageInfo() {
			const current = currentPage();
			pageInfo.textContent = ctx.t('DocStudioPage', current, doc.numPages);
			sidebar.querySelectorAll('.docstudio-pdf-thumb').forEach(function (thumb) {
				thumb.classList.toggle('docstudio-current', Number(thumb.dataset.page) === current);
			});
		}

		let scrollTimer = null;
		function onScroll() {
			updatePageInfo();
			if (scrollTimer) {
				return;
			}
			scrollTimer = window.setTimeout(function () {
				scrollTimer = null;
				drawVisible();
			}, 80);
		}

		/**
		 * @param {number} direction 1 or -1
		 */
		function step(direction) {
			const scale = currentScale();
			let next;
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

		/* Search */

		const searchInput = document.createElement('input');
		searchInput.type = 'search';
		searchInput.className = 'docstudio-search';
		searchInput.placeholder = ctx.t('DocStudioSearch');
		searchInput.setAttribute('aria-label', ctx.t('DocStudioSearch'));
		const searchInfo = document.createElement('span');
		searchInfo.className = 'docstudio-pageinfo';
		let hits = [];
		let hitIndex = -1;

		/**
		 * @param {Object} p
		 * @returns {Promise<string>} normalized text of a page
		 */
		async function pageText(p) {
			if (p.text === null) {
				const content = await p.page.getTextContent();
				p.text = normalize(content.items.map(function (item) {
					return item.str || '';
				}).join(' '));
			}
			return p.text;
		}

		async function search() {
			query = normalize(searchInput.value.trim());
			hits = [];
			hitIndex = -1;
			if (query === '') {
				searchInfo.textContent = '';
				pages.forEach(highlight);
				return;
			}
			for (const p of pages) {
				const text = await pageText(p);
				let pos = text.indexOf(query);
				while (pos >= 0) {
					hits.push(Number(p.wrapper.dataset.page));
					pos = text.indexOf(query, pos + query.length);
				}
			}
			pages.forEach(highlight);
			if (!hits.length) {
				searchInfo.textContent = ctx.t('DocStudioSearchNone');
				return;
			}
			const from = currentPage();
			hitIndex = hits.findIndex(function (page) {
				return page >= from;
			});
			if (hitIndex < 0) {
				hitIndex = 0;
			}
			showHit();
		}

		function showHit() {
			searchInfo.textContent = ctx.t('DocStudioSearchResults', hitIndex + 1, hits.length);
			goToPage(hits[hitIndex]);
		}

		/**
		 * @param {number} direction 1 or -1
		 */
		function nextHit(direction) {
			if (!hits.length) {
				search();
				return;
			}
			hitIndex = (hitIndex + direction + hits.length) % hits.length;
			showHit();
		}

		let searchTimer = null;
		searchInput.addEventListener('input', function () {
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(search, 300);
		});
		searchInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				window.clearTimeout(searchTimer);
				if (normalize(searchInput.value.trim()) !== query) {
					search();
				} else {
					nextHit(e.shiftKey ? -1 : 1);
				}
			}
		});

		/* Thumbnails */

		let thumbObserver = null;

		/**
		 * @param {Object} p
		 */
		async function drawThumb(p) {
			if (p.thumb === null) {
				return;
			}
			const base = p.page.getViewport({ scale: 1 });
			const viewport = p.page.getViewport({ scale: THUMB_WIDTH / base.width });
			const canvas = document.createElement('canvas');
			canvas.width = Math.floor(viewport.width);
			canvas.height = Math.floor(viewport.height);
			try {
				await p.page.render({ canvas: canvas, canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
				p.thumb.querySelector('.docstudio-pdf-thumb-image').replaceChildren(canvas);
			} catch (e) {
				// Thumbnail not drawn, its number stays
			}
		}

		function buildThumbs() {
			if (sidebar.childElementCount) {
				return;
			}
			thumbObserver = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						thumbObserver.unobserve(entry.target);
						drawThumb(pages[Number(entry.target.dataset.page) - 1]);
					}
				});
			}, { root: sidebar, rootMargin: '300px' });
			pages.forEach(function (p) {
				const base = p.page.getViewport({ scale: 1 });
				const thumb = document.createElement('button');
				thumb.type = 'button';
				thumb.className = 'docstudio-pdf-thumb';
				thumb.dataset.page = p.wrapper.dataset.page;
				const image = document.createElement('span');
				image.className = 'docstudio-pdf-thumb-image';
				image.style.width = THUMB_WIDTH + 'px';
				image.style.height = Math.floor(base.height * THUMB_WIDTH / base.width) + 'px';
				const label = document.createElement('span');
				label.textContent = p.wrapper.dataset.page;
				thumb.append(image, label);
				thumb.addEventListener('click', function () {
					goToPage(Number(thumb.dataset.page));
				});
				p.thumb = thumb;
				sidebar.appendChild(thumb);
				thumbObserver.observe(thumb);
			});
			updatePageInfo();
		}

		function toggleThumbs() {
			sidebar.hidden = !sidebar.hidden;
			if (!sidebar.hidden) {
				buildThumbs();
			}
			if (typeof zoom !== 'number') {
				layout();
			}
		}

		/* Print: every page drawn as an image, then the print dialog of the browser */

		const printEl = document.createElement('div');
		printEl.className = 'docstudio-print';
		document.body.appendChild(printEl);
		let printing = false;

		async function print(button) {
			if (printing) {
				return;
			}
			printing = true;
			const label = button.textContent;
			button.disabled = true;
			const urls = [];
			try {
				printEl.replaceChildren();
				for (const p of pages) {
					button.textContent = ctx.t('DocStudioPreparingPrint', p.wrapper.dataset.page, doc.numPages);
					const viewport = p.page.getViewport({ scale: PRINT_SCALE });
					const canvas = document.createElement('canvas');
					canvas.width = Math.floor(viewport.width);
					canvas.height = Math.floor(viewport.height);
					const context = canvas.getContext('2d');
					context.fillStyle = '#ffffff';
					context.fillRect(0, 0, canvas.width, canvas.height);
					await p.page.render({ canvas: canvas, canvasContext: context, viewport: viewport, intent: 'print' }).promise;
					const blob = await new Promise(function (resolve) {
						canvas.toBlob(resolve, 'image/png');
					});
					const url = URL.createObjectURL(blob);
					urls.push(url);
					const img = document.createElement('img');
					img.src = url;
					img.alt = '';
					await img.decode();
					printEl.appendChild(img);
				}
				window.print();
			} catch (e) {
				console.error(e);
			} finally {
				printEl.replaceChildren();
				urls.forEach(URL.revokeObjectURL);
				button.textContent = label;
				button.disabled = false;
				printing = false;
			}
		}

		/* Toolbar */

		const thumbsButton = ctx.utils.button('☰', ctx.t('DocStudioThumbnails'), toggleThumbs);
		const printButton = ctx.utils.button(ctx.t('DocStudioPrint'), ctx.t('DocStudioPrint'), function () {
			print(printButton);
		});
		ctx.toolbar.append(
			thumbsButton,
			ctx.utils.iconButton('minus', ctx.t('DocStudioZoomOut'), function () {
				step(-1);
			}),
			ctx.utils.iconButton('plus', ctx.t('DocStudioZoomIn'), function () {
				step(1);
			}),
			ctx.utils.iconButton('fit', ctx.t('DocStudioZoomFit'), function () {
				zoom = 'fit';
				layout();
			}),
			pageInfo,
			searchInput,
			ctx.utils.button('↑', ctx.t('DocStudioSearchPrevious'), function () {
				nextHit(-1);
			}),
			ctx.utils.button('↓', ctx.t('DocStudioSearchNext'), function () {
				nextHit(1);
			}),
			searchInfo,
			printButton
		);

		// Ctrl+F searches in the PDF (the text of the pages not drawn yet is not in the page for the browser)
		function onKey(e) {
			if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
				e.preventDefault();
				searchInput.focus();
				searchInput.select();
			}
		}

		let resizeTimer = null;
		function onResize() {
			window.clearTimeout(resizeTimer);
			resizeTimer = window.setTimeout(function () {
				if (typeof zoom !== 'number') {
					layout();
				}
			}, 150);
		}

		// Ctrl + wheel zooms the PDF only, not the whole page
		function onWheel(e) {
			if (!e.ctrlKey && !e.metaKey) {
				return;
			}
			e.preventDefault();
			zoom = Math.min(4, Math.max(0.25, currentScale() * (e.deltaY < 0 ? 1.1 : 1 / 1.1)));
			layout();
		}

		scroller.addEventListener('scroll', onScroll, { passive: true });
		scroller.addEventListener('wheel', onWheel, { passive: false });
		window.addEventListener('resize', onResize);
		document.addEventListener('keydown', onKey);
		layout();
		updatePageInfo();

		return {
			unmount() {
				scroller.removeEventListener('scroll', onScroll);
				scroller.removeEventListener('wheel', onWheel);
				window.removeEventListener('resize', onResize);
				document.removeEventListener('keydown', onKey);
				if (thumbObserver) {
					thumbObserver.disconnect();
				}
				printEl.remove();
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

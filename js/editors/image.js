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
 * Image editor (lot 2b). Same layout as the PDF editor: palette of tools on the left, options of the tool on top.
 *
 * The document is a photo (pixels, changed by the tools of the image: crop, rotate, flip, resize, adjust, mask) and
 * objects over it (Fabric.js: arrows, lines, rectangles, ellipses, drawings, numbers, texts), editable until saving.
 * The photo is a canvas never changed in place: each change makes a new one, so the history keeps them by reference.
 * Coordinates are the pixels of the photo; the zoom only changes the display.
 *
 * Saving: the photo and the objects drawn in a canvas of the size of the photo, in the type of the file. The server
 * decodes and encodes the image again (no metadata).
 */

const SHAPES = ['arrow', 'line', 'rect', 'ellipse'];
const DRAWS = ['pen', 'marker'];
const HISTORY_MAX = 50;
// Pixels of the photos kept by the history (about 4 bytes each)
const HISTORY_PIXELS = 300000000;
const SVG_NS = 'http://www.w3.org/2000/svg';

window.DocStudio.registerViewer('imageedit', {
	async mount(ctx) {
		const t = ctx.t;
		const fabric = await import(ctx.config.fabric);
		const cutoutLib = await import(ctx.config.cutout);
		const fonts = ctx.config.fonts || [];
		const types = ctx.config.imageEditTypes || {};
		const mime = types[ctx.file.extension];
		if (!mime) {
			throw new Error(ctx.t('DocStudioErrorNoViewer'));
		}

		/* Photo */

		const blob = await ctx.file.blob(mime);
		let bitmap;
		try {
			// Orientation of the camera applied, like the browser shows the photo
			bitmap = await createImageBitmap(blob, { imageOrientation: 'from-image' });
		} catch (e) {
			throw new Error(t('DocStudioErrorLoading'));
		}
		const maxPixels = ctx.config.imageMaxPixels || 50000000;
		if (bitmap.width * bitmap.height > maxPixels) {
			const mp = function (n) {
				return (Math.round(n / 100000) / 10).toLocaleString();
			};
			throw new Error(t('DocStudioImageTooBig', mp(bitmap.width * bitmap.height), mp(maxPixels)));
		}

		/**
		 * @param {number} w
		 * @param {number} h
		 * @returns {HTMLCanvasElement}
		 */
		function newCanvas(w, h) {
			const c = document.createElement('canvas');
			c.width = Math.max(1, Math.round(w));
			c.height = Math.max(1, Math.round(h));
			return c;
		}

		let photo = newCanvas(bitmap.width, bitmap.height);
		photo.getContext('2d').drawImage(bitmap, 0, 0);
		bitmap.close();
		let width = photo.width;
		let height = photo.height;

		/* Layout */

		const root = document.createElement('div');
		root.className = 'ds-edit ds-imgedit';
		const wrap = document.createElement('div');
		wrap.className = 'ds-edit-wrap';
		const scroller = document.createElement('div');
		scroller.className = 'ds-edit-viewer ds-img-scroller';
		const stage = document.createElement('div');
		stage.className = 'ds-img-stage';
		const canvasEl = document.createElement('canvas');
		stage.appendChild(canvasEl);
		scroller.appendChild(stage);
		wrap.appendChild(scroller);
		root.appendChild(wrap);
		ctx.container.appendChild(root);

		// Frames and handles of the selected element visible on any photo
		Object.assign(fabric.FabricObject.ownDefaults, {
			borderColor: '#2a78d6', borderScaleFactor: 2, cornerColor: '#ffffff', cornerStrokeColor: '#2a78d6',
			cornerStyle: 'circle', cornerSize: 12, transparentCorners: false, borderOpacityWhenMoving: 0.6
		});
		const canvas = new fabric.Canvas(canvasEl, {
			preserveObjectStacking: true,
			selection: false,
			stopContextMenu: true,
			fireRightClick: false,
			uniformScaling: true
		});
		canvas.wrapperEl.classList.add('ds-img-canvas');

		let base = null;		// FabricImage of the photo, always at 0, 0 without transformation
		let zoom = 1;
		let dirty = false;

		// Cutout session while the tool is on, its own steps until it is applied
		let cut = null;
		let cutMode = 'wand';
		let cutHist = [];
		let cutPos = 0;
		let cutSettings = '';
		let cutPainting = null;
		let cutHover = null;
		let cutFrame = 0;
		// Pen: selected path and point, drag in progress
		const pen = { pi: -1, i: -1, drag: null };

		function setDirty() {
			if (!dirty) {
				dirty = true;
				document.getElementById('docstudio-save').disabled = false;
			}
		}

		/**
		 * Photo shown: a new canvas, never changed after
		 * @param {HTMLCanvasElement} c
		 */
		function setPhoto(c) {
			photo = c;
			width = c.width;
			height = c.height;
			if (sizeInfo) {
				sizeInfo.textContent = width + ' × ' + height + ' px';
			}
			const img = new fabric.FabricImage(c, {
				left: 0, top: 0, originX: 'left', originY: 'top',
				selectable: false, evented: false, hasControls: false, objectCaching: false
			});
			img.dsBase = true;
			if (base) {
				canvas.remove(base);
			}
			base = img;
			canvas.insertAt(0, img);
			applyZoom();
		}

		/** Objects of the user (not the photo, not the frames of the tools) */
		function userObjects() {
			return canvas.getObjects().filter(function (o) {
				return !o.dsBase && !o.dsHelper;
			});
		}

		/* Zoom */

		const zoomLabel = document.createElement('span');
		zoomLabel.className = 'ds-zoom-label';

		function applyZoom() {
			canvas.setDimensions({ width: Math.max(1, Math.round(width * zoom)), height: Math.max(1, Math.round(height * zoom)) });
			canvas.setViewportTransform([zoom, 0, 0, zoom, 0, 0]);
			zoomLabel.textContent = Math.round(zoom * 100) + ' %';
			canvas.requestRenderAll();
		}

		// Fit to the window: the zoom follows the size of the window until the user chooses another zoom
		let autoFit = true;

		function fitZoom() {
			const box = scroller.getBoundingClientRect();
			const z = Math.min((box.width - 48) / width, (box.height - 48) / height, 1);
			zoom = Math.max(0.02, z > 0 ? z : 1);
			autoFit = true;
			applyZoom();
		}

		/**
		 * Zoom chosen by the user, the point under the mouse (or the middle of the view) stays in place
		 * @param {number} z
		 * @param {{x: number, y: number}|null} anchor Point of the screen
		 */
		function setZoom(z, anchor) {
			const before = canvas.wrapperEl.getBoundingClientRect();
			const view = scroller.getBoundingClientRect();
			const ax = anchor ? anchor.x : view.left + view.width / 2;
			const ay = anchor ? anchor.y : view.top + view.height / 2;
			const sx = (ax - before.left) / zoom;
			const sy = (ay - before.top) / zoom;
			zoom = Math.min(8, Math.max(0.02, z));
			autoFit = false;
			applyZoom();
			const after = canvas.wrapperEl.getBoundingClientRect();
			scroller.scrollLeft += after.left + sx * zoom - ax;
			scroller.scrollTop += after.top + sy * zoom - ay;
		}

		// Ctrl + wheel zooms the photo only, not the whole page
		scroller.addEventListener('wheel', function (e) {
			if (e.ctrlKey || e.metaKey) {
				e.preventDefault();
				setZoom(zoom * (e.deltaY < 0 ? 1.1 : 1 / 1.1), { x: e.clientX, y: e.clientY });
			}
		}, { passive: false });

		/* Hand: space held (pointer over the image) or middle button, then drag to move the view, like the image editors */

		let spaceDown = false;
		let overView = false;
		let panning = null;
		scroller.addEventListener('pointerenter', function () {
			overView = true;
		});
		scroller.addEventListener('pointerleave', function () {
			overView = false;
		});
		function setPanReady(on) {
			spaceDown = on;
			scroller.classList.toggle('ds-pan-ready', on);
		}
		function onSpace(e) {
			if (e.code !== 'Space') {
				return;
			}
			const active = document.activeElement;
			const typing = active && (active.isContentEditable || ['SELECT', 'TEXTAREA'].includes(active.tagName)
				|| (active.tagName === 'INPUT' && !['checkbox', 'radio', 'range', 'color', 'button'].includes(active.type)));
			const editingText = canvas.getActiveObject() && canvas.getActiveObject().isEditing;
			if (typing || editingText) {
				return;
			}
			if (e.type === 'keydown') {
				// Only with the pointer over the image: elsewhere Space keeps pressing the focused button
				if (!overView && !spaceDown) {
					return;
				}
				e.preventDefault();
				if (!spaceDown) {
					setPanReady(true);
				}
			} else if (spaceDown) {
				e.preventDefault();
				setPanReady(false);
			}
		}
		document.addEventListener('keydown', onSpace);
		document.addEventListener('keyup', onSpace);
		function onWindowBlur() {
			setPanReady(false);
		}
		window.addEventListener('blur', onWindowBlur);
		// Before Fabric (capture on its wrapper): the tools do not see a drag of the hand
		canvas.wrapperEl.addEventListener('pointerdown', function (e) {
			if (!spaceDown && e.button !== 1) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			panning = { id: e.pointerId, x: e.clientX, y: e.clientY, left: scroller.scrollLeft, top: scroller.scrollTop };
			scroller.classList.add('ds-panning');
			try {
				canvas.wrapperEl.setPointerCapture(e.pointerId);
			} catch (err) {
				// Pointer already gone: the drag ends at the next pointerup
			}
		}, true);
		canvas.wrapperEl.addEventListener('pointermove', function (e) {
			if (!panning || e.pointerId !== panning.id) {
				return;
			}
			e.stopPropagation();
			scroller.scrollLeft = panning.left - (e.clientX - panning.x);
			scroller.scrollTop = panning.top - (e.clientY - panning.y);
		}, true);
		function endPan(e) {
			if (!panning || e.pointerId !== panning.id) {
				return;
			}
			e.stopPropagation();
			panning = null;
			scroller.classList.remove('ds-panning');
		}
		canvas.wrapperEl.addEventListener('pointerup', endPan, true);
		canvas.wrapperEl.addEventListener('pointercancel', endPan, true);
		// Middle button: no automatic scrolling of the browser
		canvas.wrapperEl.addEventListener('mousedown', function (e) {
			if (e.button === 1) {
				e.preventDefault();
			}
		}, true);

		/* History */

		const history = [];
		let historyIndex = -1;
		let restoring = false;
		const PROPS = ['dsKind', 'dsNumber', 'dsFont', 'dsName', 'dsLocked'];

		function snapshot() {
			return {
				photo: photo,
				objects: userObjects().map(function (o) {
					return o.toObject(PROPS);
				})
			};
		}

		/** Record the state after a change */
		function commit() {
			if (restoring) {
				return;
			}
			history.splice(historyIndex + 1);
			history.push(snapshot());
			// Oldest states dropped beyond 50 steps, or when the photos kept are too big
			while (history.length > HISTORY_MAX + 1) {
				history.shift();
			}
			let pixels = 0;
			const seen = new Set();
			for (let i = history.length - 1; i >= 0; i--) {
				if (!seen.has(history[i].photo)) {
					seen.add(history[i].photo);
					pixels += history[i].photo.width * history[i].photo.height;
					if (pixels > HISTORY_PIXELS && i > 0) {
						history.splice(0, i);
						break;
					}
				}
			}
			historyIndex = history.length - 1;
			if (history.length > 1) {
				setDirty();
			}
			syncHistoryButtons();
			refreshLayers();
		}

		async function restore(index) {
			const state = history[index];
			if (!state) {
				return;
			}
			restoring = true;
			try {
				leaveTool();
				canvas.discardActiveObject();
				userObjects().forEach(function (o) {
					canvas.remove(o);
				});
				if (state.photo !== photo) {
					setPhoto(state.photo);
				}
				const objects = await fabric.util.enlivenObjects(state.objects);
				objects.forEach(function (o) {
					canvas.add(o);
				});
				historyIndex = index;
				setDirty();
				canvas.requestRenderAll();
			} finally {
				restoring = false;
			}
			syncHistoryButtons();
			enterTool();
			refreshLayers();
		}

		function undo() {
			if (tool === 'cutout' && cut) {
				cutUndo();
				return;
			}
			if (historyIndex > 0) {
				restore(historyIndex - 1);
			}
		}

		function redo() {
			if (tool === 'cutout' && cut) {
				cutRedo();
				return;
			}
			if (historyIndex < history.length - 1) {
				restore(historyIndex + 1);
			}
		}

		/* Changes of the whole image (crop, rotate, flip, resize) */

		/**
		 * Photo and objects moved by a matrix [a, b, c, d, e, f] into an image of a new size. Texts and numbers only
		 * follow their place (they stay readable), the other objects follow the image (an arrow keeps pointing at
		 * the same thing).
		 * @param {number[]} m
		 * @param {number} w New width
		 * @param {number} h New height
		 * @param {boolean} scaleOnly Resize: texts and numbers scaled too
		 */
		function transformImage(m, w, h, scaleOnly) {
			const c = newCanvas(w, h);
			const g = c.getContext('2d');
			g.imageSmoothingEnabled = true;
			g.imageSmoothingQuality = 'high';
			g.setTransform(m[0], m[1], m[2], m[3], m[4], m[5]);
			g.drawImage(photo, 0, 0);
			canvas.discardActiveObject();
			userObjects().forEach(function (o) {
				if (o.dsKind === 'text' || o.dsKind === 'number') {
					const center = fabric.util.transformPoint(o.getCenterPoint(), m);
					if (scaleOnly) {
						const s = Math.min(Math.abs(m[0]), Math.abs(m[3]));
						o.set({ scaleX: o.scaleX * s, scaleY: o.scaleY * s });
					}
					o.setPositionByOrigin(center, 'center', 'center');
				} else {
					fabric.util.addTransformToObject(o, m);
				}
				o.setCoords();
			});
			setPhoto(c);
			fitZoom();
			commit();
		}

		function rotate(delta) {
			if (tool === 'cutout') {
				selectTool('select');
			}
			if (delta > 0) {
				transformImage([0, 1, -1, 0, height, 0], height, width, false);
			} else {
				transformImage([0, -1, 1, 0, 0, width], height, width, false);
			}
		}

		function flip(horizontal) {
			if (tool === 'cutout') {
				selectTool('select');
			}
			if (horizontal) {
				transformImage([-1, 0, 0, 1, width, 0], width, height, false);
			} else {
				transformImage([1, 0, 0, -1, 0, height], width, height, false);
			}
		}

		/* Icons */

		function icon(name) {
			const svg = document.createElementNS(SVG_NS, 'svg');
			svg.setAttribute('class', 'ds-icon');
			svg.setAttribute('aria-hidden', 'true');
			const use = document.createElementNS(SVG_NS, 'use');
			use.setAttribute('href', '#ds-i-' + name);
			svg.appendChild(use);
			return svg;
		}

		/* Tools */

		const tools = [
			{ code: 'select', label: t('DocStudioToolSelect'), icon: 'select', key: 'v' },
			{ code: 'text', label: t('DocStudioToolText'), icon: 'text', key: 't' },
			{ code: 'arrow', label: t('DocStudioToolArrow'), icon: 'arrow', key: 'a', group: 'shape' },
			{ code: 'line', label: t('DocStudioToolLine'), icon: 'line', key: 'l', group: 'shape' },
			{ code: 'rect', label: t('DocStudioToolRect'), icon: 'rect', key: 'r', group: 'shape' },
			{ code: 'ellipse', label: t('DocStudioToolEllipse'), icon: 'ellipse', key: 'e', group: 'shape' },
			{ code: 'pen', label: t('DocStudioToolDraw'), icon: 'draw', key: 'b', group: 'draw' },
			{ code: 'marker', label: t('DocStudioToolHighlight'), icon: 'highlight', key: 'h', group: 'draw' },
			{ code: 'number', label: t('DocStudioToolNumber'), icon: 'number', key: 'n' },
			{ code: 'mask', label: t('DocStudioToolMask'), icon: 'blur', key: 'x' },
			{ code: 'cutout', label: t('DocStudioToolCutout'), icon: 'cutout', key: 'w' },
			{ code: 'crop', label: t('DocStudioToolCrop'), icon: 'crop', key: 'c' },
			{ code: 'resize', label: t('DocStudioToolResize'), icon: 'resize', key: '' },
			{ code: 'adjust', label: t('DocStudioToolAdjust'), icon: 'adjust', key: '' }
		];
		const toolOf = function (code) {
			return tools.find(function (x) {
				return x.code === code;
			});
		};
		const groupCurrent = { shape: 'arrow', draw: 'pen' };
		const slotButtons = {};
		let tool = 'select';
		let flyout = null;

		const palette = document.createElement('nav');
		palette.className = 'ds-palette';
		palette.setAttribute('aria-label', t('DocStudioTools'));
		root.insertBefore(palette, wrap);

		function tipOf(def) {
			return def.key ? def.label + ' (' + def.key.toUpperCase() + ')' : def.label;
		}

		function closeFlyout() {
			if (flyout) {
				flyout.remove();
				flyout = null;
			}
		}

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
		function onDocPointerDown(e) {
			if (flyout && !flyout.contains(e.target) && !e.target.closest('.ds-group-more')) {
				closeFlyout();
			}
		}
		document.addEventListener('pointerdown', onDocPointerDown);

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

		function separator() {
			const s = document.createElement('span');
			s.className = 'ds-palette-separator';
			palette.appendChild(s);
		}

		const seenGroups = new Set();
		tools.forEach(function (def) {
			if (def.code === 'mask' || def.code === 'resize') {
				separator();
			}
			if (!def.group) {
				paletteButton(def, def.code);
			} else if (!seenGroups.has(def.group)) {
				seenGroups.add(def.group);
				paletteButton(toolOf(groupCurrent[def.group]), def.group);
			}
		});
		separator();
		actionButton('rotate-left', t('DocStudioRotateImageLeft'), function () {
			rotate(-90);
		});
		actionButton('rotate-right', t('DocStudioRotateImageRight'), function () {
			rotate(90);
		});
		actionButton('flip-h', t('DocStudioFlipH'), function () {
			flip(true);
		});
		actionButton('flip-v', t('DocStudioFlipV'), function () {
			flip(false);
		});
		separator();
		const undoButton = actionButton('undo', t('DocStudioUndo') + ' (Ctrl+Z)', undo);
		const redoButton = actionButton('redo', t('DocStudioRedo') + ' (Ctrl+Y)', redo);

		function syncHistoryButtons() {
			if (tool === 'cutout' && cut) {
				// Steps of the cutout until it is applied
				undoButton.disabled = cutPos <= 0;
				redoButton.disabled = cutPos >= cutHist.length;
				return;
			}
			undoButton.disabled = historyIndex <= 0;
			redoButton.disabled = historyIndex >= history.length - 1;
		}

		/* Options of the tool (top bar) */

		const toolName = document.createElement('span');
		toolName.className = 'ds-toolname';

		function labeled(text, input) {
			const label = document.createElement('label');
			label.className = 'ds-option';
			label.append(document.createTextNode(text + ' '), input);
			return label;
		}

		function checkbox(text) {
			const label = document.createElement('label');
			label.className = 'docstudio-check ds-option';
			const box = document.createElement('input');
			box.type = 'checkbox';
			label.append(box, document.createTextNode(' ' + text));
			return { label: label, box: box };
		}

		function colorInput(value) {
			const input = document.createElement('input');
			input.type = 'color';
			input.value = value;
			input.className = 'ds-color';
			return input;
		}

		function numberInput(min, max, value, step) {
			const input = document.createElement('input');
			input.type = 'number';
			input.min = String(min);
			input.max = String(max);
			input.step = String(step || 1);
			input.value = String(value);
			input.className = 'ds-size';
			return input;
		}

		function rangeInput(min, max, value) {
			const input = document.createElement('input');
			input.type = 'range';
			input.min = String(min);
			input.max = String(max);
			input.value = String(value);
			input.className = 'ds-range';
			return input;
		}

		function select(options) {
			const s = document.createElement('select');
			s.className = 'ds-font';
			options.forEach(function (o) {
				const option = document.createElement('option');
				option.value = o[0];
				option.textContent = o[1];
				s.appendChild(option);
			});
			return s;
		}

		// Common: color and thickness
		const color = colorInput('#e53935');
		const colorLabel = labeled(t('DocStudioColor'), color);
		const size = numberInput(1, 200, 6);
		const sizeLabel = labeled(t('DocStudioSize'), size);
		const fill = checkbox(t('DocStudioFill'));
		const fillColor = colorInput('#ffeb3b');
		fill.label.appendChild(fillColor);

		// Text
		const fontSelect = select(fonts.map(function (f) {
			return [f.key, f.label];
		}));
		const fontLabel = labeled(t('DocStudioFont'), fontSelect);
		const bold = checkbox(t('DocStudioBold'));
		const italic = checkbox(t('DocStudioItalic'));
		const outline = checkbox(t('DocStudioOutline'));
		const outlineColor = colorInput('#ffffff');
		outline.label.appendChild(outlineColor);
		const background = checkbox(t('DocStudioBackground'));
		const backgroundColor = colorInput('#000000');
		background.label.appendChild(backgroundColor);
		const shadow = checkbox(t('DocStudioShadow'));

		// Number
		const nextNumber = numberInput(1, 999, 1);
		const nextNumberLabel = labeled(t('DocStudioNextNumber'), nextNumber);

		// Mask
		const maskMode = select([['pixels', t('DocStudioMaskPixels')], ['blur', t('DocStudioMaskBlur')], ['flat', t('DocStudioMaskFlat')]]);
		const maskModeLabel = labeled(t('DocStudioMaskMode'), maskMode);
		const strength = rangeInput(1, 10, 6);
		const strengthLabel = labeled(t('DocStudioStrength'), strength);
		const maskColor = colorInput('#000000');
		const maskColorLabel = labeled(t('DocStudioColor'), maskColor);

		// Crop
		const ratioSelect = select([
			['free', t('DocStudioRatioFree')], ['original', t('DocStudioRatioOriginal')], ['1', '1:1'],
			['1.3333', '4:3'], ['0.75', '3:4'], ['1.7778', '16:9'], ['0.5625', '9:16'], ['1.5', '3:2']
		]);
		const ratioLabel = labeled(t('DocStudioRatio'), ratioSelect);

		// Resize
		const widthInput = numberInput(1, 20000, width);
		const widthLabel = labeled(t('DocStudioWidth'), widthInput);
		const heightInput = numberInput(1, 20000, height);
		const heightLabel = labeled(t('DocStudioHeight'), heightInput);
		const keepRatio = checkbox(t('DocStudioKeepRatio'));
		keepRatio.box.checked = true;

		// Adjust
		const brightness = rangeInput(-100, 100, 0);
		const brightnessLabel = labeled(t('DocStudioBrightness'), brightness);
		const contrast = rangeInput(-100, 100, 0);
		const contrastLabel = labeled(t('DocStudioContrast'), contrast);

		// Cutout: mode (wand, eraser, restore, pen), options of the mode, white background from the borders
		const cutModes = [
			{ code: 'wand', label: t('DocStudioCutWand'), icon: 'wand', key: 'w' },
			{ code: 'erase', label: t('DocStudioCutErase'), icon: 'eraser', key: 'e' },
			{ code: 'keep', label: t('DocStudioCutKeep'), icon: 'brush', key: 'r' },
			{ code: 'pen', label: t('DocStudioCutPen'), icon: 'bezier', key: 'p' }
		];
		const cutModeBox = document.createElement('span');
		cutModeBox.className = 'ds-tools';
		cutModeBox.setAttribute('role', 'group');
		cutModeBox.setAttribute('aria-label', t('DocStudioToolCutout'));
		const cutModeButtons = {};
		cutModes.forEach(function (m) {
			const b = ctx.utils.button('', m.label + ' (' + m.key.toUpperCase() + ')', function () {
				setCutMode(m.code);
			});
			b.replaceChildren(icon(m.icon), document.createTextNode(' ' + m.label));
			cutModeButtons[m.code] = b;
			cutModeBox.appendChild(b);
		});
		const cutWandTol = rangeInput(0, 100, 30);
		const cutWandTolLabel = labeled(t('DocStudioCutTolerance'), cutWandTol);
		const cutSize = rangeInput(3, 150, 30);
		const cutSizeLabel = labeled(t('DocStudioSize'), cutSize);
		const cutHard = rangeInput(0, 100, 80);
		const cutHardLabel = labeled(t('DocStudioCutHardness'), cutHard);
		const cutPenMode = select([['keep', t('DocStudioCutPenKeep')], ['erase', t('DocStudioCutPenErase')]]);
		const cutPenModeLabel = labeled(t('DocStudioCutPath'), cutPenMode);
		const cutFeather = rangeInput(0, 6, 1);
		cutFeather.step = '0.5';
		const cutFeatherLabel = labeled(t('DocStudioCutFeather'), cutFeather);
		const cutCorner = ctx.utils.button(t('DocStudioCutCorner'), t('DocStudioCutPointHelp'), function () {
			setPenPointType('corner');
		});
		const cutSmooth = ctx.utils.button(t('DocStudioCutSmooth'), t('DocStudioCutPointHelp'), function () {
			setPenPointType('smooth');
		});
		const cutPointDelete = ctx.utils.button(t('DocStudioCutDeletePoint'), t('DocStudioCutDeletePoint') + ' (Suppr)', function () {
			deletePenPoint();
		});
		const cutPathDelete = ctx.utils.button(t('DocStudioCutDeletePath'), '', function () {
			deletePenPath();
		});
		const cutPointBox = document.createElement('span');
		cutPointBox.className = 'ds-tools';
		cutPointBox.append(cutCorner, cutSmooth, cutPointDelete, cutPathDelete);
		const cutAuto = checkbox(t('DocStudioCutAuto'));
		const cutAutoTol = rangeInput(0, 100, 30);
		cutAutoTol.title = t('DocStudioCutTolerance');
		cutAutoTol.setAttribute('aria-label', t('DocStudioCutTolerance'));
		cutAuto.label.appendChild(cutAutoTol);
		const cutReset = ctx.utils.button(t('DocStudioCutReset'), '', function () {
			resetCut();
		});

		const applyButton = ctx.utils.button(t('DocStudioApply'), '', function () {
			applyTool();
		});
		applyButton.classList.add('docstudio-primary');
		const cancelButton = ctx.utils.button(t('DocStudioCancel'), '', function () {
			cancelTool();
		});
		const deleteButton = ctx.utils.button(t('DocStudioDeleteObject'), t('DocStudioDeleteObject') + ' (Suppr)', function () {
			deleteSelection();
		});
		const hint = document.createElement('span');
		hint.className = 'docstudio-pageinfo ds-hint';

		const zoomOut = ctx.utils.iconButton('minus', t('DocStudioZoomOut'), function () {
			setZoom(zoom / 1.25);
		});
		const zoomIn = ctx.utils.iconButton('plus', t('DocStudioZoomIn'), function () {
			setZoom(zoom * 1.25);
		});
		const zoomFit = ctx.utils.iconButton('fit', t('DocStudioZoomFitImage'), fitZoom);
		const zoomActual = ctx.utils.button('1:1', t('DocStudioZoomActual'), function () {
			setZoom(1);
		});
		const zoomBox = document.createElement('span');
		zoomBox.className = 'ds-zoom';
		zoomBox.append(zoomOut, zoomLabel, zoomIn, zoomFit, zoomActual);

		const optionElements = {
			color: colorLabel, size: sizeLabel, fill: fill.label, font: fontLabel, bold: bold.label, italic: italic.label,
			outline: outline.label, background: background.label, shadow: shadow.label, number: nextNumberLabel,
			maskMode: maskModeLabel, strength: strengthLabel, maskColor: maskColorLabel, ratio: ratioLabel,
			width: widthLabel, height: heightLabel, keepRatio: keepRatio.label, brightness: brightnessLabel,
			contrast: contrastLabel, cutMode: cutModeBox, cutWandTol: cutWandTolLabel, cutSize: cutSizeLabel,
			cutHard: cutHardLabel, cutPenMode: cutPenModeLabel, cutFeather: cutFeatherLabel, cutPoint: cutPointBox,
			cutAuto: cutAuto.label, cutReset: cutReset, apply: applyButton, cancel: cancelButton, delete: deleteButton
		};
		ctx.toolbar.append(toolName);
		Object.keys(optionElements).forEach(function (k) {
			ctx.toolbar.appendChild(optionElements[k]);
		});
		// Bottom bar like the image editors: zoom on the left, help of the tool, size of the image on the right
		const sizeInfo = document.createElement('span');
		sizeInfo.className = 'ds-status-info';
		(ctx.statusbar || ctx.toolbar).append(zoomBox, hint, sizeInfo);

		const hints = {
			select: t('DocStudioHintImgSelect'),
			text: t('DocStudioHintImgText'),
			arrow: t('DocStudioHintShape'),
			line: t('DocStudioHintShape'),
			rect: t('DocStudioHintShape'),
			ellipse: t('DocStudioHintShape'),
			pen: t('DocStudioHintDraw'),
			marker: t('DocStudioHintDraw'),
			number: t('DocStudioHintNumber'),
			mask: t('DocStudioHintMask'),
			crop: t('DocStudioHintImgCrop'),
			resize: t('DocStudioHintResize'),
			adjust: t('DocStudioHintAdjust'),
			wand: t('DocStudioHintCutWand'),
			erase: t('DocStudioHintCutErase'),
			keep: t('DocStudioHintCutKeep'),
			pen: t('DocStudioHintCutPen')
		};

		/** Kind of the object selected (select tool), '' when none */
		function selectedKind() {
			const o = canvas.getActiveObject();
			return o && !o.dsHelper && o.dsKind ? o.dsKind : '';
		}

		function showParams() {
			const def = toolOf(tool);
			const kind = tool === 'select' ? selectedKind() : tool;
			const shown = new Set();
			if (kind === 'text') {
				['font', 'size', 'color', 'bold', 'italic', 'outline', 'background', 'shadow'].forEach(shown.add, shown);
			} else if (SHAPES.includes(kind) || DRAWS.includes(kind)) {
				['color', 'size'].forEach(shown.add, shown);
				if (kind === 'rect' || kind === 'ellipse') {
					shown.add('fill');
				}
			} else if (kind === 'number') {
				['color', 'size'].forEach(shown.add, shown);
				if (tool === 'number') {
					shown.add('number');
				}
			} else if (kind === 'mask') {
				shown.add('maskMode');
				shown.add(maskMode.value === 'flat' ? 'maskColor' : 'strength');
			} else if (kind === 'crop') {
				['ratio', 'apply', 'cancel'].forEach(shown.add, shown);
			} else if (kind === 'resize') {
				['width', 'height', 'keepRatio', 'apply', 'cancel'].forEach(shown.add, shown);
			} else if (kind === 'adjust') {
				['brightness', 'contrast', 'apply', 'cancel'].forEach(shown.add, shown);
			} else if (kind === 'cutout') {
				shown.add('cutMode');
				if (cutMode === 'wand') {
					shown.add('cutWandTol');
				} else if (cutMode === 'pen') {
					['cutPenMode', 'cutFeather', 'cutPoint'].forEach(shown.add, shown);
				} else {
					['cutSize', 'cutHard'].forEach(shown.add, shown);
				}
				['cutAuto', 'cutReset', 'apply', 'cancel'].forEach(shown.add, shown);
			}
			if (tool === 'select' && kind !== '') {
				shown.add('delete');
			}
			Object.keys(optionElements).forEach(function (k) {
				optionElements[k].hidden = !shown.has(k);
			});
			toolName.replaceChildren(icon(def.icon), document.createTextNode(' ' + def.label));
			let text = tool === 'select' && kind !== '' ? '' : (hints[tool] || '');
			if (tool === 'mask' && maskMode.value === 'blur') {
				text = t('DocStudioHintMaskBlur');
			}
			if (tool === 'cutout') {
				text = hints[cutMode] + (mime === 'image/jpeg' ? ' ' + t('DocStudioHintCutJpeg') : '');
				syncCutUi();
			}
			hint.textContent = text;
		}

		/* Default options of each tool, remembered while the page is open */

		const toolOptions = {
			text: { color: '#ffffff', size: 48 },
			arrow: { color: '#e53935', size: 8 },
			line: { color: '#e53935', size: 6 },
			rect: { color: '#e53935', size: 6 },
			ellipse: { color: '#e53935', size: 6 },
			pen: { color: '#e53935', size: 6 },
			marker: { color: '#ffeb3b', size: 30 },
			number: { color: '#e53935', size: 48 }
		};

		function loadOptions(code) {
			const o = toolOptions[code];
			if (o) {
				color.value = o.color;
				// Sizes relative to the photo the first time: readable on a big photo too
				size.value = String(o.size);
			}
		}

		function saveOptions(code) {
			if (toolOptions[code]) {
				toolOptions[code] = { color: color.value, size: Number(size.value) || toolOptions[code].size };
			}
		}

		// First sizes relative to the photo (a 6 px line is lost on a 6000 px photo)
		(function () {
			const scale = Math.max(1, Math.round(Math.max(width, height) / 1500));
			Object.keys(toolOptions).forEach(function (k) {
				toolOptions[k].size = Math.min(200, toolOptions[k].size * scale);
			});
		})();

		/* Fonts */

		const fontFaces = {};

		function fontOf(key) {
			return fonts.find(function (f) {
				return f.key === key;
			}) || fonts[0] || { key: '', css: 'Helvetica, Arial, sans-serif' };
		}

		async function loadFontFace(font) {
			if (!font.url || fontFaces[font.key]) {
				return;
			}
			fontFaces[font.key] = true;
			try {
				const regular = new FontFace(font.family, await (await fetch(font.url, { credentials: 'same-origin' })).arrayBuffer(), { weight: '400' });
				document.fonts.add(await regular.load());
				if (font.urlBold) {
					const boldFace = new FontFace(font.family, await (await fetch(font.urlBold, { credentials: 'same-origin' })).arrayBuffer(), { weight: '700' });
					document.fonts.add(await boldFace.load());
				}
			} catch (e) {
				console.error(e);
			}
		}

		/* Objects */

		/**
		 * Path of an arrow (or a line) from (x1, y1) to (x2, y2)
		 * @returns {string}
		 */
		function arrowPath(x1, y1, x2, y2, w, head) {
			let d = 'M ' + x1 + ' ' + y1 + ' L ' + x2 + ' ' + y2;
			if (head) {
				const len = Math.max(12, w * 4);
				const angle = Math.atan2(y2 - y1, x2 - x1);
				const a1 = angle + Math.PI * 0.85;
				const a2 = angle - Math.PI * 0.85;
				d += ' M ' + (x2 + len * Math.cos(a1)) + ' ' + (y2 + len * Math.sin(a1)) + ' L ' + x2 + ' ' + y2
					+ ' L ' + (x2 + len * Math.cos(a2)) + ' ' + (y2 + len * Math.sin(a2));
			}
			return d;
		}

		function strokeOptions() {
			return {
				stroke: color.value, strokeWidth: Number(size.value) || 1, strokeUniform: true,
				strokeLineCap: 'round', strokeLineJoin: 'round'
			};
		}

		function makeShape(kind, x1, y1, x2, y2) {
			let o;
			const s = strokeOptions();
			if (kind === 'arrow' || kind === 'line') {
				o = new fabric.Path(arrowPath(x1, y1, x2, y2, s.strokeWidth, kind === 'arrow'), Object.assign({ fill: '' }, s));
			} else if (kind === 'rect') {
				o = new fabric.Rect(Object.assign({
					left: Math.min(x1, x2), top: Math.min(y1, y2), originX: 'left', originY: 'top',
					width: Math.abs(x2 - x1), height: Math.abs(y2 - y1), fill: fill.box.checked ? fillColor.value : ''
				}, s));
			} else {
				o = new fabric.Ellipse(Object.assign({
					left: Math.min(x1, x2), top: Math.min(y1, y2), originX: 'left', originY: 'top',
					rx: Math.abs(x2 - x1) / 2, ry: Math.abs(y2 - y1) / 2, fill: fill.box.checked ? fillColor.value : ''
				}, s));
			}
			o.dsKind = kind;
			return o;
		}

		function textStyle() {
			const font = fontOf(fontSelect.value);
			const fontSize = Number(size.value) || 48;
			return {
				fontFamily: font.css || font.family || 'sans-serif',
				fontSize: fontSize,
				fill: color.value,
				fontWeight: bold.box.checked ? 'bold' : 'normal',
				fontStyle: italic.box.checked ? 'italic' : 'normal',
				stroke: outline.box.checked ? outlineColor.value : null,
				strokeWidth: outline.box.checked ? Math.max(1, Math.round(fontSize / 12)) : 0,
				paintFirst: 'stroke',
				strokeLineJoin: 'round',
				backgroundColor: background.box.checked ? backgroundColor.value : '',
				shadow: shadow.box.checked ? new fabric.Shadow({ color: 'rgba(0,0,0,0.6)', blur: Math.round(fontSize / 6), offsetX: Math.round(fontSize / 16), offsetY: Math.round(fontSize / 16) }) : null,
				dsFont: font.key
			};
		}

		function makeText(x, y) {
			const style = textStyle();
			// Width of its text (the background follows the words), new line with Enter
			const o = new fabric.IText('', Object.assign({ left: x, top: y, originX: 'left', originY: 'top' }, style));
			o.dsKind = 'text';
			return o;
		}

		function makeNumber(x, y, n) {
			const d = Number(size.value) || 48;
			const circle = new fabric.Circle({ radius: d / 2, fill: color.value, originX: 'center', originY: 'center', left: 0, top: 0, stroke: '#ffffff', strokeWidth: Math.max(1, d / 16) });
			const label = new fabric.FabricText(String(n), {
				fontFamily: 'Helvetica, Arial, sans-serif', fontWeight: 'bold', fontSize: d * 0.55, fill: '#ffffff',
				originX: 'center', originY: 'center', left: 0, top: 0
			});
			const g = new fabric.Group([circle, label], { left: x, top: y, originX: 'center', originY: 'center' });
			g.dsKind = 'number';
			g.dsNumber = n;
			return g;
		}

		function nextFreeNumber() {
			let n = 0;
			userObjects().forEach(function (o) {
				if (o.dsKind === 'number') {
					n = Math.max(n, Number(o.dsNumber) || 0);
				}
			});
			return n + 1;
		}

		/** Options of the top bar applied to the selected object (select tool) */
		function applyToSelection() {
			const o = canvas.getActiveObject();
			if (!o || o.dsHelper || !o.dsKind) {
				return;
			}
			const k = o.dsKind;
			if (k === 'text') {
				const style = textStyle();
				o.set(style);
				loadFontFace(fontOf(fontSelect.value)).then(function () {
					o.initDimensions();
					canvas.requestRenderAll();
				});
			} else if (SHAPES.includes(k) || DRAWS.includes(k)) {
				o.set({ stroke: k === 'marker' ? hexAlpha(color.value, 0.45) : color.value, strokeWidth: Number(size.value) || 1 });
				if (k === 'rect' || k === 'ellipse') {
					o.set({ fill: fill.box.checked ? fillColor.value : '' });
				}
			} else if (k === 'number') {
				const parts = o.getObjects();
				const d = Number(size.value) || 48;
				parts[0].set({ fill: color.value, radius: d / 2, strokeWidth: Math.max(1, d / 16) });
				parts[1].set({ fontSize: d * 0.55 });
				o.triggerLayout();
			}
			o.setCoords();
			canvas.requestRenderAll();
			commitSoon();
		}

		let commitTimer = null;
		function commitSoon() {
			window.clearTimeout(commitTimer);
			commitTimer = window.setTimeout(commit, 400);
		}

		/** Options of the top bar from the selected object */
		function readSelection() {
			const o = canvas.getActiveObject();
			if (!o || o.dsHelper || !o.dsKind) {
				return;
			}
			if (o.dsKind === 'text') {
				if (o.dsFont) {
					fontSelect.value = o.dsFont;
				}
				size.value = String(Math.round(o.fontSize));
				color.value = toHex(o.fill);
				bold.box.checked = o.fontWeight === 'bold';
				italic.box.checked = o.fontStyle === 'italic';
				outline.box.checked = !!o.stroke && o.strokeWidth > 0;
				background.box.checked = !!o.backgroundColor;
				if (o.backgroundColor) {
					backgroundColor.value = toHex(o.backgroundColor);
				}
				shadow.box.checked = !!o.shadow;
			} else if (o.dsKind === 'number') {
				const parts = o.getObjects();
				color.value = toHex(parts[0].fill);
				size.value = String(Math.round(parts[0].radius * 2));
			} else {
				color.value = toHex(o.stroke);
				size.value = String(Math.round(o.strokeWidth));
				fill.box.checked = !!o.fill && o.fill !== '';
				if (fill.box.checked) {
					fillColor.value = toHex(o.fill);
				}
			}
		}

		function toHex(value) {
			if (typeof value !== 'string') {
				return '#000000';
			}
			if (/^#[0-9a-f]{6}$/i.test(value)) {
				return value;
			}
			const m = value.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
			if (m) {
				return '#' + [m[1], m[2], m[3]].map(function (x) {
					return Number(x).toString(16).padStart(2, '0');
				}).join('');
			}
			return '#000000';
		}

		function hexAlpha(hex, alpha) {
			const v = parseInt(hex.slice(1), 16);
			return 'rgba(' + ((v >> 16) & 255) + ',' + ((v >> 8) & 255) + ',' + (v & 255) + ',' + alpha + ')';
		}

		[color, size, fillColor, outlineColor, backgroundColor].forEach(function (input) {
			input.addEventListener('input', function () {
				if (tool === 'select') {
					applyToSelection();
				} else if (tool === 'text') {
					applyToEditedText();
				} else if (tool === 'pen' || tool === 'marker') {
					syncBrush();
				}
			});
		});
		[fontSelect, bold.box, italic.box, outline.box, background.box, shadow.box, fill.box].forEach(function (input) {
			input.addEventListener('change', function () {
				if (input === fontSelect) {
					loadFontFace(fontOf(fontSelect.value));
				}
				if (tool === 'select') {
					applyToSelection();
				} else if (tool === 'text') {
					applyToEditedText();
				}
			});
		});
		maskMode.addEventListener('change', showParams);

		/** Text being written with the text tool: the options apply to it */
		function applyToEditedText() {
			const o = canvas.getActiveObject();
			if (o && o.dsKind === 'text') {
				applyToSelection();
			}
		}

		function syncBrush() {
			if (!canvas.isDrawingMode) {
				return;
			}
			const brush = canvas.freeDrawingBrush;
			brush.width = Number(size.value) || 1;
			brush.color = tool === 'marker' ? hexAlpha(color.value, 0.45) : color.value;
		}

		/* Pixels of the photo: mask a zone */

		/**
		 * New photo with a zone masked. Pixels: blocks of one color; blur: strong blur (a text may stay guessable,
		 * said in the help); flat: plain color.
		 */
		function maskZone(x, y, w, h) {
			x = Math.max(0, Math.round(x));
			y = Math.max(0, Math.round(y));
			w = Math.min(width - x, Math.round(w));
			h = Math.min(height - y, Math.round(h));
			if (w < 2 || h < 2) {
				return;
			}
			const c = newCanvas(width, height);
			const g = c.getContext('2d');
			g.drawImage(photo, 0, 0);
			const level = Number(strength.value) || 6;
			if (maskMode.value === 'flat') {
				g.fillStyle = maskColor.value;
				g.fillRect(x, y, w, h);
			} else if (maskMode.value === 'blur') {
				const radius = Math.max(4, Math.round(Math.max(w, h) * level / 60));
				g.save();
				g.beginPath();
				g.rect(x, y, w, h);
				g.clip();
				if ('filter' in g) {
					g.filter = 'blur(' + radius + 'px)';
					// Drawn twice: the edges of the zone blurred as much as its middle
					g.drawImage(photo, 0, 0);
					g.drawImage(c, 0, 0);
					g.filter = 'none';
				} else {
					pixelate(g, x, y, w, h, Math.max(4, Math.round(radius / 2)));
				}
				g.restore();
			} else {
				const block = Math.max(4, Math.round(Math.max(w, h) * level / 80));
				pixelate(g, x, y, w, h, block);
			}
			setPhoto(c);
			commit();
		}

		function pixelate(g, x, y, w, h, block) {
			const small = newCanvas(Math.max(1, Math.ceil(w / block)), Math.max(1, Math.ceil(h / block)));
			const sg = small.getContext('2d');
			sg.imageSmoothingEnabled = true;
			sg.imageSmoothingQuality = 'high';
			sg.drawImage(photo, x, y, w, h, 0, 0, small.width, small.height);
			g.imageSmoothingEnabled = false;
			g.drawImage(small, 0, 0, small.width, small.height, x, y, small.width * block, small.height * block);
			g.imageSmoothingEnabled = true;
		}

		/* Adjust: brightness and contrast */

		/**
		 * @param {HTMLCanvasElement} source
		 * @returns {HTMLCanvasElement}
		 */
		function adjusted(source) {
			const b = Number(brightness.value) / 100;
			const k = Number(contrast.value) / 100;
			const c = newCanvas(source.width, source.height);
			const g = c.getContext('2d');
			if ('filter' in g) {
				g.filter = 'brightness(' + (1 + b) + ') contrast(' + (1 + k) + ')';
				g.drawImage(source, 0, 0);
				return c;
			}
			g.drawImage(source, 0, 0);
			const data = g.getImageData(0, 0, c.width, c.height);
			const d = data.data;
			for (let i = 0; i < d.length; i += 4) {
				for (let j = 0; j < 3; j++) {
					d[i + j] = ((d[i + j] * (1 + b)) - 128) * (1 + k) + 128;
				}
			}
			g.putImageData(data, 0, 0);
			return c;
		}

		let adjustFrame = 0;
		function previewAdjust() {
			window.cancelAnimationFrame(adjustFrame);
			adjustFrame = window.requestAnimationFrame(function () {
				base.setElement(adjusted(photo));
				base.set({ left: 0, top: 0 });
				canvas.requestRenderAll();
			});
		}
		[brightness, contrast].forEach(function (input) {
			input.addEventListener('input', previewAdjust);
		});

		/* Crop */

		let cropRect = null;

		function cropRatio() {
			if (ratioSelect.value === 'free') {
				return 0;
			}
			if (ratioSelect.value === 'original') {
				return width / height;
			}
			return Number(ratioSelect.value);
		}

		function startCrop() {
			const r = cropRatio();
			let w = width * 0.9;
			let h = height * 0.9;
			if (r > 0) {
				if (w / h > r) {
					w = h * r;
				} else {
					h = w / r;
				}
			}
			if (cropRect) {
				canvas.remove(cropRect);
			}
			cropRect = new fabric.Rect({
				left: (width - w) / 2, top: (height - h) / 2, originX: 'left', originY: 'top', width: w, height: h,
				fill: 'rgba(0,0,0,0)', strokeWidth: 0, hasRotatingPoint: false, lockRotation: true, lockScalingFlip: true,
				objectCaching: false, transparentCorners: false, cornerSize: 14, cornerStyle: 'circle',
				cornerColor: '#ffffff', cornerStrokeColor: '#263c5c', borderColor: '#ffffff', borderScaleFactor: 2
			});
			cropRect.dsHelper = true;
			cropRect.setControlsVisibility({ mtr: false, ml: r === 0, mr: r === 0, mt: r === 0, mb: r === 0 });
			canvas.add(cropRect);
			canvas.setActiveObject(cropRect);
			canvas.requestRenderAll();
		}
		ratioSelect.addEventListener('change', function () {
			if (tool === 'crop') {
				startCrop();
			}
		});

		/** Crop frame kept inside the photo, at the ratio chosen */
		function clampCrop() {
			const o = cropRect;
			let w = o.width * o.scaleX;
			let h = o.height * o.scaleY;
			const r = cropRatio();
			w = Math.min(w, width);
			h = Math.min(h, height);
			if (r > 0) {
				if (w / h > r) {
					w = h * r;
				} else {
					h = w / r;
				}
			}
			const left = Math.min(Math.max(0, o.left), width - w);
			const top = Math.min(Math.max(0, o.top), height - h);
			o.set({ left: left, top: top, width: w, height: h, scaleX: 1, scaleY: 1 });
			o.setCoords();
		}

		function cropBox() {
			const o = cropRect;
			return { x: o.left, y: o.top, w: o.width * o.scaleX, h: o.height * o.scaleY };
		}

		// Outside of the frame darkened, frame and thirds drawn over the canvas
		canvas.on('after:render', function (e) {
			if (!cropRect || tool !== 'crop') {
				return;
			}
			const g = e.ctx || canvas.contextContainer;
			const b = cropBox();
			const s = zoom * canvas.getRetinaScaling();
			g.save();
			g.setTransform(1, 0, 0, 1, 0, 0);
			g.fillStyle = 'rgba(0,0,0,0.55)';
			const W = width * s;
			const H = height * s;
			g.fillRect(0, 0, W, b.y * s);
			g.fillRect(0, (b.y + b.h) * s, W, H - (b.y + b.h) * s);
			g.fillRect(0, b.y * s, b.x * s, b.h * s);
			g.fillRect((b.x + b.w) * s, b.y * s, W - (b.x + b.w) * s, b.h * s);
			g.strokeStyle = 'rgba(255,255,255,0.7)';
			g.lineWidth = 1;
			for (let i = 1; i < 3; i++) {
				g.beginPath();
				g.moveTo((b.x + b.w * i / 3) * s, b.y * s);
				g.lineTo((b.x + b.w * i / 3) * s, (b.y + b.h) * s);
				g.moveTo(b.x * s, (b.y + b.h * i / 3) * s);
				g.lineTo((b.x + b.w) * s, (b.y + b.h * i / 3) * s);
				g.stroke();
			}
			g.restore();
		});
		canvas.on('object:moving', function (e) {
			if (e.target === cropRect) {
				clampCrop();
			}
		});
		canvas.on('object:scaling', function (e) {
			if (e.target === cropRect) {
				clampCrop();
			}
		});

		/* Resize */

		function syncResizeInputs(changed) {
			if (!keepRatio.box.checked) {
				return;
			}
			if (changed === widthInput) {
				heightInput.value = String(Math.max(1, Math.round(Number(widthInput.value) * height / width)));
			} else {
				widthInput.value = String(Math.max(1, Math.round(Number(heightInput.value) * width / height)));
			}
		}
		widthInput.addEventListener('input', function () {
			syncResizeInputs(widthInput);
		});
		heightInput.addEventListener('input', function () {
			syncResizeInputs(heightInput);
		});

		/* Cutout: wand, eraser, restore brush, pen (engine in cutout.js) */

		function startCut() {
			const prev = photo.dsCut;
			// A photo made by a cutout goes on from its source, so the restore brush brings back its pixels
			cut = new cutoutLib.Cutout(prev ? prev.source : photo, prev ? prev.state : null);
			cutHist = [];
			cutPos = 0;
			cutSettings = cut.settings();
			cutAuto.box.checked = !cut.noCut;
			cutAutoTol.value = String(cut.tol);
			cut.render(null);
			base.setElement(cut.canvas);
			base.set({ left: 0, top: 0 });
			syncHistoryButtons();
		}

		/** The cutout shown becomes the photo (one step of the history of the editor) */
		function applyCut() {
			if (!cut || cutPos === 0) {
				return;
			}
			const session = cut;
			cut = null;
			cutHist = [];
			cutPos = 0;
			if (session.isEmpty()) {
				if (session.source === photo) {
					return;
				}
				setPhoto(session.source);
			} else {
				const result = session.canvas;
				result.dsCut = { source: session.source, state: session.state() };
				setPhoto(result);
			}
			commit();
		}

		function renderCutSoon() {
			window.cancelAnimationFrame(cutFrame);
			cutFrame = window.requestAnimationFrame(function () {
				if (cut) {
					cut.render(null);
					canvas.requestRenderAll();
				}
			});
		}

		function renderCut() {
			cut.render(null);
			canvas.requestRenderAll();
		}

		/** One step of the cutout: settings before and after, bytes of the brushes changed */
		function cutCommit(patch) {
			if (!cut) {
				return;
			}
			const after = cut.settings();
			if (!patch && after === cutSettings) {
				return;
			}
			cutHist.splice(cutPos);
			cutHist.push({ before: cutSettings, after: after, patch: patch || null });
			if (cutHist.length > HISTORY_MAX) {
				cutHist.shift();
			}
			cutPos = cutHist.length;
			cutSettings = after;
			setDirty();
			syncHistoryButtons();
			syncCutUi();
		}

		function cutRestore(json, patch, side) {
			cut.setSettings(json);
			if (patch) {
				cut.applyPatch(patch, side);
			}
			cutSettings = json;
			pen.pi = -1;
			pen.i = -1;
			cutAuto.box.checked = !cut.noCut;
			cutAutoTol.value = String(cut.tol);
			renderCut();
			syncHistoryButtons();
			syncCutUi();
		}

		function cutUndo() {
			if (cutPos > 0) {
				const step = cutHist[--cutPos];
				cutRestore(step.before, step.patch, 'before');
			}
		}

		function cutRedo() {
			if (cutPos < cutHist.length) {
				const step = cutHist[cutPos++];
				cutRestore(step.after, step.patch, 'after');
			}
		}

		function setCutMode(code) {
			cutMode = code;
			if (code !== 'pen') {
				pen.i = -1;
			}
			showParams();
			canvas.requestRenderAll();
		}

		function openPath() {
			return cut ? cut.paths.findIndex(function (x) {
				return !x.closed;
			}) : -1;
		}

		function selPath() {
			return cut && pen.pi >= 0 ? cut.paths[pen.pi] || null : null;
		}

		function selPt() {
			const path = selPath();
			return path && pen.i >= 0 ? path.pts[pen.i] || null : null;
		}

		function syncCutUi() {
			Object.keys(cutModeButtons).forEach(function (code) {
				cutModeButtons[code].classList.toggle('docstudio-active', code === cutMode);
				cutModeButtons[code].setAttribute('aria-pressed', code === cutMode ? 'true' : 'false');
			});
			const path = selPath();
			const pt = selPt();
			if (path) {
				cutPenMode.value = path.mode;
				cutFeather.value = String(path.feather);
			}
			cutCorner.disabled = !pt;
			cutSmooth.disabled = !pt;
			cutCorner.classList.toggle('docstudio-active', !!pt && !cutoutLib.isSmooth(pt));
			cutSmooth.classList.toggle('docstudio-active', !!pt && cutoutLib.isSmooth(pt));
			cutPointDelete.disabled = !pt;
			cutPathDelete.disabled = !path;
		}

		/* Cutout: settings */

		cutWandTol.addEventListener('input', function () {
			// The tolerance of the last wand point follows, the next points take it
			if (cut && cut.seeds.length) {
				cut.seeds[cut.seeds.length - 1].tol = Number(cutWandTol.value);
				renderCutSoon();
			}
		});
		cutWandTol.addEventListener('change', function () {
			cutCommit();
		});
		cutAuto.box.addEventListener('change', function () {
			if (cut) {
				cut.noCut = !cutAuto.box.checked;
				renderCut();
				cutCommit();
			}
		});
		cutAutoTol.addEventListener('input', function () {
			if (cut) {
				cut.tol = Number(cutAutoTol.value);
				renderCutSoon();
			}
		});
		cutAutoTol.addEventListener('change', function () {
			cutCommit();
		});
		cutPenMode.addEventListener('change', function () {
			const path = selPath();
			if (path) {
				path.mode = cutPenMode.value;
				renderCut();
				cutCommit();
			}
		});
		cutFeather.addEventListener('input', function () {
			const path = selPath();
			if (path) {
				path.feather = Number(cutFeather.value);
				renderCutSoon();
			}
		});
		cutFeather.addEventListener('change', function () {
			cutCommit();
		});

		function resetCut() {
			if (!cut) {
				return;
			}
			const patch = cut.clearMask();
			cut.paths = [];
			cut.seeds = [];
			cut.noCut = true;
			cutAuto.box.checked = false;
			pen.pi = -1;
			pen.i = -1;
			renderCut();
			cutCommit(patch);
		}

		/* Cutout: pen */

		function setPenPointType(type) {
			const path = selPath();
			if (path && selPt()) {
				cutoutLib.setPointType(path, pen.i, type);
				renderCut();
				cutCommit();
			}
		}

		function deletePenPoint() {
			const path = selPath();
			if (!path || !selPt()) {
				return;
			}
			path.pts.splice(pen.i, 1);
			if (path.pts.length < 3) {
				path.closed = false;
			}
			if (!path.pts.length) {
				cut.paths.splice(pen.pi, 1);
				pen.pi = -1;
			}
			pen.i = -1;
			renderCut();
			cutCommit();
		}

		function deletePenPath(index) {
			const pi = typeof index === 'number' ? index : pen.pi;
			if (!cut || pi < 0 || !cut.paths[pi]) {
				return;
			}
			cut.paths.splice(pi, 1);
			pen.pi = -1;
			pen.i = -1;
			renderCut();
			cutCommit();
		}

		function closePenPath(pi) {
			cut.paths[pi].closed = true;
			pen.pi = pi;
			pen.i = -1;
			renderCut();
			cutCommit();
		}

		function penDown(p, e) {
			const tol = 8 / zoom;
			const handle = cutoutLib.hitHandle(selPt(), p, tol);
			if (handle) {
				pen.drag = { type: handle };
				return;
			}
			const a = cutoutLib.hitAnchor(cut.paths, p, tol);
			const open = openPath();
			if (a) {
				if (a.pi === open && a.i === 0 && cut.paths[open].pts.length >= 3) {
					closePenPath(open);
					return;
				}
				pen.pi = a.pi;
				pen.i = a.i;
				if (e.altKey) {
					setPenPointType(cutoutLib.isSmooth(selPt()) ? 'corner' : 'smooth');
					return;
				}
				const pt = selPt();
				pen.drag = { type: 'anchor', start: p, x: pt.x, y: pt.y };
				syncCutUi();
				canvas.requestRenderAll();
				return;
			}
			const seg = cutoutLib.hitSegment(cut.paths, p, tol);
			if (seg) {
				pen.pi = seg.pi;
				pen.i = cutoutLib.insertPoint(cut.paths, seg);
				const pt = selPt();
				pen.drag = { type: 'anchor', start: p, x: pt.x, y: pt.y };
				syncCutUi();
				canvas.requestRenderAll();
				return;
			}
			// New point at the end of the path being drawn, else a new path; dragging pulls its handles
			let pi = open;
			if (pi < 0) {
				cut.paths.push({ mode: cutPenMode.value, feather: Number(cutFeather.value), closed: false, pts: [] });
				pi = cut.paths.length - 1;
			}
			cut.paths[pi].pts.push({ x: p.x, y: p.y, ix: 0, iy: 0, ox: 0, oy: 0 });
			pen.pi = pi;
			pen.i = cut.paths[pi].pts.length - 1;
			pen.drag = { type: 'new' };
			syncCutUi();
			canvas.requestRenderAll();
		}

		function penMove(p, e) {
			const pt = selPt();
			const d = pen.drag;
			if (!d || !pt) {
				return;
			}
			if (d.type === 'anchor') {
				pt.x = d.x + p.x - d.start.x;
				pt.y = d.y + p.y - d.start.y;
				return;
			}
			// Handles opposed (smooth point), Alt: the other one does not move
			const out = d.type !== 'in';
			const hx = p.x - pt.x;
			const hy = p.y - pt.y;
			if (out) {
				pt.ox = hx;
				pt.oy = hy;
			} else {
				pt.ix = hx;
				pt.iy = hy;
			}
			if (!e.altKey) {
				if (out) {
					pt.ix = -hx;
					pt.iy = -hy;
				} else {
					pt.ox = -hx;
					pt.oy = -hy;
				}
			}
		}

		/* Cutout: mouse */

		function wandClick(p) {
			const x = Math.floor(p.x);
			const y = Math.floor(p.y);
			if (x < 0 || y < 0 || x >= cut.w || y >= cut.h) {
				return;
			}
			const region = cut.regionAt(x, y);
			if (region >= 2) {
				// Click on an area of a wand point: given back
				cut.seeds.splice(region - 2, 1);
			} else if (region === 1) {
				return;
			} else if (cutoutLib.maskKeep(cut.mask[y * cut.w + x]) >= 0.5) {
				hint.textContent = t('DocStudioCutWandKept');
				return;
			} else {
				cut.seeds.push({ x: x, y: y, tol: Number(cutWandTol.value) });
			}
			renderCut();
			cutCommit();
		}

		function brushRadius() {
			return Number(cutSize.value) / 2 / zoom;
		}

		function dab(p) {
			cut.paint(p.x, p.y, brushRadius(), Number(cutHard.value) / 100, cutMode);
		}

		function cutDown(p, e) {
			if (!cut) {
				return;
			}
			// Fabric keeps the focus where it was: an option of the bar would catch the shortcuts
			if (document.activeElement && ctx.toolbar.contains(document.activeElement)) {
				document.activeElement.blur();
			}
			if (cutMode === 'wand') {
				wandClick(p);
			} else if (cutMode === 'pen') {
				penDown(p, e);
			} else {
				cutPainting = { last: p };
				cut.beginStroke();
				dab(p);
				canvas.requestRenderAll();
			}
		}

		function cutMove(p, e) {
			if (!cut) {
				return;
			}
			cutHover = p;
			if (cutPainting) {
				// Dabs close enough for a continuous stroke
				const last = cutPainting.last;
				const step = Math.max(1, brushRadius() / 3);
				const len = Math.hypot(p.x - last.x, p.y - last.y);
				for (let d = step; d <= len; d += step) {
					dab({ x: last.x + (p.x - last.x) * d / len, y: last.y + (p.y - last.y) * d / len });
				}
				cutPainting.last = p;
			} else if (cutMode === 'pen') {
				penMove(p, e);
			}
			canvas.requestRenderAll();
		}

		function cutUp() {
			if (!cut) {
				return;
			}
			if (cutPainting) {
				cutPainting = null;
				// The restore brush stops the wand: the areas computed again
				const patch = cut.endStroke(cutMode === 'keep');
				canvas.requestRenderAll();
				if (patch) {
					cutCommit(patch);
				}
			} else if (pen.drag) {
				pen.drag = null;
				renderCut();
				cutCommit();
			}
		}

		canvas.on('mouse:out', function () {
			if (cut && cutHover) {
				cutHover = null;
				canvas.requestRenderAll();
			}
		});

		/** Keys of the cutout, true when used */
		function cutKey(e) {
			const k = e.key.toLowerCase();
			const mode = cutModes.find(function (m) {
				return m.key === k;
			});
			if (mode) {
				setCutMode(mode.code);
				return true;
			}
			if (e.key === '[' || e.key === ']') {
				cutSize.value = String(Math.max(3, Math.min(150, Number(cutSize.value) + (e.key === ']' ? 4 : -4))));
				canvas.requestRenderAll();
				return true;
			}
			if (cutMode === 'pen') {
				if ((e.key === 'Delete' || e.key === 'Backspace') && selPt()) {
					deletePenPoint();
					return true;
				}
				const open = openPath();
				if (e.key === 'Enter' && open >= 0 && cut.paths[open].pts.length >= 3) {
					closePenPath(open);
					return true;
				}
			}
			return false;
		}

		/* Cutout: paths, wand points and brush drawn over the photo */

		canvas.on('after:render', function (e) {
			// Drawn on the main layer only: the top layer of Fabric is not cleared at each render
			if (!cut || tool !== 'cutout' || (e.ctx && e.ctx !== canvas.getContext())) {
				return;
			}
			const g = e.ctx || canvas.contextContainer;
			const s = zoom * canvas.getRetinaScaling();
			const k = 1 / zoom;
			g.save();
			g.setTransform(s, 0, 0, s, 0, 0);
			const open = openPath();
			cut.paths.forEach(function (path, pi) {
				const col = path.mode === 'erase' ? '#d31d11' : '#2a78d6';
				g.setLineDash(path.closed ? [] : [5 * k, 4 * k]);
				// White under the line: visible on any photo
				g.beginPath();
				cutoutLib.tracePath(g, path);
				g.strokeStyle = 'rgba(255,255,255,0.85)';
				g.lineWidth = 3.5 * k;
				g.stroke();
				g.strokeStyle = col;
				g.lineWidth = 1.5 * k;
				g.stroke();
				if (cutMode !== 'pen') {
					return;
				}
				g.setLineDash([]);
				if (pi === open && cutHover && !pen.drag && path.pts.length) {
					const last = path.pts[path.pts.length - 1];
					g.globalAlpha = 0.5;
					g.beginPath();
					g.moveTo(last.x, last.y);
					g.lineTo(cutHover.x, cutHover.y);
					g.stroke();
					g.globalAlpha = 1;
				}
				path.pts.forEach(function (pt, i) {
					const sel = pi === pen.pi && i === pen.i;
					if (sel) {
						[[pt.ix, pt.iy], [pt.ox, pt.oy]].forEach(function (h) {
							if (!h[0] && !h[1]) {
								return;
							}
							g.beginPath();
							g.moveTo(pt.x, pt.y);
							g.lineTo(pt.x + h[0], pt.y + h[1]);
							g.stroke();
							g.beginPath();
							g.arc(pt.x + h[0], pt.y + h[1], 3.5 * k, 0, Math.PI * 2);
							g.fillStyle = '#ffffff';
							g.fill();
							g.stroke();
						});
					}
					// First point of the path being drawn: bigger, click it to close
					const r = (i === 0 && !path.closed ? 6 : 4) * k;
					g.fillStyle = sel ? col : '#ffffff';
					g.beginPath();
					if (cutoutLib.isSmooth(pt)) {
						g.arc(pt.x, pt.y, r, 0, Math.PI * 2);
					} else {
						g.rect(pt.x - r, pt.y - r, r * 2, r * 2);
					}
					g.fill();
					g.stroke();
				});
			});
			g.setLineDash([]);
			if (cutMode === 'wand') {
				g.lineWidth = 1.5 * k;
				g.strokeStyle = '#2a78d6';
				g.fillStyle = '#ffffff';
				cut.seeds.forEach(function (sd) {
					g.beginPath();
					g.arc(sd.x + 0.5, sd.y + 0.5, 4 * k, 0, Math.PI * 2);
					g.fill();
					g.stroke();
				});
			}
			if ((cutMode === 'erase' || cutMode === 'keep') && cutHover) {
				// Size of the brush, and its hard part
				const rad = brushRadius();
				g.lineWidth = k;
				g.strokeStyle = 'rgba(255,255,255,0.9)';
				g.beginPath();
				g.arc(cutHover.x, cutHover.y, rad, 0, Math.PI * 2);
				g.stroke();
				g.setLineDash([3 * k, 3 * k]);
				g.strokeStyle = cutMode === 'erase' ? '#d31d11' : '#2a78d6';
				g.stroke();
				g.setLineDash([]);
				const inner = rad * Number(cutHard.value) / 100;
				if (inner > 2 * k && inner < rad - k) {
					g.globalAlpha = 0.6;
					g.beginPath();
					g.arc(cutHover.x, cutHover.y, inner, 0, Math.PI * 2);
					g.stroke();
					g.globalAlpha = 1;
				}
			}
			g.restore();
		});

		/* Layers: the elements over the photo, top first; hide, lock, rename, order */

		/** Selectable and clickable as the tool and the lock of each element allow */
		function applyObjectFlags() {
			const selecting = tool === 'select';
			userObjects().forEach(function (o) {
				o.selectable = !o.dsLocked && o.visible !== false && (selecting || (tool === 'text' && o.dsKind === 'text'));
				o.evented = o.selectable;
			});
		}

		const KIND_ICONS = { arrow: 'arrow', line: 'line', rect: 'rect', ellipse: 'ellipse', pen: 'draw', marker: 'highlight', number: 'number', text: 'text' };
		const KIND_LABELS = {
			arrow: t('DocStudioToolArrow'), line: t('DocStudioToolLine'), rect: t('DocStudioToolRect'), ellipse: t('DocStudioToolEllipse'),
			pen: t('DocStudioToolDraw'), marker: t('DocStudioToolHighlight')
		};

		/** Name shown: the one given by the user, else the kind with its number among the elements of this kind */
		function layerName(o, rank) {
			if (o.dsName) {
				return o.dsName;
			}
			if (o.dsKind === 'text') {
				const text = (o.text || '').replace(/\s+/g, ' ').trim();
				return t('DocStudioLayerText', text.length > 24 ? text.slice(0, 23) + '…' : text);
			}
			if (o.dsKind === 'number') {
				return t('DocStudioLayerNumber', o.dsNumber);
			}
			return (KIND_LABELS[o.dsKind] || o.dsKind) + ' ' + rank;
		}

		const layersPanel = document.createElement('aside');
		layersPanel.className = 'ds-layers';
		layersPanel.setAttribute('aria-label', t('DocStudioLayers'));
		const layersHead = document.createElement('div');
		layersHead.className = 'ds-layers-head';
		const layersTitle = document.createElement('h2');
		layersTitle.textContent = t('DocStudioLayers');
		const layersCount = document.createElement('span');
		layersCount.className = 'ds-layers-count';
		layersHead.append(layersTitle, layersCount);
		const layersList = document.createElement('ul');
		layersList.className = 'ds-layers-list';
		const layersEmpty = document.createElement('p');
		layersEmpty.className = 'ds-layers-empty';
		layersEmpty.textContent = t('DocStudioLayersEmpty');
		const layersHelp = document.createElement('p');
		layersHelp.className = 'ds-layers-help';
		layersHelp.textContent = t('DocStudioLayersHelp');
		layersPanel.append(layersHead, layersEmpty, layersList, layersHelp);
		root.appendChild(layersPanel);

		// Shown or not, remembered by this browser
		const layersToggle = ctx.utils.button('', t('DocStudioLayers'), function () {
			setLayersShown(layersPanel.hidden);
		});
		layersToggle.classList.add('ds-layers-toggle');
		const layersToggleLabel = document.createElement('span');
		layersToggle.replaceChildren(icon('layers'), layersToggleLabel);
		(ctx.statusbar || ctx.toolbar).appendChild(layersToggle);

		function setLayersShown(shown) {
			layersPanel.hidden = !shown;
			layersToggle.setAttribute('aria-pressed', shown ? 'true' : 'false');
			layersToggle.title = shown ? t('DocStudioLayersHide') : t('DocStudioLayersShow');
			try {
				window.localStorage.setItem('docstudio.imageLayers', shown ? '1' : '0');
			} catch (e) {
				// Storage not available: shown by default at each opening
			}
		}
		(function () {
			let shown = true;
			try {
				shown = window.localStorage.getItem('docstudio.imageLayers') !== '0';
			} catch (e) {
				shown = true;
			}
			setLayersShown(shown);
		})();

		let layerDrag = null;

		/** Elements put in this order (top first), the photo stays under them */
		function reorderLayers(topFirst) {
			const bottomFirst = topFirst.slice().reverse();
			bottomFirst.forEach(function (o, i) {
				canvas.moveObjectTo(o, i + 1);
			});
			canvas.requestRenderAll();
			commit();
		}

		function layerButton(name, label, pressed, onClick) {
			const b = document.createElement('button');
			b.type = 'button';
			b.className = 'ds-layer-action';
			b.title = label;
			b.setAttribute('aria-label', label);
			b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
			b.appendChild(icon(name));
			b.addEventListener('click', function (e) {
				e.stopPropagation();
				onClick();
			});
			return b;
		}

		function startRename(o, nameEl, rank) {
			const input = document.createElement('input');
			input.type = 'text';
			input.className = 'ds-layer-rename';
			input.value = o.dsName || layerName(o, rank);
			input.maxLength = 60;
			input.setAttribute('aria-label', t('DocStudioLayerRename'));
			let done = false;
			const finish = function (keep) {
				if (done) {
					return;
				}
				done = true;
				const value = input.value.trim();
				if (keep && value !== (o.dsName || '')) {
					o.dsName = value;
					commit();
				} else {
					refreshLayers();
				}
			};
			input.addEventListener('keydown', function (e) {
				e.stopPropagation();
				if (e.key === 'Enter') {
					e.preventDefault();
					finish(true);
				} else if (e.key === 'Escape') {
					e.preventDefault();
					finish(false);
				}
			});
			input.addEventListener('blur', function () {
				finish(true);
			});
			nameEl.replaceWith(input);
			input.focus();
			input.select();
		}

		function refreshLayers() {
			const objects = userObjects().slice().reverse();
			const ranks = {};
			const rankOf = new Map();
			objects.slice().reverse().forEach(function (o) {
				ranks[o.dsKind] = (ranks[o.dsKind] || 0) + 1;
				rankOf.set(o, ranks[o.dsKind]);
			});
			const focusedIndex = Array.prototype.indexOf.call(layersList.children, document.activeElement && document.activeElement.closest ? document.activeElement.closest('.ds-layer') : null);
			layersList.replaceChildren();
			const active = canvas.getActiveObjects();
			objects.forEach(function (o, index) {
				const li = document.createElement('li');
				li.className = 'ds-layer' + (active.includes(o) ? ' docstudio-active' : '') + (o.visible === false ? ' ds-layer-hidden' : '');
				li.tabIndex = 0;
				li.draggable = true;
				li.dsObject = o;
				const grip = document.createElement('span');
				grip.className = 'ds-layer-grip';
				grip.appendChild(icon('grip'));
				const kind = icon(KIND_ICONS[o.dsKind] || 'select');
				kind.classList.add('ds-layer-kind');
				const swatch = document.createElement('span');
				swatch.className = 'ds-layer-swatch';
				const c = o.dsKind === 'text' ? o.fill : (o.dsKind === 'number' ? o.getObjects()[0].fill : o.stroke);
				swatch.style.background = typeof c === 'string' ? c : 'transparent';
				const name = document.createElement('span');
				name.className = 'ds-layer-name';
				name.textContent = layerName(o, rankOf.get(o));
				name.title = t('DocStudioLayerRename');
				const eye = layerButton(o.visible === false ? 'eye-off' : 'eye', o.visible === false ? t('DocStudioLayerShow') : t('DocStudioLayerHide'), o.visible === false, function () {
					o.visible = o.visible === false;
					if (!o.visible && canvas.getActiveObjects().includes(o)) {
						canvas.discardActiveObject();
					}
					applyObjectFlags();
					canvas.requestRenderAll();
					commit();
				});
				const lock = layerButton(o.dsLocked ? 'lock' : 'lock-open', o.dsLocked ? t('DocStudioLayerUnlock') : t('DocStudioLayerLock'), !!o.dsLocked, function () {
					o.dsLocked = !o.dsLocked;
					if (o.dsLocked && canvas.getActiveObjects().includes(o)) {
						canvas.discardActiveObject();
					}
					applyObjectFlags();
					canvas.requestRenderAll();
					commit();
				});
				li.append(grip, kind, swatch, name, eye, lock);
				li.addEventListener('click', function () {
					if (o.dsLocked || o.visible === false) {
						return;
					}
					if (tool !== 'select') {
						selectTool('select');
					}
					canvas.setActiveObject(o);
					canvas.requestRenderAll();
					readSelection();
					showParams();
					syncLayerSelection();
				});
				name.addEventListener('dblclick', function (e) {
					e.stopPropagation();
					startRename(o, name, rankOf.get(o));
				});
				li.addEventListener('keydown', function (e) {
					if (e.target !== li) {
						return;
					}
					// Order with the keyboard too: Alt + up or down
					if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
						e.preventDefault();
						e.stopPropagation();
						const to = index + (e.key === 'ArrowUp' ? -1 : 1);
						if (to >= 0 && to < objects.length) {
							const order = objects.slice();
							order.splice(index, 1);
							order.splice(to, 0, o);
							reorderLayers(order);
							const row = layersList.children[to];
							if (row) {
								row.focus();
							}
						}
					} else if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						e.stopPropagation();
						li.click();
					} else if (e.key === 'F2') {
						e.preventDefault();
						e.stopPropagation();
						startRename(o, name, rankOf.get(o));
					}
				});
				li.addEventListener('dragstart', function (e) {
					layerDrag = o;
					li.classList.add('ds-layer-dragging');
					e.dataTransfer.effectAllowed = 'move';
					e.dataTransfer.setData('text/plain', '');
				});
				li.addEventListener('dragend', function () {
					layerDrag = null;
					refreshLayers();
				});
				li.addEventListener('dragover', function (e) {
					if (!layerDrag || layerDrag === o) {
						return;
					}
					e.preventDefault();
					const box = li.getBoundingClientRect();
					const after = e.clientY > box.top + box.height / 2;
					layersList.querySelectorAll('.ds-drop-before, .ds-drop-after').forEach(function (x) {
						x.classList.remove('ds-drop-before', 'ds-drop-after');
					});
					li.classList.add(after ? 'ds-drop-after' : 'ds-drop-before');
				});
				li.addEventListener('drop', function (e) {
					if (!layerDrag || layerDrag === o) {
						return;
					}
					e.preventDefault();
					const box = li.getBoundingClientRect();
					const after = e.clientY > box.top + box.height / 2;
					const order = objects.filter(function (x) {
						return x !== layerDrag;
					});
					order.splice(order.indexOf(o) + (after ? 1 : 0), 0, layerDrag);
					layerDrag = null;
					reorderLayers(order);
				});
				layersList.appendChild(li);
			});
			// The photo, always under the elements
			const photoRow = document.createElement('li');
			photoRow.className = 'ds-layer ds-layer-photo';
			const photoIcon = icon('photo');
			photoIcon.classList.add('ds-layer-kind');
			const photoName = document.createElement('span');
			photoName.className = 'ds-layer-name';
			photoName.textContent = t('DocStudioLayerPhoto');
			const photoLock = icon('lock');
			photoLock.classList.add('ds-layer-fixed');
			photoLock.setAttribute('aria-hidden', 'false');
			photoLock.setAttribute('role', 'img');
			photoLock.setAttribute('aria-label', t('DocStudioLayerPhotoFixed'));
			photoRow.title = t('DocStudioLayerPhotoFixed');
			photoRow.append(photoIcon, photoName, photoLock);
			layersList.appendChild(photoRow);
			// Same height as a layer and help always there: the panel keeps its shape when the first element comes
			layersEmpty.hidden = objects.length > 0;
			layersCount.textContent = String(objects.length);
			layersToggleLabel.textContent = t('DocStudioLayers') + (objects.length ? ' (' + objects.length + ')' : '');
			if (focusedIndex >= 0 && layersList.children[focusedIndex]) {
				layersList.children[focusedIndex].focus();
			}
		}

		// An element shows in the layers as soon as it is put (a text while it is written), not only at the next step
		let layersFrame = 0;
		function refreshLayersSoon() {
			window.cancelAnimationFrame(layersFrame);
			layersFrame = window.requestAnimationFrame(refreshLayers);
		}
		canvas.on('object:added', function (e) {
			if (!restoring && e.target && !e.target.dsHelper && !e.target.dsBase) {
				refreshLayersSoon();
			}
		});
		canvas.on('object:removed', function (e) {
			if (!restoring && e.target && !e.target.dsHelper && !e.target.dsBase) {
				refreshLayersSoon();
			}
		});
		canvas.on('text:changed', refreshLayersSoon);

		/** Rows of the selected elements marked, without building the list again */
		function syncLayerSelection() {
			const active = canvas.getActiveObjects();
			Array.prototype.forEach.call(layersList.children, function (li) {
				li.classList.toggle('docstudio-active', !!li.dsObject && active.includes(li.dsObject));
			});
		}

		/* Tool changes */

		/** The tool left: what it showed without applying it is removed */
		function leaveTool() {
			if (cropRect) {
				canvas.remove(cropRect);
				cropRect = null;
			}
			if ((tool === 'adjust' || tool === 'cutout') && base && base.getElement() !== photo) {
				base.setElement(photo);
				base.set({ left: 0, top: 0 });
			}
			if (cut) {
				cut = null;
				pen.pi = -1;
				pen.i = -1;
				pen.drag = null;
				cutPainting = null;
				syncHistoryButtons();
			}
			canvas.isDrawingMode = false;
			const active = canvas.getActiveObject();
			if (active && active.isEditing) {
				active.exitEditing();
			}
			canvas.requestRenderAll();
		}

		/** The tool entered: state of the canvas for it */
		function enterTool() {
			const selecting = tool === 'select';
			canvas.skipTargetFind = !(selecting || tool === 'text' || tool === 'crop');
			applyObjectFlags();
			if (!selecting) {
				canvas.discardActiveObject();
			}
			if (DRAWS.includes(tool)) {
				canvas.isDrawingMode = true;
				canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
				syncBrush();
			} else if (tool === 'crop') {
				startCrop();
			} else if (tool === 'resize') {
				widthInput.value = String(width);
				heightInput.value = String(height);
			} else if (tool === 'adjust') {
				brightness.value = '0';
				contrast.value = '0';
			} else if (tool === 'number') {
				nextNumber.value = String(nextFreeNumber());
			} else if (tool === 'cutout') {
				startCut();
			}
			canvas.defaultCursor = selecting ? 'default' : 'crosshair';
			canvas.requestRenderAll();
			showParams();
		}

		function selectTool(code) {
			const def = toolOf(code);
			if (!def) {
				return;
			}
			saveOptions(tool);
			if (tool === 'cutout' && code !== 'cutout') {
				// Another tool chosen: the cutout is applied (Cancel or Escape drops it)
				applyCut();
			}
			leaveTool();
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
			loadOptions(code);
			enterTool();
		}

		/** Apply button of crop, resize and adjust */
		function applyTool() {
			if (tool === 'crop' && cropRect) {
				const b = cropBox();
				const x = Math.round(b.x);
				const y = Math.round(b.y);
				const w = Math.max(1, Math.min(width - x, Math.round(b.w)));
				const h = Math.max(1, Math.min(height - y, Math.round(b.h)));
				canvas.remove(cropRect);
				cropRect = null;
				transformImage([1, 0, 0, 1, -x, -y], w, h, false);
			} else if (tool === 'resize') {
				const w = Math.max(1, Math.min(20000, Math.round(Number(widthInput.value))));
				const h = Math.max(1, Math.min(20000, Math.round(Number(heightInput.value))));
				if (w * h > maxPixels) {
					hint.textContent = t('DocStudioImageTooBig', Math.round(w * h / 100000) / 10, Math.round(maxPixels / 100000) / 10);
					return;
				}
				if (w !== width || h !== height) {
					transformImage([w / width, 0, 0, h / height, 0, 0], w, h, true);
				}
			} else if (tool === 'cutout') {
				applyCut();
			} else if (tool === 'adjust') {
				if (Number(brightness.value) !== 0 || Number(contrast.value) !== 0) {
					const c = adjusted(photo);
					setPhoto(c);
					commit();
				}
			}
			selectTool('select');
		}

		/** Cancel button and Escape: what the tool showed is dropped */
		function cancelTool() {
			if (tool === 'cutout') {
				leaveTool();
			}
			selectTool('select');
		}

		/* Mouse on the image */

		let drag = null;
		let wasEditing = false;

		// Fabric ends the edition of a text before mouse:down: remembered here, so that this click only ends it
		canvas.on('mouse:down:before', function () {
			const active = canvas.getActiveObject();
			wasEditing = !!(active && active.isEditing);
		});

		canvas.on('mouse:down', function (opt) {
			const p = opt.scenePoint;
			if (!p) {
				return;
			}
			if (tool === 'cutout') {
				cutDown(p, opt.e);
				return;
			}
			if (tool === 'text') {
				if (opt.target && opt.target.dsKind === 'text') {
					canvas.setActiveObject(opt.target);
					opt.target.enterEditing();
					readSelection();
					return;
				}
				const active = canvas.getActiveObject();
				if (wasEditing || (active && active.isEditing)) {
					// A click outside of the text ends it
					if (active && active.isEditing) {
						active.exitEditing();
					}
					canvas.discardActiveObject();
					canvas.requestRenderAll();
					return;
				}
				loadFontFace(fontOf(fontSelect.value));
				const o = makeText(p.x, p.y);
				canvas.add(o);
				canvas.setActiveObject(o);
				o.enterEditing();
				return;
			}
			if (tool === 'number') {
				const n = Math.max(1, Number(nextNumber.value) || nextFreeNumber());
				const o = makeNumber(p.x, p.y, n);
				o.selectable = false;
				o.evented = false;
				canvas.add(o);
				nextNumber.value = String(n + 1);
				commit();
				return;
			}
			if (SHAPES.includes(tool) || tool === 'mask') {
				drag = { x: p.x, y: p.y, obj: null };
			}
		});

		canvas.on('mouse:move', function (opt) {
			if (tool === 'cutout' && opt.scenePoint) {
				cutMove(opt.scenePoint, opt.e);
				return;
			}
			if (!drag || !opt.scenePoint) {
				return;
			}
			let x = opt.scenePoint.x;
			let y = opt.scenePoint.y;
			if (opt.e.shiftKey && (tool === 'arrow' || tool === 'line')) {
				// Horizontal, vertical or 45 degrees
				const angle = Math.round(Math.atan2(y - drag.y, x - drag.x) / (Math.PI / 4)) * (Math.PI / 4);
				const len = Math.hypot(x - drag.x, y - drag.y);
				x = drag.x + len * Math.cos(angle);
				y = drag.y + len * Math.sin(angle);
			} else if (opt.e.shiftKey && (tool === 'rect' || tool === 'ellipse')) {
				// Square or circle
				const s = Math.max(Math.abs(x - drag.x), Math.abs(y - drag.y));
				x = drag.x + Math.sign(x - drag.x || 1) * s;
				y = drag.y + Math.sign(y - drag.y || 1) * s;
			}
			if (drag.obj) {
				canvas.remove(drag.obj);
			}
			if (tool === 'mask') {
				drag.obj = new fabric.Rect({
					left: Math.min(drag.x, x), top: Math.min(drag.y, y), originX: 'left', originY: 'top',
					width: Math.abs(x - drag.x), height: Math.abs(y - drag.y), fill: 'rgba(38,60,92,0.25)',
					stroke: '#ffffff', strokeWidth: 1, strokeUniform: true, strokeDashArray: [6, 4], selectable: false, evented: false
				});
				drag.obj.dsHelper = true;
			} else {
				drag.obj = makeShape(tool, drag.x, drag.y, x, y);
				drag.obj.selectable = false;
				drag.obj.evented = false;
				drag.obj.dsHelper = true;
			}
			drag.end = { x: x, y: y };
			canvas.add(drag.obj);
			canvas.requestRenderAll();
		});

		canvas.on('mouse:up', function () {
			if (tool === 'cutout') {
				cutUp();
				return;
			}
			if (!drag) {
				return;
			}
			const d = drag;
			drag = null;
			if (!d.obj) {
				return;
			}
			canvas.remove(d.obj);
			const w = Math.abs(d.end.x - d.x);
			const h = Math.abs(d.end.y - d.y);
			if (Math.max(w, h) < 3) {
				canvas.requestRenderAll();
				return;
			}
			if (tool === 'mask') {
				maskZone(Math.min(d.x, d.end.x), Math.min(d.y, d.end.y), w, h);
				return;
			}
			const o = makeShape(tool, d.x, d.y, d.end.x, d.end.y);
			o.selectable = false;
			o.evented = false;
			canvas.add(o);
			canvas.requestRenderAll();
			commit();
		});

		canvas.on('path:created', function (e) {
			const path = e.path;
			path.dsKind = tool === 'marker' ? 'marker' : 'pen';
			if (tool === 'marker') {
				path.globalCompositeOperation = 'multiply';
				path.strokeLineCap = 'butt';
			}
			path.selectable = false;
			path.evented = false;
			commit();
		});

		canvas.on('object:modified', function (e) {
			if (e.target && !e.target.dsHelper) {
				commit();
			}
		});
		canvas.on('text:editing:exited', function (e) {
			const o = e.target;
			if (o && o.dsKind === 'text' && o.text.trim() === '') {
				canvas.remove(o);
			}
			commit();
		});
		['selection:created', 'selection:updated', 'selection:cleared'].forEach(function (name) {
			canvas.on(name, function () {
				// The crop frame stays selected: its handles always usable
				if (tool === 'crop' && cropRect && canvas.getActiveObject() !== cropRect) {
					canvas.setActiveObject(cropRect);
					canvas.requestRenderAll();
					return;
				}
				if (tool === 'select') {
					readSelection();
					showParams();
				}
				syncLayerSelection();
			});
		});

		function deleteSelection() {
			const list = canvas.getActiveObjects().filter(function (o) {
				return !o.dsHelper && !o.dsBase;
			});
			if (!list.length) {
				return;
			}
			canvas.discardActiveObject();
			list.forEach(function (o) {
				canvas.remove(o);
			});
			canvas.requestRenderAll();
			commit();
			showParams();
		}

		/* Keyboard */

		function onKey(e) {
			const active = document.activeElement;
			// Check boxes, sliders and colors do not take letters: the shortcuts stay usable
			const typing = active && (active.isContentEditable || ['SELECT', 'TEXTAREA'].includes(active.tagName)
				|| (active.tagName === 'INPUT' && !['checkbox', 'radio', 'range', 'color', 'button'].includes(active.type)));
			const editingText = canvas.getActiveObject() && canvas.getActiveObject().isEditing;
			if (e.key === 'Escape') {
				closeFlyout();
				if (editingText) {
					canvas.getActiveObject().exitEditing();
				} else if (tool === 'cutout' && openPath() >= 0) {
					// Drops the path being drawn only (an open path does not cut)
					deletePenPath(openPath());
				} else if (tool !== 'select') {
					cancelTool();
				}
				return;
			}
			if (editingText || typing) {
				if (e.key === 'Enter' && active && active.tagName === 'INPUT' && ['crop', 'resize', 'adjust', 'cutout'].includes(tool)) {
					e.preventDefault();
					applyTool();
				}
				return;
			}
			if ((e.ctrlKey || e.metaKey) && !e.altKey) {
				const k = e.key.toLowerCase();
				if (k === 'z' && !e.shiftKey) {
					e.preventDefault();
					undo();
				} else if (k === 'y' || (k === 'z' && e.shiftKey)) {
					e.preventDefault();
					redo();
				} else if (k === '0') {
					e.preventDefault();
					fitZoom();
				}
				return;
			}
			if (e.altKey) {
				return;
			}
			if (tool === 'cutout' && cutKey(e)) {
				e.preventDefault();
				return;
			}
			if (e.key === 'Enter' && ['crop', 'resize', 'adjust', 'cutout'].includes(tool)) {
				e.preventDefault();
				applyTool();
				return;
			}
			if ((e.key === 'Delete' || e.key === 'Backspace') && tool === 'select') {
				e.preventDefault();
				deleteSelection();
				return;
			}
			const def = tools.find(function (x) {
				return x.key !== '' && x.key === e.key.toLowerCase();
			});
			if (def) {
				e.preventDefault();
				selectTool(def.code);
			}
		}
		document.addEventListener('keydown', onKey);

		/* Start */

		setPhoto(photo);
		fitZoom();
		selectTool('select');
		commit();
		const resizeObserver = new ResizeObserver(function () {
			// Only while fitted: scrollbars appearing after a zoom chosen by the user must not undo it
			if (autoFit) {
				fitZoom();
			}
		});
		let fittedOnce = false;
		resizeObserver.observe(scroller);
		window.requestAnimationFrame(function () {
			if (!fittedOnce) {
				fittedOnce = true;
				fitZoom();
			}
		});

		/* Build the file */

		/**
		 * Photo and objects in a canvas of the size of the photo, encoded in the type of the file
		 * @returns {Promise<Blob>}
		 */
		async function buildImage() {
			leaveTool();
			canvas.discardActiveObject();
			const c = newCanvas(width, height);
			const g = c.getContext('2d');
			if (mime === 'image/jpeg') {
				// No transparency in JPEG
				g.fillStyle = '#ffffff';
				g.fillRect(0, 0, width, height);
			}
			// Rendered by Fabric at the scale of the photo (its caches are made again at this scale), without the frames
			// of the tools; +0.5 so that the rounding gives exactly the size of the photo
			const rendered = canvas.toCanvasElement(1 / zoom, {
				left: 0, top: 0, width: (width + 0.5) * zoom, height: (height + 0.5) * zoom,
				filter: function (o) {
					return !o.dsHelper;
				}
			});
			g.drawImage(rendered, 0, 0);
			const quality = mime === 'image/png' ? undefined : 0.92;
			const out = await new Promise(function (resolve) {
				c.toBlob(resolve, mime, quality);
			});
			if (!out || out.type !== mime) {
				// The browser cannot write this type
				throw new Error(t('DocStudioImageCannotEncode', ctx.file.extension.toUpperCase()));
			}
			return out;
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
		// Main document of a validated invoice: a copy only, the reason in the tooltip
		if (ctx.config.canReplace === false) {
			replaceLabel.title = t('DocStudioErrorValidatedMainDoc');
		}
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
		const metadata = document.createElement('p');
		metadata.textContent = t('DocStudioImageMetadata');
		impact.appendChild(metadata);
		const hiddenNote = document.createElement('p');
		hiddenNote.className = 'ds-warning';
		impact.appendChild(hiddenNote);
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
			replaceRadio.disabled = ctx.config.canReplace === false;
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

		function onSaveClick() {
			if (tool === 'cutout') {
				// The cutout shown is what the user wants to save
				applyTool();
			}
			status.textContent = '';
			// Hidden layers are not in the image saved
			const hiddenCount = userObjects().filter(function (o) {
				return o.visible === false;
			}).length;
			hiddenNote.hidden = hiddenCount === 0;
			hiddenNote.textContent = hiddenCount ? t('DocStudioLayersHiddenNotSaved', hiddenCount) : '';
			syncDialog();
			dialog.showModal();
		}
		saveButton.addEventListener('click', onSaveClick);

		let saving = false;
		async function doSave() {
			if (saving) {
				return;
			}
			saving = true;
			confirm.disabled = true;
			try {
				status.textContent = t('DocStudioPreparing');
				const out = await buildImage();
				status.textContent = t('DocStudioSaving');
				const result = await window.DocStudio.saveContent(out, {
					mode: replaceRadio.checked ? 'replace' : 'copy',
					filename: nameInput.value.trim() || ctx.config.copyName,
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
				enterTool();
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
				document.removeEventListener('keydown', onSpace);
				document.removeEventListener('keyup', onSpace);
				window.removeEventListener('blur', onWindowBlur);
				document.removeEventListener('pointerdown', onDocPointerDown);
				saveButton.removeEventListener('click', onSaveClick);
				resizeObserver.disconnect();
				dialog.remove();
				canvas.dispose();
			}
		};
	}
});

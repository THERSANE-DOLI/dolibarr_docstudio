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
 * Viewer page: chooses the viewer, runs the JavaScript hooks, gives the content of the file to the viewer.
 *
 * JavaScript hooks (only when the JavaScript context of Dolibarr is loaded, otherwise the default behaviour applies):
 * - await Dolibarr.executeHookAwait('docstudio:beforeOpen', ctx), ctx = {
 *     source, file: {name, extension, size}, viewers: [codes offered], viewer: code chosen, cancel: false, reason: ''
 *   }: a hook may change ctx.viewer (to one of ctx.viewers only) or set ctx.cancel = true with a ctx.reason.
 * - Dolibarr.executeHook('docstudio:opened', {source, file, viewer}) once the viewer is shown.
 */

const DS = window.DocStudio;
const cfg = DS.getConfig();
const main = document.getElementById('docstudio-main');
const tools = document.getElementById('docstudio-tools');
// Bottom bar of the editor page (zoom, help), null on the viewer page: the viewers then use the toolbar
const statusbar = document.getElementById('docstudio-status');
const select = document.getElementById('docstudio-viewer-select');
const allowed = (cfg.viewers || []).map(function (v) {
	return v.code;
});
const fileInfo = Object.freeze({ name: cfg.file.name, extension: cfg.file.extension, size: cfg.file.size });

/* Light / dark switch of the status bar (edit page): saved for the user, the new theme grows in a circle from the
   button (View Transitions API), else the colors cross-fade; at once when the user asks for reduced motion */

const SVG_NS = 'http://www.w3.org/2000/svg';

function themeIcon(name, cls) {
	const svg = document.createElementNS(SVG_NS, 'svg');
	svg.setAttribute('class', 'ds-icon ' + cls);
	svg.setAttribute('aria-hidden', 'true');
	const use = document.createElementNS(SVG_NS, 'use');
	use.setAttribute('href', '#ds-i-' + name);
	svg.appendChild(use);
	return svg;
}

/** The theme shown now is dark: forced dark (1), or like the system (2) with a dark system */
function isDarkShown() {
	const mode = document.documentElement.dataset.darkmode;
	if (mode === '1') {
		return true;
	}
	return mode === '2' && !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
}

function addThemeSwitch() {
	if (!statusbar || !cfg.themeUrl) {
		return;
	}
	const btn = document.createElement('button');
	btn.type = 'button';
	btn.className = 'docstudio-button ds-theme-switch';
	btn.setAttribute('role', 'switch');
	btn.setAttribute('aria-label', DS.t('DocStudioThemeDark'));
	btn.append(themeIcon('moon', 'ds-theme-moon'), themeIcon('sun', 'ds-theme-sun'));
	const paint = function () {
		const dark = isDarkShown();
		btn.setAttribute('aria-checked', String(dark));
		btn.title = DS.t(dark ? 'DocStudioThemeToLight' : 'DocStudioThemeToDark');
	};
	paint();
	// Next to the zoom (or the page), before the help of the tool
	const hint = statusbar.querySelector('.ds-hint');
	statusbar.insertBefore(btn, hint);
	btn.addEventListener('click', function () {
		const root = document.documentElement;
		const theme = isDarkShown() ? 'light' : 'dark';
		const apply = function () {
			root.dataset.darkmode = theme === 'dark' ? '1' : '0';
			void root.offsetHeight;
			paint();
		};
		const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		if (reduced) {
			apply();
		} else if (document.startViewTransition) {
			const box = btn.getBoundingClientRect();
			const x = box.left + box.width / 2;
			const y = box.top + box.height / 2;
			const radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
			const tr = document.startViewTransition(apply);
			tr.ready.then(function () {
				root.animate({ clipPath: ['circle(0 at ' + x + 'px ' + y + 'px)', 'circle(' + radius + 'px at ' + x + 'px ' + y + 'px)'] },
					{ duration: 550, easing: 'ease-in-out', pseudoElement: '::view-transition-new(root)' });
			}).catch(function () {
				// Applied without animation
			});
		} else {
			root.classList.add('ds-theme-fading');
			apply();
			window.setTimeout(function () {
				root.classList.remove('ds-theme-fading');
			}, 400);
		}
		const form = new FormData();
		form.append('token', cfg.token || '');
		form.append('theme', theme);
		fetch(cfg.themeUrl, { method: 'POST', body: form, credentials: 'same-origin' }).catch(function () {
			// Shown anyway, not remembered for the next page
		});
	});
}

let contentPromise = null;
let current = null;

/**
 * Content of the file, fetched once
 * @returns {Promise<ArrayBuffer>}
 */
function fetchContent() {
	if (contentPromise === null) {
		contentPromise = fetch(cfg.contentUrl, { credentials: 'same-origin', cache: 'no-store', redirect: 'error' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error(DS.t('DocStudioErrorLoading'));
				}
				return response.arrayBuffer();
			});
		contentPromise.catch(function () {
			contentPromise = null;
		});
	}
	return contentPromise;
}

const file = Object.freeze({
	name: fileInfo.name,
	extension: fileInfo.extension,
	size: fileInfo.size,
	/** @returns {Promise<ArrayBuffer>} a copy, the viewer may transfer it */
	arrayBuffer: function () {
		return fetchContent().then(function (buffer) {
			return buffer.slice(0);
		});
	},
	/**
	 * @param {string} type MIME type of the blob
	 * @returns {Promise<Blob>}
	 */
	blob: function (type) {
		return fetchContent().then(function (buffer) {
			return new Blob([buffer], { type: type || 'application/octet-stream' });
		});
	},
	/** @returns {Promise<{text: string, encoding: string}>} */
	text: function () {
		return fetchContent().then(DS.utils.decodeText);
	}
});

/**
 * @param {string} text
 * @param {boolean} isError
 */
function showMessage(text, isError) {
	main.replaceChildren();
	const div = document.createElement('div');
	div.className = 'docstudio-message' + (isError ? ' docstudio-error' : '');
	div.textContent = text;
	main.appendChild(div);
}

/**
 * @returns {string} viewer asked by the link, else the one chosen for this type (administrator or user), else the first
 */
function defaultViewer() {
	if (cfg.viewerAsked && allowed.indexOf(cfg.viewerAsked) >= 0) {
		return cfg.viewerAsked;
	}
	if (cfg.viewerDefault && allowed.indexOf(cfg.viewerDefault) >= 0) {
		return cfg.viewerDefault;
	}
	return allowed[0];
}

/**
 * Open the file with a viewer
 * @param {string} code
 */
async function open(code) {
	let ctx = { source: cfg.source, file: fileInfo, viewers: allowed.slice(), viewer: code, cancel: false, reason: '' };
	if (window.Dolibarr && typeof window.Dolibarr.executeHookAwait === 'function') {
		try {
			const result = await window.Dolibarr.executeHookAwait('docstudio:beforeOpen', ctx);
			if (result && typeof result === 'object') {
				ctx = result;
			}
		} catch (e) {
			ctx.cancel = true;
			ctx.reason = e && e.message ? e.message : '';
		}
	}
	if (ctx.cancel) {
		showMessage(DS.t('DocStudioOpeningCancelled') + (ctx.reason ? ' ' + String(ctx.reason) : ''), true);
		return;
	}
	// A hook may only choose among the viewers offered by the server
	if (allowed.indexOf(ctx.viewer) >= 0) {
		code = ctx.viewer;
	}

	const impl = DS.getViewer(code);
	if (!impl) {
		showMessage(DS.t('DocStudioErrorNoViewer'), true);
		return;
	}

	if (current && typeof current.unmount === 'function') {
		try {
			current.unmount();
		} catch (e) {
			console.error(e);
		}
	}
	current = null;
	tools.replaceChildren();
	if (statusbar) {
		statusbar.replaceChildren();
	}
	showMessage(DS.t('DocStudioLoading'), false);
	const loading = main.firstChild;
	main.className = 'docstudio-main docstudio-viewer-' + code;

	// The container is in the page from the start: viewers can measure the width available
	const container = document.createElement('div');
	container.className = 'docstudio-container';
	main.appendChild(container);
	try {
		const mounted = await impl.mount({ container: container, toolbar: tools, statusbar: statusbar, file: file, config: cfg, t: DS.t, utils: DS.utils });
		loading.remove();
		current = mounted || null;
	} catch (e) {
		console.error(e);
		showMessage(e && e.message ? e.message : DS.t('DocStudioErrorLoading'), true);
		return;
	}
	if (select) {
		select.value = code;
	}
	addThemeSwitch();
	if (window.Dolibarr && typeof window.Dolibarr.executeHook === 'function') {
		window.Dolibarr.executeHook('docstudio:opened', { source: cfg.source, file: fileInfo, viewer: code });
	}
}

if (select) {
	// One time choice: the lasting choice belongs to the administrator (and to the user when allowed, on the user card)
	select.addEventListener('change', function () {
		open(select.value);
	});
}

// Escape closes the dialog of the page of Dolibarr that shows this viewer
document.addEventListener('keydown', function (e) {
	if (e.key === 'Escape' && window.parent !== window) {
		window.parent.postMessage({ type: 'docstudio:close' }, window.location.origin);
	}
});

if (allowed.length) {
	open(defaultViewer());
}

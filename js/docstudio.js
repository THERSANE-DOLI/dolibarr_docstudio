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
 * DocStudio: registry of the viewers in the browser.
 *
 * A viewer script (declared in PHP with the hook docstudioRegisterViewers) calls:
 *   DocStudio.registerViewer('mycode', {
 *     async mount(ctx) { ... ; return { unmount() { ... } }; }
 *   });
 * ctx = {
 *   container: element to fill,
 *   toolbar: element of the toolbar where the viewer may add its own buttons,
 *   file: { name, extension, size, arrayBuffer(), blob(type), text() },
 *   config: configuration of the page (read only),
 *   t(key, ...args): translation,
 *   utils: helpers (button(), decodeText())
 * }
 * Only the viewers declared for the file on the server are offered: registering a code here does not make it usable.
 * Viewers must build the page with textContent / DOM methods, never with innerHTML on content of the file.
 *
 * Also available as Dolibarr.tools.docStudio when the JavaScript context of Dolibarr is loaded.
 */
(function () {
	'use strict';

	const viewers = new Map();
	let config = null;

	/**
	 * Configuration printed by the page as JSON data
	 * @returns {Object}
	 */
	function getConfig() {
		if (config === null) {
			const el = document.getElementById('docstudio-config');
			try {
				config = el ? JSON.parse(el.textContent) : {};
			} catch (e) {
				config = {};
			}
			deepFreeze(config);
		}
		return config;
	}

	/**
	 * @param {Object} obj
	 * @returns {Object}
	 */
	function deepFreeze(obj) {
		if (obj && typeof obj === 'object') {
			Object.values(obj).forEach(deepFreeze);
			Object.freeze(obj);
		}
		return obj;
	}

	/**
	 * Translation given by the page, %s replaced by the arguments
	 * @param {string} key
	 * @param {...*} args
	 * @returns {string}
	 */
	function t(key, ...args) {
		const langs = getConfig().langs || {};
		let text = Object.prototype.hasOwnProperty.call(langs, key) ? String(langs[key]) : key;
		args.forEach(function (arg) {
			text = text.replace('%s', String(arg));
		});
		return text;
	}

	/**
	 * Button of the toolbar
	 * @param {string} label  Text of the button
	 * @param {string} title  Tooltip
	 * @param {function} onClick
	 * @returns {HTMLButtonElement}
	 */
	function button(label, title, onClick) {
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'docstudio-button';
		btn.textContent = label;
		if (title) {
			btn.title = title;
			btn.setAttribute('aria-label', title);
		}
		btn.addEventListener('click', onClick);
		return btn;
	}

	/**
	 * Text of a file: UTF-8 when valid (byte order mark removed), else Windows-1252
	 * @param {ArrayBuffer} buffer
	 * @returns {{text: string, encoding: string}}
	 */
	function decodeText(buffer) {
		let bytes = new Uint8Array(buffer);
		if (bytes.length >= 3 && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
			bytes = bytes.subarray(3);
		}
		try {
			return { text: new TextDecoder('utf-8', { fatal: true }).decode(bytes), encoding: 'UTF-8' };
		} catch (e) {
			return { text: new TextDecoder('windows-1252').decode(bytes), encoding: 'Windows-1252' };
		}
	}

	const api = {
		/**
		 * Register the implementation of a viewer
		 * @param {string} code Code declared in PHP
		 * @param {{mount: function}} impl
		 * @returns {boolean} false if refused (bad code, already registered, no mount function)
		 */
		registerViewer(code, impl) {
			if (typeof code !== 'string' || !/^[a-z][a-z0-9_]{1,31}$/.test(code) || viewers.has(code)
				|| !impl || typeof impl.mount !== 'function') {
				console.warn('DocStudio: viewer refused', code);
				return false;
			}
			viewers.set(code, Object.freeze({ mount: impl.mount }));
			return true;
		},

		/**
		 * @param {string} code
		 * @returns {{mount: function}|null}
		 */
		getViewer(code) {
			return viewers.get(code) || null;
		},

		getConfig: getConfig,
		t: t,
		utils: Object.freeze({ button: button, decodeText: decodeText })
	};
	Object.freeze(api);

	Object.defineProperty(window, 'DocStudio', { value: api, writable: false, configurable: false });
	if (window.Dolibarr && typeof window.Dolibarr.defineTool === 'function' && !window.Dolibarr.checkToolExist('docStudio')) {
		window.Dolibarr.defineTool('docStudio', api);
	}
})();

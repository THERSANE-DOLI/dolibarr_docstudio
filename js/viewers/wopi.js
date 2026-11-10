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
 * Office server (WOPI, lot 4): Collabora Online, OnlyOffice or Euro-Office in a frame. The access token is posted to
 * the frame (never in its address). The office server reads and writes the file through wopi.php of DocStudio.
 * Messages with the frame (PostMessage API) only with the origin of the office server.
 * Editing: the office server saves by itself; the back button asks it to save first.
 */

function mountWopi(ctx, editing) {
	const t = ctx.t;
	const w = ctx.config.wopi;
	if (!w || !w.url || !w.token) {
		throw new Error(t('DocStudioWopiUnavailable'));
	}

	const name = 'docstudio-wopi-' + Math.random().toString(36).slice(2);
	const frame = document.createElement('iframe');
	frame.name = name;
	frame.className = 'docstudio-wopi';
	frame.title = ctx.file.name;
	frame.setAttribute('allow', 'clipboard-read; clipboard-write; fullscreen');
	frame.setAttribute('allowfullscreen', '');
	ctx.container.appendChild(frame);

	const form = document.createElement('form');
	form.method = 'POST';
	form.action = w.url;
	form.target = name;
	form.hidden = true;
	[['access_token', w.token], ['access_token_ttl', String(w.ttl)]].forEach(function (pair) {
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = pair[0];
		input.value = pair[1];
		form.appendChild(input);
	});
	document.body.appendChild(form);
	form.submit();
	form.remove();

	// Saved by the office server: the button of DocStudio is not used
	const saveButton = document.getElementById('docstudio-save');
	if (saveButton) {
		saveButton.hidden = true;
	}

	const status = document.createElement('span');
	status.className = 'docstudio-pageinfo';
	status.setAttribute('role', 'status');
	// Only the editing says something (saving when leaving): no empty bar when reading
	if (editing) {
		ctx.toolbar.appendChild(status);
	}

	function post(message) {
		if (frame.contentWindow) {
			frame.contentWindow.postMessage(JSON.stringify(Object.assign({ SendTime: Date.now() }, message)), w.origin);
		}
	}

	let saveWaiter = null;
	function onMessage(e) {
		if (e.origin !== w.origin || e.source !== frame.contentWindow) {
			return;
		}
		let msg;
		try {
			msg = typeof e.data === 'string' ? JSON.parse(e.data) : e.data;
		} catch (err) {
			return;
		}
		if (!msg || typeof msg.MessageId !== 'string') {
			return;
		}
		const values = msg.Values || {};
		if (msg.MessageId === 'App_LoadingStatus' && values.Status === 'Frame_Ready') {
			post({ MessageId: 'Host_PostmessageReady' });
		} else if (msg.MessageId === 'Action_Save_Resp' && saveWaiter) {
			saveWaiter();
		} else if (msg.MessageId === 'UI_Close') {
			leave();
		}
	}
	window.addEventListener('message', onMessage);

	/** Back to Dolibarr: when editing, the office server saves first (a few seconds at most) */
	let leaving = false;
	async function leave() {
		if (leaving) {
			return;
		}
		leaving = true;
		if (editing) {
			status.textContent = t('DocStudioWopiSaving');
			await new Promise(function (resolve) {
				saveWaiter = resolve;
				post({ MessageId: 'Action_Save', Values: { DontTerminateEdit: false, DontSaveIfUnmodified: true, Notify: true } });
				window.setTimeout(resolve, 5000);
			});
		}
		window.location.href = ctx.config.backUrl || document.getElementById('docstudio-back').href;
	}
	const back = document.getElementById('docstudio-back');
	function onBack(e) {
		if (editing) {
			e.preventDefault();
			leave();
		}
	}
	if (back) {
		back.addEventListener('click', onBack);
	}

	return {
		unmount() {
			window.removeEventListener('message', onMessage);
			if (back) {
				back.removeEventListener('click', onBack);
			}
			if (saveButton) {
				saveButton.hidden = false;
			}
			frame.remove();
		}
	};
}

window.DocStudio.registerViewer('wopi', {
	async mount(ctx) {
		return mountWopi(ctx, false);
	}
});
window.DocStudio.registerViewer('wopiedit', {
	async mount(ctx) {
		return mountWopi(ctx, true);
	}
});

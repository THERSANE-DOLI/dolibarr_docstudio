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
 * Office installed on the computer (lot 4): a button with the link ms-excel:, ms-word: or ms-powerpoint: of the file.
 * Office reads (and when editing, locks and writes back) the file through dav.php, with the signed address of the link.
 * Nothing is opened without a click: the browser asks first whether Office may open.
 */

function mountDesktop(ctx, editing) {
	const t = ctx.t;
	const d = ctx.config.desktop;
	if (!d || !d.link) {
		throw new Error(t('DocStudioErrorNoViewer'));
	}
	const app = d.application || 'Office';

	const box = document.createElement('div');
	box.className = 'docstudio-desktop';

	const title = document.createElement('h2');
	title.textContent = t('DocStudioDesktopTitle', app);
	box.appendChild(title);

	const help = document.createElement('p');
	help.textContent = t(d.edit ? 'DocStudioDesktopHelpEdit' : 'DocStudioDesktopHelpView', app);
	box.appendChild(help);

	const open = document.createElement('a');
	open.className = 'docstudio-button docstudio-primary';
	open.href = d.link;
	open.textContent = t('DocStudioDesktopOpen', app);
	box.appendChild(open);

	// Details only on demand: what to do when nothing opens, the address for File > Open
	const more = document.createElement('details');
	const summary = document.createElement('summary');
	summary.textContent = t('DocStudioDesktopNothing');
	more.appendChild(summary);
	const expire = document.createElement('p');
	const until = new Date(d.expire * 1000);
	expire.textContent = t('DocStudioDesktopExpire', app, until.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
	more.appendChild(expire);
	const row = document.createElement('div');
	row.className = 'docstudio-desktop-url';
	const input = document.createElement('input');
	input.type = 'text';
	input.readOnly = true;
	input.value = d.url;
	input.setAttribute('aria-label', t('DocStudioDesktopCopy'));
	input.addEventListener('focus', function () {
		input.select();
	});
	const copy = document.createElement('button');
	copy.type = 'button';
	copy.className = 'docstudio-button';
	copy.textContent = t('DocStudioDesktopCopy');
	copy.addEventListener('click', function () {
		input.select();
		const done = function () {
			copy.textContent = t('DocStudioDesktopCopied');
			window.setTimeout(function () {
				copy.textContent = t('DocStudioDesktopCopy');
			}, 2000);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(d.url).then(done, function () {
				document.execCommand('copy');
				done();
			});
		} else {
			document.execCommand('copy');
			done();
		}
	});
	row.appendChild(input);
	row.appendChild(copy);
	more.appendChild(row);
	box.appendChild(more);

	ctx.container.appendChild(box);

	// Saved by Office itself: the button of DocStudio is not used
	const saveButton = document.getElementById('docstudio-save');
	if (editing && saveButton) {
		saveButton.hidden = true;
	}

	return {
		unmount() {
			if (saveButton) {
				saveButton.hidden = false;
			}
			box.remove();
		}
	};
}

window.DocStudio.registerViewer('desktop', {
	async mount(ctx) {
		return mountDesktop(ctx, false);
	}
});
window.DocStudio.registerViewer('desktopedit', {
	async mount(ctx) {
		return mountDesktop(ctx, true);
	}
});

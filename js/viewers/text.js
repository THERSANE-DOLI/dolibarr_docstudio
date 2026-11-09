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
 * Text viewer: the source of the file as plain text (HTML, XML... are shown as code, never rendered).
 */

window.DocStudio.registerViewer('text', {
	async mount(ctx) {
		const decoded = await ctx.file.text();
		const pre = document.createElement('pre');
		pre.className = 'docstudio-text docstudio-text-wrap';
		pre.textContent = decoded.text;
		ctx.container.appendChild(pre);

		const info = document.createElement('span');
		info.className = 'docstudio-pageinfo';
		info.textContent = ctx.t('DocStudioEncoding', decoded.encoding);

		const wrapLabel = document.createElement('label');
		wrapLabel.className = 'docstudio-check';
		const wrap = document.createElement('input');
		wrap.type = 'checkbox';
		wrap.checked = true;
		wrap.addEventListener('change', function () {
			pre.classList.toggle('docstudio-text-wrap', wrap.checked);
		});
		wrapLabel.append(wrap, document.createTextNode(' ' + ctx.t('DocStudioWrapLines')));
		ctx.toolbar.append(wrapLabel, info);

		return null;
	}
});

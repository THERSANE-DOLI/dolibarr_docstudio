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
 * Image viewer. The type given to the browser comes from the list of the server (by extension), never from the file.
 * An SVG is shown in an <img> element only, where its scripts and external resources never run nor load.
 */

window.DocStudio.registerViewer('image', {
	async mount(ctx) {
		const types = ctx.config.imageMimeTypes || {};
		const type = types[ctx.file.extension];
		if (!type) {
			throw new Error(ctx.t('DocStudioErrorNoViewer'));
		}
		const url = URL.createObjectURL(await ctx.file.blob(type));
		const img = document.createElement('img');
		img.className = 'docstudio-image docstudio-image-fit';
		img.alt = ctx.file.name;
		img.decoding = 'async';
		await new Promise(function (resolve, reject) {
			img.onload = resolve;
			img.onerror = function () {
				reject(new Error(ctx.t('DocStudioErrorLoading')));
			};
			img.src = url;
		});
		ctx.container.appendChild(img);

		ctx.toolbar.append(
			ctx.utils.button('↔', ctx.t('DocStudioZoomFit'), function () {
				img.classList.add('docstudio-image-fit');
			}),
			ctx.utils.button('1:1', ctx.t('DocStudioZoomActual'), function () {
				img.classList.remove('docstudio-image-fit');
			})
		);
		img.addEventListener('click', function () {
			img.classList.toggle('docstudio-image-fit');
		});

		return {
			unmount() {
				URL.revokeObjectURL(url);
			}
		};
	}
});

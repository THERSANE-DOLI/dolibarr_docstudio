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
 * File associations page: the handler chosen in « Opened by » is always a choice of the users, so its box is ticked
 * and locked.
 */
(function () {
	'use strict';

	function sync(select) {
		const row = select.closest('tr');
		row.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
			const isCurrent = box.value === select.value;
			if (isCurrent) {
				box.checked = true;
			} else if (box.disabled) {
				box.checked = false;
			}
			box.disabled = isCurrent;
		});
	}

	document.querySelectorAll('select.docstudio-handler').forEach(function (select) {
		sync(select);
		select.addEventListener('change', function () {
			sync(select);
		});
	});
})();

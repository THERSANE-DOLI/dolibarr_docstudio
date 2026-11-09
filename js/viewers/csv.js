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
 * CSV viewer: table with the first line as header, sorted by a click on a header.
 * Cells are always written as text (formulas like =HYPERLINK(...) are shown, never run).
 */

const MAX_ROWS = 5000;

/**
 * Separator most used on the first line among ; , tab |
 * @param {string} text
 * @param {string} extension
 * @returns {string}
 */
function detectSeparator(text, extension) {
	if (extension === 'tsv') {
		return '\t';
	}
	const firstLine = text.slice(0, Math.max(0, text.indexOf('\n')) || text.length);
	let best = ',';
	let bestCount = 0;
	[';', ',', '\t', '|'].forEach(function (sep) {
		const count = firstLine.split(sep).length - 1;
		if (count > bestCount) {
			best = sep;
			bestCount = count;
		}
	});
	return best;
}

/**
 * Parse CSV (RFC 4180: quotes, doubled quotes, line breaks inside quotes)
 * @param {string} text
 * @param {string} sep
 * @param {number} maxRows
 * @returns {{rows: string[][], truncated: boolean}}
 */
function parse(text, sep, maxRows) {
	const rows = [];
	let row = [];
	let field = '';
	let inQuotes = false;
	for (let i = 0; i < text.length; i++) {
		const c = text[i];
		if (inQuotes) {
			if (c === '"') {
				if (text[i + 1] === '"') {
					field += '"';
					i++;
				} else {
					inQuotes = false;
				}
			} else {
				field += c;
			}
		} else if (c === '"' && field === '') {
			inQuotes = true;
		} else if (c === sep) {
			row.push(field);
			field = '';
		} else if (c === '\n' || c === '\r') {
			if (c === '\r' && text[i + 1] === '\n') {
				i++;
			}
			row.push(field);
			field = '';
			rows.push(row);
			row = [];
			if (rows.length > maxRows) {
				return { rows: rows.slice(0, maxRows + 1), truncated: true };
			}
		} else {
			field += c;
		}
	}
	if (field !== '' || row.length) {
		row.push(field);
		rows.push(row);
	}
	return { rows: rows, truncated: false };
}

/**
 * Number of a cell for sorting (1 234,56 or 1,234.56), NaN if not a number
 * @param {string} value
 * @returns {number}
 */
function toNumber(value) {
	let v = String(value).trim().replace(/[\s  ]/g, '');
	if (!/^[-+]?[\d.,]+%?$/.test(v)) {
		return NaN;
	}
	v = v.replace('%', '');
	if (v.indexOf(',') >= 0 && v.indexOf('.') >= 0) {
		v = v.lastIndexOf(',') > v.lastIndexOf('.') ? v.replace(/\./g, '').replace(',', '.') : v.replace(/,/g, '');
	} else {
		v = v.replace(',', '.');
	}
	return parseFloat(v);
}

window.DocStudio.registerViewer('csv', {
	async mount(ctx) {
		const decoded = await ctx.file.text();
		const sep = detectSeparator(decoded.text, ctx.file.extension);
		const parsed = parse(decoded.text, sep, MAX_ROWS);
		const header = parsed.rows.length ? parsed.rows[0] : [];
		let body = parsed.rows.slice(1);

		const table = document.createElement('table');
		table.className = 'docstudio-table';
		const thead = document.createElement('thead');
		const headRow = document.createElement('tr');
		const tbody = document.createElement('tbody');
		let sortCol = -1;
		let sortDir = 1;

		function renderBody() {
			const fragment = document.createDocumentFragment();
			body.forEach(function (cells) {
				const tr = document.createElement('tr');
				for (let i = 0; i < Math.max(cells.length, header.length); i++) {
					const td = document.createElement('td');
					td.textContent = cells[i] !== undefined ? cells[i] : '';
					tr.appendChild(td);
				}
				fragment.appendChild(tr);
			});
			tbody.replaceChildren(fragment);
		}

		header.forEach(function (label, index) {
			const th = document.createElement('th');
			th.textContent = label;
			th.tabIndex = 0;
			const sort = function () {
				sortDir = (sortCol === index) ? -sortDir : 1;
				sortCol = index;
				body = body.slice().sort(function (a, b) {
					const va = a[index] !== undefined ? a[index] : '';
					const vb = b[index] !== undefined ? b[index] : '';
					const na = toNumber(va);
					const nb = toNumber(vb);
					if (!isNaN(na) && !isNaN(nb)) {
						return (na - nb) * sortDir;
					}
					return va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' }) * sortDir;
				});
				headRow.querySelectorAll('th').forEach(function (cell) {
					cell.removeAttribute('aria-sort');
				});
				th.setAttribute('aria-sort', sortDir > 0 ? 'ascending' : 'descending');
				renderBody();
			};
			th.addEventListener('click', sort);
			th.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					sort();
				}
			});
			headRow.appendChild(th);
		});
		thead.appendChild(headRow);
		table.append(thead, tbody);
		renderBody();
		ctx.container.appendChild(table);

		const info = document.createElement('span');
		info.className = 'docstudio-pageinfo';
		info.textContent = (parsed.truncated ? ctx.t('DocStudioRowsTruncated', MAX_ROWS) : ctx.t('DocStudioRows', body.length))
			+ ' · ' + ctx.t('DocStudioEncoding', decoded.encoding);
		ctx.toolbar.append(info);

		return null;
	}
});

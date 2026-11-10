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
 * Background removal (cutout) of the image editor, taken over from Creative Banners and made for big photos.
 *
 * The source photo is never changed. The result is its alpha, computed from:
 * - the white background reached from the borders (optional) and the areas of the color clicked with the wand,
 *   each wand point with its own tolerance;
 * - the closed pen paths: outside of the "keep" paths and inside of the "erase" paths removed, soft edge (feather);
 * - the brushes: erase and restore, soft edge (hardness). The restore brush also stops the wand.
 *
 * Mask of the brushes, one byte a pixel: 0 automatic, 1 erased, 2 kept, 3-102 erased at 1-100 %, 103-202 kept at
 * 1-100 %. While painting, only the rectangle of the brush is computed again.
 */

/** @param {number} v @returns {number} */
export const maskErase = (v) => (v === 1 ? 1 : v >= 3 && v <= 102 ? (v - 2) / 100 : 0);
/** @param {number} v @returns {number} */
export const maskKeep = (v) => (v === 2 ? 1 : v >= 103 && v <= 202 ? (v - 102) / 100 : 0);

const maskCode = (mode, strength) => {
	const pc = Math.round(strength * 100);
	if (pc <= 0) {
		return 0;
	}
	if (mode === 'erase') {
		return pc >= 100 ? 1 : 2 + pc;
	}
	return pc >= 100 ? 2 : 102 + pc;
};

/** Kept at 50 % or more: a barrier for the wand (code 2 or 152-202) */
const isBarrier = (v) => v === 2 || (v >= 152 && v <= 202);

const MAX_SEEDS = 60000;

/* Pen paths */

/*
 * Path: { mode: 'keep'|'erase', feather: px of soft edge, closed: bool, pts: [{ x, y, ix, iy, ox, oy }] }, in px of the
 * photo. ix / iy and ox / oy are the incoming and outgoing handles, relative to the point (0 = corner).
 */

/**
 * @param {CanvasRenderingContext2D} c
 * @param {object} path
 */
export function tracePath(c, path) {
	const pts = path.pts;
	if (!pts.length) {
		return;
	}
	c.moveTo(pts[0].x, pts[0].y);
	const seg = (a, b) => c.bezierCurveTo(a.x + a.ox, a.y + a.oy, b.x + b.ix, b.y + b.iy, b.x, b.y);
	for (let i = 1; i < pts.length; i++) {
		seg(pts[i - 1], pts[i]);
	}
	if (path.closed) {
		seg(pts[pts.length - 1], pts[0]);
		c.closePath();
	}
}

/** @param {object} pt @returns {boolean} */
export const isSmooth = (pt) => !!(pt.ix || pt.iy || pt.ox || pt.oy);

const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
const lerp = (a, b, k) => ({ x: a.x + (b.x - a.x) * k, y: a.y + (b.y - a.y) * k });

/** Point of a path near pos: { pi, i } or null */
export function hitAnchor(paths, pos, tol) {
	for (let pi = paths.length - 1; pi >= 0; pi--) {
		const i = paths[pi].pts.findIndex((pt) => dist(pt, pos) <= tol);
		if (i >= 0) {
			return { pi, i };
		}
	}
	return null;
}

/** Handle of the point pt near pos: 'in', 'out' or null */
export function hitHandle(pt, pos, tol) {
	if (!pt) {
		return null;
	}
	if ((pt.ox || pt.oy) && dist({ x: pt.x + pt.ox, y: pt.y + pt.oy }, pos) <= tol) {
		return 'out';
	}
	if ((pt.ix || pt.iy) && dist({ x: pt.x + pt.ix, y: pt.y + pt.iy }, pos) <= tol) {
		return 'in';
	}
	return null;
}

/** Segment of a path near pos, with what is needed to add a point there keeping the curve (de Casteljau) */
export function hitSegment(paths, pos, tol) {
	for (let pi = paths.length - 1; pi >= 0; pi--) {
		const path = paths[pi];
		const n = path.pts.length;
		for (let i = 0; i < (path.closed ? n : n - 1); i++) {
			const a = path.pts[i];
			const b = path.pts[(i + 1) % n];
			const P = [a, { x: a.x + a.ox, y: a.y + a.oy }, { x: b.x + b.ix, y: b.y + b.iy }, b];
			for (let s = 1; s < 24; s++) {
				const k = s / 24;
				const q0 = lerp(P[0], P[1], k);
				const q1 = lerp(P[1], P[2], k);
				const q2 = lerp(P[2], P[3], k);
				const r0 = lerp(q0, q1, k);
				const r1 = lerp(q1, q2, k);
				const m = lerp(r0, r1, k);
				if (dist(m, pos) <= tol) {
					return { pi, i, q0, q2, r0, r1, m };
				}
			}
		}
	}
	return null;
}

/** Point added on the segment found by hitSegment, the curve keeps its shape. Returns its index */
export function insertPoint(paths, seg) {
	const path = paths[seg.pi];
	const n = path.pts.length;
	const a0 = path.pts[seg.i];
	const b0 = path.pts[(seg.i + 1) % n];
	a0.ox = seg.q0.x - a0.x;
	a0.oy = seg.q0.y - a0.y;
	b0.ix = seg.q2.x - b0.x;
	b0.iy = seg.q2.y - b0.y;
	path.pts.splice(seg.i + 1, 0, {
		x: seg.m.x, y: seg.m.y, ix: seg.r0.x - seg.m.x, iy: seg.r0.y - seg.m.y, ox: seg.r1.x - seg.m.x, oy: seg.r1.y - seg.m.y
	});
	return seg.i + 1;
}

/** Corner: no handles; smooth: handles along the neighbours */
export function setPointType(path, i, type) {
	const pt = path.pts[i];
	if (type === 'corner') {
		Object.assign(pt, { ix: 0, iy: 0, ox: 0, oy: 0 });
	} else if (!isSmooth(pt)) {
		const n = path.pts.length;
		const prev = path.pts[(i - 1 + n) % n];
		const next = path.pts[(i + 1) % n];
		const dx = (next.x - prev.x) / 6;
		const dy = (next.y - prev.y) / 6;
		Object.assign(pt, { ix: -dx, iy: -dy, ox: dx, oy: dy });
	}
}

/* Session */

export class Cutout {
	/**
	 * @param {HTMLCanvasElement} source Photo, never changed
	 * @param {object|null} state State of a previous cutout of this photo (to go on with it)
	 */
	constructor(source, state) {
		this.source = source;
		this.w = source.width;
		this.h = source.height;
		this.n = this.w * this.h;
		this.src = source.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, this.w, this.h).data;
		this.tol = state ? state.tol : 30;
		this.noCut = state ? state.noCut : true;
		this.mask = state ? state.mask.slice() : new Uint8Array(this.n);
		this.paths = state ? JSON.parse(JSON.stringify(state.paths)) : [];
		this.seeds = state ? state.seeds.map((s) => Object.assign({}, s)) : [];
		this.out = new ImageData(this.w, this.h);
		this.out.data.set(this.src);
		this.canvas = document.createElement('canvas');
		this.canvas.width = this.w;
		this.canvas.height = this.h;
		this.ctx = this.canvas.getContext('2d');
		this.bg = null;
		this.bgKey = '';
		this.pf = null;
		this.pfKey = '';
		this.maskVersion = 0;
		this.stroke = null;
	}

	/** State kept with the photo made, to go on with the cutout later */
	state() {
		return { tol: this.tol, noCut: this.noCut, mask: this.mask.slice(), paths: JSON.parse(JSON.stringify(this.paths)), seeds: this.seeds.map((s) => Object.assign({}, s)) };
	}

	/** Settings without the mask (JSON), for the history */
	settings() {
		return JSON.stringify({ tol: this.tol, noCut: this.noCut, paths: this.paths, seeds: this.seeds });
	}

	setSettings(json) {
		const s = JSON.parse(json);
		this.tol = s.tol;
		this.noCut = s.noCut;
		this.paths = s.paths;
		this.seeds = s.seeds;
	}

	/** Something removed: the result differs from the photo */
	isEmpty() {
		return this.noCut && !this.seeds.length && !this.paths.some((p) => p.closed) && !this.mask.some((v) => v !== 0);
	}

	/* Background reached by the wand and from the borders */

	/** Reference color of a region of bg: 1 = white of the borders, k + 2 = wand point k */
	regionRef(id) {
		if (id === 1) {
			return { white: true, tol: this.tol };
		}
		const s = this.seeds[id - 2];
		const i = (Math.round(s.y) * this.w + Math.round(s.x)) * 4;
		return { white: false, r: this.src[i], g: this.src[i + 1], b: this.src[i + 2], tol: s.tol };
	}

	/** Distance of the pixel i to the reference color: 0 same, 255 far */
	distTo(ref, i) {
		const d = this.src;
		const p = i * 4;
		if (ref.white) {
			return 255 - Math.min(d[p], d[p + 1], d[p + 2]);
		}
		return Math.max(Math.abs(d[p] - ref.r), Math.abs(d[p + 1] - ref.g), Math.abs(d[p + 2] - ref.b));
	}

	/** Pixels close to the reference color (or transparent), connected to the starts, marked id in bg */
	flood(id, starts) {
		const { w, n, src, mask, bg } = this;
		const ref = this.regionRef(id);
		const queue = this.queue || (this.queue = new Int32Array(n));
		let qh = 0;
		let qt = 0;
		const take = (i) => {
			if (bg[i] || isBarrier(mask[i])) {
				return;
			}
			if (src[i * 4 + 3] < 16 || this.distTo(ref, i) <= ref.tol) {
				bg[i] = id;
				queue[qt++] = i;
			}
		};
		starts.forEach(take);
		while (qh < qt) {
			const i = queue[qh++];
			const x = i % w;
			if (x > 0) {
				take(i - 1);
			}
			if (x < w - 1) {
				take(i + 1);
			}
			if (i >= w) {
				take(i - w);
			}
			if (i < n - w) {
				take(i + w);
			}
		}
	}

	ensureBg() {
		const key = JSON.stringify([this.tol, this.noCut, this.seeds, this.maskVersion]);
		if (this.bg && key === this.bgKey) {
			return;
		}
		const { w, h } = this;
		this.bg = this.bg || new Uint16Array(this.n);
		this.bg.fill(0);
		this.refs = [null, this.regionRef(1)];
		if (!this.noCut) {
			const edges = [];
			for (let x = 0; x < w; x++) {
				edges.push(x, (h - 1) * w + x);
			}
			for (let y = 0; y < h; y++) {
				edges.push(y * w, y * w + w - 1);
			}
			this.flood(1, edges);
		}
		this.seeds.slice(0, MAX_SEEDS).forEach((s, k) => {
			if (s.x >= 0 && s.y >= 0 && s.x < w && s.y < h) {
				this.refs[k + 2] = this.regionRef(k + 2);
				this.flood(k + 2, [Math.round(s.y) * w + Math.round(s.x)]);
			}
		});
		this.bgKey = key;
	}

	/** Region of bg under the pixel (0 when kept), after a render */
	regionAt(x, y) {
		this.ensureBg();
		return this.bg[Math.floor(y) * this.w + Math.floor(x)];
	}

	/* Pen paths: alpha of the closed paths of each mode */

	ensurePaths() {
		const closed = this.paths.filter((p) => p.closed && p.pts.length >= 3);
		const key = JSON.stringify(closed);
		if (key === this.pfKey) {
			return;
		}
		this.pfKey = key;
		this.pf = null;
		if (!closed.length) {
			return;
		}
		const raster = (mode) => {
			const list = closed.filter((p) => p.mode === mode);
			if (!list.length) {
				return null;
			}
			const cv = document.createElement('canvas');
			cv.width = this.w;
			cv.height = this.h;
			const c = cv.getContext('2d', { willReadFrequently: true });
			c.fillStyle = '#fff';
			for (const p of list) {
				c.filter = p.feather > 0 ? 'blur(' + p.feather + 'px)' : 'none';
				c.beginPath();
				tracePath(c, p);
				c.fill();
			}
			const rgba = c.getImageData(0, 0, this.w, this.h).data;
			const a = new Uint8Array(this.n);
			for (let i = 0; i < this.n; i++) {
				a[i] = rgba[i * 4 + 3];
			}
			return a;
		};
		this.pf = { keep: raster('keep'), erase: raster('erase') };
	}

	/* Result */

	/**
	 * Alpha computed again in a rectangle (all the photo by default) and drawn in this.canvas
	 * @param {{x: number, y: number, w: number, h: number}|null} rect
	 */
	render(rect) {
		this.ensureBg();
		this.ensurePaths();
		const { w, n, src, bg, mask, refs } = this;
		const o = this.out.data;
		const cut = !this.noCut || this.seeds.length > 0;
		const keep = this.pf && this.pf.keep;
		const erase = this.pf && this.pf.erase;
		const x0 = rect ? rect.x : 0;
		const y0 = rect ? rect.y : 0;
		const x1 = rect ? rect.x + rect.w : w;
		const y1 = rect ? rect.y + rect.h : this.h;
		for (let y = y0; y < y1; y++) {
			for (let x = x0; x < x1; x++) {
				const i = y * w + x;
				const a0 = src[i * 4 + 3];
				let a = a0;
				if (bg[i]) {
					a = 0;
				} else if (cut) {
					// Soft edge next to a removed area: no fringe of the background color nor stairs
					const r = (x > 0 && bg[i - 1]) || (x < w - 1 && bg[i + 1]) || (i >= w && bg[i - w]) || (i < n - w && bg[i + w]);
					if (r) {
						const ref = refs[r];
						a = a * Math.min(1, Math.max(0.35, 0.35 + (this.distTo(ref, i) - ref.tol) / 60));
					}
				}
				if (keep) {
					a = a * keep[i] / 255;
				}
				if (erase) {
					a = a * (1 - erase[i] / 255);
				}
				const m = mask[i];
				if (m) {
					// Kept: back to the photo, erased: faded, partly with a soft brush
					a += (a0 - a) * maskKeep(m);
					a = a * (1 - maskErase(m));
				}
				o[i * 4 + 3] = Math.round(a);
			}
		}
		if (rect) {
			this.ctx.putImageData(this.out, 0, 0, x0, y0, x1 - x0, y1 - y0);
		} else {
			this.ctx.putImageData(this.out, 0, 0);
		}
	}

	/* Brushes */

	beginStroke() {
		this.stroke = { base: this.mask.slice(), x0: Infinity, y0: Infinity, x1: -Infinity, y1: -Infinity };
	}

	/**
	 * One dab of the brush, the rectangle shown again
	 * @param {number} x
	 * @param {number} y
	 * @param {number} rad Radius in px of the photo
	 * @param {number} hard 0 to 1: full strength inside this part of the radius, then fading
	 * @param {string} mode 'erase' or 'keep'
	 */
	paint(x, y, rad, hard, mode) {
		const { w, h, mask } = this;
		const inner = rad * hard;
		const xa = Math.max(0, Math.floor(x - rad));
		const xb = Math.min(w - 1, Math.ceil(x + rad));
		const ya = Math.max(0, Math.floor(y - rad));
		const yb = Math.min(h - 1, Math.ceil(y + rad));
		if (xa > xb || ya > yb) {
			return;
		}
		for (let yy = ya; yy <= yb; yy++) {
			for (let xx = xa; xx <= xb; xx++) {
				const dd = Math.hypot(xx - x, yy - y);
				if (dd > rad) {
					continue;
				}
				const strength = dd <= inner ? 1 : (rad - dd) / Math.max(0.001, rad - inner);
				const k = yy * w + xx;
				const prev = mask[k];
				const same = mode === 'erase' ? maskErase(prev) : maskKeep(prev);
				const other = mode === 'erase' ? maskKeep(prev) : maskErase(prev);
				// Stronger than what is there: a soft edge does not undo a full retouch of the other brush
				if (strength > same && strength >= other) {
					mask[k] = maskCode(mode, strength);
				}
			}
		}
		const s = this.stroke;
		if (s) {
			s.x0 = Math.min(s.x0, xa);
			s.y0 = Math.min(s.y0, ya);
			s.x1 = Math.max(s.x1, xb);
			s.y1 = Math.max(s.y1, yb);
		}
		this.render({ x: xa, y: ya, w: xb - xa + 1, h: yb - ya + 1 });
	}

	/**
	 * End of a stroke: the bytes of the mask changed, for undo and redo
	 * @param {boolean} full Restore brush: it stops the wand, all the areas computed again
	 * @returns {object|null} Patch
	 */
	endStroke(full) {
		const s = this.stroke;
		this.stroke = null;
		if (!s || s.x1 < s.x0) {
			return null;
		}
		const patch = this.patchOf(s.base, s.x0, s.y0, s.x1 - s.x0 + 1, s.y1 - s.y0 + 1);
		if (full) {
			this.maskVersion++;
			this.render(null);
		}
		return patch;
	}

	/** Bytes of a rectangle before (from base) and after (the mask now) */
	patchOf(base, x, y, w, h) {
		const before = new Uint8Array(w * h);
		const after = new Uint8Array(w * h);
		for (let r = 0; r < h; r++) {
			const start = (y + r) * this.w + x;
			before.set(base.subarray(start, start + w), r * w);
			after.set(this.mask.subarray(start, start + w), r * w);
		}
		return { x, y, w, h, before, after };
	}

	/** @param {object} patch @param {string} side 'before' or 'after' */
	applyPatch(patch, side) {
		const bytes = patch[side];
		for (let r = 0; r < patch.h; r++) {
			this.mask.set(bytes.subarray(r * patch.w, (r + 1) * patch.w), (patch.y + r) * this.w + patch.x);
		}
		this.maskVersion++;
	}

	/** Brushes cleared, returns the patch (null when there was nothing) */
	clearMask() {
		let x0 = Infinity;
		let y0 = Infinity;
		let x1 = -1;
		let y1 = -1;
		for (let i = 0; i < this.n; i++) {
			if (this.mask[i]) {
				const x = i % this.w;
				const y = (i - x) / this.w;
				x0 = Math.min(x0, x);
				x1 = Math.max(x1, x);
				y0 = Math.min(y0, y);
				y1 = y;
			}
		}
		if (x1 < 0) {
			return null;
		}
		const base = this.mask.slice();
		this.mask.fill(0);
		this.maskVersion++;
		return this.patchOf(base, x0, y0, x1 - x0 + 1, y1 - y0 + 1);
	}
}

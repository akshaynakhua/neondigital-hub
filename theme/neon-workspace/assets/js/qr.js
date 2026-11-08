/* Small QR Code encoder for table menus and member cards. Byte mode, error correction level L,
 * versions 1 to 10 (up to 271 bytes), fixed mask 0. Follows ISO/IEC 18004 as in Nayuki's
 * reference implementation. App.qr(text, canvas, scale) draws it; no network, no dependencies. */
(function () {
	'use strict';
	var ECC = [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18], BLOCKS = [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4];
	function rawModules(v) { var r = (16 * v + 128) * v + 64; if (v >= 2) { var n = Math.floor(v / 7) + 2; r -= (25 * n - 10) * n - 55; if (v >= 7) r -= 36; } return r; }
	function dataCw(v) { return Math.floor(rawModules(v) / 8) - ECC[v] * BLOCKS[v]; }
	function gfMul(x, y) { var z = 0; for (var i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11D); z ^= ((y >>> i) & 1) * x; } return z & 255; }
	function rsDivisor(deg) { var r = []; for (var i = 0; i < deg - 1; i++) r.push(0); r.push(1); var root = 1; for (i = 0; i < deg; i++) { for (var j = 0; j < r.length; j++) { r[j] = gfMul(r[j], root); if (j + 1 < r.length) r[j] ^= r[j + 1]; } root = gfMul(root, 2); } return r; }
	function rsRem(data, div) { var r = div.map(function () { return 0; }); data.forEach(function (b) { var f = b ^ r.shift(); r.push(0); div.forEach(function (c, i) { r[i] ^= gfMul(c, f); }); }); return r; }
	function alignPos(v) { if (v === 1) return []; var n = Math.floor(v / 7) + 2, step = (v === 32) ? 26 : Math.ceil((v * 4 + 4) / (n * 2 - 2)) * 2, out = [6]; for (var p = v * 4 + 10; out.length < n; p -= step) out.splice(1, 0, p); return out; }
	function encode(text) {
		var bytes = Array.prototype.slice.call(new TextEncoder().encode(String(text)));
		var v = 1;
		for (; v <= 10; v++) if (4 + (v < 10 ? 8 : 16) + bytes.length * 8 <= dataCw(v) * 8) break;
		if (v > 10) throw new Error('Text too long for a QR code.');
		var bits = [], put = function (val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
		put(4, 4); put(bytes.length, v < 10 ? 8 : 16); bytes.forEach(function (b) { put(b, 8); });
		var cap = dataCw(v) * 8; put(0, Math.min(4, cap - bits.length)); put(0, (8 - bits.length % 8) % 8);
		for (var pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) put(pad, 8);
		var data = []; for (var i = 0; i < bits.length; i += 8) { var x = 0; for (var j = 0; j < 8; j++) x = (x << 1) | bits[i + j]; data.push(x); }
		var nb = BLOCKS[v], ecl = ECC[v], raw = Math.floor(rawModules(v) / 8), shortN = nb - raw % nb, shortLen = Math.floor(raw / nb), div = rsDivisor(ecl), blocks = [];
		for (i = 0, k = 0; i < nb; i++) { var len = shortLen - ecl + (i < shortN ? 0 : 1), dat = data.slice(k, k + len); k += len; var e = rsRem(dat, div); if (i < shortN) dat.push(0); blocks.push(dat.concat(e)); }
		var all = []; for (i = 0; i < blocks[0].length; i++) blocks.forEach(function (b, bi) { if (i !== shortLen - ecl || bi >= shortN) all.push(b[i]); });
		var size = v * 4 + 17, m = [], fn = [];
		for (i = 0; i < size; i++) { m.push(new Array(size).fill(false)); fn.push(new Array(size).fill(false)); }
		var set = function (x, y, d) { m[y][x] = d; fn[y][x] = true; };
		for (i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
		[[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) { for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) { var xx = c[0] + dx, yy = c[1] + dy, d = Math.max(Math.abs(dx), Math.abs(dy)); if (xx >= 0 && xx < size && yy >= 0 && yy < size) set(xx, yy, d !== 2 && d !== 4); } });
		var ap = alignPos(v);
		ap.forEach(function (ax, ai) { ap.forEach(function (ay, aj) { if ((ai === 0 && aj === 0) || (ai === 0 && aj === ap.length - 1) || (ai === ap.length - 1 && aj === 0)) return; for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1); }); });
		var fmt = function () { var d = (1 << 3) | 0, rem = d; for (var q = 0; q < 10; q++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537); var b = ((d << 10) | rem) ^ 0x5412, bit = function (n) { return ((b >>> n) & 1) !== 0; };
			for (q = 0; q <= 5; q++) set(8, q, bit(q)); set(8, 7, bit(6)); set(8, 8, bit(7)); set(7, 8, bit(8)); for (q = 9; q < 15; q++) set(14 - q, 8, bit(q));
			for (q = 0; q < 8; q++) set(size - 1 - q, 8, bit(q)); for (q = 8; q < 15; q++) set(8, size - 15 + q, bit(q)); set(8, size - 8, true); };
		fmt();
		if (v >= 7) { var rem = v; for (i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25); var vb = (v << 12) | rem; for (i = 0; i < 18; i++) { var bt = ((vb >>> i) & 1) !== 0, a = size - 11 + i % 3, b2 = Math.floor(i / 3); set(a, b2, bt); set(b2, a, bt); } }
		var bi = 0;
		for (var right = size - 1; right >= 1; right -= 2) { if (right === 6) right = 5; for (var vert = 0; vert < size; vert++) for (j = 0; j < 2; j++) { var xx = right - j, up = ((right + 1) & 2) === 0, yy = up ? size - 1 - vert : vert; if (!fn[yy][xx] && bi < all.length * 8) { m[yy][xx] = ((all[bi >>> 3] >>> (7 - (bi & 7))) & 1) !== 0; bi++; } } }
		for (var y = 0; y < size; y++) for (var x = 0; x < size; x++) if (!fn[y][x] && (x + y) % 2 === 0) m[y][x] = !m[y][x];
		return m;
	}
	var k;
	window.App = window.App || {};
	window.App.qrMatrix = encode;
	window.App.qr = function (text, canvas, scale) {
		var mx = encode(text), n = mx.length, s = scale || 5, q = 4, c = canvas || document.createElement('canvas');
		c.width = c.height = (n + q * 2) * s;
		var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.fillStyle = '#000';
		for (var y = 0; y < n; y++) for (var x = 0; x < n; x++) if (mx[y][x]) g.fillRect((x + q) * s, (y + q) * s, s, s);
		return c;
	};
})();

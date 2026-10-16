/* SC Programări – admin */
(function () {
	'use strict';
	var A = window.SCP_ADMIN || {};
	var MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
	var PAY = { unpaid: 'Neplătită', link_sent: 'Link trimis', paid: 'Plătită online', cash: 'Plătită la studio' };

	function api(path, opts) {
		opts = opts || {};
		return fetch(A.api + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: Object.assign({ 'X-WP-Nonce': A.nonce }, opts.body ? { 'Content-Type': 'application/json' } : {}),
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) {
				if (!r.ok) { var e = new Error(d.message || 'Eroare'); e.data = d; throw e; }
				return d;
			});
		});
	}
	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v;
			else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), v);
			else n.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c !== null && c !== undefined && c !== false) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return n;
	}
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ym(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
	function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +(p[2] || 1)); }
	function lastDay(m) { var d = parse(m + '-01'); return m + '-' + pad(new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()); }
	function toast(msg, bad) {
		var t = el('div', { class: 'scp-toast' + (bad ? ' is-bad' : ''), role: 'status', text: msg });
		document.body.appendChild(t);
		setTimeout(function () { t.classList.add('is-out'); }, 2600);
		setTimeout(function () { t.remove(); }, 3100);
	}

	/* =========================================================
	 * Calendar
	 * ======================================================= */
	function Calendar(root) {
		this.root = root;
		this.body = root.querySelector('.scp-cal-body');
		this.title = root.querySelector('.scp-cal-title');
		this.hideClosed = root.querySelector('[data-hide-closed]');
		var start = parse(A.start), end = parse(A.end), today = parse(A.today);
		this.months = [];
		var cur = new Date(start.getFullYear(), start.getMonth(), 1);
		while (cur <= end) { this.months.push(ym(cur)); cur = new Date(cur.getFullYear(), cur.getMonth() + 1, 1); }
		if (!this.months.length) this.months.push(ym(today));
		var ti = this.months.indexOf(ym(today));
		var stored = null;
		try { stored = window.localStorage.getItem('scp_cal_month'); } catch (e) { stored = null; }
		this.idx = stored && this.months.indexOf(stored) !== -1 ? this.months.indexOf(stored) : (ti !== -1 ? ti : 0);
		var self = this;
		root.querySelectorAll('[data-nav]').forEach(function (b) {
			b.addEventListener('click', function () {
				var n = self.idx + (+b.getAttribute('data-nav'));
				if (n >= 0 && n < self.months.length) { self.idx = n; self.load(); }
			});
		});
		this.hideClosed.addEventListener('change', function () { self.render(); });
		this.pop = el('div', { class: 'scp-pop', hidden: true });
		document.body.appendChild(this.pop);
		document.addEventListener('click', function (e) { if (!self.pop.contains(e.target) && !e.target.closest('.scp-cell, .scp-dayact')) self.closePop(); });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') self.closePop(); });
		window.addEventListener('scroll', function () { if (Date.now() - (self.openedAt || 0) > 400) self.closePop(); }, { passive: true });
		this.load();
	}

	Calendar.prototype.load = function () {
		var self = this, m = this.months[this.idx];
		var p = m.split('-');
		this.title.textContent = MONTHS[+p[1] - 1] + ' ' + p[0];
		this.root.querySelector('[data-nav="-1"]').disabled = this.idx === 0;
		this.root.querySelector('[data-nav="1"]').disabled = this.idx === this.months.length - 1;
		try { window.localStorage.setItem('scp_cal_month', m); } catch (e) { /* ignorăm */ }
		this.body.classList.add('is-loading');
		api('/calendar?from=' + m + '-01&to=' + lastDay(m)).then(function (d) {
			self.data = d;
			self.body.classList.remove('is-loading');
			self.render();
		}).catch(function (e) { self.body.textContent = e.message; });
	};

	Calendar.prototype.render = function () {
		var self = this, d = this.data;
		if (!d) return;
		var times = {};
		(d.times || []).forEach(function (t) { times[t] = 1; });
		d.days.forEach(function (day) { day.slots.forEach(function (s) { times[s.time] = 1; }); });
		var cols = Object.keys(times).sort();
		var hide = this.hideClosed.checked;

		var table = el('table', { class: 'scp-cal-table' });
		var head = el('tr', {}, [el('th', { class: 'scp-cal-dayh', text: 'Ziua' })]);
		cols.forEach(function (t) { head.appendChild(el('th', { text: t })); });
		head.appendChild(el('th', { class: 'scp-cal-sum', text: 'Ocupat' }));
		table.appendChild(el('thead', {}, [head]));
		var tb = el('tbody');
		var shown = 0;

		d.days.forEach(function (day) {
			var bySlot = {};
			day.slots.forEach(function (s) { bySlot[s.time] = s; });
			var hasProgram = day.slots.length > 0;
			if (hide && !hasProgram && !day.day_blocked) return;
			shown++;
			var booked = day.slots.filter(function (s) { return s.state === 'booked'; }).length;
			var cap = day.slots.filter(function (s) { return s.state === 'booked' || s.state === 'free'; }).length;
			var isToday = day.date === A.today;
			var weekend = day.weekday >= 6;

			var dayCell = el('th', { class: 'scp-cal-day' }, [
				el('span', { class: 'scp-cal-dlabel', text: day.label.charAt(0).toUpperCase() + day.label.slice(1) }),
				day.day_blocked ? el('span', { class: 'scp-cal-dnote', text: 'zi închisă' + (day.day_reason ? ': ' + day.day_reason : '') }) : null,
				!hasProgram && !day.day_blocked ? el('span', { class: 'scp-cal-dnote', text: day.in_season ? 'zi fără program' : 'în afara sezonului' }) : null,
				el('button', { type: 'button', class: 'scp-dayact', 'aria-label': 'Opțiuni pentru ' + day.label, text: '⋯', onclick: function (e) { self.dayMenu(e.currentTarget, day); } })
			]);
			var tr = el('tr', { class: (isToday ? 'is-today ' : '') + (weekend ? 'is-weekend ' : '') + (day.day_blocked ? 'is-dayblocked' : '') }, [dayCell]);

			cols.forEach(function (t) {
				var s = bySlot[t];
				if (!s) { tr.appendChild(el('td', { class: 'scp-cell scp-cell--none' })); return; }
				var cls = 'scp-cell scp-cell--' + s.state + (s.kind === 'extra' ? ' is-extra' : '');
				var content = [];
				if (s.state === 'booked' && s.booking) {
					cls += ' pay-' + s.booking.payment_status + (s.booking.plus ? ' is-plus' : '');
					content.push(el('span', { class: 'scp-cell-name', text: s.booking.name }));
					content.push(el('span', { class: 'scp-cell-meta', text: (s.booking.plus ? 'Plus' : 'Foto') + ' · ' + (PAY[s.booking.payment_status] || '') }));
				} else if (s.state === 'blocked') {
					content.push(el('span', { class: 'scp-cell-meta', text: s.reason || 'blocat' }));
				} else if (s.state === 'free') {
					content.push(el('span', { class: 'scp-cell-meta', text: s.kind === 'extra' ? 'liber · extra' : 'liber' }));
				} else {
					content.push(el('span', { class: 'scp-cell-meta', text: '–' }));
				}
				var td = el('td', {}, [el('button', {
					type: 'button', class: cls,
					'aria-label': day.label + ' ' + t + ': ' + (s.state === 'booked' && s.booking ? s.booking.name : s.state),
					onclick: function (e) { self.cellMenu(e.currentTarget, day, s); }
				}, content)]);
				tr.appendChild(td);
			});
			tr.appendChild(el('td', { class: 'scp-cal-sum' }, [cap ? el('span', { text: booked + '/' + cap }) : el('span', { class: 'scp-muted', text: '–' })]));
			tb.appendChild(tr);
		});
		table.appendChild(tb);
		this.body.innerHTML = '';
		if (!shown) {
			this.body.appendChild(el('p', { class: 'scp-empty scp-empty--pad', text: 'Nicio zi cu program în această lună. Debifează „ascunde zilele fără program” ca să adaugi ore într-o zi anume.' }));
		} else {
			this.body.appendChild(el('div', { class: 'scp-cal-scroll' }, [table]));
		}
	};

	Calendar.prototype.openPop = function (anchor, children) {
		this.pop.innerHTML = '';
		children.forEach(function (c) { if (c) this.pop.appendChild(c); }, this);
		this.pop.hidden = false;
		this.openedAt = Date.now();
		var r = anchor.getBoundingClientRect();
		var pw = this.pop.offsetWidth, ph = this.pop.offsetHeight;
		var left = Math.min(window.innerWidth - pw - 12, Math.max(12, r.left + r.width / 2 - pw / 2));
		var top = r.bottom + 6;
		if (top + ph > window.innerHeight - 12) top = r.top - ph - 6;
		this.pop.style.left = left + 'px';
		this.pop.style.top = top + 'px';
		var f = this.pop.querySelector('input, button, a');
		if (f) f.focus();
	};
	Calendar.prototype.closePop = function () { this.pop.hidden = true; };

	Calendar.prototype.act = function (path, body, okMsg) {
		var self = this;
		this.closePop();
		return api(path, { method: 'POST', body: body }).then(function () {
			if (okMsg) toast(okMsg);
			self.load();
		}).catch(function (e) { toast(e.message, true); });
	};

	Calendar.prototype.cellMenu = function (btn, day, s) {
		var self = this;
		var title = el('p', { class: 'scp-pop-title', text: day.label.charAt(0).toUpperCase() + day.label.slice(1) + ', ' + s.time });
		if (s.state === 'booked' && s.booking) {
			window.location.href = A.bookingUrl + s.booking.id;
			return;
		}
		var newLink = el('a', { class: 'button button-primary', href: A.newUrl + '&date=' + day.date + '&time=' + s.time, text: 'Programare nouă' });
		if (s.state === 'free') {
			var reason = el('input', { type: 'text', placeholder: 'Motiv (opțional, doar pentru voi)', class: 'scp-pop-input' });
			var block = el('button', { type: 'button', class: 'button', text: 'Blochează ora', onclick: function () { self.act('/slot', { date: day.date, time: s.time, action: 'toggle', reason: reason.value }, 'Ora ' + s.time + ' a fost blocată.'); } });
			var remove = s.kind === 'extra' ? el('button', { type: 'button', class: 'button-link scp-danger-link', text: 'Scoate ora din această zi', onclick: function () { self.act('/slot', { date: day.date, time: s.time, action: 'toggle' }, 'Ora a fost scoasă.'); } }) : null;
			if (s.kind === 'extra') {
				this.openPop(btn, [title, el('div', { class: 'scp-pop-row' }, [newLink]), remove]);
			} else {
				this.openPop(btn, [title, reason, el('div', { class: 'scp-pop-row' }, [block, newLink])]);
			}
		} else if (s.state === 'blocked') {
			if (day.day_blocked) {
				this.openPop(btn, [title, el('p', { text: 'Toată ziua e închisă.' }), el('button', { type: 'button', class: 'button button-primary', text: 'Redeschide ziua', onclick: function () { self.act('/day', { date: day.date, block: false }, 'Ziua a fost redeschisă.'); } })]);
			} else {
				this.openPop(btn, [title, s.reason ? el('p', { text: 'Motiv: ' + s.reason }) : null, el('button', { type: 'button', class: 'button button-primary', text: 'Deblochează ora', onclick: function () { self.act('/slot', { date: day.date, time: s.time, action: 'toggle' }, 'Ora ' + s.time + ' e din nou liberă.'); } })]);
			}
		} else {
			this.openPop(btn, [title, el('p', { text: 'Ora a trecut sau e prea aproape pentru rezervări online. Din admin o poți programa totuși.' }), newLink]);
		}
	};

	Calendar.prototype.dayMenu = function (btn, day) {
		var self = this;
		var title = el('p', { class: 'scp-pop-title', text: day.label.charAt(0).toUpperCase() + day.label.slice(1) });
		var time = el('input', { type: 'time', step: 300, class: 'scp-pop-input', 'aria-label': 'Ora suplimentară' });
		var add = el('button', { type: 'button', class: 'button', text: 'Adaugă ora', onclick: function () {
			if (!/^\d{2}:\d{2}$/.test(time.value)) { time.focus(); return; }
			self.act('/slot', { date: day.date, time: time.value, action: 'add' }, 'Ora ' + time.value + ' a fost adăugată.');
		} });
		var reason = el('input', { type: 'text', placeholder: 'Motiv (opțional)', class: 'scp-pop-input' });
		var hasBookings = day.slots.some(function (s) { return s.state === 'booked'; });
		var dayBtn = day.day_blocked
			? el('button', { type: 'button', class: 'button button-primary', text: 'Redeschide ziua', onclick: function () { self.act('/day', { date: day.date, block: false }, 'Ziua a fost redeschisă.'); } })
			: el('button', { type: 'button', class: 'button', text: 'Închide toată ziua', onclick: function () { self.act('/day', { date: day.date, block: true, reason: reason.value }, 'Ziua a fost închisă pentru rezervări.'); } });
		this.openPop(btn, [
			title,
			el('p', { class: 'scp-pop-label', text: 'Oră suplimentară doar în această zi' }),
			el('div', { class: 'scp-pop-row' }, [time, add]),
			el('hr'),
			day.day_blocked ? null : reason,
			dayBtn,
			hasBookings && !day.day_blocked ? el('p', { class: 'scp-pop-note', text: 'Programările existente din această zi rămân valabile.' }) : null
		]);
	};

	/* =========================================================
	 * Fișa programării
	 * ======================================================= */
	function BookingForm(form) {
		var date = form.querySelector('#scp-date');
		var time = form.querySelector('#scp-time');
		var hint = form.querySelector('#scp-slot-hint');
		var pkg = form.querySelector('[name="package"]');
		var price = form.querySelector('[name="price"]');
		var current = time.getAttribute('data-current');
		var bookingId = +time.getAttribute('data-booking');

		function loadTimes() {
			if (!date.value) return;
			time.disabled = true;
			api('/calendar?from=' + date.value + '&to=' + date.value).then(function (d) {
				var day = d.days[0];
				time.innerHTML = '';
				var free = 0;
				day.slots.forEach(function (s) {
					var mine = s.booking && s.booking.id === bookingId;
					var label = s.time;
					var disabled = false;
					if (s.state === 'booked' && !mine) { label += ' – ocupat (' + s.booking.name + ')'; disabled = true; }
					else if (s.state === 'blocked') label += ' – blocat';
					else if (s.state === 'past') label += ' – a trecut / prea aproape';
					else if (s.state === 'free') { label += ' – liber'; free++; }
					if (mine) label += ' – ora actuală';
					var o = el('option', { value: s.time, text: label, disabled: disabled });
					if (s.time === current && (!disabled)) o.selected = true;
					time.appendChild(o);
				});
				if (!day.slots.length) time.appendChild(el('option', { value: '', text: 'Nicio oră în program – folosește „Altă oră”' }));
				if (!time.value) {
					var firstFree = Array.prototype.find.call(time.options, function (o) { return !o.disabled && /liber/.test(o.textContent); });
					if (firstFree) firstFree.selected = true;
				}
				hint.textContent = day.day_blocked ? 'Atenție: ziua este închisă pentru rezervări online.' : (!day.in_season ? 'Atenție: ziua e în afara perioadei din Setări.' : (free ? free + ' ore libere în această zi.' : 'Nicio oră liberă în această zi.'));
				time.disabled = false;
			}).catch(function (e) { hint.textContent = e.message; time.disabled = false; });
		}
		date.addEventListener('change', function () { current = ''; loadTimes(); });
		if (date.value) loadTimes();

		if (pkg && price) {
			pkg.addEventListener('change', function () {
				var o = pkg.options[pkg.selectedIndex];
				price.value = o.getAttribute('data-price') || '';
			});
		}
	}

	/* =========================================================
	 * Diverse
	 * ======================================================= */
	function misc() {
		document.querySelectorAll('[data-copy]').forEach(function (b) {
			b.addEventListener('click', function () {
				var input = document.querySelector(b.getAttribute('data-copy'));
				if (!input) return;
				var done = function () { toast('Copiat.'); };
				if (navigator.clipboard) navigator.clipboard.writeText(input.value).then(done, function () { input.select(); document.execCommand('copy'); done(); });
				else { input.select(); document.execCommand('copy'); done(); }
			});
		});
		document.querySelectorAll('[data-regenerate]').forEach(function (b) {
			b.addEventListener('click', function () {
				var input = document.querySelector(b.getAttribute('data-regenerate'));
				var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
				var arr = new Uint32Array(40); window.crypto.getRandomValues(arr);
				input.value = Array.prototype.map.call(arr, function (n) { return chars[n % chars.length]; }).join('');
				toast('Cheie nouă generată. Salvează setările și actualizeaz-o și în n8n.');
			});
		});
		document.querySelectorAll('form[data-confirm]').forEach(function (f) {
			f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault(); });
		});
		document.querySelectorAll('.scp-table tr[data-href]').forEach(function (tr) {
			tr.addEventListener('click', function (e) { if (!e.target.closest('a, button')) window.location.href = tr.getAttribute('data-href'); });
		});
		var test = document.getElementById('scp-webhook-test');
		if (test) {
			test.addEventListener('click', function () {
				var out = document.getElementById('scp-webhook-result');
				test.disabled = true; out.textContent = 'Se trimite…'; out.className = '';
				api('/webhook/test', { method: 'POST' }).then(function (r) {
					out.textContent = r.ok ? 'Primit de n8n (HTTP ' + r.code + ').' : 'Nu a mers: ' + (r.error || 'fără răspuns');
					out.className = r.ok ? 'scp-ok' : 'scp-err';
				}).catch(function (e) { out.textContent = e.message; out.className = 'scp-err'; }).then(function () { test.disabled = false; });
			});
		}
		document.querySelectorAll('[data-retry]').forEach(function (b) {
			b.addEventListener('click', function () {
				b.disabled = true;
				api('/webhook/' + b.getAttribute('data-retry') + '/retry', { method: 'POST' }).then(function (r) {
					toast(r.ok ? 'Trimis.' : 'Tot nu merge: ' + r.error, !r.ok);
					if (r.ok) setTimeout(function () { window.location.reload(); }, 700); else b.disabled = false;
				}).catch(function (e) { toast(e.message, true); b.disabled = false; });
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var cal = document.getElementById('scp-calendar');
		if (cal) new Calendar(cal);
		var form = document.querySelector('.scp-edit-form');
		if (form) new BookingForm(form);
		misc();
	});
})();

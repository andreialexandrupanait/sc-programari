/* SC Programări – admin */
(function () {
	'use strict';
	var A = window.SCP_ADMIN || {};
	var MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
	var PAY = { unpaid: 'Neplătită', link_sent: 'Link trimis', paid: 'Plătită online', cash: 'Plătită cash', card: 'Plătită cu cardul', voucher: 'Plătită cu voucher' };

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
	 * Încasare la studio (Panou): Cash / Card
	 * ======================================================= */
	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-collect]');
		if (!b || b.closest('.scpq')) return;
		var li = b.closest('[data-id]'), method = b.getAttribute('data-collect');
		if (!li) return;
		li.querySelectorAll('[data-collect]').forEach(function (x) { x.disabled = true; });
		api('/booking/' + li.getAttribute('data-id') + '/pay', { method: 'POST', body: { method: method } })
			.then(function (r) {
				var badge = li.querySelector('.scp-badge');
				if (badge) { badge.className = 'scp-badge scp-badge--ps-paid'; badge.textContent = method === 'card' ? 'Plătită card' : 'Plătită cash'; }
				var box = li.querySelector('.scp-collect-btns');
				if (box) box.remove();
				toast(r.label || 'Încasat.');
			})
			.catch(function (err) {
				li.querySelectorAll('[data-collect]').forEach(function (x) { x.disabled = false; });
				toast(err.message, true);
			});
	});

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
		this.side = root.querySelector('.scp-cal-side');
		var backdrop = root.querySelector('.scp-cal-backdrop');
		if (backdrop) backdrop.addEventListener('click', function () { self.closeSide(); });
		this.pop = el('div', { class: 'scp-pop', hidden: true });
		document.body.appendChild(this.pop);
		document.addEventListener('click', function (e) { if (!self.pop.contains(e.target) && !e.target.closest('.scp-dayact')) self.closePop(); });
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') return;
			if (!self.pop.hidden) self.closePop(); else if (self.sel) self.closeSide();
		});
		window.addEventListener('scroll', function () { if (Date.now() - (self.openedAt || 0) > 400) self.closePop(); }, { passive: true });
		this.closeSide();
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
			if (self.sel) {
				var f = self.findSlot(self.sel.date, self.sel.time);
				if (f) self.showSide(f.day, f.slot);
			}
		}).catch(function (e) { self.body.textContent = e.message; });
	};

	/** În căsuță: numele și primul prenume („Popescu Ana Maria” → „Popescu Ana”); numele întreg rămâne în panou. */
	function shortName(name) {
		var p = String(name || '').trim().split(/\s+/);
		return p.slice(0, 2).join(' ');
	}

	Calendar.prototype.render = function () {
		var self = this, d = this.data;
		if (!d) return;
		// Coloanele, în ordinea orei: programul standard plus orele adăugate în zilele afișate (o oră extra
		// de 16:00 stă între 15:00 și 17:00). O oră extra are coloana ei doar în lunile în care există.
		var std = {};
		(d.times || []).forEach(function (t) { std[t] = 1; });
		var hide = this.hideClosed.checked;
		var times = Object.assign({}, std);
		d.days.forEach(function (day) {
			if (hide && !day.slots.length && !day.day_blocked) return;
			day.slots.forEach(function (s) { times[s.time] = 1; });
		});
		var cols = Object.keys(times).sort();

		var table = el('table', { class: 'scp-cal-table' });
		var head = el('tr', {}, [el('th', { class: 'scp-cal-dayh', text: 'Ziua' })]);
		cols.forEach(function (t) {
			head.appendChild(el('th', std[t] ? { text: t } : { class: 'scp-cal-xh', title: 'Oră adăugată doar în unele zile' }, std[t] ? null : [t, el('small', { text: 'extra' })]));
		});
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

			var cellBtn = function (s) {
				var t = s.time;
				var cls = 'scp-cell scp-cell--' + s.state + (s.kind === 'extra' ? ' is-extra' : '');
				var content = [];
				if (s.state === 'booked' && s.booking) {
					var ps = s.booking.pay || { key: s.booking.payment_status, short: PAY[s.booking.payment_status] || '' };
					cls += ' pay-' + ps.key + (s.booking.plus ? ' is-plus' : '');
					content.push(el('span', { class: 'scp-cell-name', text: shortName(s.booking.name), title: s.booking.name }));
					if (s.booking.phone) content.push(el('span', { class: 'scp-cell-line', text: s.booking.phone }));
				} else if (s.state === 'blocked') {
					content.push(el('span', { class: 'scp-cell-meta', text: s.reason || 'blocat' }));
				} else if (s.state === 'free') {
					content.push(el('span', { class: 'scp-cell-meta', text: 'liber' }));
				} else {
					content.push(el('span', { class: 'scp-cell-meta', text: '–' }));
				}
				return el('button', {
					type: 'button', class: cls + (self.sel && self.sel.date === day.date && self.sel.time === t ? ' is-selected' : ''), 'data-slot': day.date + ' ' + t,
					'aria-label': day.label + ' ' + t + ': ' + (s.state === 'booked' && s.booking ? s.booking.name : ({ free: 'liber', blocked: 'blocat', past: 'trecut' })[s.state] || s.state),
					onclick: function () { self.closePop(); self.select(day, s); }
				}, content);
			};
			cols.forEach(function (t) {
				var s = bySlot[t];
				tr.appendChild(s ? el('td', {}, [cellBtn(s)]) : el('td', { class: 'scp-cell scp-cell--none' }));
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

	/* ---------- Panoul din dreapta: detaliile orei alese ---------- */
	function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
	function copyText(text) {
		var done = function () { toast('Copiat.'); };
		var manual = function () { window.prompt('Copiază linkul:', text); };
		if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, manual); else manual();
	}

	Calendar.prototype.findSlot = function (date, time) {
		var day = ((this.data && this.data.days) || []).find(function (d) { return d.date === date; });
		if (!day) return null;
		var s = day.slots.find(function (x) { return x.time === time; });
		return s ? { day: day, slot: s } : null;
	};

	Calendar.prototype.select = function (day, s) {
		this.sel = { date: day.date, time: s.time };
		this.markSelected();
		this.showSide(day, s);
		this.root.classList.add('has-side');
	};

	Calendar.prototype.markSelected = function () {
		var key = this.sel ? this.sel.date + ' ' + this.sel.time : '';
		this.root.querySelectorAll('.scp-cell[data-slot]').forEach(function (c) { c.classList.toggle('is-selected', c.getAttribute('data-slot') === key); });
	};

	Calendar.prototype.closeSide = function () {
		this.sel = null;
		this.markSelected();
		this.root.classList.remove('has-side');
		this.fillSide(null, [el('p', { class: 'scp-side-empty', text: 'Apasă pe o oră din calendar ca să vezi aici detaliile și ce poți face cu ea.' })]);
	};

	Calendar.prototype.fillSide = function (title, kids) {
		var self = this;
		this.side.innerHTML = '';
		this.side.appendChild(el('div', { class: 'scp-side-head' }, [
			el('p', { class: 'scp-side-when', text: title || 'Detalii' }),
			el('button', { type: 'button', class: 'scp-side-close', 'aria-label': 'Închide panoul', text: '×', onclick: function () { self.closeSide(); } })
		]));
		kids.forEach(function (k) { if (k) this.side.appendChild(k); }, this);
	};

	Calendar.prototype.showSide = function (day, s) {
		var self = this;
		var title = ucfirst(day.label) + ', ora ' + s.time;
		var newLink = el('a', { class: 'button button-primary scp-side-wide', href: (s.state === 'free' ? A.quickUrl : A.newUrl) + '&date=' + day.date + '&time=' + s.time, text: 'Programare nouă pe această oră' });
		if (s.state === 'booked' && s.booking) {
			if (!this.side.querySelector('.scp-side-name')) this.fillSide(title, [el('p', { class: 'scp-muted', text: 'Se încarcă…' })]);
			api('/booking/' + s.booking.id).then(function (b) {
				if (self.sel && self.sel.date === day.date && self.sel.time === s.time) self.bookingSide(b);
			}).catch(function (e) { self.fillSide(title, [el('p', { class: 'scp-err', text: e.message })]); });
			return;
		}
		if (s.state === 'free') {
			var reason = el('input', { type: 'text', placeholder: 'Motiv (opțional, doar pentru voi)', class: 'scp-pop-input' });
			this.fillSide(title, [
				el('p', { class: 'scp-side-state', text: s.kind === 'extra' ? 'Oră liberă, adăugată doar în această zi.' : 'Oră liberă.' }),
				newLink,
				el('div', { class: 'scp-side-sec' }, s.kind === 'extra'
					? [el('button', { type: 'button', class: 'button-link scp-danger-link', text: 'Scoate ora din această zi', onclick: function () { self.act('/slot', { date: day.date, time: s.time, action: 'toggle' }, 'Ora a fost scoasă.'); } })]
					: [el('p', { class: 'scp-side-label', text: 'Nu lucrezi la ora asta?' }), reason, el('button', { type: 'button', class: 'button', text: 'Blochează ora', onclick: function () { self.act('/slot', { date: day.date, time: s.time, action: 'toggle', reason: reason.value }, 'Ora ' + s.time + ' a fost blocată.'); } })])
			]);
		} else if (s.state === 'blocked') {
			this.fillSide(title, day.day_blocked
				? [el('p', { class: 'scp-side-state', text: 'Toată ziua e închisă' + (day.day_reason ? ': ' + day.day_reason : '.') }), el('button', { type: 'button', class: 'button button-primary', text: 'Redeschide ziua', onclick: function () { self.act('/day', { date: day.date, block: false }, 'Ziua a fost redeschisă.'); } })]
				: [el('p', { class: 'scp-side-state', text: 'Oră blocată' + (s.reason ? ': ' + s.reason : '.') }), el('button', { type: 'button', class: 'button button-primary', text: 'Deblochează ora', onclick: function () { self.act('/slot', { date: day.date, time: s.time, action: 'toggle' }, 'Ora ' + s.time + ' e din nou liberă.'); } })]);
		} else {
			this.fillSide(title, [el('p', { class: 'scp-side-state', text: 'Ora a trecut sau e prea aproape pentru rezervări online. Din admin o poți programa totuși.' }), newLink]);
		}
	};

	Calendar.prototype.bookingSide = function (b) {
		var self = this;
		function row(label, value, node) {
			if (!value && !node) return null;
			return el('div', { class: 'scp-side-row' }, [el('dt', { text: label }), el('dd', {}, [node || value])]);
		}
		function post(path, body, msg) {
			return api(path, { method: 'POST', body: body || {} }).then(function (r) { toast(msg); self.load(); return r; }).catch(function (e) { toast(e.message, true); });
		}

		var contact = el('div', { class: 'scp-side-contact' }, [
			b.phone ? el('a', { class: 'scp-side-phone', href: 'tel:' + b.tel, text: b.phone }) : el('span', { class: 'scp-muted', text: 'fără telefon' }),
			b.email ? el('a', { class: 'scp-side-email', href: 'mailto:' + b.email, text: b.email }) : el('span', { class: 'scp-muted', text: 'fără email' })
		]);

		var moveBox = el('div', { class: 'scp-side-box', hidden: true });
		var cancelBox = el('div', { class: 'scp-side-box scp-side-box--danger', hidden: true });
		var actions = el('div', { class: 'scp-side-actions' });
		if (b.can.move) {
			actions.appendChild(el('button', { type: 'button', class: 'button button-primary scp-side-wide', text: 'Mută pe altă zi sau oră', onclick: function () {
				cancelBox.hidden = true;
				moveBox.hidden = !moveBox.hidden;
				if (!moveBox.hidden) self.moveForm(moveBox, b);
			} }));
			actions.appendChild(moveBox);
		}
		if (b.can.collect) {
			actions.appendChild(el('div', { class: 'scp-side-collect' }, [
				el('span', { text: 'Încasat la studio:' }),
				el('button', { type: 'button', class: 'button', text: 'Cash', onclick: function () { post('/booking/' + b.id + '/pay', { method: 'cash' }, 'Marcată plătită cash.'); } }),
				el('button', { type: 'button', class: 'button', text: 'Card', onclick: function () { post('/booking/' + b.id + '/pay', { method: 'card' }, 'Marcată plătită cu cardul.'); } })
			]));
		}
		if (b.can.studio) {
			actions.appendChild(el('button', { type: 'button', class: 'button scp-side-wide', text: 'Trece pe plată la studio', title: 'Se confirmă și nu mai expiră; plătește cash sau card la ședință.', onclick: function () { post('/booking/' + b.id + '/studio', {}, 'Confirmată cu plata la studio.'); } }));
		}
		actions.appendChild(el('a', { class: 'button scp-side-wide', href: b.admin_url, text: 'Deschide fișa completă' }));

		if (b.can.cancel) {
			var notify = el('input', { type: 'checkbox', checked: true });
			cancelBox.appendChild(el('p', { text: 'Anulezi programarea? Ora se eliberează pe site.' }));
			cancelBox.appendChild(el('label', { class: 'scp-side-check' }, [notify, ' Anunță clientul pe email']));
			cancelBox.appendChild(el('button', { type: 'button', class: 'button scp-danger-btn', text: 'Da, anulează', onclick: function () { post('/booking/' + b.id + '/cancel', { notify: notify.checked ? 1 : 0 }, 'Programarea a fost anulată.'); } }));
		}

		var payLink = b.pay_link ? el('span', {}, [
			el('a', { href: b.pay_link, target: '_blank', rel: 'noopener', text: 'deschide' }), ' · ',
			el('button', { type: 'button', class: 'button-link', text: 'copiază', onclick: function () { copyText(b.pay_link); } })
		]) : null;

		this.fillSide(ucfirst(b.when), [
			el('h2', { class: 'scp-side-name', text: b.name }),
			el('div', { class: 'scp-side-badges' }, [
				el('span', { class: 'scp-badge scp-badge--ps-' + b.pay.key, text: b.pay.label }),
				b.status === 'confirmed' || b.status === 'pending' ? null : el('span', { class: 'scp-badge', text: b.status_label })
			]),
			contact,
			el('dl', { class: 'scp-side-dl' }, [
				row('Pachet', b.package + ' · ' + b.price),
				row('Reducere', b.discount),
				row('Voucher', b.voucher),
				row('Mesaj client', b.message),
				row('Observații', b.notes),
				row('Link de plată', null, payLink),
				row('Cod', b.code),
				row('Sursa', b.source_label + ' · ' + b.created)
			]),
			actions,
			b.can.cancel ? el('button', { type: 'button', class: 'button-link scp-danger-link', text: 'Anulează programarea', onclick: function () { moveBox.hidden = true; cancelBox.hidden = !cancelBox.hidden; } }) : null,
			cancelBox
		]);
	};

	/** Mutarea: ziua, apoi orele libere din ziua aleasă. Plata rămâne cum e. */
	Calendar.prototype.moveForm = function (box, b) {
		var self = this, chosen = '';
		box.innerHTML = '';
		var date = el('input', { type: 'date', value: b.date, min: A.start, max: A.end, class: 'scp-pop-input', 'aria-label': 'Noua zi' });
		var times = el('div', { class: 'scp-side-times' });
		var notify = el('input', { type: 'checkbox', checked: true });
		var go = el('button', { type: 'button', class: 'button button-primary scp-side-wide', text: 'Alege o oră', disabled: true });
		function load() {
			chosen = '';
			go.disabled = true;
			go.textContent = 'Alege o oră';
			times.innerHTML = '';
			times.appendChild(el('span', { class: 'scp-muted', text: 'Se încarcă…' }));
			api('/calendar?from=' + date.value + '&to=' + date.value).then(function (d) {
				times.innerHTML = '';
				var day = d.days[0];
				var free = day ? day.slots.filter(function (s) { return s.state === 'free'; }) : [];
				if (!free.length) { times.appendChild(el('span', { class: 'scp-muted', text: 'Nicio oră liberă în această zi.' })); return; }
				free.forEach(function (s) {
					times.appendChild(el('button', { type: 'button', class: 'scp-side-time', text: s.time, onclick: function (e) {
						times.querySelectorAll('.scp-side-time').forEach(function (x) { x.classList.remove('is-on'); });
						e.currentTarget.classList.add('is-on');
						chosen = s.time;
						go.disabled = false;
						go.textContent = 'Mută pe ' + day.label + ', ' + s.time;
					} }));
				});
			}).catch(function (e) { times.innerHTML = ''; times.appendChild(el('span', { class: 'scp-err', text: e.message })); });
		}
		date.addEventListener('change', load);
		go.addEventListener('click', function () {
			if (!chosen) return;
			go.disabled = true;
			api('/booking/' + b.id + '/move', { method: 'POST', body: { date: date.value, time: chosen, notify: notify.checked ? 1 : 0 } }).then(function () {
				toast('Programarea a fost mutată' + (notify.checked ? '. Clientul a fost anunțat.' : '.'));
				self.sel = { date: date.value, time: chosen };
				var m = self.months.indexOf(date.value.slice(0, 7));
				if (m !== -1) self.idx = m;
				self.load();
			}).catch(function (e) { toast(e.message, true); load(); });
		});
		box.appendChild(el('p', { class: 'scp-side-label', text: 'Noua zi' }));
		box.appendChild(date);
		box.appendChild(el('p', { class: 'scp-side-label', text: 'Orele libere' }));
		box.appendChild(times);
		box.appendChild(el('label', { class: 'scp-side-check' }, [notify, ' Anunță clientul (email și SMS cu noua oră)']));
		box.appendChild(go);
		box.appendChild(el('p', { class: 'scp-side-note', text: 'Plata rămâne așa cum e. Reamintirile se reprogramează, iar Sheet-ul se actualizează singur.' }));
		load();
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
		// Setări: toate secțiunile rămân la vedere; meniul de sus duce la secțiune și arată unde ești.
		var settings = document.querySelector('form.scp-settings');
		if (settings) {
			var tabs = settings.querySelectorAll('.scp-tabs a');
			var sections = Array.prototype.slice.call(settings.querySelectorAll('section.scp-panel'));
			var setActive = function (id) {
				tabs.forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('href') === '#' + id); });
			};
			tabs.forEach(function (a) {
				a.addEventListener('click', function (e) {
					var target = settings.querySelector(a.getAttribute('href'));
					if (!target) return;
					e.preventDefault();
					history.replaceState(null, '', a.getAttribute('href'));
					var bar = settings.querySelector('.scp-tabs');
					window.scrollTo({ top: target.getBoundingClientRect().top + window.scrollY - (bar ? bar.offsetHeight : 0) - 46, behavior: 'smooth' });
					setActive(target.id);
				});
			});
			var spy = function () {
				var current = sections[0] ? sections[0].id : '';
				sections.forEach(function (s) { if (s.getBoundingClientRect().top < 160) current = s.id; });
				if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4 && sections.length) current = sections[sections.length - 1].id;
				setActive(current);
			};
			window.addEventListener('scroll', spy, { passive: true });
			spy();
			// Modificări nesalvate: semn pe bara de jos și avertizare la plecarea de pe pagină.
			var dirty = false, flag = settings.querySelector('.scp-dirty');
			var mark = function () { if (!dirty) { dirty = true; if (flag) flag.hidden = false; } };
			settings.addEventListener('input', mark);
			settings.addEventListener('change', mark);
			settings.addEventListener('submit', function () { dirty = false; });
			window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
			settings.querySelectorAll('[data-pkg-add]').forEach(function (b) {
				b.addEventListener('click', function () {
					var card = b.nextElementSibling;
					if (card) { card.hidden = false; var f = card.querySelector('input'); if (f) f.focus(); }
					b.remove();
				});
			});
		}
		document.querySelectorAll('[data-reveal]').forEach(function (b) {
			b.addEventListener('click', function () {
				var input = document.querySelector(b.getAttribute('data-reveal'));
				if (!input) return;
				var show = input.type === 'password';
				input.type = show ? 'text' : 'password';
				b.textContent = show ? 'Ascunde' : 'Arată';
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

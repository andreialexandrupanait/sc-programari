/* SC Programări – programare rapidă (telefon / mesaje) */
(function () {
	'use strict';
	var Q = window.SCP_QUICK || {};
	var root = document.getElementById('scpq');
	if (!root) return;

	var WD = ['dum', 'lun', 'mar', 'mie', 'joi', 'vin', 'sâm'];
	var MON = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'nov', 'dec'];
	var form = root.querySelector('.scpq-form');
	var done = root.querySelector('.scpq-done');
	var state = { days: [], date: '', time: '', pkg: '', busy: false };

	function api(path, opts) {
		opts = opts || {};
		return fetch(Q.api + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: Object.assign({ 'X-WP-Nonce': Q.nonce }, opts.body ? { 'Content-Type': 'application/json' } : {}),
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) {
				if (!r.ok) { var e = new Error(d.message || 'Eroare'); e.data = d; e.status = r.status; throw e; }
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
	function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
	function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function addDays(s, n) { var d = parse(s); d.setDate(d.getDate() + n); return iso(d); }
	function $(sel) { return form.querySelector(sel); }
	function step(name) { return form.querySelector('[data-step="' + name + '"]'); }
	function show(node, on) { if (node) node.hidden = !on; }

	/* ---------- Zilele libere (în bucăți de cel mult 60 de zile, cât permite ruta) ---------- */
	function loadDays() {
		var box = form.querySelector('[data-days]');
		if (!Q.start || !Q.end || Q.start > Q.end) {
			box.innerHTML = '';
			box.appendChild(el('p', { class: 'scp-empty', text: 'Sezonul s-a încheiat sau nu are zile setate.' }));
			return Promise.resolve();
		}
		var chunks = [], from = Q.start;
		while (from <= Q.end) {
			var to = addDays(from, 59);
			if (to > Q.end) to = Q.end;
			chunks.push([from, to]);
			from = addDays(to, 1);
		}
		return Promise.all(chunks.map(function (c) { return api('/calendar?from=' + c[0] + '&to=' + c[1]); }))
			.then(function (res) {
				state.days = [];
				res.forEach(function (r) { state.days = state.days.concat(r.days || []); });
				renderDays();
			})
			.catch(function (e) {
				box.innerHTML = '';
				box.appendChild(el('p', { class: 'scpq-error', text: 'Nu am putut încărca zilele: ' + e.message }));
			});
	}
	function freeSlots(day) {
		if (!day || day.day_blocked) return [];
		return (day.slots || []).filter(function (s) { return s.state === 'free'; });
	}
	function renderDays() {
		var box = form.querySelector('[data-days]');
		box.innerHTML = '';
		var open = state.days.filter(function (d) { return freeSlots(d).length; });
		if (!open.length) {
			box.appendChild(el('p', { class: 'scp-empty', text: 'Nu mai sunt ore libere în sezon. Poți adăuga o oră din Calendar.' }));
			return;
		}
		var list = el('div', { class: 'scpq-daylist' });
		open.forEach(function (d) {
			var dt = parse(d.date), n = freeSlots(d).length;
			list.appendChild(el('button', {
				type: 'button', class: 'scpq-day' + (d.date === state.date ? ' is-on' : ''), 'data-date': d.date,
				'aria-pressed': d.date === state.date ? 'true' : 'false',
				onclick: function () { pickDay(d.date); }
			}, [
				el('span', { class: 'scpq-day-wd', text: WD[dt.getDay()] }),
				el('span', { class: 'scpq-day-d', text: String(dt.getDate()) }),
				el('span', { class: 'scpq-day-m', text: MON[dt.getMonth()] }),
				el('span', { class: 'scpq-day-n', text: n === 1 ? '1 oră' : n + ' ore' })
			]));
		});
		box.appendChild(list);
	}
	function dayByDate(date) {
		for (var i = 0; i < state.days.length; i++) if (state.days[i].date === date) return state.days[i];
		return null;
	}
	function pickDay(date) {
		state.date = date;
		state.time = '';
		renderDays();
		var day = dayByDate(date);
		$('[data-day-label]').textContent = day ? day.label : date;
		var box = form.querySelector('[data-times]');
		box.innerHTML = '';
		freeSlots(day).forEach(function (s) {
			box.appendChild(el('button', {
				type: 'button', class: 'scpq-chip', 'data-time': s.time, 'aria-pressed': 'false',
				onclick: function () { pickTime(s.time); }
			}, [s.time]));
		});
		show(step('time'), true);
		refresh();
		step('time').scrollIntoView({ behavior: 'smooth', block: 'start' });
	}
	function pickTime(t) {
		state.time = t;
		form.querySelectorAll('[data-time]').forEach(function (b) {
			var on = b.getAttribute('data-time') === t;
			b.classList.toggle('is-on', on);
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		show(step('package'), true);
		if (Q.packages && Q.packages.length === 1 && !state.pkg) pickPkg(Q.packages[0].key);
		refresh();
	}

	/* ---------- Pachete ---------- */
	function renderPackages() {
		var box = form.querySelector('[data-packages]');
		(Q.packages || []).forEach(function (p) {
			box.appendChild(el('button', {
				type: 'button', class: 'scpq-chip scpq-chip--pkg', 'data-pkg': p.key, 'aria-pressed': 'false',
				onclick: function () { pickPkg(p.key); }
			}, [
				el('b', { text: p.label }),
				el('span', {}, [p.list ? el('s', { text: p.list + ' lei' }) : null, ' ' + p.price + ' lei'])
			]));
		});
	}
	function pickPkg(key) {
		state.pkg = key;
		form.querySelectorAll('[data-pkg]').forEach(function (b) {
			var on = b.getAttribute('data-pkg') === key;
			b.classList.toggle('is-on', on);
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		show(step('client'), true);
		show(step('pay'), true);
		show(form.querySelector('.scpq-submit'), true);
		refresh();
		var name = form.elements.name;
		if (name && !name.value) name.focus();
	}

	/* ---------- Plată ---------- */
	function pay() { var r = form.querySelector('input[name="pay"]:checked'); return r ? r.value : 'online'; }
	function hoursUntil() {
		if (!state.date || !state.time) return Infinity;
		var p = state.date.split('-'), t = state.time.split(':');
		var at = new Date(+p[0], +p[1] - 1, +p[2], +t[0], +t[1]);
		var n = Q.now.split(/[-T:]/);
		var now = new Date(+n[0], +n[1] - 1, +n[2], +n[3], +n[4]);
		return (at - now) / 36e5;
	}
	function refresh() {
		var v = pay();
		show(form.querySelector('.scpq-voucher'), v === 'voucher');
		var warn = form.querySelector('[data-lastminute]');
		var last = Q.payHours > 0 && hoursUntil() <= Q.payHours;
		if (v === 'online' && last) {
			warn.textContent = 'Ședința e în mai puțin de ' + Q.payHours + ' de ore, deci rezervarea nu se mai anulează automat dacă nu plătește. Mai bine alege „La studio”.';
			show(warn, true);
		} else {
			show(warn, false);
		}
		form.querySelectorAll('.scpq-opt').forEach(function (o) { o.classList.toggle('is-on', o.querySelector('input').checked); });
	}
	form.addEventListener('change', function (e) { if (e.target.name === 'pay') refresh(); });

	/* ---------- Salvare ---------- */
	function setError(msg, fields) {
		var box = form.querySelector('.scpq-error');
		form.querySelectorAll('.scpq-field-err').forEach(function (n) { n.remove(); });
		form.querySelectorAll('[aria-invalid]').forEach(function (n) { n.removeAttribute('aria-invalid'); });
		if (!msg) { show(box, false); return; }
		box.textContent = msg;
		show(box, true);
		Object.keys(fields || {}).forEach(function (k) {
			var input = form.elements[k];
			if (input && input.closest) {
				input.setAttribute('aria-invalid', 'true');
				input.closest('label').appendChild(el('strong', { class: 'scpq-field-err', text: fields[k] }));
			}
		});
	}
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (state.busy) return;
		var f = form.elements;
		if (!state.date || !state.time) return setError('Alege ziua și ora.');
		if (!state.pkg) return setError('Alege pachetul.');
		if (f.name.value.trim().length < 3) return setError('Verifică datele.', { name: 'Scrie numele complet.' });
		if (!f.phone.value.trim()) return setError('Verifică datele.', { phone: 'Telefonul e necesar pentru SMS-uri.' });
		setError('');
		state.busy = true;
		var btn = form.querySelector('[type="submit"]');
		btn.disabled = true;
		btn.textContent = 'Se salvează…';
		api('/quick', {
			method: 'POST',
			body: {
				date: state.date, time: state.time, package: state.pkg,
				name: f.name.value, phone: f.phone.value, email: f.email.value,
				message: f.message.value, notes: f.notes.value,
				pay: pay(), voucher: f.voucher ? f.voucher.value : ''
			}
		}).then(function (r) {
			success(r.booking);
		}).catch(function (err) {
			var d = err.data || {};
			setError(err.message, d.fields);
			if (d.code === 'scp_slot_taken') {
				setError('Ora tocmai a fost ocupată (probabil online). Alege altă oră.');
				state.time = '';
				loadDays().then(function () { if (state.date) pickDay(state.date); });
			}
		}).then(function () {
			state.busy = false;
			btn.disabled = false;
			btn.textContent = 'Salvează programarea';
		});
	});

	function success(b) {
		done.innerHTML = '';
		var lines = [];
		if (b.pay === 'online' && b.status === 'pending') {
			lines.push(el('p', { class: 'scpq-done-pay' }, [
				b.pay_by_label ? 'Trebuie plătită până ' + b.pay_by_label + '. Altfel se anulează automat.' : 'Așteaptă plata online. Ședința e aproape, deci nu se anulează automat.'
			]));
			if (b.payment_url) {
				var input = el('input', { type: 'text', readonly: true, value: b.payment_url, 'aria-label': 'Link de plată' });
				lines.push(el('div', { class: 'scp-copy' }, [
					input,
					el('button', {
						type: 'button', class: 'button', onclick: function (ev) {
							input.select();
							var ok = function () { ev.target.textContent = 'Copiat'; };
							if (navigator.clipboard) navigator.clipboard.writeText(b.payment_url).then(ok, function () { document.execCommand('copy'); ok(); });
							else { document.execCommand('copy'); ok(); }
						}
					}, ['Copiază'])
				]));
			}
		} else if (b.pay === 'studio') {
			lines.push(el('p', { text: 'Confirmată. Plata (' + b.price + ' lei) se face la studio, cash sau card.' }));
		} else {
			lines.push(el('p', { text: 'Confirmată și plătită cu voucher.' }));
		}
		var sent = [];
		if (b.sms) sent.push('SMS');
		if (b.email) sent.push('email');
		lines.push(el('p', { class: 'scp-muted', text: sent.length ? 'Clientul primește ' + sent.join(' și ') + '.' + (b.email ? '' : ' Nu are email, deci primește doar SMS.') : 'SMS-urile sunt oprite și clientul nu are email: anunță-l tu.' }));

		done.appendChild(el('div', { class: 'scpq-done-head' }, [
			el('span', { class: 'scpq-done-ok', 'aria-hidden': 'true', text: '✓' }),
			el('div', {}, [
				el('h2', { text: b.name }),
				el('p', { text: b.date_label + ', ora ' + b.time + ' · ' + b.package_label + ' · ' + b.price + ' lei' }),
				el('p', { class: 'scp-mono', text: 'Cod ' + b.code })
			])
		]));
		lines.forEach(function (n) { done.appendChild(n); });
		done.appendChild(el('div', { class: 'scpq-done-actions' }, [
			el('button', { type: 'button', class: 'button button-primary button-hero', onclick: reset }, ['Încă o programare']),
			el('a', { class: 'button button-hero', href: b.url }, ['Deschide fișa'])
		]));
		show(form, false);
		show(done, true);
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}
	function reset() {
		form.reset();
		state.date = state.time = state.pkg = '';
		['time', 'package', 'client', 'pay'].forEach(function (s) { show(step(s), false); });
		show(form.querySelector('.scpq-submit'), false);
		form.querySelectorAll('.is-on').forEach(function (n) { n.classList.remove('is-on'); });
		setError('');
		refresh();
		show(done, false);
		show(form, true);
		loadDays();
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	renderPackages();
	refresh();
	// Venit din Calendar, cu ziua și ora alese: le selectăm direct.
	var qs = new URLSearchParams(window.location.search);
	loadDays().then(function () {
		var d = qs.get('date'), t = qs.get('time');
		if (!d || !freeSlots(dayByDate(d)).length) return;
		pickDay(d);
		if (t && freeSlots(dayByDate(d)).some(function (s) { return s.time === t; })) pickTime(t);
	});
})();

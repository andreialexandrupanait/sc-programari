/* SC Programări – formular public */
(function () {
	'use strict';

	var DOW = ['L', 'Ma', 'Mi', 'J', 'V', 'S', 'D'];
	var DOW_FULL = ['luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă', 'duminică'];
	var MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) return;
				if (k === 'class') n.className = v;
				else if (k === 'text') n.textContent = v;
				else if (k === 'html') n.innerHTML = v;
				else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), v);
				else n.setAttribute(k, v === true ? '' : v);
			});
		}
		(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function parseDate(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
	function isoWeekday(d) { return ((d.getDay() + 6) % 7); } // 0 = luni
	function longDate(s) { var d = parseDate(s); return DOW_FULL[isoWeekday(d)] + ', ' + d.getDate() + ' ' + MONTHS[d.getMonth()]; }
	function addMinutes(t, m) { var p = t.split(':'); var x = (+p[0]) * 60 + (+p[1]) + m; return pad(Math.floor(x / 60) % 24) + ':' + pad(x % 60); }
	function money(v) { v = +v; return (Math.round(v) === v ? v.toString() : v.toFixed(2).replace('.', ',')).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
	function plural(n, one, many) { return n === 1 ? one : (n % 100 >= 20 || n === 0 ? n + ' de ' + many : n + ' ' + many); }

	function storage() { try { return window.sessionStorage; } catch (e) { return null; } }

	function captureUtm() {
		var out = {};
		var s = storage();
		try {
			var saved = s && s.getItem('scp_utm');
			if (saved) out = JSON.parse(saved) || {};
		} catch (e) { out = {}; }
		var q = new URLSearchParams(window.location.search);
		['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid'].forEach(function (k) {
			if (q.get(k)) out[k] = q.get(k);
		});
		if (!out.referrer && document.referrer && document.referrer.indexOf(window.location.host) === -1) out.referrer = document.referrer;
		if (!out.landing) out.landing = window.location.pathname;
		try { if (s) s.setItem('scp_utm', JSON.stringify(out)); } catch (e) { /* ignorăm */ }
		return out;
	}

	function App(root) {
		this.root = root;
		this.card = root.querySelector('.scp-card');
		this.cfg = JSON.parse(root.getAttribute('data-scp') || '{}');
		this.state = { pkg: null, monthIdx: 0, months: {}, date: null, time: null, slots: null, busy: false, voucher: null, quotes: null };
		this.started = Date.now();
		this.utm = captureUtm();
		this.load();
	}

	App.prototype.api = function (path, opts) {
		opts = opts || {};
		var url = this.cfg.api.replace(/\/$/, '') + path;
		return fetch(url, {
			method: opts.method || 'GET',
			headers: opts.body ? { 'Content-Type': 'application/json' } : {},
			body: opts.body ? JSON.stringify(opts.body) : undefined,
			credentials: 'omit',
			cache: 'no-store'
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) { var e = new Error(data.message || 'Eroare de rețea.'); e.status = r.status; e.data = data; throw e; }
				return data;
			});
		});
	};

	App.prototype.load = function () {
		var self = this;
		this.api('/config').then(function (cfg) {
			self.conf = cfg;
			self.state.quotes = cfg.quotes || {};
			if (!cfg.open || !cfg.months.length) { self.renderClosed(cfg.closed_message); return; }
			self.render();
			self.loadMonth();
		}).catch(function () {
			self.card.innerHTML = '';
			self.card.appendChild(el('p', { class: 'scp-note scp-note--error', text: 'Calendarul nu s-a putut încărca. Reîncarcă pagina sau sună-ne pentru programare.' }));
		});
	};

	App.prototype.renderClosed = function (msg) {
		this.card.innerHTML = '';
		this.card.appendChild(el('div', { class: 'scp-closed' }, [
			el('p', { class: 'scp-closed-text', text: msg })
		]));
	};

	/* ---------- Structură ---------- */

	App.prototype.step = function (n, title, body) {
		return el('section', { class: 'scp-step', 'data-step': n, 'aria-labelledby': 'scp-step-' + n }, [
			el('header', { class: 'scp-step-h' }, [
				el('span', { class: 'scp-num', 'aria-hidden': 'true', text: String(n) }),
				el('h3', { id: 'scp-step-' + n, text: title })
			]),
			body
		]);
	};

	App.prototype.render = function () {
		var self = this;
		var c = this.conf;
		this.card.innerHTML = '';

		/* 1. Pachet */
		var pk = el('div', { class: 'scp-packages', role: 'radiogroup', 'aria-label': 'Pachet' });
		c.packages.forEach(function (p) {
			var input = el('input', { type: 'radio', name: 'package', value: p.key, class: 'scp-sr' });
			input.addEventListener('change', function () { self.state.pkg = p; self.updatePrices(); self.updateSummary(); self.clearError('package'); });
			pk.appendChild(el('label', { class: 'scp-pkg' }, [
				input,
				el('span', { class: 'scp-pkg-body' }, [
					el('span', { class: 'scp-pkg-top' }, [
						el('span', { class: 'scp-pkg-name', text: p.label }),
						el('span', { class: 'scp-pkg-price', 'data-price': p.key })
					]),
					el('span', { class: 'scp-pkg-deal', 'data-deal': p.key }),
					el('ul', { class: 'scp-pkg-list' }, p.features.map(function (f) { return el('li', { text: f }); })),
					el('span', { class: 'scp-pkg-check', 'aria-hidden': 'true' })
				])
			]));
		});
		pk.appendChild(el('p', { class: 'scp-field-error', 'data-error': 'package' }));
		this.pkgBox = pk;

		/* 2. Zi și oră */
		this.calHead = el('div', { class: 'scp-cal-head' });
		this.calGrid = el('div', { class: 'scp-cal-grid', role: 'grid', 'aria-label': 'Calendar' });
		this.slotsBox = el('div', { class: 'scp-slots' });
		var dow = el('div', { class: 'scp-cal-dow', 'aria-hidden': 'true' }, DOW.map(function (d) { return el('span', { text: d }); }));
		var when = el('div', { class: 'scp-when' }, [
			el('div', { class: 'scp-cal' }, [this.calHead, dow, this.calGrid,
				el('p', { class: 'scp-legend' }, [el('span', { class: 'scp-dots scp-dots--legend', 'aria-hidden': 'true' }, [el('i'), el('i'), el('i')]), 'ore libere în ziua respectivă'])
			]),
			this.slotsBox
		]);
		var whenWrap = el('div', {}, [when, el('p', { class: 'scp-field-error', 'data-error': 'time' })]);
		this.renderSlotsEmpty();

		/* 3. Date */
		var f = function (name, label, type, extra) {
			extra = extra || {};
			var id = 'scp-' + name;
			var input = type === 'textarea'
				? el('textarea', { id: id, name: name, rows: 3, placeholder: extra.placeholder || '' })
				: el('input', { id: id, name: name, type: type, autocomplete: extra.autocomplete || 'on', inputmode: extra.inputmode || null, required: extra.optional ? null : true, placeholder: extra.placeholder || '' });
			input.addEventListener('input', function () { self.clearError(name); });
			return el('div', { class: 'scp-field' + (extra.wide ? ' scp-field--wide' : '') }, [
				el('label', { for: id }, [label, extra.optional ? el('span', { class: 'scp-opt', text: ' (opțional)' }) : null]),
				input,
				el('p', { class: 'scp-field-error', 'data-error': name, id: id + '-err' })
			]);
		};
		var gdprInput = el('input', { type: 'checkbox', name: 'gdpr', id: 'scp-gdpr', value: '1' });
		gdprInput.addEventListener('change', function () { self.clearError('gdpr'); });
		var fields = el('div', { class: 'scp-fields' }, [
			f('name', 'Nume și prenume', 'text', { autocomplete: 'name', wide: true }),
			f('phone', 'Telefon', 'tel', { autocomplete: 'tel', inputmode: 'tel', placeholder: '07xx xxx xxx' }),
			f('email', 'Email', 'email', { autocomplete: 'email', inputmode: 'email', placeholder: 'confirmarea vine aici' }),
			f('message', 'Ceva ce ar trebui să știm', 'textarea', { optional: true, wide: true, placeholder: 'De exemplu, câți copii vin și ce vârstă au' }),
			c.vouchers ? this.voucherBox() : null,
			el('div', { class: 'scp-field scp-field--wide scp-check' }, [
				el('label', { for: 'scp-gdpr' }, [gdprInput, el('span', {}, ['Am citit și sunt de acord cu ', el('a', { href: c.terms_url, target: '_blank', rel: 'noopener', text: 'termenii și condițiile' }), '.'])]),
				el('p', { class: 'scp-field-error', 'data-error': 'gdpr' })
			]),
			el('div', { class: 'scp-hp', 'aria-hidden': 'true' }, [el('label', {}, ['Website', el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' })])])
		]);

		/* Bara de trimitere */
		this.summary = el('p', { class: 'scp-summary' });
		this.submitBtn = el('button', { type: 'submit', class: 'scp-btn' }, [el('span', { text: 'Programează ședința' })]);
		this.formError = el('p', { class: 'scp-form-error', role: 'alert' });
		var bar = el('div', { class: 'scp-bar' }, [this.summary, this.submitBtn]);

		this.form = el('form', { class: 'scp-form', novalidate: true }, [
			this.step(1, 'Alege pachetul', pk),
			this.step(2, 'Alege ziua și ora', whenWrap),
			this.step(3, 'Datele tale', fields),
			bar,
			this.formError
		]);
		this.form.addEventListener('submit', function (e) { e.preventDefault(); self.submit(); });
		this.card.appendChild(this.form);
		this.updatePrices();
		this.updateSummary();
	};

	App.prototype.quoteFor = function (key) {
		var q = (this.state.quotes || {})[key];
		var p = this.conf.packages.filter(function (x) { return x.key === key; })[0];
		return q || { list: p ? p.price : 0, price: p ? p.price : 0, discount: 0, source: '', label: '' };
	};

	App.prototype.updatePrices = function () {
		var self = this;
		this.conf.packages.forEach(function (p) {
			var q = self.quoteFor(p.key);
			if (self.state.voucher && self.state.voucher.package === p.key) q = { list: q.list, price: q.list, discount: 0, label: 'Achitat cu voucherul ' + self.state.voucher.code };
			var box = self.pkgBox.querySelector('[data-price="' + p.key + '"]');
			var deal = self.pkgBox.querySelector('[data-deal="' + p.key + '"]');
			box.innerHTML = '';
			if (q.discount > 0) {
				box.appendChild(el('s', { class: 'scp-pkg-old', text: money(q.list) }));
				box.appendChild(document.createTextNode(' '));
			}
			box.appendChild(document.createTextNode(money(q.price)));
			box.appendChild(el('small', { text: ' lei' }));
			deal.textContent = q.label || '';
			deal.hidden = !q.label;
		});
	};

	App.prototype.voucherBox = function () {
		var self = this;
		var input = el('input', { type: 'text', id: 'scp-voucher', name: 'voucher', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', placeholder: 'ex. CHELMU-BRAD-K7MP9Q' });
		var apply = el('button', { type: 'button', class: 'scp-btn scp-btn--ghost scp-btn--small', text: 'Folosește voucherul' });
		var remove = el('button', { type: 'button', class: 'scp-link', text: 'Renunță la voucher', hidden: true });
		var msg = el('p', { class: 'scp-voucher-msg', 'aria-live': 'polite' });
		var row = el('div', { class: 'scp-voucher-row', hidden: true }, [input, apply]);
		var toggle = el('button', { type: 'button', class: 'scp-link', 'aria-expanded': 'false', text: 'Ai primit un voucher cadou?' });
		toggle.addEventListener('click', function () {
			row.hidden = !row.hidden;
			toggle.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
			if (!row.hidden) input.focus();
		});
		var clear = function () {
			self.state.voucher = null;
			input.disabled = false; apply.hidden = false; remove.hidden = true;
			msg.textContent = ''; msg.className = 'scp-voucher-msg';
			self.lockPackage(null);
		};
		var run = function () {
			var code = input.value.trim();
			self.clearError('voucher');
			if (!code) { input.focus(); return; }
			apply.disabled = true;
			self.api('/voucher?code=' + encodeURIComponent(code)).then(function (d) {
				apply.disabled = false;
				if (!d.ok) { msg.textContent = d.message; msg.className = 'scp-voucher-msg is-bad'; return; }
				self.state.voucher = d;
				input.value = d.code; input.disabled = true; apply.hidden = true; remove.hidden = false;
				msg.textContent = 'Voucher valabil pentru ' + d.package_label + (d.expires_on ? ', până pe ' + d.expires_on : '') + '. Ședința e achitată integral cu voucherul, nu mai ai nimic de plătit.';
				msg.className = 'scp-voucher-msg is-ok';
				self.lockPackage(d.package);
			}).catch(function (e) { apply.disabled = false; msg.textContent = e.message; msg.className = 'scp-voucher-msg is-bad'; });
		};
		apply.addEventListener('click', run);
		remove.addEventListener('click', clear);
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
		return el('div', { class: 'scp-field scp-field--wide scp-voucher' }, [toggle, row, msg, remove, el('p', { class: 'scp-field-error', 'data-error': 'voucher' })]);
	};

	/** Cu voucher, pachetul e cel de pe voucher; celălalt nu se mai poate alege. */
	App.prototype.lockPackage = function (key) {
		var self = this;
		Array.prototype.forEach.call(this.pkgBox.querySelectorAll('input[name="package"]'), function (inp) {
			var lab = inp.closest('.scp-pkg');
			if (key) {
				inp.disabled = inp.value !== key;
				lab.classList.toggle('is-locked', inp.value !== key);
				if (inp.value === key && !inp.checked) { inp.checked = true; }
			} else {
				inp.disabled = false;
				lab.classList.remove('is-locked');
			}
		});
		if (key) {
			this.state.pkg = this.conf.packages.filter(function (p) { return p.key === key; })[0];
			this.clearError('package');
		}
		this.updatePrices();
		this.updateSummary();
	};

	/* ---------- Calendar ---------- */

	App.prototype.loadMonth = function () {
		var self = this;
		var m = this.conf.months[this.state.monthIdx];
		this.renderCalHead();
		if (this.state.months[m]) { this.renderGrid(); return; }
		this.calGrid.classList.add('is-loading');
		this.api('/month?month=' + m).then(function (data) {
			self.state.months[m] = data.days;
			self.calGrid.classList.remove('is-loading');
			// Prima dată: dacă luna curentă nu mai are nimic liber, trecem singuri la următoarea.
			if (!self.autoAdvanced) {
				self.autoAdvanced = true;
				var any = Object.keys(data.days).some(function (d) { return data.days[d].state === 'available'; });
				if (!any && self.state.monthIdx < self.conf.months.length - 1) { self.state.monthIdx++; self.loadMonth(); return; }
			}
			self.renderGrid();
		}).catch(function () {
			self.calGrid.classList.remove('is-loading');
			self.calGrid.innerHTML = '';
			self.calGrid.appendChild(el('p', { class: 'scp-note scp-note--error', text: 'Nu am putut încărca zilele. Încearcă din nou.' }));
		});
	};

	App.prototype.renderCalHead = function () {
		var self = this;
		var m = this.conf.months[this.state.monthIdx].split('-');
		this.calHead.innerHTML = '';
		var prev = el('button', { type: 'button', class: 'scp-nav', 'aria-label': 'Luna anterioară', disabled: this.state.monthIdx === 0 }, [el('span', { 'aria-hidden': 'true', text: '‹' })]);
		var next = el('button', { type: 'button', class: 'scp-nav', 'aria-label': 'Luna următoare', disabled: this.state.monthIdx >= this.conf.months.length - 1 }, [el('span', { 'aria-hidden': 'true', text: '›' })]);
		prev.addEventListener('click', function () { if (self.state.monthIdx > 0) { self.state.monthIdx--; self.loadMonth(); } });
		next.addEventListener('click', function () { if (self.state.monthIdx < self.conf.months.length - 1) { self.state.monthIdx++; self.loadMonth(); } });
		this.calHead.appendChild(prev);
		this.calHead.appendChild(el('span', { class: 'scp-month', text: MONTHS[+m[1] - 1] + ' ' + m[0] }));
		this.calHead.appendChild(next);
	};

	App.prototype.renderGrid = function () {
		var self = this;
		var m = this.conf.months[this.state.monthIdx];
		var days = this.state.months[m];
		var first = parseDate(m + '-01');
		var lead = isoWeekday(first);
		var count = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
		this.calGrid.innerHTML = '';
		for (var i = 0; i < lead; i++) this.calGrid.appendChild(el('span', { class: 'scp-day scp-day--blank', 'aria-hidden': 'true' }));
		for (var d = 1; d <= count; d++) {
			(function (d) {
				var iso = m + '-' + pad(d);
				var info = days[iso] || { state: 'closed', free: 0 };
				var selected = self.state.date === iso;
				var dots = null;
				if (info.state === 'available') {
					dots = el('span', { class: 'scp-dots', 'aria-hidden': 'true' });
					for (var k = 0; k < Math.min(info.free, 6); k++) dots.appendChild(el('i'));
				}
				var label = longDate(iso) + (info.state === 'available' ? ', ' + plural(info.free, 'o oră liberă', 'ore libere') : info.state === 'full' ? ', ocupat' : ', indisponibil');
				var btn = el('button', {
					type: 'button',
					class: 'scp-day scp-day--' + info.state + (selected ? ' is-selected' : '') + (iso === self.conf.today ? ' is-today' : ''),
					disabled: info.state !== 'available',
					'aria-pressed': selected ? 'true' : 'false',
					'aria-label': label,
					'data-date': iso
				}, [el('span', { class: 'scp-day-n', text: String(d) }), dots, info.state === 'full' ? el('span', { class: 'scp-day-full', text: 'plin' }) : null]);
				btn.addEventListener('click', function () { self.pickDate(iso); });
				self.calGrid.appendChild(btn);
			})(d);
		}
	};

	App.prototype.pickDate = function (iso) {
		var self = this;
		this.state.date = iso;
		this.state.time = null;
		this.clearError('time');
		Array.prototype.forEach.call(this.calGrid.querySelectorAll('.scp-day'), function (b) {
			var on = b.getAttribute('data-date') === iso;
			b.classList.toggle('is-selected', on);
			if (b.hasAttribute('data-date')) b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		this.updateSummary();
		this.slotsBox.innerHTML = '';
		this.slotsBox.appendChild(el('p', { class: 'scp-slots-title', text: longDate(iso) }));
		this.slotsBox.appendChild(el('div', { class: 'scp-slot-list is-loading' }, [el('span', { class: 'scp-spinner' })]));
		this.api('/day?date=' + iso).then(function (data) {
			if (self.state.date !== iso) return;
			self.renderSlots(data);
		}).catch(function () {
			self.slotsBox.innerHTML = '';
			self.slotsBox.appendChild(el('p', { class: 'scp-note scp-note--error', text: 'Orele nu s-au putut încărca. Alege ziua din nou.' }));
		});
	};

	App.prototype.renderSlotsEmpty = function () {
		this.slotsBox.innerHTML = '';
		this.slotsBox.appendChild(el('div', { class: 'scp-slots-empty' }, [
			el('p', { text: 'Alege o zi din calendar și îți arătăm orele libere.' }),
			el('p', { class: 'scp-muted', text: 'Ședința durează ' + (this.conf.session_minutes || 60) + ' de minute.' })
		]));
	};

	App.prototype.renderSlots = function (data) {
		var self = this;
		var dur = this.conf.session_minutes || 60;
		this.slotsBox.innerHTML = '';
		this.slotsBox.appendChild(el('p', { class: 'scp-slots-title', text: data.label }));
		var list = el('div', { class: 'scp-slot-list', role: 'radiogroup', 'aria-label': 'Ora ședinței' });
		var free = 0;
		data.slots.forEach(function (s) {
			if (s.available) free++;
			var btn = el('button', {
				type: 'button',
				role: 'radio',
				class: 'scp-slot' + (s.available ? '' : ' is-taken') + (self.state.time === s.time ? ' is-selected' : ''),
				disabled: !s.available,
				'aria-checked': self.state.time === s.time ? 'true' : 'false',
				'data-time': s.time
			}, [
				el('span', { class: 'scp-slot-t', text: s.time }),
				el('span', { class: 'scp-slot-s', text: s.available ? 'până la ' + addMinutes(s.time, dur) : 'ocupat' })
			]);
			btn.addEventListener('click', function () { self.pickTime(s.time); });
			list.appendChild(btn);
		});
		if (!free) {
			this.slotsBox.appendChild(el('p', { class: 'scp-muted', text: 'Ziua aceasta s-a ocupat între timp. Alege te rog altă zi.' }));
		}
		this.slotsBox.appendChild(list);
	};

	App.prototype.pickTime = function (t) {
		this.state.time = t;
		this.clearError('time');
		Array.prototype.forEach.call(this.slotsBox.querySelectorAll('.scp-slot'), function (b) {
			var on = b.getAttribute('data-time') === t;
			b.classList.toggle('is-selected', on);
			b.setAttribute('aria-checked', on ? 'true' : 'false');
		});
		this.updateSummary();
	};

	App.prototype.refreshAvailability = function () {
		var m = this.conf.months[this.state.monthIdx];
		delete this.state.months[m];
		var keepDate = this.state.date;
		this.state.time = null;
		this.loadMonth();
		if (keepDate) this.pickDate(keepDate);
	};

	/* ---------- Rezumat și trimitere ---------- */

	App.prototype.updateSummary = function () {
		var s = this.state;
		this.summary.innerHTML = '';
		if (!s.pkg && !s.date) {
			this.summary.appendChild(el('span', { class: 'scp-muted', text: 'Alege pachetul, ziua și ora.' }));
			return;
		}
		var parts = [];
		if (s.pkg) {
			var q = this.quoteFor(s.pkg.key);
			if (s.voucher && s.voucher.package === s.pkg.key) parts.push(el('strong', { text: s.pkg.label + ', achitat cu voucher' }));
			else parts.push(el('strong', { text: s.pkg.label + ', ' + money(q.price) + ' lei' + (q.discount > 0 ? ' (în loc de ' + money(q.list) + ')' : '') }));
		}
		if (s.date) parts.push(el('span', { text: longDate(s.date) + (s.time ? ', ora ' + s.time : '') }));
		if (s.date && !s.time) parts.push(el('span', { class: 'scp-muted', text: 'alege ora' }));
		if (!s.pkg) parts.push(el('span', { class: 'scp-muted', text: 'alege pachetul' }));
		var self = this;
		parts.forEach(function (p) { self.summary.appendChild(p); });
	};

	App.prototype.setError = function (name, msg) {
		var n = this.root.querySelector('[data-error="' + name + '"]');
		if (n) n.textContent = msg;
		var input = this.form.querySelector('[name="' + name + '"]');
		if (input && input.type !== 'radio') { input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', 'scp-' + name + '-err'); }
	};

	App.prototype.clearError = function (name) {
		var n = this.root.querySelector('[data-error="' + name + '"]');
		if (n) n.textContent = '';
		var input = this.form && this.form.querySelector('[name="' + name + '"]');
		if (input) input.removeAttribute('aria-invalid');
		if (this.formError) this.formError.textContent = '';
	};

	App.prototype.validate = function (v) {
		var e = {};
		if (!this.state.pkg) e['package'] = 'Alege unul dintre pachete.';
		if (!this.state.date || !this.state.time) e.time = 'Alege ziua și ora din calendar.';
		if (v.name.trim().length < 3) e.name = 'Scrie numele complet.';
		if (v.phone.replace(/\D/g, '').length < 9) e.phone = 'Numărul de telefon nu pare corect.';
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.email.trim())) e.email = 'Adresa de email nu pare corectă.';
		if (!v.gdpr) e.gdpr = 'Pentru programare e nevoie de acordul tău cu termenii și condițiile.';
		return e;
	};

	App.prototype.submit = function () {
		var self = this;
		if (this.state.busy) return;
		var fd = this.form.elements;
		var v = {
			name: fd.name.value, phone: fd.phone.value, email: fd.email.value,
			message: fd.message.value, gdpr: fd.gdpr.checked, website: fd.website.value
		};
		['package', 'time', 'name', 'phone', 'email', 'gdpr'].forEach(function (k) { self.clearError(k); });
		var errs = this.validate(v);
		var keys = Object.keys(errs);
		if (keys.length) {
			keys.forEach(function (k) { self.setError(k, errs[k]); });
			this.focusFirstError(keys);
			return;
		}
		this.state.busy = true;
		this.submitBtn.disabled = true;
		this.submitBtn.classList.add('is-busy');
		this.submitBtn.firstChild.textContent = 'Se salvează…';

		this.api('/bookings', {
			method: 'POST',
			body: {
				package: this.state.pkg.key, date: this.state.date, time: this.state.time,
				name: v.name, phone: v.phone, email: v.email, message: v.message,
				gdpr: v.gdpr ? 1 : 0, website: v.website, voucher: this.state.voucher ? this.state.voucher.code : '',
				elapsed: Date.now() - this.started, utm: this.utm
			}
		}).then(function (res) {
			self.success(res.booking);
		}).catch(function (err) {
			self.state.busy = false;
			self.submitBtn.disabled = false;
			self.submitBtn.classList.remove('is-busy');
			self.submitBtn.firstChild.textContent = 'Programează ședința';
			var data = err.data || {};
			if (data.fields && Object.keys(data.fields).length) {
				Object.keys(data.fields).forEach(function (k) { self.setError(k, data.fields[k]); });
				self.focusFirstError(Object.keys(data.fields));
				return;
			}
			if (data.code === 'scp_slot_taken') {
				self.setError('time', data.message);
				self.refreshAvailability();
				self.focusFirstError(['time']);
				return;
			}
			self.formError.textContent = err.message && err.status ? err.message : 'Nu am putut trimite programarea. Verifică conexiunea și încearcă din nou.';
		});
	};

	App.prototype.focusFirstError = function (keys) {
		var order = ['package', 'time', 'name', 'phone', 'email', 'voucher', 'gdpr'];
		var first = order.filter(function (k) { return keys.indexOf(k) !== -1; })[0];
		var target = first === 'package' ? this.form.querySelector('[name="package"]')
			: first === 'time' ? this.form.querySelector('[data-step="2"]')
			: this.form.querySelector('[name="' + first + '"]');
		if (target) {
			var box = target.closest('.scp-step') || target;
			box.scrollIntoView({ behavior: 'smooth', block: 'start' });
			if (target.focus) setTimeout(function () { target.focus({ preventScroll: true }); }, 350);
		}
	};

	App.prototype.success = function (b) {
		var dur = this.conf.session_minutes || 60;
		try {
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push({ event: 'scp_booking', scp_package: b.package, scp_package_label: b.package_label, value: b.price, currency: this.cfg.currency || 'RON', scp_date: b.date, scp_time: b.time });
			if (this.cfg.fbq && typeof window.fbq === 'function') window.fbq('track', 'Schedule', { value: b.price, currency: this.cfg.currency || 'RON', content_name: b.package_label });
		} catch (e) { /* măsurarea nu are voie să strice confirmarea */ }

		this.card.innerHTML = '';
		var pending = b.status === 'pending' && b.payment_url;
		var when = el('p', { class: 'scp-done-when' }, [
			el('strong', { text: b.date_label.charAt(0).toUpperCase() + b.date_label.slice(1) }),
			el('br'),
			'ora ' + b.time + ' – ' + addMinutes(b.time, dur)
		]);
		var list = el('dl', { class: 'scp-done-list' }, [
			el('div', {}, [el('dt', { text: 'Pachet' }), el('dd', { text: b.package_label })]),
			b.voucher_code
				? el('div', {}, [el('dt', { text: 'Plată' }), el('dd', { text: 'Achitat cu voucherul ' + b.voucher_code })])
				: el('div', {}, [el('dt', { text: 'De plată' }), el('dd', {}, [b.discount > 0 ? el('s', { class: 'scp-pkg-old', text: money(b.list_price) + ' lei' }) : null, b.discount > 0 ? ' ' : null, money(b.price) + ' lei'])]),
			b.discount > 0 && b.discount_label ? el('div', {}, [el('dt', { text: 'Reducere' }), el('dd', { text: b.discount_label })]) : null,
			el('div', {}, [el('dt', { text: 'Unde' }), el('dd', { text: this.conf.studio_address })]),
			el('div', {}, [el('dt', { text: 'Cod programare' }), el('dd', { class: 'scp-code', text: b.code })])
		]);
		var box;
		if (pending) {
			box = el('div', { class: 'scp-done scp-done--pending', tabindex: '-1' }, [
				el('h3', { class: 'scp-done-title', text: 'Ora e rezervată pentru tine' }),
				when,
				el('p', { class: 'scp-done-step' }, ['Mai ai un pas: plata, până ', el('strong', { text: b.pay_by_label }), '. Dacă plata nu ajunge până atunci, rezervarea se anulează automat și ora devine liberă pentru alți părinți.']),
				el('a', { class: 'scp-btn scp-btn--pay', href: b.payment_url, text: 'Plătește acum ' + money(b.price) + ' lei' }),
				list,
				el('p', { class: 'scp-done-note', text: 'Ți-am trimis linkul de plată și pe email, la ' + b.email + '. Dacă nu îl vezi, uită-te și în Spam sau Promoții.' }),
				el('p', { class: 'scp-muted', text: 'Ai întrebări? Sună-ne la ' + this.conf.studio_phone + '.' })
			]);
		} else {
			box = el('div', { class: 'scp-done', tabindex: '-1' }, [
				el('div', { class: 'scp-done-mark', 'aria-hidden': 'true' }),
				el('h3', { class: 'scp-done-title', text: 'Te așteptăm!' }),
				when,
				list,
				el('p', { class: 'scp-done-note', text: 'Ți-am trimis confirmarea la ' + b.email + '. Dacă nu o vezi în câteva minute, uită-te și în Spam sau Promoții.' }),
				el('div', { class: 'scp-done-actions' }, [
					el('a', { class: 'scp-btn scp-btn--ghost', href: b.gcal_url, target: '_blank', rel: 'noopener', text: 'Adaugă în Google Calendar' }),
					el('a', { class: 'scp-btn scp-btn--ghost', href: b.ics_url, text: 'Descarcă pentru alt calendar' })
				]),
				el('p', { class: 'scp-muted', text: 'Vrei să muți programarea? Sună-ne la ' + this.conf.studio_phone + '.' })
			]);
		}
		this.card.appendChild(box);
		this.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		setTimeout(function () { box.focus({ preventScroll: true }); }, 300);
	};

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.scp[data-scp]'), function (root) {
			if (!root.__scp) root.__scp = new App(root);
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
	// Elementor poate încărca widget-ul după DOMContentLoaded (popup, editor).
	window.addEventListener('elementor/frontend/init', boot);
})();

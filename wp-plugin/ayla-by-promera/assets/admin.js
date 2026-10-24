/* Ayla by Promera: dashboard UI. Every number and list comes from the Ayla service via the PHP proxy. */
(function () {
	'use strict';

	var D = window.AylaData || {};
	var T = D.i18n || {};
	var DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];
	var ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

	function t(key) { return T[key] || key; }

	function esc(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}

	function api(route, method, payload, query) {
		var body = new FormData();
		body.append('action', 'ayla_proxy');
		body.append('nonce', D.nonce);
		body.append('route', route);
		body.append('method', method || 'GET');
		if (payload) { body.append('payload', JSON.stringify(payload)); }
		if (query) { body.append('query', JSON.stringify(query)); }

		return fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.catch(function () { return { ok: false, error: { code: 'unreachable', message: t('error_generic') } }; });
	}

	function el(id) { return document.getElementById(id); }

	function notice(root, message, type) {
		var box = document.createElement('div');
		box.className = 'notice notice-' + (type || 'error') + ' is-dismissible ayla-notice';
		box.innerHTML = '<p>' + esc(message) + '</p>';
		root.insertBefore(box, root.firstChild);
		setTimeout(function () { if (box.parentNode) { box.parentNode.removeChild(box); } }, 6000);
	}

	function table(headers, rows) {
		if (!rows.length) { return '<p class="ayla-empty">' + esc(t('empty')) + '</p>'; }
		var head = headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
		return '<table class="widefat striped ayla-table"><thead><tr>' + head + '</tr></thead><tbody>' + rows.join('') + '</tbody></table>';
	}

	function pager(res, key) {
		if (!res || res.pages <= 1) { return ''; }
		return '<div class="ayla-pager">' +
			(res.page > 1 ? '<button class="button" data-page="' + (res.page - 1) + '" data-pager="' + key + '">' + esc(t('prev')) + '</button> ' : '') +
			'<span>' + res.page + ' / ' + res.pages + '</span> ' +
			(res.page < res.pages ? '<button class="button" data-page="' + (res.page + 1) + '" data-pager="' + key + '">' + esc(t('next')) + '</button>' : '') +
			'</div>';
	}

	/* ---------- App shell ---------- */

	var state = { tab: 'overview', account: null };
	var root;

	function boot() {
		root = el('ayla-app');
		if (root) { initApp(); }
		var status = document.querySelector('[data-view="status"]');
		if (status) { initStatus(status); }
	}

	function initStatus(node) {
		api('account', 'GET').then(function (res) {
			node.textContent = res.ok ? t('connected') : (res.error && res.error.message) || t('error_generic');
			node.className = res.ok ? 'ayla-ok' : 'ayla-bad';
		});
	}

	function initApp() {
		root.innerHTML = '<p>' + esc(t('loading')) + '</p>';
		api('account', 'GET').then(function (res) {
			if (!res.ok) {
				root.innerHTML = '<div class="notice notice-error"><p>' + esc((res.error && res.error.message) || t('error_generic')) + '</p></div>';
				return;
			}
			state.account = res.data;
			renderShell();
			show('overview');
		});
	}

	function renderShell() {
		var a = state.account;
		var tabs = [['overview', 'tab_overview'], ['leads', 'tab_leads'], ['appointments', 'tab_appointments'], ['conversations', 'tab_conversations'], ['doctors', 'tab_doctors'], ['business', 'tab_business']];
		var nav = tabs.map(function (x) { return '<a href="#" class="nav-tab" data-tab="' + x[0] + '">' + esc(t(x[1])) + '</a>'; }).join('');
		var sub = a.expires_at ? '<span class="ayla-sub">' + esc(t('subscription')) + ': ' + esc(t('expires_on')) + ' ' + esc(a.expires_at) + (a.days_left !== null ? ' (' + esc(a.days_left) + ' ' + esc(t('days_left')) + ')' : '') + '</span>' : '';
		root.innerHTML = '<div class="ayla-top"><strong>' + esc(a.name) + '</strong> ' + sub + '</div><h2 class="nav-tab-wrapper">' + nav + '</h2><div id="ayla-view"></div>';
		root.addEventListener('click', function (e) {
			var tab = e.target.closest('[data-tab]');
			if (tab) { e.preventDefault(); show(tab.getAttribute('data-tab')); }
		});
	}

	function show(tab) {
		state.tab = tab;
		Array.prototype.forEach.call(root.querySelectorAll('.nav-tab'), function (n) {
			n.classList.toggle('nav-tab-active', n.getAttribute('data-tab') === tab);
		});
		var view = el('ayla-view');
		view.innerHTML = '<p>' + esc(t('loading')) + '</p>';
		var renderers = { overview: overview, leads: leads, appointments: appointments, conversations: conversations, doctors: doctors, business: business };
		renderers[tab](view);
	}

	function fail(view, res) {
		view.innerHTML = '<div class="notice notice-error"><p>' + esc((res.error && res.error.message) || t('error_generic')) + '</p></div>';
	}

	/* ---------- Overview ---------- */

	function kpi(label, value) {
		return '<div class="ayla-kpi"><span>' + esc(label) + '</span><b>' + esc(value) + '</b></div>';
	}

	function overview(view) {
		api('dashboard/stats', 'GET', null, { days: 30 }).then(function (res) {
			if (!res.ok) { return fail(view, res); }
			var s = res.data;
			var hours = s.busiest_hours || [];
			var max = Math.max.apply(null, hours.concat([1]));
			var bars = hours.map(function (n, h) {
				return '<div class="ayla-bar" title="' + h + ':00 - ' + n + '"><i style="height:' + Math.round((n / max) * 100) + '%"></i><small>' + h + '</small></div>';
			}).join('');
			var byDoctor = (s.appointments.by_doctor || []).map(function (d) {
				return '<tr><td>' + esc(d.doctor) + '</td><td>' + esc(d.total) + '</td></tr>';
			}).join('');

			view.innerHTML =
				'<p class="ayla-sub">' + esc(t('period_30')) + '</p>' +
				'<div class="ayla-kpis">' +
				kpi(t('k_conversations'), s.conversations.total) +
				kpi(t('k_needs_human'), s.conversations.needs_human) +
				kpi(t('k_messages_in'), s.messages.received) +
				kpi(t('k_messages_out'), s.messages.sent) +
				kpi(t('k_new_leads'), s.leads.new) +
				kpi(t('k_hot_leads'), s.leads.hot) +
				kpi(t('k_conversion'), s.leads.conversion_rate + '%') +
				kpi(t('k_upcoming'), s.appointments.upcoming) +
				kpi(t('k_no_shows'), s.appointments.no_shows) +
				kpi(t('k_reminders'), s.reminders.sent) +
				kpi(t('k_response'), s.avg_response_seconds === null ? '-' : s.avg_response_seconds) +
				'</div>' +
				'<div class="ayla-cols"><div class="ayla-card"><h3>' + esc(t('by_doctor')) + '</h3>' +
				(byDoctor ? '<table class="widefat striped"><tbody>' + byDoctor + '</tbody></table>' : '<p class="ayla-empty">' + esc(t('empty')) + '</p>') +
				'</div><div class="ayla-card"><h3>' + esc(t('busiest_hours')) + '</h3><div class="ayla-bars">' + bars + '</div></div></div>';
		});
	}

	/* ---------- Leads / Appointments ---------- */

	function leads(view, page) {
		api('leads', 'GET', null, { page: page || 1 }).then(function (res) {
			if (!res.ok) { return fail(view, res); }
			var rows = res.data.items.map(function (l) {
				return '<tr><td>' + esc(l.name || '-') + '</td><td dir="ltr">+' + esc(l.phone) + '</td><td>' + esc(l.lead_score) + '</td><td>' + esc(l.status) + '</td><td>' + esc(l.last_message || '') + '</td></tr>';
			});
			view.innerHTML = table([t('name'), t('phone'), t('score'), t('status'), t('last_message')], rows) + pager(res.data, 'leads');
			bindPager(view, function (p) { leads(view, p); });
		});
	}

	function appointments(view, page) {
		api('appointments', 'GET', null, { page: page || 1, order: 'desc' }).then(function (res) {
			if (!res.ok) { return fail(view, res); }
			var rows = res.data.items.map(function (a) {
				return '<tr><td>' + esc(a.date) + '</td><td>' + esc(a.time) + '</td><td>' + esc(a.patient || '-') + '</td><td>' + esc(a.doctor || '-') + '</td><td>' + esc(a.service || '-') + '</td><td>' + esc(a.status) + '</td></tr>';
			});
			view.innerHTML = table([t('date'), t('time'), t('patient'), t('doctor'), t('service'), t('status')], rows) + pager(res.data, 'appointments');
			bindPager(view, function (p) { appointments(view, p); });
		});
	}

	function bindPager(view, fn) {
		Array.prototype.forEach.call(view.querySelectorAll('[data-pager]'), function (b) {
			b.addEventListener('click', function () { fn(parseInt(b.getAttribute('data-page'), 10)); });
		});
	}

	/* ---------- Conversations (read-only, plus "resume") ---------- */

	function conversations(view, page) {
		api('conversations', 'GET', null, { page: page || 1 }).then(function (res) {
			if (!res.ok) { return fail(view, res); }
			var rows = res.data.items.map(function (c) {
				var badge = c.needs_human ? ' <span class="ayla-badge">' + esc(t('needs_human')) + '</span>' : '';
				return '<tr><td>' + esc(c.patient || '-') + badge + '</td><td dir="ltr">+' + esc(c.phone) + '</td><td>' + esc(c.last_message || '') + '</td><td><button class="button" data-open="' + c.id + '">' + esc(t('open')) + '</button></td></tr>';
			});
			view.innerHTML = table([t('patient'), t('phone'), t('last_message'), ''], rows) + pager(res.data, 'conversations');
			bindPager(view, function (p) { conversations(view, p); });
			Array.prototype.forEach.call(view.querySelectorAll('[data-open]'), function (b) {
				b.addEventListener('click', function () { thread(view, parseInt(b.getAttribute('data-open'), 10)); });
			});
		});
	}

	function thread(view, id) {
		api('conversations/' + id + '/messages', 'GET').then(function (res) {
			if (!res.ok) { return fail(view, res); }
			var msgs = res.data.map(function (m) {
				return '<div class="ayla-msg ' + (m.direction === 'in' ? 'in' : 'out') + '">' + esc(m.body) + '<small>' + esc((m.at || '').replace('T', ' ').slice(0, 16)) + '</small></div>';
			}).join('');
			view.innerHTML = '<p><button class="button" id="ayla-back">' + esc(t('back')) + '</button> <button class="button button-primary" id="ayla-resume">' + esc(t('resume_ayla')) + '</button></p><div class="ayla-thread">' + (msgs || '<p class="ayla-empty">' + esc(t('empty')) + '</p>') + '</div>';
			el('ayla-back').addEventListener('click', function () { show('conversations'); });
			el('ayla-resume').addEventListener('click', function () {
				api('conversations/' + id + '/resume', 'POST', {}).then(function (r) {
					if (r.ok) { show('conversations'); } else { notice(view, (r.error && r.error.message) || t('error_generic')); }
				});
			});
		});
	}

	/* ---------- Hours editor (two ranges per day) ---------- */

	function hoursEditor(prefix, hours) {
		hours = hours || {};
		return ORDER.map(function (d) {
			var r = hours[d] || [];
			var a = r[0] || ['', ''];
			var b = r[1] || ['', ''];
			return '<div class="ayla-hours-row"><span>' + esc(t(d)) + '</span>' +
				'<input type="time" data-h="' + prefix + d + '-0-0" value="' + esc(a[0]) + '"> - <input type="time" data-h="' + prefix + d + '-0-1" value="' + esc(a[1]) + '">' +
				' &nbsp; <input type="time" data-h="' + prefix + d + '-1-0" value="' + esc(b[0]) + '"> - <input type="time" data-h="' + prefix + d + '-1-1" value="' + esc(b[1]) + '"></div>';
		}).join('');
	}

	function readHours(scope, prefix) {
		var out = {};
		ORDER.forEach(function (d) {
			var ranges = [];
			[0, 1].forEach(function (i) {
				var s = scope.querySelector('[data-h="' + prefix + d + '-' + i + '-0"]').value;
				var e = scope.querySelector('[data-h="' + prefix + d + '-' + i + '-1"]').value;
				if (s && e) { ranges.push([s, e]); }
			});
			if (ranges.length) { out[d] = ranges; }
		});
		return out;
	}

	/* ---------- Doctors ---------- */

	function doctors(view) {
		api('doctors', 'GET').then(function (res) {
			if (!res.ok) { return fail(view, res); }
			var d = res.data;
			var rows = d.items.map(function (x) {
				return '<tr><td>' + esc(x.name) + '</td><td>' + esc(x.specialty || '-') + '</td><td>' + esc(x.appointment_duration) + '</td><td>' + (x.is_active ? '&#10003;' : '-') + '</td>' +
					'<td><button class="button" data-edit="' + x.id + '">' + esc(t('edit')) + '</button> <button class="button" data-del="' + x.id + '">' + esc(t('remove')) + '</button></td></tr>';
			});
			view.innerHTML = '<p><strong>' + esc(t('doctors_count')) + ': ' + esc(d.count) + ' ' + esc(t('of')) + ' ' + esc(d.limit) + '</strong> ' +
				'<button class="button button-primary" id="ayla-add">' + esc(t('add_doctor')) + '</button></p>' +
				table([t('name'), t('specialty'), t('duration_min'), t('active'), ''], rows) + '<div id="ayla-form"></div>';

			el('ayla-add').addEventListener('click', function () { doctorForm(view, null); });
			Array.prototype.forEach.call(view.querySelectorAll('[data-edit]'), function (b) {
				b.addEventListener('click', function () {
					var id = parseInt(b.getAttribute('data-edit'), 10);
					doctorForm(view, d.items.filter(function (i) { return i.id === id; })[0]);
				});
			});
			Array.prototype.forEach.call(view.querySelectorAll('[data-del]'), function (b) {
				b.addEventListener('click', function () {
					if (!window.confirm(t('confirm_remove'))) { return; }
					api('doctors/' + b.getAttribute('data-del'), 'DELETE').then(function (r) {
						if (r.ok) { doctors(view); } else { notice(view, (r.error && r.error.message) || t('error_generic')); }
					});
				});
			});
		});
	}

	function doctorForm(view, doc) {
		doc = doc || { name: '', specialty: '', appointment_duration: 30, buffer_minutes: 0, is_active: true, working_hours: {} };
		var box = el('ayla-form');
		box.innerHTML = '<div class="ayla-card"><h3>' + esc(doc.id ? t('edit') : t('add_doctor')) + '</h3>' +
			'<p><label>' + esc(t('name')) + '<br><input type="text" id="d-name" class="regular-text" value="' + esc(doc.name) + '"></label></p>' +
			'<p><label>' + esc(t('specialty')) + '<br><input type="text" id="d-spec" class="regular-text" value="' + esc(doc.specialty || '') + '"></label></p>' +
			'<p><label>' + esc(t('duration_min')) + '<br><input type="number" id="d-dur" min="5" max="480" value="' + esc(doc.appointment_duration) + '"></label> ' +
			'<label>' + esc(t('buffer_min')) + '<br><input type="number" id="d-buf" min="0" max="120" value="' + esc(doc.buffer_minutes) + '"></label></p>' +
			'<p><label><input type="checkbox" id="d-active"' + (doc.is_active ? ' checked' : '') + '> ' + esc(t('active')) + '</label></p>' +
			'<h4>' + esc(t('working_hours')) + '</h4>' + hoursEditor('d', doc.working_hours) +
			'<p><button class="button button-primary" id="d-save">' + esc(t('save')) + '</button> <button class="button" id="d-cancel">' + esc(t('cancel')) + '</button></p></div>';

		el('d-cancel').addEventListener('click', function () { box.innerHTML = ''; });
		el('d-save').addEventListener('click', function () {
			var payload = {
				name: el('d-name').value, specialty: el('d-spec').value,
				appointment_duration: parseInt(el('d-dur').value, 10) || 30,
				buffer_minutes: parseInt(el('d-buf').value, 10) || 0,
				is_active: el('d-active').checked, working_hours: readHours(box, 'd')
			};
			api(doc.id ? 'doctors/' + doc.id : 'doctors', doc.id ? 'PUT' : 'POST', payload).then(function (r) {
				if (r.ok) { doctors(view); } else { notice(box, (r.error && r.error.message) || t('error_generic')); }
			});
		});
	}

	/* ---------- Business info, services, FAQs ---------- */

	function business(view) {
		api('account', 'GET').then(function (res) {
			if (!res.ok) { return fail(view, res); }
			var a = res.data;
			var c = a.clinic || {};
			view.innerHTML =
				'<div class="ayla-card"><h3>' + esc(t('clinic_info')) + '</h3>' +
				field('b-address', t('address'), c.address) + field('b-phone', t('phone'), c.phone) + field('b-email', t('email'), c.email) +
				field('b-map', t('map_url'), c.map_url) +
				'<p><label>' + esc(t('about')) + '<br><textarea id="b-about" rows="3" class="large-text">' + esc(c.about || '') + '</textarea></label></p>' +
				field('b-staff', t('staff_number'), a.staff_alert_number) + field('b-emerg', t('emergency_contact'), a.emergency_contact) +
				'<p><label>' + esc(t('days_off')) + '<br><textarea id="b-off" rows="3" class="large-text" dir="ltr">' + esc((a.days_off || []).join('\n')) + '</textarea></label></p>' +
				'<h4>' + esc(t('working_hours')) + '</h4>' + hoursEditor('b', a.hours) +
				'<p><button class="button button-primary" id="b-save">' + esc(t('save')) + '</button></p></div>' +
				'<div class="ayla-card" id="ayla-services"></div><div class="ayla-card" id="ayla-faqs"></div>';

			el('b-save').addEventListener('click', function () {
				var off = el('b-off').value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
				api('account', 'PUT', {
					clinic: { address: el('b-address').value, phone: el('b-phone').value, email: el('b-email').value, map_url: el('b-map').value, about: el('b-about').value },
					staff_alert_number: el('b-staff').value, emergency_contact: el('b-emerg').value,
					days_off: off, hours: readHours(view, 'b')
				}).then(function (r) {
					notice(view, r.ok ? t('saved') : ((r.error && r.error.message) || t('error_generic')), r.ok ? 'success' : 'error');
				});
			});

			listServices(a.locks && a.locks.prices);
			listFaqs();
		});
	}

	function field(id, label, value) {
		return '<p><label>' + esc(label) + '<br><input type="text" id="' + id + '" class="regular-text" value="' + esc(value || '') + '"></label></p>';
	}

	function listServices(locked) {
		var box = el('ayla-services');
		api('services', 'GET').then(function (res) {
			if (!res.ok) { return fail(box, res); }
			var rows = res.data.items.map(function (s) {
				return '<tr><td>' + esc(s.name) + '</td><td>' + esc(s.price === null ? '-' : s.price) + ' ' + esc(s.currency || '') + '</td><td>' + esc(s.duration_minutes || '-') + '</td>' +
					'<td>' + (locked ? '' : '<button class="button" data-sdel="' + s.id + '">' + esc(t('remove')) + '</button>') + '</td></tr>';
			});
			var form = locked ? '<p class="ayla-sub">' + esc(t('prices_locked')) + '</p>' :
				'<p><input type="text" id="s-name" placeholder="' + esc(t('name')) + '"> <input type="number" step="0.01" min="0" id="s-price" placeholder="' + esc(t('price')) + '" style="width:110px"> ' +
				'<input type="text" id="s-cur" maxlength="3" placeholder="' + esc(t('currency')) + '" style="width:70px"> <input type="number" min="5" id="s-dur" placeholder="' + esc(t('duration_min')) + '" style="width:130px"> ' +
				'<button class="button button-primary" id="s-add">' + esc(t('add_service')) + '</button></p>';
			box.innerHTML = '<h3>' + esc(t('services_prices')) + '</h3>' + table([t('name'), t('price'), t('duration_min'), ''], rows) + form;

			if (!locked) {
				el('s-add').addEventListener('click', function () {
					var price = el('s-price').value;
					api('services', 'POST', {
						name: el('s-name').value, price: price === '' ? null : price, currency: el('s-cur').value || null,
						duration_minutes: el('s-dur').value ? parseInt(el('s-dur').value, 10) : null
					}).then(function (r) { if (r.ok) { listServices(locked); } else { notice(box, (r.error && r.error.message) || t('error_generic')); } });
				});
				Array.prototype.forEach.call(box.querySelectorAll('[data-sdel]'), function (b) {
					b.addEventListener('click', function () {
						if (!window.confirm(t('confirm_remove'))) { return; }
						api('services/' + b.getAttribute('data-sdel'), 'DELETE').then(function () { listServices(locked); });
					});
				});
			}
		});
	}

	function listFaqs() {
		var box = el('ayla-faqs');
		api('faqs', 'GET').then(function (res) {
			if (!res.ok) { return fail(box, res); }
			var rows = res.data.items.map(function (f) {
				return '<tr><td>' + esc(f.question) + '</td><td>' + esc(f.answer) + '</td><td><button class="button" data-fdel="' + f.id + '">' + esc(t('remove')) + '</button></td></tr>';
			});
			box.innerHTML = '<h3>' + esc(t('faqs')) + '</h3>' + table([t('question'), t('answer'), ''], rows) +
				'<p><input type="text" id="f-q" class="regular-text" placeholder="' + esc(t('question')) + '"></p>' +
				'<p><textarea id="f-a" rows="2" class="large-text" placeholder="' + esc(t('answer')) + '"></textarea></p>' +
				'<p><button class="button button-primary" id="f-add">' + esc(t('add_faq')) + '</button></p>';

			el('f-add').addEventListener('click', function () {
				api('faqs', 'POST', { question: el('f-q').value, answer: el('f-a').value }).then(function (r) {
					if (r.ok) { listFaqs(); } else { notice(box, (r.error && r.error.message) || t('error_generic')); }
				});
			});
			Array.prototype.forEach.call(box.querySelectorAll('[data-fdel]'), function (b) {
				b.addEventListener('click', function () {
					if (!window.confirm(t('confirm_remove'))) { return; }
					api('faqs/' + b.getAttribute('data-fdel'), 'DELETE').then(function () { listFaqs(); });
				});
			});
		});
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();

/**
 * MaintenanceCheck web app (Check-family, no build step).
 *
 * Architecture: one bootstrap reads the page shell dataset
 * (#app-content[data-mn-*]) and dispatches to a page module. All rendering
 * uses DOM construction (never innerHTML with user data). All mutations go
 * through api() which sends the CSRF request token and normalises the
 * SPEC §7.1 error envelope into ApiError objects.
 *
 * Pure helpers (interval math, bucket labels, status meta) are exposed on
 * window.MnApp for the Node contract tests.
 */
(function () {
	'use strict';

	var APP = 'maintenancecheck';

	function tr(text, vars) {
		if (typeof window !== 'undefined' && typeof window.t === 'function') {
			return window.t(APP, text, vars);
		}
		// Node test environment: substitute placeholders only.
		var out = text;
		if (vars) {
			Object.keys(vars).forEach(function (k) {
				out = out.replace('{' + k + '}', String(vars[k]));
			});
		}
		return out;
	}

	// ── Pure date/interval helpers (mirror of IntervalCalculator, S2) ──

	function isValidYmd(value) {
		if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
			return false;
		}
		var y = Number(value.slice(0, 4));
		var m = Number(value.slice(5, 7));
		var d = Number(value.slice(8, 10));
		if (m < 1 || m > 12 || d < 1) {
			return false;
		}
		return d <= daysInMonth(y, m);
	}

	function daysInMonth(year, month) {
		return new Date(Date.UTC(year, month, 0)).getUTCDate();
	}

	function pad2(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function ymdFromParts(y, m, d) {
		return y + '-' + pad2(m) + '-' + pad2(d);
	}

	/**
	 * Clamped calendar math (S2): month/year arithmetic clamps to the last
	 * day of the target month (Jan 31 + 1 month = Feb 28/29).
	 */
	function addInterval(ymd, unit, count) {
		if (!isValidYmd(ymd)) {
			return null;
		}
		var y = Number(ymd.slice(0, 4));
		var m = Number(ymd.slice(5, 7));
		var d = Number(ymd.slice(8, 10));
		if (unit === 'day' || unit === 'week') {
			var days = unit === 'week' ? count * 7 : count;
			var date = new Date(Date.UTC(y, m - 1, d));
			date.setUTCDate(date.getUTCDate() + days);
			return ymdFromParts(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
		}
		if (unit === 'month' || unit === 'year') {
			var totalMonths = (unit === 'year' ? count * 12 : count) + (y * 12 + (m - 1));
			var ty = Math.floor(totalMonths / 12);
			var tm = (totalMonths % 12) + 1;
			var td = Math.min(d, daysInMonth(ty, tm));
			return ymdFromParts(ty, tm, td);
		}
		return null;
	}

	/**
	 * S1: prefer the server calendar date (data-mn-server-today / due board
	 * today_date). Browser local date is only a last-resort fallback so
	 * Node contract tests and offline shells still work.
	 */
	var serverTodayYmd = null;

	function setServerToday(ymd) {
		if (isValidYmd(ymd)) {
			serverTodayYmd = ymd;
		}
	}

	function todayYmd() {
		if (serverTodayYmd) {
			return serverTodayYmd;
		}
		var now = new Date();
		return ymdFromParts(now.getFullYear(), now.getMonth() + 1, now.getDate());
	}

	function formatDate(ymd, lang) {
		if (!isValidYmd(ymd)) {
			return ymd == null ? '' : String(ymd);
		}
		try {
			var date = new Date(Date.UTC(
				Number(ymd.slice(0, 4)),
				Number(ymd.slice(5, 7)) - 1,
				Number(ymd.slice(8, 10))
			));
			return new Intl.DateTimeFormat(lang || undefined, {
				year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC'
			}).format(date);
		} catch (e) {
			return ymd;
		}
	}

	function statusMeta(status) {
		switch (status) {
			case 'scheduled': return { label: tr('Scheduled'), badge: 'mn-badge--scheduled', icon: 'calendar' };
			case 'done': return { label: tr('Done'), badge: 'mn-badge--done', icon: 'check' };
			case 'skipped': return { label: tr('Skipped'), badge: 'mn-badge--skipped', icon: 'skip' };
			case 'cancelled': return { label: tr('Cancelled'), badge: 'mn-badge--cancelled', icon: 'x' };
			case 'draft': return { label: tr('Draft'), badge: 'mn-badge--neutral', icon: 'calendar' };
			case 'planned': return { label: tr('Planned'), badge: 'mn-badge--scheduled', icon: 'calendar' };
			case 'ready': return { label: tr('Ready'), badge: 'mn-badge--today', icon: 'clock' };
			case 'in_progress': return { label: tr('In progress'), badge: 'mn-badge--scheduled', icon: 'clock' };
			case 'blocked': return { label: tr('Blocked'), badge: 'mn-badge--overdue', icon: 'alert-triangle' };
			default: return { label: String(status), badge: 'mn-badge--neutral', icon: null };
		}
	}

	function intervalLabel(unit, count) {
		var n = Number(count);
		switch (unit) {
			case 'day': return n === 1 ? tr('Every day') : tr('Every {n} days', { n: n });
			case 'week': return n === 1 ? tr('Every week') : tr('Every {n} weeks', { n: n });
			case 'month': return n === 1 ? tr('Every month') : tr('Every {n} months', { n: n });
			case 'year': return n === 1 ? tr('Every year') : tr('Every {n} years', { n: n });
			default: return '';
		}
	}

	function bucketMeta(key) {
		switch (key) {
			// A4: icon name is part of the contract — never colour alone.
			case 'overdue': return { title: tr('Overdue'), badge: 'mn-badge--overdue', icon: 'alert-triangle' };
			case 'today': return { title: tr('Due today'), badge: 'mn-badge--today', icon: 'clock' };
			case 'next7': return { title: tr('Next 7 days'), badge: 'mn-badge--scheduled', icon: 'calendar' };
			case 'later': return { title: tr('Later (up to 30 days)'), badge: 'mn-badge--neutral', icon: 'calendar' };
			default: return { title: key, badge: 'mn-badge--neutral', icon: null };
		}
	}

	/**
	 * A4 status / bucket badge: text label + one non-colour cue (icon OR dot).
	 * Bachus: never stack dot + icon — that is visual noise for toddlers and auditors.
	 */
	function statusBadge(label, badgeClass, iconName) {
		// Node contract tests have no DOM — return a plain descriptor instead.
		if (typeof document === 'undefined') {
			return { label: label, badge: badgeClass || '', icon: iconName || null };
		}
		var kids = [];
		var hasIcon = !!(iconName && ICONS[iconName]);
		if (hasIcon) {
			kids.push(svgIcon(iconName));
		} else {
			kids.push(el('span', { class: 'mn-badge__dot', 'aria-hidden': 'true' }));
		}
		kids.push(document.createTextNode(label));
		return el('span', { class: 'mn-badge' + (badgeClass ? ' ' + badgeClass : '') }, kids);
	}

	/**
	 * Human maint-type label — never bilingual "EN / DE" catalog leftovers.
	 * @param {{maintTypeName?: string, isInspection?: boolean}|null|undefined} visit
	 */
	function displayMaintTypeName(visit) {
		visit = visit || {};
		var raw = String(visit.maintTypeName || '').trim();
		if (visit.isInspection || raw === 'Inspection / Prüfung' || raw === 'Prüfung / Inspection') {
			return tr('Inspection');
		}
		var slash = raw.indexOf(' / ');
		if (slash > 0) {
			// Prefer the left segment (canonical EN seed); drop the slash twin.
			return raw.slice(0, slash).trim() || raw;
		}
		return raw || null;
	}

	/**
	 * Visits list primary title — equipment first; never "X — X" when labels match.
	 * @param {{customerName?: string, equipmentLabel?: string}|null|undefined} visit
	 */
	function visitSubjectTitle(visit) {
		visit = visit || {};
		var customer = String(visit.customerName || '').trim();
		var equipment = String(visit.equipmentLabel || '').trim();
		if (equipment) {
			return equipment;
		}
		if (customer) {
			return customer;
		}
		return tr('Visit');
	}

	/**
	 * Visits list sub-line: customer (when distinct) · type · optional extras.
	 * @param {{customerName?: string, equipmentLabel?: string, maintTypeName?: string, isInspection?: boolean}|null|undefined} visit
	 * @param {string[]} [extraParts]
	 */
	function visitSubjectSub(visit, extraParts) {
		visit = visit || {};
		var parts = [];
		var customer = String(visit.customerName || '').trim();
		var equipment = String(visit.equipmentLabel || '').trim();
		if (customer && customer !== equipment) {
			parts.push(customer);
		}
		var typeName = displayMaintTypeName(visit);
		if (typeName) {
			parts.push(typeName);
		}
		if (Array.isArray(extraParts)) {
			for (var i = 0; i < extraParts.length; i++) {
				if (extraParts[i]) {
					parts.push(String(extraParts[i]));
				}
			}
		}
		return parts.length ? parts.join(' · ') : null;
	}

	/**
	 * Normalize column defs for design-system data tables (§3.7).
	 * Pure — safe for Node contract / mutation tests.
	 *
	 * @param {unknown} columns
	 * @returns {Array<{id: string, label: string, className: string, actions: boolean}>}
	 */
	function normalizeTableColumns(columns) {
		if (!Array.isArray(columns)) {
			return [];
		}
		var out = [];
		for (var i = 0; i < columns.length; i++) {
			var col = columns[i];
			if (!col || typeof col !== 'object') {
				continue;
			}
			var id = typeof col.id === 'string' ? col.id.trim() : '';
			if (!id && typeof col.key === 'string') {
				id = col.key.trim();
			}
			var label = typeof col.label === 'string' ? col.label.trim() : '';
			if (!id || !label) {
				continue;
			}
			out.push({
				id: id,
				label: label,
				className: typeof col.className === 'string' ? col.className : '',
				actions: !!col.actions,
			});
		}
		return out;
	}

	/** Empty / missing cell display for tables (em dash, never blank). */
	function tableCellText(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		var text = String(value).trim();
		return text === '' ? '—' : text;
	}

	/**
	 * Pure table model for design-system §3.7 lists (Node-safe).
	 * Mirrors Check-family tableOrCards column contract without touching the DOM.
	 *
	 * @param {unknown} columns
	 * @param {unknown} rows
	 * @returns {{columns: Array<{id: string, label: string, className: string, actions: boolean}>, rowCount: number}}
	 */
	function buildTableModel(columns, rows) {
		return {
			columns: normalizeTableColumns(columns),
			rowCount: Array.isArray(rows) ? rows.length : 0,
		};
	}

	// ── Export pure helpers for Node contract tests ─────────────────────

	var MnApp = {
		isValidYmd: isValidYmd,
		addInterval: addInterval,
		formatDate: formatDate,
		statusMeta: statusMeta,
		bucketMeta: bucketMeta,
		intervalLabel: intervalLabel,
		statusBadge: statusBadge,
		displayMaintTypeName: displayMaintTypeName,
		visitSubjectTitle: visitSubjectTitle,
		visitSubjectSub: visitSubjectSub,
		todayYmd: todayYmd,
		setServerToday: setServerToday,
		normalizeTableColumns: normalizeTableColumns,
		tableCellText: tableCellText,
		buildTableModel: buildTableModel,
	};
	if (typeof window !== 'undefined') {
		window.MnApp = MnApp;
	}
	if (typeof globalThis !== 'undefined') {
		globalThis.MnApp = MnApp;
	}

	// Everything below needs a real DOM — bail in bare test sandboxes.
	if (typeof document === 'undefined' || typeof document.addEventListener !== 'function') {
		return;
	}

	// ── DOM helpers ─────────────────────────────────────────────────────

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (key) {
				var value = attrs[key];
				if (value === null || value === undefined || value === false) {
					return;
				}
				if (key === 'class') {
					node.className = value;
				} else if (key === 'text') {
					node.textContent = value;
				} else if (key === 'html') {
					// Only used with trusted, static icon markup.
					node.innerHTML = value;
				} else if (key === 'onClick') {
					node.addEventListener('click', value);
				} else if (key === 'onKeyDown') {
					node.addEventListener('keydown', value);
				} else if (key === 'onSubmit') {
					node.addEventListener('submit', value);
				} else if (key === 'onInput') {
					node.addEventListener('input', value);
				} else if (key === 'onChange') {
					node.addEventListener('change', value);
				} else if (value === true) {
					node.setAttribute(key, '');
				} else {
					node.setAttribute(key, String(value));
				}
			});
		}
		(children || []).forEach(function (child) {
			if (child === null || child === undefined || child === false) {
				return;
			}
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function clear(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	/** Primary cell: title (often a link) + optional muted sub-line. */
	function tableStack(titleNode, subText) {
		var kids = [
			typeof titleNode === 'string'
				? el('span', { class: 'mn-table-primary', text: titleNode })
				: titleNode,
		];
		if (subText) {
			kids.push(el('div', { class: 'mn-table-sub', text: subText }));
		}
		return el('div', { class: 'mn-table-stack' }, kids);
	}

	/**
	 * Catalog name cell: optional edit affordance (button link) + optional
	 * deactivated badge inline — never a dead Status column of em-dashes.
	 */
	function catalogNameCell(label, subText, options) {
		options = options || {};
		var titleKids = [];
		if (typeof options.onEdit === 'function') {
			titleKids.push(el('button', {
				type: 'button',
				class: 'mn-table-link',
				text: label,
				onClick: options.onEdit,
			}));
		} else {
			titleKids.push(el('span', { class: 'mn-table-primary', text: label }));
		}
		if (options.inactive) {
			titleKids.push(statusBadge(tr('Deactivated'), 'mn-badge--neutral'));
		}
		return tableStack(el('div', { class: 'mn-table-primary-row' }, titleKids), subText || null);
	}

	function tableLink(href, text, extraClass) {
		return el('a', {
			class: 'mn-table-link' + (extraClass ? ' ' + extraClass : ''),
			href: href,
			text: text,
		});
	}

	/**
	 * Design-system data table (§3.7) — Check-family / AZC parity.
	 * Markup: .mn-table-wrap.table-container > table.mn-table.table.table--hover.mn-table--responsive
	 * Columns: { id|key, label, className?, actions?, render?(row) }
	 * Options: { rowClass?(row), tableClass?, caption? }
	 */
	function tableOrCards(columns, rows, options) {
		options = options || {};
		var cols = [];
		var raw = Array.isArray(columns) ? columns : [];
		for (var i = 0; i < raw.length; i++) {
			var col = raw[i];
			if (!col || typeof col !== 'object') {
				continue;
			}
			var id = typeof col.id === 'string' ? col.id.trim() : '';
			if (!id && typeof col.key === 'string') {
				id = col.key.trim();
			}
			var label = typeof col.label === 'string' ? col.label.trim() : '';
			if (!id || !label) {
				continue;
			}
			cols.push({
				id: id,
				label: label,
				className: typeof col.className === 'string' ? col.className : '',
				actions: !!col.actions,
				render: typeof col.render === 'function' ? col.render : null,
				key: typeof col.key === 'string' ? col.key : id,
			});
		}

			var wrap = el('div', {
				class: 'mn-table-wrap table-container',
				tabindex: '0',
				role: 'region',
				'aria-label': options.caption ? String(options.caption) : tr('Data table'),
			});
		var tableClass = 'mn-table table table--hover mn-table--responsive';
		if (options.tableClass) {
			tableClass += ' ' + options.tableClass;
		}
		var table = el('table', { class: tableClass });
		if (options.caption) {
			table.appendChild(el('caption', { class: 'mn-sr-only', text: String(options.caption) }));
		}

		var headRow = el('tr');
		cols.forEach(function (c) {
			var thAttrs = { scope: 'col', text: c.label };
			if (c.actions) {
				thAttrs.class = 'mn-table-actions-col';
			} else if (c.className) {
				thAttrs.class = c.className;
			}
			headRow.appendChild(el('th', thAttrs));
		});
		table.appendChild(el('thead', {}, [headRow]));

		var tbody = el('tbody');
		(Array.isArray(rows) ? rows : []).forEach(function (row) {
			var trClass = '';
			if (typeof options.rowClass === 'function') {
				trClass = options.rowClass(row) || '';
			} else if (row && row.active === false) {
				trClass = 'mn-table__row--inactive';
			}
			var tr = el('tr', trClass ? { class: trClass } : null);
			cols.forEach(function (c) {
				var cellContent;
				if (c.render) {
					cellContent = c.render(row);
				} else if (row && Object.prototype.hasOwnProperty.call(row, c.key)) {
					cellContent = tableCellText(row[c.key]);
				} else {
					cellContent = '—';
				}

				var tdAttrs = { 'data-label': c.label };
				if (c.actions) {
					tdAttrs.class = 'mn-table-actions-col actions-cell';
				} else if (c.className) {
					tdAttrs.class = c.className;
				}

				var kids = [];
				if (cellContent === null || cellContent === undefined || cellContent === false) {
					kids.push(document.createTextNode('—'));
				} else if (typeof cellContent === 'string' || typeof cellContent === 'number') {
					kids.push(document.createTextNode(String(cellContent)));
				} else if (Array.isArray(cellContent)) {
					cellContent.forEach(function (n) {
						if (n === null || n === undefined || n === false) {
							return;
						}
						kids.push(typeof n === 'string' ? document.createTextNode(n) : n);
					});
					if (!kids.length) {
						kids.push(document.createTextNode('—'));
					}
				} else {
					kids.push(cellContent);
				}

				if (c.actions) {
					tr.appendChild(el('td', tdAttrs, [
						el('div', { class: 'mn-table-actions' }, kids),
					]));
				} else {
					tr.appendChild(el('td', tdAttrs, kids));
				}
			});
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	var ICONS = {
		plus: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--sm" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg>',
		check: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--sm" aria-hidden="true" focusable="false"><path d="m5 12 5 5L20 7"/></svg>',
		inbox: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--xl" aria-hidden="true" focusable="false"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/></svg>',
		x: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--sm" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>',
		'alert-triangle': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--sm" aria-hidden="true" focusable="false"><path d="m12 3 10 17H2Z"/><path d="M12 9v4M12 17h.01"/></svg>',
		clock: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--sm" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
		skip: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--sm" aria-hidden="true" focusable="false"><path d="m5 4 10 8-10 8V4Z"/><path d="M19 5v14"/></svg>',
		calendar: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--sm" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
	};

	function svgIcon(name) {
		var wrap = document.createElement('span');
		wrap.className = 'mn-badge__icon';
		wrap.setAttribute('aria-hidden', 'true');
		wrap.innerHTML = ICONS[name] || '';
		return wrap;
	}

	// ── API layer ───────────────────────────────────────────────────────

	function ApiError(status, code, message, details) {
		this.name = 'ApiError';
		this.status = status;
		this.code = code;
		this.message = message;
		this.details = details || [];
	}
	ApiError.prototype = Object.create(Error.prototype);

	function requestToken() {
		if (typeof window.OC !== 'undefined' && window.OC.requestToken) {
			return window.OC.requestToken;
		}
		var meta = document.querySelector('head meta[name="requesttoken"]');
		return meta ? meta.getAttribute('content') : '';
	}

	function api(method, url, body) {
		var options = {
			method: method,
			headers: {
				'Accept': 'application/json',
				'requesttoken': requestToken(),
			},
			credentials: 'same-origin',
		};
		if (body !== undefined) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(body);
		}
		return fetch(url, options).then(function (response) {
			return response.text().then(function (text) {
				var data = null;
				if (text) {
					try {
						data = JSON.parse(text);
					} catch (e) {
						data = null;
					}
				}
				if (response.ok) {
					return data;
				}
				var envelope = data && data.error ? data.error : {};
				throw new ApiError(
					response.status,
					envelope.code || 'unexpected_error',
					envelope.message || tr('Something went wrong. Please try again.'),
					envelope.details
				);
			});
		}, function () {
			throw new ApiError(0, 'network_error', tr('Could not reach the server. Check your connection and try again.'));
		});
	}

	/** Multipart upload (WO photos) — do not set Content-Type (browser sets boundary). */
	function apiUpload(url, formData) {
		return fetch(url, {
			method: 'POST',
			headers: {
				'Accept': 'application/json',
				'requesttoken': requestToken(),
			},
			credentials: 'same-origin',
			body: formData,
		}).then(function (response) {
			return response.text().then(function (text) {
				var data = null;
				if (text) {
					try { data = JSON.parse(text); } catch (e) { data = null; }
				}
				if (response.ok) {
					return data;
				}
				var envelope = data && data.error ? data.error : {};
				throw new ApiError(
					response.status,
					envelope.code || 'unexpected_error',
					envelope.message || tr('Something went wrong. Please try again.'),
					envelope.details
				);
			});
		}, function () {
			throw new ApiError(0, 'network_error', tr('Could not reach the server. Check your connection and try again.'));
		});
	}

	// ── Toasts (§11.2, A7) ─────────────────────────────────────────────

	function announce(message, assertive) {
		var region = document.getElementById(assertive ? 'mn-alert-region' : 'mn-live-region');
		if (region) {
			region.textContent = '';
			window.setTimeout(function () { region.textContent = message; }, 30);
		}
	}

	/** Announce list/filter result counts (polite live region). */
	function announceResults(total, emptyMessage) {
		var n = Number(total) || 0;
		if (n === 0) {
			announce(emptyMessage || tr('No results.'));
			return;
		}
		announce(tr('{n} results', { n: n }));
	}

	function toast(message, type, action) {
		var region = document.getElementById('mn-toast-region');
		if (!region) {
			return;
		}
		var kind = type || 'info';
		var azcKind = kind === 'error' ? 'error' : (kind === 'success' || kind === 'ok' ? 'success' : (kind === 'warning' ? 'warning' : 'info'));
		var contentKids = [el('p', { class: 'mn-toast__message', text: message })];
		if (action && action.label && typeof action.onClick === 'function') {
			contentKids.push(el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--primary mn-toast__action',
				text: action.label,
				onClick: function () {
					remove();
					action.onClick();
				},
			}));
		}
		var node = el('div', {
			class: 'toast mn-toast toast--' + azcKind + (type ? ' mn-toast--' + type : ''),
			role: kind === 'error' ? 'alert' : 'status',
		}, [
			el('div', { class: 'toast-content' }, contentKids),
			el('button', {
				type: 'button',
				class: 'mn-toast__close',
				'aria-label': tr('Dismiss'),
				html: ICONS.x,
				onClick: function () { remove(); },
			}),
		]);
		var timer = window.setTimeout(remove, action ? 8000 : (kind === 'error' ? 5000 : 3000));
		function remove() {
			window.clearTimeout(timer);
			if (node.parentNode) {
				node.parentNode.removeChild(node);
			}
		}
		region.appendChild(node);
		announce(message, type === 'error');
	}

	// ── Dialogs (A6: focus trap, Esc, focus return) ────────────────────

	function openDialog(options) {
		var previousFocus = document.activeElement;
		var overlay = el('div', { class: 'modal-backdrop mn-dialog-overlay' });
		document.body.style.overflow = 'hidden';
		['header', 'app-navigation', 'mn-main-content'].forEach(function (id) {
			var node = document.getElementById(id);
			if (node) node.setAttribute('inert', '');
		});
		var titleId = 'mn-dialog-title-' + Date.now();
		var dialog = el('div', {
			class: 'modal mn-dialog',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': titleId,
		});
		dialog.appendChild(el('h2', { class: 'mn-dialog__title', id: titleId, text: options.title }));
		if (options.text) {
			dialog.appendChild(el('p', { class: 'mn-dialog__text', text: options.text }));
		}
		var errorBox = el('div', { class: 'mn-dialog__error', role: 'alert', hidden: true });
		dialog.appendChild(errorBox);
		if (options.content) {
			dialog.appendChild(options.content);
		}
		var actionsRow = el('div', { class: 'mn-dialog__actions' });
		dialog.appendChild(actionsRow);
		overlay.appendChild(dialog);

		var closed = false;
		function close() {
			if (closed) {
				return;
			}
			closed = true;
			document.removeEventListener('keydown', onKeydown, true);
			if (overlay.parentNode) {
				overlay.parentNode.removeChild(overlay);
			}
			document.body.style.overflow = '';
			['header', 'app-navigation', 'mn-main-content'].forEach(function (id) {
				var node = document.getElementById(id);
				if (node) {
					node.removeAttribute('inert');
				}
			});
			if (previousFocus && typeof previousFocus.focus === 'function') {
				previousFocus.focus();
			}
		}

		function focusables() {
			return Array.prototype.filter.call(
				dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
				function (node) { return !node.disabled && node.offsetParent !== null; }
			);
		}

		function onKeydown(event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				close();
				return;
			}
			if (event.key !== 'Tab') {
				return;
			}
			var nodes = focusables();
			if (nodes.length === 0) {
				return;
			}
			var first = nodes[0];
			var last = nodes[nodes.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		}

		overlay.addEventListener('mousedown', function (event) {
			if (event.target === overlay) {
				close();
			}
		});
		document.addEventListener('keydown', onKeydown, true);

		var ctx = {
			close: close,
			dialog: dialog,
			setError: function (message) {
				if (message) {
					errorBox.textContent = message;
					errorBox.hidden = false;
				} else {
					errorBox.textContent = '';
					errorBox.hidden = true;
				}
			},
			/**
			 * While busy, every action button is disabled (no double-submit).
			 * On idle, each button restores its pre-busy disabled state, then
			 * optional onIdle re-applies live gates (e.g. S9 checkbox).
			 */
			setBusy: function (busy) {
				Array.prototype.forEach.call(actionsRow.querySelectorAll('button'), function (b) {
					if (busy) {
						if (b.dataset.mnWasDisabled === undefined) {
							b.dataset.mnWasDisabled = b.disabled ? '1' : '0';
						}
						b.disabled = true;
					} else {
						var wasDisabled = b.dataset.mnWasDisabled === '1';
						delete b.dataset.mnWasDisabled;
						b.disabled = wasDisabled;
					}
				});
				if (!busy && typeof options.onIdle === 'function') {
					options.onIdle(ctx);
				}
			},
		};

		(options.actions || []).forEach(function (action) {
			var button = el('button', {
				type: 'button',
				class: 'mn-btn ' + (action.variant || 'mn-btn--secondary'),
				text: action.label,
			});
			button.addEventListener('click', function () {
				action.onClick(ctx, button);
			});
			if (action.disabled) {
				button.disabled = true;
			}
			if (action.id) {
				button.id = action.id;
			}
			actionsRow.appendChild(button);
		});

		document.body.appendChild(overlay);
		var initial = options.initialFocus ? dialog.querySelector(options.initialFocus) : null;
		(initial || focusables()[0] || dialog).focus();
		return ctx;
	}

	// ── Form field helpers (A5) ─────────────────────────────────────────

	var fieldSeq = 0;

	function field(labelText, input, options) {
		options = options || {};
		fieldSeq += 1;
		// Prefer an author-supplied stable id (E2E / deep links); else allocate.
		var id = input.getAttribute('id') || ('mn-f-' + fieldSeq);
		var errorId = id + '-error';
		var hintId = id + '-hint';
		input.setAttribute('id', id);
		input.classList.add('mn-input');
		if (options.required) {
			input.setAttribute('required', '');
			input.setAttribute('aria-required', 'true');
		}
		var describedBy = [];
		var wrap = el('div', { class: 'mn-field' + (options.wide ? ' mn-field--wide' : '') });
		/* Visible “(required)” text — do not also add form-label--required (::after *) */
		wrap.appendChild(el('label', {
			class: 'mn-field__label form-label',
			for: id,
			text: options.required ? tr('{label} (required)', { label: labelText }) : labelText,
		}));
		wrap.appendChild(input);
		if (options.hint) {
			wrap.appendChild(el('p', { class: 'mn-field__hint form-help', id: hintId, text: options.hint }));
			describedBy.push(hintId);
		}
		var error = el('p', { class: 'mn-field__error form-error', id: errorId, role: 'alert', hidden: true });
		wrap.appendChild(error);
		if (describedBy.length) {
			input.setAttribute('aria-describedby', describedBy.join(' '));
		}
		wrap.mnSetError = function (message) {
			if (message) {
				error.textContent = message;
				error.hidden = false;
				input.setAttribute('aria-invalid', 'true');
				input.setAttribute('aria-describedby', describedBy.concat([errorId]).join(' '));
			} else {
				error.textContent = '';
				error.hidden = true;
				input.removeAttribute('aria-invalid');
				if (describedBy.length) {
					input.setAttribute('aria-describedby', describedBy.join(' '));
				} else {
					input.removeAttribute('aria-describedby');
				}
			}
		};
		wrap.mnInput = input;
		return wrap;
	}

	var FIELD_ERROR_TEXT = {
		name_required: function () { return tr('Please enter a name.'); },
		name_too_long: function () { return tr('This value is too long.'); },
		customer_no_too_long: function () { return tr('This value is too long.'); },
		invalid_country: function () { return tr('Use a two-letter country code, e.g. DE.'); },
		invalid_email: function () { return tr('This email address looks invalid.'); },
		label_required: function () { return tr('Please enter a label.'); },
		notes_too_long: function () { return tr('This text is too long.'); },
		invalid_code: function () { return tr('Use lowercase letters, digits and underscores only.'); },
		invalid_type: function () { return tr('This value has the wrong format.'); },
		too_long: function () { return tr('This value is too long.'); },
		required: function () { return tr('This field is required.'); },
		unknown_customer: function () { return tr('Unknown customer.'); },
		unknown_equip_type: function () { return tr('Unknown equipment type.'); },
		inactive_equip_type: function () { return tr('This equipment type is deactivated.'); },
		unknown_maint_type: function () { return tr('Unknown maintenance type.'); },
		inactive_maint_type: function () { return tr('This maintenance type is deactivated.'); },
		unknown_user: function () { return tr('This Nextcloud user does not exist.'); },
		unknown_group: function () { return tr('This Nextcloud group does not exist.'); },
	};

	function applyFieldErrors(fieldsByName, error) {
		var handled = false;
		Object.keys(fieldsByName).forEach(function (name) {
			if (fieldsByName[name].mnSetError) {
				fieldsByName[name].mnSetError(null);
			}
		});
		(error.details || []).forEach(function (detail) {
			var target = fieldsByName[detail.field];
			if (target && target.mnSetError) {
				var textFn = FIELD_ERROR_TEXT[detail.code];
				target.mnSetError(textFn ? textFn() : tr('This value is invalid.'));
				if (!handled) {
					target.mnInput.focus();
				}
				handled = true;
			}
		});
		return handled;
	}

	// ── Empty states / skeletons / pagination ─────────────────────────

	function emptyState(title, hint, action) {
		var box = el('div', { class: 'mn-empty' }, [
			el('span', { class: 'mn-empty__icon', html: ICONS.inbox, 'aria-hidden': 'true' }),
			el('p', { class: 'mn-empty__title', text: title }),
			hint ? el('p', { class: 'mn-empty__hint', text: hint }) : null,
			action || null,
		]);
		return box;
	}

	function skeleton(rows) {
		var box = el('div', { class: 'mn-skeleton', 'aria-hidden': 'true' });
		for (var i = 0; i < (rows || 3); i++) {
			box.appendChild(el('div', { class: 'mn-skeleton__bar' }));
		}
		return box;
	}

	function renderPagination(nav, envelope, onPage) {
		clear(nav);
		var total = envelope.total;
		var limit = envelope.limit;
		var offset = envelope.offset;
		if (total <= limit && offset === 0) {
			return;
		}
		var from = total === 0 ? 0 : offset + 1;
		var to = Math.min(offset + limit, total);
		var prev = el('button', {
			type: 'button',
			class: 'mn-btn mn-btn--tertiary mn-btn--compact',
			text: tr('Previous'),
		});
		prev.disabled = offset <= 0;
		prev.addEventListener('click', function () { onPage(Math.max(0, offset - limit)); });
		var next = el('button', {
			type: 'button',
			class: 'mn-btn mn-btn--tertiary mn-btn--compact',
			text: tr('Next'),
		});
		next.disabled = offset + limit >= total;
		next.addEventListener('click', function () { onPage(offset + limit); });
		nav.appendChild(prev);
		nav.appendChild(el('p', {
			class: 'mn-pagination__info',
			text: tr('{from}–{to} of {total}', { from: from, to: to, total: total }),
		}));
		nav.appendChild(next);
	}

	// ── Shared context ─────────────────────────────────────────────────

	var ctx = null;

	function buildContext() {
		var root = document.getElementById('app-content');
		if (!root || !root.dataset.mnPage) {
			return null;
		}
		var urls;
		try {
			urls = JSON.parse(root.dataset.mnUrls || '{}');
		} catch (e) {
			urls = { pages: {}, api: {} };
		}
		// S1: pin "today" to the server calendar date from the page shell.
		setServerToday(root.dataset.mnServerToday || null);
		return {
			root: root,
			page: root.dataset.mnPage,
			entityId: root.dataset.mnEntityId ? Number(root.dataset.mnEntityId) : null,
			currentUser: root.dataset.mnCurrentUser || '',
			isAppAdmin: root.dataset.mnIsAppAdmin === '1',
			isOffice: root.dataset.mnIsOffice === '1',
			isSystemAdmin: root.dataset.mnIsSystemAdmin === '1',
			mobileAppStatus: root.dataset.mnMobileAppStatus || 'coming_soon',
			serverToday: root.dataset.mnServerToday || null,
			lang: root.getAttribute('lang') || document.documentElement.lang || undefined,
			urls: urls,
		};
	}

	function apiUrl(name) {
		return ctx.urls.api[name];
	}

	function pageUrl(name) {
		return ctx.urls.pages[name];
	}

	function withQuery(url, params) {
		var search = new URLSearchParams();
		Object.keys(params).forEach(function (key) {
			var value = params[key];
			if (value !== null && value !== undefined && value !== '') {
				search.set(key, String(value));
			}
		});
		var qs = search.toString();
		return qs ? url + '?' + qs : url;
	}

	function fmt(ymd) {
		return formatDate(ymd, ctx.lang);
	}

	function debounce(fn, wait) {
		var timer = null;
		return function () {
			var args = arguments;
			window.clearTimeout(timer);
			timer = window.setTimeout(function () { fn.apply(null, args); }, wait);
		};
	}

	/**
	 * Accessible NC user combobox (SPEC §8.3). Fetches /api/users/search.
	 * Returns { root, getValue, setError, focus }.
	 */
	function attachUserPicker(options) {
		fieldSeq += 1;
		var base = 'mn-user-picker-' + fieldSeq;
		var selectedUid = '';
		var rows = [];
		var activeIdx = -1;
		var combo = el('input', {
			type: 'search',
			id: base + '-combo',
			class: 'mn-input mn-user-picker__input',
			autocomplete: 'off',
			role: 'combobox',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': base + '-list',
			'aria-haspopup': 'listbox',
			placeholder: options.placeholder || tr('Start typing a name…'),
		});
		var list = el('ul', {
			id: base + '-list',
			class: 'mn-user-picker__list',
			role: 'listbox',
			hidden: true,
		});
		var error = el('p', { class: 'mn-field__error', id: base + '-error', hidden: true });
		var hint = options.hint
			? el('p', { class: 'mn-field__hint', id: base + '-hint', text: options.hint })
			: null;
		if (hint) {
			combo.setAttribute('aria-describedby', base + '-hint');
		}
		var root = el('div', { class: 'mn-field mn-user-picker' }, [
			el('label', { class: 'mn-field__label', for: base + '-combo', text: options.label || tr('Nextcloud user') }),
			el('div', { class: 'mn-user-picker__wrap' }, [combo, list]),
			hint,
			error,
		]);

		function closeList() {
			list.hidden = true;
			combo.setAttribute('aria-expanded', 'false');
			activeIdx = -1;
		}

		function formatRow(row) {
			var dn = row.displayName || row.id;
			return dn + (row.id && dn !== row.id ? ' (' + row.id + ')' : '');
		}

		function applySelection(row) {
			selectedUid = row.id;
			combo.value = formatRow(row);
			closeList();
			root.dispatchEvent(new CustomEvent('mn-user-selected', {
				bubbles: true,
				detail: { uid: selectedUid, displayName: row.displayName || row.id, label: formatRow(row) },
			}));
			try {
				root.dispatchEvent(new Event('change', { bubbles: true }));
			} catch (e) {
				// IE-less environments always support Event
			}
		}

		function resolveLabel(uid) {
			if (!uid || !ctx.urls || !ctx.urls.api || !ctx.urls.api.usersSearch) {
				return;
			}
			api('GET', withQuery(apiUrl('usersSearch'), { q: uid, limit: '10' }))
				.then(function (payload) {
					if (selectedUid !== uid) {
						return;
					}
					var rowsFound = payload.data || [];
					var match = null;
					rowsFound.forEach(function (row) {
						if (row && row.id === uid) {
							match = row;
						}
					});
					if (match) {
						combo.value = formatRow(match);
					}
				})
				.catch(function () { /* best-effort label resolve */ });
		}

		function renderOptions() {
			clear(list);
			if (rows.length === 0) {
				list.appendChild(el('li', {
					class: 'mn-user-picker__empty',
					role: 'presentation',
					text: tr('No users match your search.'),
				}));
				return;
			}
			rows.forEach(function (row, i) {
				list.appendChild(el('li', {
					class: 'mn-user-picker__option' + (i === activeIdx ? ' is-active' : ''),
					role: 'option',
					id: base + '-opt-' + i,
					tabIndex: -1,
					text: formatRow(row),
					onMouseDown: function (ev) {
						ev.preventDefault();
						applySelection(row);
					},
				}));
			});
		}

		function openList() {
			if (!list.children.length) {
				return;
			}
			list.hidden = false;
			combo.setAttribute('aria-expanded', 'true');
		}

		var runSearch = debounce(function (q) {
			if (!ctx.urls || !ctx.urls.api || !ctx.urls.api.usersSearch) {
				return;
			}
			api('GET', withQuery(apiUrl('usersSearch'), { q: q, limit: '25' }))
				.then(function (payload) {
					rows = payload.data || [];
					activeIdx = rows.length ? 0 : -1;
					renderOptions();
					openList();
				})
				.catch(function () {
					rows = [];
					renderOptions();
					openList();
				});
		}, 220);

		combo.addEventListener('input', function () {
			selectedUid = '';
			var q = combo.value.trim();
			if (q.length === 0) {
				rows = [];
				clear(list);
				closeList();
				return;
			}
			runSearch(q);
		});

		combo.addEventListener('keydown', function (ev) {
			if (list.hidden) {
				return;
			}
			if (ev.key === 'ArrowDown') {
				ev.preventDefault();
				activeIdx = Math.min(rows.length - 1, activeIdx + 1);
				renderOptions();
				openList();
			} else if (ev.key === 'ArrowUp') {
				ev.preventDefault();
				activeIdx = Math.max(0, activeIdx - 1);
				renderOptions();
				openList();
			} else if (ev.key === 'Enter' && activeIdx >= 0 && rows[activeIdx]) {
				ev.preventDefault();
				applySelection(rows[activeIdx]);
			} else if (ev.key === 'Escape') {
				ev.preventDefault();
				closeList();
			}
		});

		combo.addEventListener('blur', function () {
			window.setTimeout(closeList, 120);
		});

		return {
			root: root,
			getValue: function () { return selectedUid; },
			setValue: function (uid) {
				selectedUid = uid ? String(uid) : '';
				combo.value = selectedUid;
				if (selectedUid) {
					resolveLabel(selectedUid);
				}
			},
			setError: function (message) {
				if (message) {
					error.textContent = message;
					error.hidden = false;
					combo.setAttribute('aria-invalid', 'true');
					combo.setAttribute('aria-describedby', (hint ? base + '-hint ' : '') + base + '-error');
				} else {
					error.textContent = '';
					error.hidden = true;
					combo.removeAttribute('aria-invalid');
					if (hint) {
						combo.setAttribute('aria-describedby', base + '-hint');
					} else {
						combo.removeAttribute('aria-describedby');
					}
				}
			},
			focus: function () { combo.focus(); },
		};
	}

	function handleGlobalError(error) {
		toast(error && error.message ? error.message : tr('Something went wrong. Please try again.'), 'error');
	}

	// ── Catalog helpers (selects) ──────────────────────────────────────

	function loadAllCatalog(url) {
		// Catalogs are small (S7 max 200); one page suffices for selects.
		return api('GET', withQuery(url, { limit: 200 })).then(function (envelope) {
			return envelope.data;
		});
	}

	function catalogSelect(types, selectedId, onlyActive) {
		var select = el('select', {});
		types.forEach(function (type) {
			if (onlyActive && !type.active && type.id !== selectedId) {
				return;
			}
			var option = el('option', { value: String(type.id), text: type.active ? type.name : tr('{name} (deactivated)', { name: type.name }) });
			if (type.id === selectedId) {
				option.selected = true;
			}
			select.appendChild(option);
		});
		return select;
	}

	// ── Visit action dialogs (shared by due board / visits / detail) ───

	/**
	 * Bachus happy path: one tap completes with server today + empty notes.
	 * Meter closing reading and backdated done_on stay on the details dialog.
	 */
	function quickCompleteVisit(visit, onDone, triggerBtn) {
		if (triggerBtn) {
			triggerBtn.disabled = true;
		}
		api('POST', apiUrl('visits') + '/' + visit.id + '/complete', {
			doneOn: todayYmd(),
			notes: null,
		}).then(function (result) {
			if (!result.planActive) {
				toast(tr('Visit completed. Plan inactive — no follow-up visit created.'), 'success');
			} else if (result.nextVisit) {
				toast(tr('Visit completed — next due {date}.', { date: fmt(result.nextVisit.dueOn) }), 'success');
			} else {
				toast(tr('Visit completed.'), 'success');
			}
			onDone();
		}).catch(function (error) {
			if (triggerBtn) {
				triggerBtn.disabled = false;
			}
			if (error.code === 'inspection_requires_work_order') {
				toast(tr('Inspections must be closed on a work order with result and inspector — not with Complete visit.'), 'error');
			} else if (error.code === 'visit_not_open') {
				toast(tr('This visit was already closed.'), 'error');
				onDone();
			} else if (error.code === 'invalid_done_on' || error.code === 'meter_not_monotonic') {
				completeDialog(visit, onDone);
			} else {
				toast(error.message || tr('Something went wrong. Please try again.'), 'error');
			}
		});
	}

	function quickSkipVisit(visit, onDone) {
		api('POST', apiUrl('visits') + '/' + visit.id + '/skip', { notes: null }).then(function (result) {
			toast(result.nextVisit
				? tr('Visit skipped — next due {date}.', { date: fmt(result.nextVisit.dueOn) })
				: tr('Visit skipped.'), 'success');
			onDone();
		}).catch(function (error) {
			if (error.code === 'inspection_requires_work_order') {
				toast(tr('Inspections must be closed on a work order with result and inspector — not by skipping the visit.'), 'error');
			} else if (error.code === 'visit_not_open') {
				toast(tr('This visit was already closed.'), 'error');
				onDone();
			} else {
				toast(error.message || tr('Something went wrong. Please try again.'), 'error');
			}
		});
	}

	function completeDialog(visit, onDone) {
		var doneOnInput = el('input', { type: 'date', value: todayYmd(), max: todayYmd() });
		var notesInput = el('textarea', { rows: '3' });
		var doneOnField = field(tr('Completed on'), doneOnInput, { required: true });
		var notesField = field(tr('Notes'), notesInput, { hint: tr('Optional — what was done, parts used, anything the office should know.') });
		var nextInfo = el('p', { class: 'mn-dialog__hint' });
		var needsMeter = visit.triggerKind === 'meter' || visit.triggerKind === 'either';
		var closingValueInput = el('input', { type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: visit.meterCode ? tr('Reading for {code}', { code: visit.meterCode }) : tr('Meter reading') });
		var closingValueField = field(tr('Closing meter reading (optional)'), closingValueInput, {
			hint: needsMeter
				? tr('Record the counter after the job. This does not create a new due visit by itself.')
				: tr('Optional. Only used when this plan is meter-based.'),
		});

		function refreshNext() {
			if (!visit.planActive) {
				nextInfo.className = 'mn-dialog__hint mn-dialog__hint--warning';
				nextInfo.textContent = tr('Plan inactive — no follow-up visit will be created.');
				return;
			}
			if (visit.triggerKind === 'meter') {
				nextInfo.textContent = tr('Meter plan — no calendar follow-up visit is created.');
				return;
			}
			var next = addInterval(doneOnInput.value, visit.intervalUnit, visit.intervalCount);
			nextInfo.textContent = next
				? tr('The next visit will be due on {date}.', { date: fmt(next) })
				: tr('Enter a valid date to see the next due date.');
		}
		doneOnInput.addEventListener('input', refreshNext);
		refreshNext();

		var contentChildren = [doneOnField, notesField];
		if (needsMeter) {
			contentChildren.push(closingValueField);
		}
		contentChildren.push(nextInfo);
		var content = el('div', {}, contentChildren);
		openDialog({
			title: tr('Complete visit'),
			text: tr('{customer} — {equipment}, {type}', {
				customer: visit.customerName, equipment: visit.equipmentLabel, type: displayMaintTypeName(visit) || '',
			}),
			content: content,
			initialFocus: 'input[type="date"]',
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Complete visit'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						var body = {
							doneOn: doneOnInput.value,
							notes: notesInput.value.trim() === '' ? null : notesInput.value,
						};
						var closingVal = closingValueInput.value.trim();
						if (needsMeter && closingVal !== '') {
							body.closingReading = {
								meterCode: visit.meterCode,
								value: closingVal,
							};
						}
						api('POST', apiUrl('visits') + '/' + visit.id + '/complete', body).then(function (result) {
							d.close();
							if (!result.planActive) {
								toast(tr('Visit completed. Plan inactive — no follow-up visit created.'), 'success');
							} else if (result.nextVisit) {
								toast(tr('Visit completed — next due {date}.', { date: fmt(result.nextVisit.dueOn) }), 'success');
							} else {
								toast(tr('Visit completed.'), 'success');
							}
							onDone();
						}).catch(function (error) {
							d.setBusy(false);
							if (error.code === 'invalid_done_on') {
								doneOnField.mnSetError(tr('The completion date must be a real date between 2000-01-01 and today.'));
								doneOnInput.focus();
							} else if (error.code === 'meter_not_monotonic') {
								closingValueField.mnSetError(error.message);
								closingValueInput.focus();
							} else if (error.code === 'inspection_requires_work_order') {
								d.setError(tr('Inspections must be closed on a work order with result and inspector — not with Complete visit.'));
							} else if (error.code === 'visit_not_open') {
								d.close();
								toast(tr('This visit was already closed.'), 'error');
								onDone();
							} else if (!applyFieldErrors({ doneOn: doneOnField, notes: notesField }, error)) {
								d.setError(error.message);
							}
						});
					},
				},
			],
		});
	}

	function skipDialog(visit, onDone) {
		var notesInput = el('textarea', { rows: '3' });
		var notesField = field(tr('Notes'), notesInput, { hint: tr('Optional — why is this visit skipped?') });
		var next = visit.planActive ? addInterval(todayYmd(), visit.intervalUnit, visit.intervalCount) : null;
		var hint = el('p', {
			class: 'mn-dialog__hint' + (visit.planActive ? '' : ' mn-dialog__hint--warning'),
			text: visit.planActive
				? tr('The next visit will be due on {date} (counted from today).', { date: fmt(next) })
				: tr('Plan inactive — no follow-up visit will be created.'),
		});
		openDialog({
			title: tr('Skip visit'),
			text: tr('{customer} — {equipment}, {type}', {
				customer: visit.customerName, equipment: visit.equipmentLabel, type: displayMaintTypeName(visit) || '',
			}),
			content: el('div', {}, [hint, notesField]),
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Skip visit'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						api('POST', apiUrl('visits') + '/' + visit.id + '/skip', {
							notes: notesInput.value.trim() === '' ? null : notesInput.value,
						}).then(function (result) {
							d.close();
							toast(result.nextVisit
								? tr('Visit skipped — next due {date}.', { date: fmt(result.nextVisit.dueOn) })
								: tr('Visit skipped.'), 'success');
							onDone();
						}).catch(function (error) {
							d.setBusy(false);
							if (error.code === 'inspection_requires_work_order') {
								d.setError(tr('Inspections must be closed on a work order with result and inspector — not by skipping the visit.'));
							} else if (error.code === 'visit_not_open') {
								d.close();
								toast(tr('This visit was already closed.'), 'error');
								onDone();
							} else {
								d.setError(error.message);
							}
						});
					},
				},
			],
		});
	}

	function rescheduleDialog(visit, onDone) {
		var dueOnInput = el('input', { type: 'date', value: visit.dueOn });
		var dueOnField = field(tr('New due date'), dueOnInput, { required: true });
		openDialog({
			title: tr('Reschedule visit'),
			text: tr('{customer} — {equipment}, {type}', {
				customer: visit.customerName, equipment: visit.equipmentLabel, type: displayMaintTypeName(visit) || '',
			}),
			content: el('div', {}, [dueOnField]),
			initialFocus: 'input[type="date"]',
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Save new date'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						api('PUT', apiUrl('visits') + '/' + visit.id, { dueOn: dueOnInput.value })
							.then(function () {
								d.close();
								toast(tr('Visit rescheduled to {date}.', { date: fmt(dueOnInput.value) }), 'success');
								onDone();
							})
							.catch(function (error) {
								d.setBusy(false);
								if (error.code === 'invalid_due_date') {
									dueOnField.mnSetError(tr('The due date must be between 2000-01-01 and ten years from today.'));
									dueOnInput.focus();
								} else if (error.code === 'visit_not_open') {
									d.close();
									toast(tr('This visit was already closed.'), 'error');
									onDone();
								} else {
									d.setError(error.message);
								}
							});
					},
				},
			],
		});
	}

	function cancelVisitDialog(visit, onDone) {
		openDialog({
			title: tr('Cancel visit'),
			text: tr('This ends the visit without a result. No follow-up visit is created — you can schedule one manually on the plan later.'),
			actions: [
				{ label: tr('Keep visit'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Cancel visit'),
					variant: 'mn-btn--danger',
					onClick: function (d) {
						d.setBusy(true);
						api('POST', apiUrl('visits') + '/' + visit.id + '/cancel', {})
							.then(function () {
								d.close();
								toast(tr('Visit cancelled.'), 'success');
								onDone();
							})
							.catch(function (error) {
								d.setBusy(false);
								if (error.code === 'visit_not_open') {
									d.close();
									toast(tr('This visit was already closed.'), 'error');
									onDone();
								} else {
									d.setError(error.message);
								}
							});
					},
				},
			],
		});
	}

	function assignDialog(visit, onDone) {
		var picker = attachUserPicker({
			label: tr('Technician'),
			placeholder: tr('Start typing a name…'),
			hint: tr('Search and pick a technician. Leave empty and save to remove the assignment. Never type a raw user id.'),
		});
		if (visit.assignedUid) {
			picker.setValue && picker.setValue(visit.assignedUid);
		}
		var warn = el('p', {
			class: 'mn-dialog__hint mn-dialog__hint--warning',
			hidden: 'true',
			role: 'status',
			text: tr('This user currently cannot open MaintenanceCheck (access restriction). You can still assign the visit — the warning is informational only.'),
		});

		var accessTimer = null;
		function refreshAccessWarning() {
			var value = picker.getValue();
			warn.hidden = true;
			if (value === '' || !ctx.urls || !ctx.urls.api || !ctx.urls.api.userAccess) {
				return;
			}
			api('GET', withQuery(ctx.urls.api.userAccess, { userId: value }))
				.then(function (result) {
					if (picker.getValue() !== value) {
						return;
					}
					if (result.exists && result.canUseApp === false) {
						warn.hidden = false;
					}
				})
				.catch(function () { /* preview is best-effort */ });
		}
		picker.root.addEventListener('mn-user-selected', refreshAccessWarning);
		picker.root.addEventListener('change', function () {
			window.clearTimeout(accessTimer);
			accessTimer = window.setTimeout(refreshAccessWarning, 300);
		});
		if (picker.getValue()) {
			refreshAccessWarning();
		}

		openDialog({
			title: tr('Assign visit'),
			text: tr('{customer} — {equipment}, {type}', {
				customer: visit.customerName, equipment: visit.equipmentLabel, type: displayMaintTypeName(visit) || '',
			}),
			content: el('div', {}, [picker.root, warn]),
			initialFocus: 'input[type="search"]',
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Save assignment'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						var value = picker.getValue();
						api('PUT', apiUrl('visits') + '/' + visit.id + '/assign', { userId: value === '' ? null : value })
							.then(function () {
								d.close();
								toast(value === '' ? tr('Assignment removed.') : tr('Visit assigned to {user}.', { user: value }), 'success');
								onDone();
							})
							.catch(function (error) {
								d.setBusy(false);
								if (error.code === 'unknown_user') {
									picker.setError(tr('This Nextcloud user does not exist.'));
									picker.focus();
								} else if (error.code === 'visit_not_open') {
									d.close();
									toast(tr('This visit was already closed.'), 'error');
									onDone();
								} else {
									d.setError(error.message);
								}
							});
					},
				},
			],
		});
	}

	function createWorkOrderFromVisit(visit, isInspection, onDone, body) {
		return api('POST', apiUrl('visits') + '/' + visit.id + '/work-orders', body)
			.then(function (wo) {
				toast(tr('Work order created from visit.'), 'success');
				if (wo && wo.id) {
					window.location.href = pageUrl('workOrders') + '/' + wo.id;
				} else {
					onDone();
				}
			});
	}

	function openCreateWorkOrderDialog(visit, isInspection, onDone, procedures) {
		var select = el('select', { class: 'mn-input form-select' });
		select.appendChild(el('option', { value: '', text: tr('Select a procedure…') }));
		procedures.forEach(function (proc) {
			select.appendChild(el('option', {
				value: String(proc.id),
				text: proc.title || proc.code || ('#' + proc.id),
			}));
		});
		var skipToggle = el('input', { type: 'checkbox', id: 'mn-visit-wo-skip' });
		var skipReason = el('textarea', {
			class: 'mn-input form-textarea',
			rows: '3',
			id: 'mn-visit-wo-skip-reason',
		});
		function syncSkip() {
			select.disabled = !!skipToggle.checked;
			skipReason.disabled = !skipToggle.checked;
		}
		skipToggle.addEventListener('change', syncSkip);
		syncSkip();
		openDialog({
			title: isInspection ? tr('Create inspection work order') : tr('Create work order'),
			content: el('div', { class: 'mn-form-grid mn-form-grid--single' }, [
				isInspection ? el('p', {
					class: 'mn-muted',
					text: tr('Inspections must be closed on a work order with result and inspector — not with Complete visit.'),
				}) : null,
				field(tr('Procedure'), select, {
					hint: tr('Pick a checklist template, or skip with a reason.'),
				}),
				el('label', { class: 'mn-checkbox', for: 'mn-visit-wo-skip' }, [
					skipToggle,
					el('span', { text: tr('Skip without procedure') }),
				]),
				field(tr('Skip reason'), skipReason, {
					hint: tr('At least 10 characters when skipping.'),
				}),
			].filter(Boolean)),
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Create'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						var body = {};
						if (skipToggle.checked) {
							var reason = String(skipReason.value || '').trim();
							if (reason.length < 10) {
								d.setError(tr('The skip reason must be at least 10 characters.'));
								return;
							}
							body.procedureSkipped = true;
							body.procedureSkipReason = reason;
						} else if (select.value) {
							body.procedureId = Number(select.value);
						} else {
							d.setError(tr('Select a procedure or skip with a reason.'));
							return;
						}
						d.setBusy(true);
						d.setError(null);
						createWorkOrderFromVisit(visit, isInspection, onDone, body)
							.then(function () { d.close(); })
							.catch(function (error) {
								d.setBusy(false);
								if (error.code === 'visit_already_linked') {
									d.setError(tr('This visit already has a work order.'));
									onDone();
								} else {
									d.setError(error.message || tr('Something went wrong. Please try again.'));
								}
							});
					},
				},
			],
		});
	}

	function beginCreateWorkOrderFromVisit(visit, isInspection, onDone, triggerBtn) {
		if (triggerBtn) {
			triggerBtn.disabled = true;
		}
		api('GET', apiUrl('procedures')).then(function (envelope) {
			var procedures = ((envelope && envelope.data) ? envelope.data : []).filter(function (proc) {
				return proc.active !== false;
			});
			// One active procedure → create immediately (no picker).
			if (procedures.length === 1) {
				return createWorkOrderFromVisit(visit, isInspection, onDone, {
					procedureId: Number(procedures[0].id),
				}).catch(function (error) {
					if (triggerBtn) {
						triggerBtn.disabled = false;
					}
					if (error.code === 'visit_already_linked') {
						toast(tr('This visit already has a work order.'), 'error');
						onDone();
					} else {
						toast(error.message || tr('Something went wrong. Please try again.'), 'error');
					}
				});
			}
			// Field inspection with zero procedures → skip with a fixed reason (Planned WO).
			if (isInspection && procedures.length === 0 && !ctx.isOffice) {
				return createWorkOrderFromVisit(visit, true, onDone, {
					procedureSkipped: true,
					procedureSkipReason: 'Opened in the field without a procedure template.',
				}).catch(function (error) {
					if (triggerBtn) {
						triggerBtn.disabled = false;
					}
					if (error.code === 'visit_already_linked') {
						toast(tr('This visit already has a work order.'), 'error');
						onDone();
					} else {
						toast(error.message || tr('Something went wrong. Please try again.'), 'error');
					}
				});
			}
			if (triggerBtn) {
				triggerBtn.disabled = false;
			}
			openCreateWorkOrderDialog(visit, isInspection, onDone, procedures);
		}).catch(function (error) {
			if (triggerBtn) {
				triggerBtn.disabled = false;
			}
			toast(error.message || tr('Could not load procedures.'), 'error');
		});
	}

	function visitActions(visit, onDone, options) {
		options = options || {};
		var actions = [];
		if (visit.status !== 'scheduled') {
			return actions;
		}
		var isInspection = !!visit.isInspection;
		var hasOpenWo = !!(visit.openWorkOrder && visit.openWorkOrder.id);

		// Bachus: ONE visible primary per row. Secondary paths live under More.
		if (hasOpenWo) {
			actions.push(el('a', {
				class: 'mn-btn mn-btn--primary mn-btn--compact',
				href: pageUrl('workOrders') + '/' + visit.openWorkOrder.id,
				text: isInspection ? tr('Open inspection work order') : tr('Open work order'),
			}));
		} else if (isInspection) {
			// UC-PRUEF: techs may start an inspection WO in the field (same as companion).
			actions.push(el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--primary mn-btn--compact',
				text: tr('Create inspection work order'),
				onClick: function (ev) {
					beginCreateWorkOrderFromVisit(visit, true, onDone, ev.currentTarget);
				},
			}));
		} else {
			actions.push(el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--primary mn-btn--compact',
				text: tr('Complete'),
				onClick: function (ev) {
					quickCompleteVisit(visit, onDone, ev.currentTarget);
				},
			}));
		}

		var overflowItems = [];
		if (!isInspection && !hasOpenWo && ctx.isOffice) {
			overflowItems.push({
				label: tr('Create work order'),
				onClick: function () { beginCreateWorkOrderFromVisit(visit, false, onDone, null); },
			});
		}
		if (!isInspection) {
			if (hasOpenWo) {
				overflowItems.push({
					label: tr('Complete'),
					onClick: function () { quickCompleteVisit(visit, onDone, null); },
				});
			}
			overflowItems.push({
				label: tr('Complete with details'),
				onClick: function () { completeDialog(visit, onDone); },
			});
			overflowItems.push({
				label: tr('Skip'),
				onClick: function () { quickSkipVisit(visit, onDone); },
			});
			overflowItems.push({
				label: tr('Skip with reason'),
				onClick: function () { skipDialog(visit, onDone); },
			});
		}
		if (visit.openWorkOrder && visit.openWorkOrder.logHoursUrl) {
			overflowItems.push({
				label: tr('Log hours'),
				href: visit.openWorkOrder.logHoursUrl,
			});
		}
		if (visit.openWorkOrder && visit.openWorkOrder.recordTimeUrl) {
			overflowItems.push({
				label: tr('Record time'),
				href: visit.openWorkOrder.recordTimeUrl,
			});
		}
		if (ctx.isOffice) {
			overflowItems.push({
				label: tr('Reschedule'),
				onClick: function () { rescheduleDialog(visit, onDone); },
			});
			overflowItems.push({
				label: tr('Assign'),
				onClick: function () { assignDialog(visit, onDone); },
			});
			overflowItems.push({
				label: tr('Cancel visit'),
				onClick: function () { cancelVisitDialog(visit, onDone); },
				danger: true,
			});
		}

		// Default: collapsible overflow (granny/toddler — one clear primary + More).
		// Pass `{ overflow: false }` only for dense office tables that need every action visible.
		if (options.overflow === false) {
			overflowItems.forEach(function (item) {
				if (item.href) {
					actions.push(el('a', {
						class: 'mn-btn mn-btn--secondary mn-btn--compact',
						href: item.href,
						target: '_blank',
						rel: 'noopener noreferrer',
						text: item.label,
					}));
					return;
				}
				actions.push(el('button', {
					type: 'button',
					class: 'mn-btn ' + (item.danger ? 'mn-btn--tertiary' : 'mn-btn--secondary') + ' mn-btn--compact',
					text: item.label,
					onClick: item.onClick,
				}));
			});
		} else {
			var overflow = visitOverflowMenu(overflowItems);
			if (overflow) {
				actions.push(overflow);
			}
		}
		return actions;
	}

	function visitOverflowMenu(items) {
		if (!items.length) {
			return null;
		}
		fieldSeq += 1;
		var menuId = 'mn-overflow-' + fieldSeq;
		var open = false;
		var menu = el('div', {
			class: 'mn-overflow__menu',
			id: menuId,
			role: 'menu',
			hidden: true,
		});
		items.forEach(function (item) {
			if (item.href) {
				menu.appendChild(el('a', {
					class: 'mn-overflow__item',
					role: 'menuitem',
					href: item.href,
					target: '_blank',
					rel: 'noopener noreferrer',
					text: item.label,
					onClick: function () { setOpen(false); },
				}));
				return;
			}
			menu.appendChild(el('button', {
				type: 'button',
				class: 'mn-overflow__item' + (item.danger ? ' mn-overflow__item--danger' : ''),
				role: 'menuitem',
				text: item.label,
				onClick: function () {
					setOpen(false);
					item.onClick();
				},
			}));
		});
		var toggle = el('button', {
			type: 'button',
			class: 'mn-btn mn-btn--tertiary mn-btn--compact mn-overflow__toggle',
			'aria-haspopup': 'menu',
			'aria-expanded': 'false',
			'aria-controls': menuId,
			'aria-label': tr('More actions'),
			text: tr('More'),
			onClick: function (ev) {
				ev.stopPropagation();
				setOpen(!open);
			},
			onKeyDown: function (ev) {
				if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'ArrowDown') {
					ev.preventDefault();
					ev.stopPropagation();
					setOpen(true);
					var first = menu.querySelector('[role="menuitem"]');
					if (first && typeof first.focus === 'function') {
						first.focus();
					}
				}
			},
		});
		var wrap = el('div', { class: 'mn-overflow' }, [toggle, menu]);

		function placeMenu() {
			// Portal to body + fixed coords — escapes table overflow clipping (Bachus / §3.7).
			if (menu.parentNode !== document.body) {
				document.body.appendChild(menu);
			}
			var rect = toggle.getBoundingClientRect();
			var gap = 4;
			var menuWidth = Math.min(320, Math.max(220, window.innerWidth - 16));
			var left = Math.min(Math.max(8, rect.right - menuWidth), window.innerWidth - menuWidth - 8);
			menu.style.position = 'fixed';
			menu.style.zIndex = '1000';
			menu.style.minWidth = menuWidth + 'px';
			menu.style.left = left + 'px';
			menu.style.right = 'auto';
			menu.style.top = '';
			menu.style.bottom = '';
			menu.hidden = false;
			var menuHeight = menu.offsetHeight || 200;
			var spaceBelow = window.innerHeight - rect.bottom - gap;
			if (spaceBelow < menuHeight && rect.top > spaceBelow) {
				menu.style.top = 'auto';
				menu.style.bottom = (window.innerHeight - rect.top + gap) + 'px';
			} else {
				menu.style.top = (rect.bottom + gap) + 'px';
				menu.style.bottom = 'auto';
			}
		}

		function clearMenuPlace() {
			menu.hidden = true;
			menu.style.position = '';
			menu.style.zIndex = '';
			menu.style.minWidth = '';
			menu.style.left = '';
			menu.style.right = '';
			menu.style.top = '';
			menu.style.bottom = '';
			if (menu.parentNode !== wrap) {
				wrap.appendChild(menu);
			}
		}

		function setOpen(next) {
			open = next;
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				placeMenu();
			} else {
				clearMenuPlace();
			}
		}

		function onDoc(ev) {
			if (!wrap.contains(ev.target) && !menu.contains(ev.target)) {
				setOpen(false);
			}
		}
		function onKey(ev) {
			if (ev.key === 'Escape') {
				setOpen(false);
				toggle.focus();
			}
		}
		function onReposition() {
			if (open) {
				placeMenu();
			}
		}
		document.addEventListener('click', onDoc);
		document.addEventListener('keydown', onKey);
		window.addEventListener('resize', onReposition);
		window.addEventListener('scroll', onReposition, true);
		// Cleanup listeners when the overflow root leaves the DOM.
		if (typeof MutationObserver === 'function') {
			var observer = new MutationObserver(function () {
				if (!document.body.contains(wrap)) {
					document.removeEventListener('click', onDoc);
					document.removeEventListener('keydown', onKey);
					window.removeEventListener('resize', onReposition);
					window.removeEventListener('scroll', onReposition, true);
					observer.disconnect();
				}
			});
			observer.observe(document.body, { childList: true, subtree: true });
		}
		return wrap;
	}

	// ── Page: due board ────────────────────────────────────────────────

	function pageDue() {
		var board = document.getElementById('mn-due-board');
		var emptyBox = document.getElementById('mn-due-empty');
		var mineToggle = document.getElementById('mn-due-mine');
		var todayLabel = document.getElementById('mn-due-today');
		var dueKind = 'all';
		var loadSeq = 0;

		function load() {
			var seq = ++loadSeq;
			board.setAttribute('aria-busy', 'true');
			api('GET', withQuery(apiUrl('visitsDue'), {
				mine: mineToggle.checked ? '1' : '',
				kind: dueKind === 'inspection' ? 'inspection' : '',
			}))
				.then(function (data) {
					if (seq !== loadSeq) {
						return;
					}
					render(data);
				})
				.catch(function (error) {
					if (seq !== loadSeq) {
						return;
					}
					handleGlobalError(error);
				})
				.finally(function () {
					if (seq === loadSeq) {
						board.removeAttribute('aria-busy');
					}
				});
		}

		function render(data) {
			todayLabel.textContent = tr('Today is {date}.', { date: fmt(data.today_date) });
			setServerToday(data.today_date);
			var totalVisits = 0;
			['overdue', 'today', 'next7', 'later'].forEach(function (key) {
				var meta = bucketMeta(key);
				var title = document.getElementById('mn-bucket-title-' + key);
				var section = board.querySelector('[data-bucket="' + key + '"]');
				title.textContent = meta.title + ' (' + data.counts[key] + ')';
				var list = board.querySelector('[data-bucket-list="' + key + '"]');
				clear(list);
				totalVisits += data[key].length;
				if (data[key].length === 0) {
					// Bachus: hide empty buckets so the board shows only real work.
					if (section) {
						section.hidden = true;
						section.classList.add('is-empty');
					}
					return;
				}
				if (section) {
					section.hidden = false;
					section.classList.remove('is-empty');
				}
				// Design-system §3.7: dense due lists are data tables, not card stacks.
				// Bucket title already states urgency — no per-row Overdue/Today badge noise.
				list.appendChild(dueBucketTable(data[key], meta.title));
			});
			var showEmpty = totalVisits === 0;
			board.hidden = showEmpty;
			emptyBox.hidden = !showEmpty;
			if (showEmpty) {
				clear(emptyBox);
				var link = el('a', { class: 'mn-btn mn-btn--secondary', href: pageUrl('visits'), text: tr('Open visit history') });
				emptyBox.appendChild(emptyState(
					tr('Nothing due in the next 30 days'),
					tr('New visits appear here as soon as a maintenance plan makes them due.'),
					link
				));
				announceResults(0, tr('Nothing due in the next 30 days'));
			} else {
				announceResults(totalVisits);
			}
		}

		function dueBucketTable(rows, caption) {
			return tableOrCards([
				{
					id: 'item',
					label: tr('Work'),
					render: function (row) {
						if (row.rowKind === 'workOrder') {
							var kindLabel = row.kind === 'inspection'
								? tr('Inspection work order')
								: (row.kind === 'corrective' ? tr('Corrective work order') : tr('Preventive work order'));
							var sub = kindLabel;
							if (row.equipmentLabel) {
								sub = row.equipmentLabel + ' — ' + kindLabel;
							}
							return tableStack(
								tableLink(
									pageUrl('workOrders') + '/' + row.id,
									row.number + ' — ' + (row.title || tr('Work order'))
								),
								sub
							);
						}
						return tableStack(
							tableLink(
								pageUrl('equipment') + '/' + row.equipmentId,
								visitSubjectTitle(row)
							),
							displayMaintTypeName(row)
						);
					},
				},
				{
					id: 'customer',
					label: tr('Customer'),
					render: function (row) {
						return tableCellText(row.customerName);
					},
				},
				{
					id: 'due',
					label: tr('Due'),
					render: function (row) {
						return fmt(row.dueOn);
					},
				},
				{
					id: 'assigned',
					label: tr('Assigned'),
					render: function (row) {
						var uid = row.rowKind === 'workOrder' ? row.primaryUserId : row.assignedUid;
						return tableCellText(uid || null);
					},
				},
				{
					id: 'actions',
					label: tr('Actions'),
					actions: true,
					render: function (row) {
						if (row.rowKind === 'workOrder') {
							return el('a', {
								class: 'mn-btn mn-btn--primary mn-btn--compact',
								href: pageUrl('workOrders') + '/' + row.id,
								text: tr('Open work order'),
							});
						}
						var actions = visitActions(row, load);
						return actions.length ? actions : tableCellText(null);
					},
				},
			], rows, {
				caption: caption,
				tableClass: 'mn-due-table',
			});
		}

		mineToggle.addEventListener('change', load);
		function syncDueKindAria() {
			document.querySelectorAll('[data-mn-due-kind]').forEach(function (b) {
				var active = b.classList.contains('is-active');
				b.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
		}
		syncDueKindAria();
		document.querySelectorAll('[data-mn-due-kind]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				document.querySelectorAll('[data-mn-due-kind]').forEach(function (b) {
					b.classList.remove('is-active');
				});
				btn.classList.add('is-active');
				syncDueKindAria();
				dueKind = btn.getAttribute('data-mn-due-kind') || 'all';
				load();
			});
		});
		load();
	}

	// ── Page: customers ────────────────────────────────────────────────

	function customerFormDialog(existing, onDone) {
		var f = {
			name: field(tr('Name'), el('input', { type: 'text', value: existing ? existing.name : '' }), { required: true, wide: true }),
			customerNo: field(tr('Customer number'), el('input', { type: 'text', value: existing ? (existing.customerNo || '') : '' })),
			street: field(tr('Street'), el('input', { type: 'text', value: existing ? (existing.street || '') : '' })),
			postalCode: field(tr('Postal code'), el('input', { type: 'text', value: existing ? (existing.postalCode || '') : '' })),
			city: field(tr('City'), el('input', { type: 'text', value: existing ? (existing.city || '') : '' })),
			country: field(tr('Country code'), el('input', { type: 'text', value: existing ? (existing.country || '') : '', maxlength: '2' }), { hint: tr('Two letters, e.g. DE or AT.') }),
			email: field(tr('Email'), el('input', { type: 'email', value: existing ? (existing.email || '') : '' })),
			phone: field(tr('Phone'), el('input', { type: 'text', value: existing ? (existing.phone || '') : '' })),
			notes: field(tr('Notes'), el('textarea', { rows: '3' }), { wide: true }),
		};
		if (existing && existing.notes) {
			f.notes.mnInput.value = existing.notes;
		}
		var activeBox = el('input', { type: 'checkbox' });
		activeBox.checked = existing ? !!existing.active : true;
		var grid = el('div', { class: 'mn-form-grid' }, Object.keys(f).map(function (k) { return f[k]; }));
		var content = el('div', {}, [
			grid,
			existing ? el('label', { class: 'mn-checkbox' }, [activeBox, el('span', { text: tr('Customer is active') })]) : null,
		]);

		openDialog({
			title: existing ? tr('Edit customer') : tr('New customer'),
			content: content,
			initialFocus: 'input[type="text"]',
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: existing ? tr('Save changes') : tr('Create customer'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						var body = {
							name: f.name.mnInput.value,
							customerNo: nullable(f.customerNo.mnInput.value),
							street: nullable(f.street.mnInput.value),
							postalCode: nullable(f.postalCode.mnInput.value),
							city: nullable(f.city.mnInput.value),
							country: nullable(f.country.mnInput.value),
							email: nullable(f.email.mnInput.value),
							phone: nullable(f.phone.mnInput.value),
							notes: nullable(f.notes.mnInput.value),
							active: existing ? activeBox.checked : true,
						};
						var request = existing
							? api('PUT', apiUrl('customers') + '/' + existing.id, body)
							: api('POST', apiUrl('customers'), body);
						request.then(function (customer) {
							d.close();
							toast(existing ? tr('Customer saved.') : tr('Customer created.'), 'success');
							onDone(customer);
						}).catch(function (error) {
							d.setBusy(false);
							if (!applyFieldErrors(f, error)) {
								d.setError(error.message);
							}
						});
					},
				},
			],
		});
	}

	function nullable(value) {
		return value.trim() === '' ? null : value;
	}

	function customerDeleteDialog(customer, counts, onDone) {
		var hasChildren = counts.equipment > 0;
		var confirmBox = el('input', { type: 'checkbox' });
		var confirmLabel = el('label', { class: 'mn-checkbox' }, [
			confirmBox,
			el('span', { text: tr('Yes, permanently delete this customer including {equipment} equipment, {plans} plans and {visits} visits.', counts) }),
		]);
		var content = hasChildren ? el('div', {}, [confirmLabel]) : null;

		function syncDeleteGate(d) {
			if (!hasChildren) {
				return;
			}
			var button = d.dialog.querySelector('#mn-confirm-delete');
			if (button) {
				// S9: destructive control stays disabled until the checkbox is checked —
				// including after setBusy(false) restores pre-busy button state.
				button.disabled = !confirmBox.checked;
			}
		}

		var dialogCtx = openDialog({
			title: tr('Delete customer'),
			text: hasChildren
				? tr('“{name}” still has {equipment} equipment, {plans} plans and {visits} visits. Deleting the customer removes all of it permanently.', {
					name: customer.name, equipment: counts.equipment, plans: counts.plans, visits: counts.visits,
				})
				: tr('Delete “{name}” permanently? This cannot be undone.', { name: customer.name }),
			content: content,
			onIdle: syncDeleteGate,
			actions: [
				{ label: tr('Keep customer'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					id: 'mn-confirm-delete',
					label: tr('Delete permanently'),
					variant: 'mn-btn--danger',
					disabled: hasChildren,
					onClick: function (d) {
						if (hasChildren && !confirmBox.checked) {
							return;
						}
						d.setBusy(true);
						if (hasChildren) {
							confirmBox.disabled = true;
						}
						api('DELETE', withQuery(apiUrl('customers') + '/' + customer.id, { force: hasChildren ? '1' : '' }))
							.then(function () {
								d.close();
								toast(tr('Customer deleted.'), 'success');
								onDone();
							})
							.catch(function (error) {
								if (hasChildren) {
									confirmBox.disabled = false;
								}
								d.setBusy(false);
								if (error.status === 404) {
									d.close();
									toast(tr('Already deleted.'), 'success');
									onDone();
								} else {
									d.setError(error.message);
								}
							});
					},
				},
			],
		});
		if (hasChildren) {
			confirmBox.addEventListener('change', function () {
				syncDeleteGate(dialogCtx);
			});
		}
	}

	function pageCustomers() {
		var list = document.getElementById('mn-customer-list');
		var pagination = document.getElementById('mn-customer-pagination');
		var search = document.getElementById('mn-customer-search');
		var state = { q: '', offset: 0 };
		var loadSeq = 0;

		if (ctx.isOffice) {
			document.getElementById('mn-page-actions').appendChild(el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--primary',
				onClick: function () { customerFormDialog(null, load); },
			}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), tr('New customer')]));
		}

		function load() {
			var seq = ++loadSeq;
			list.setAttribute('aria-busy', 'true');
			clear(list);
			list.appendChild(skeleton(4));
			api('GET', withQuery(apiUrl('customers'), { q: state.q, offset: state.offset }))
				.then(function (envelope) {
					if (seq !== loadSeq) {
						return;
					}
					render(envelope);
				})
				.catch(function (error) {
					if (seq !== loadSeq) {
						return;
					}
					clear(list);
					handleGlobalError(error);
				})
				.finally(function () {
					if (seq === loadSeq) {
						list.removeAttribute('aria-busy');
					}
				});
		}

		function render(envelope) {
			clear(list);
			if (envelope.total === 0) {
				var action = null;
				if (ctx.isOffice && state.q === '') {
					action = el('button', {
						type: 'button', class: 'mn-btn mn-btn--primary',
						text: tr('Create the first customer'),
						onClick: function () { customerFormDialog(null, load); },
					});
				}
				list.appendChild(emptyState(
					state.q === '' ? tr('No customers yet') : tr('No customers match your search'),
					state.q === '' ? tr('Customers are the sites you service — equipment and plans live under them.') : tr('Try a shorter search term.'),
					action
				));
				renderPagination(pagination, envelope, onPage);
				announceResults(0, state.q === '' ? tr('No customers yet') : tr('No customers match your search'));
				return;
			}
			list.appendChild(tableOrCards([
				{
					id: 'name',
					label: tr('Customer'),
					render: function (customer) {
						var subParts = [];
						if (customer.customerNo) {
							subParts.push(tr('No. {no}', { no: customer.customerNo }));
						}
						if (customer.city) {
							subParts.push(customer.city);
						}
						return tableStack(
							tableLink(pageUrl('customers') + '/' + customer.id, customer.name),
							subParts.length ? subParts.join(' · ') : null
						);
					},
				},
				{
					id: 'status',
					label: tr('Status'),
					render: function (customer) {
						return customer.active
							? tableCellText(null)
							: statusBadge(tr('Inactive'), 'mn-badge--neutral');
					},
				},
			], envelope.data, {
				caption: tr('Customers'),
				rowClass: function (customer) {
					return customer.active ? '' : 'mn-table__row--inactive';
				},
			}));
			renderPagination(pagination, envelope, onPage);
			announceResults(envelope.total);
		}

		function onPage(offset) {
			state.offset = offset;
			load();
		}

		search.addEventListener('input', debounce(function () {
			state.q = search.value.trim();
			state.offset = 0;
			load();
		}, 300));

		load();
	}

	// ── Page: customer detail ──────────────────────────────────────────

	function pageCustomerDetail() {
		var detail = document.getElementById('mn-customer-detail');
		var equipmentList = document.getElementById('mn-customer-equipment');
		var sectionActions = document.getElementById('mn-equipment-section-actions');
		var sitesList = document.getElementById('mn-customer-sites');
		var sitesActions = document.getElementById('mn-sites-section-actions');
		document.getElementById('mn-back-link').setAttribute('href', pageUrl('customers'));
		var customerId = ctx.entityId;
		var current = null;

		function load() {
			detail.setAttribute('aria-busy', 'true');
			api('GET', apiUrl('customers') + '/' + customerId)
				.then(function (customer) {
					current = customer;
					renderDetail(customer);
					loadSites();
					loadEquipment();
				})
				.catch(function (error) {
					clear(detail);
					detail.appendChild(emptyState(tr('Customer not found'), tr('It may have been deleted in the meantime.')));
					clear(equipmentList);
					if (sitesList) clear(sitesList);
					if (error.status !== 404) {
						handleGlobalError(error);
					}
				})
				.finally(function () { detail.removeAttribute('aria-busy'); });
		}

		function renderDetail(customer) {
			clear(detail);
			var title = document.getElementById('mn-page-title');
			title.textContent = customer.name;
			var addressParts = [customer.street, [customer.postalCode, customer.city].filter(Boolean).join(' '), customer.country]
				.filter(function (v) { return v; });
			var grid = el('dl', { class: 'mn-detail__grid' }, [
				pair(tr('Customer number'), customer.customerNo || '—'),
				pair(tr('Address'), addressParts.length ? addressParts.join(', ') : '—'),
				pair(tr('Email'), customer.email || '—'),
				pair(tr('Phone'), customer.phone || '—'),
				pair(tr('Status'), customer.active ? tr('Active') : tr('Inactive')),
				customer.notes ? pair(tr('Notes'), customer.notes) : null,
			]);
			var card = el('div', { class: 'mn-card' }, [grid]);
			detail.appendChild(card);
			var dual = el('aside', {
				class: 'mn-callout mn-callout--info',
				role: 'note',
				'aria-label': tr('Field vs CRM customers'),
			}, [
				el('p', { class: 'mn-callout__title', text: tr('Field vs CRM customers') }),
				el('p', {
					class: 'mn-callout__text',
					text: tr('This register is for field work. A matching name in another business app is not the same record. Link records on purpose; nothing merges silently.'),
				}),
				el('p', { class: 'mn-callout__hint' }, [
					el('a', {
						class: 'mn-btn mn-btn--secondary mn-btn--compact',
						href: 'https://nextcloud.software-by-design.de/',
						target: '_blank',
						rel: 'noopener noreferrer',
						text: tr('Learn more on our website'),
					}),
				]),
			]);
			detail.appendChild(dual);
			if (ctx.isOffice) {
				var actions = el('div', { class: 'mn-detail__actions' }, [
					el('button', {
						type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Edit customer'),
						onClick: function () { customerFormDialog(current, function () { load(); }); },
					}),
					el('button', {
						type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact', text: tr('Delete customer'),
						onClick: function () {
							customerDeleteDialog(current, current.counts, function () {
								window.location.href = pageUrl('customers');
							});
						},
					}),
				]);
				card.appendChild(actions);
			}
		}

		function pair(label, value) {
			return el('div', {}, [el('dt', { text: label }), el('dd', { text: value })]);
		}

		function loadSites() {
			if (!sitesList) {
				return;
			}
			clear(sitesActions);
			if (ctx.isOffice) {
				sitesActions.appendChild(el('button', {
					type: 'button', class: 'mn-btn mn-btn--primary mn-btn--compact',
					onClick: function () { siteFormDialog(null, customerId, loadSites); },
				}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), tr('New site')]));
			}
			sitesList.setAttribute('aria-busy', 'true');
			clear(sitesList);
			sitesList.appendChild(skeleton(2));
			api('GET', apiUrl('customers') + '/' + customerId + '/sites')
				.then(function (envelope) {
					clear(sitesList);
					var rows = envelope.data || [];
					if (rows.length === 0) {
						sitesList.appendChild(emptyState(
							tr('No sites yet'),
							ctx.isOffice
								? tr('Add a site when this customer has more than one address.')
								: tr('No sites have been registered for this customer yet.')
						));
						return;
					}
					sitesList.appendChild(renderSitesTable(rows.map(siteRow)));
				})
				.catch(handleGlobalError)
				.finally(function () { sitesList.removeAttribute('aria-busy'); });
		}

		function siteRow(site) {
			var address = [site.street, [site.postalCode, site.city].filter(Boolean).join(' '), site.country]
				.filter(Boolean).join(', ');
			var actions = [];
			if (ctx.isOffice) {
				actions.push(el('button', {
					type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Edit'),
					onClick: function () { siteFormDialog(site, customerId, loadSites); },
				}));
				actions.push(el('button', {
					type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact', text: tr('Delete'),
					onClick: function () { deleteSite(site); },
				}));
			}
			return {
				site: site,
				address: address,
				actions: actions,
			};
		}

		function renderSitesTable(rows) {
			return tableOrCards([
				{
					id: 'name',
					label: tr('Site'),
					render: function (row) {
						return tableStack(row.site.name, row.address || null);
					},
				},
				{
					id: 'status',
					label: tr('Status'),
					render: function (row) {
						return row.site.active
							? tableCellText(null)
							: statusBadge(tr('Inactive'), 'mn-badge--neutral');
					},
				},
				{
					id: 'actions',
					label: tr('Actions'),
					actions: true,
					render: function (row) {
						return row.actions.length ? row.actions : tableCellText(null);
					},
				},
			], rows, {
				caption: tr('Sites'),
				rowClass: function (row) {
					return row.site.active ? '' : 'mn-table__row--inactive';
				},
			});
		}

		function deleteSite(site) {
			openDialog({
				title: tr('Delete site'),
				text: tr('Delete “{name}”? Equipment linked to this site must be moved first.', { name: site.name }),
				actions: [
					{ label: tr('Keep site'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
					{
						label: tr('Delete permanently'),
						variant: 'mn-btn--danger',
						onClick: function (d) {
							d.setBusy(true);
							api('DELETE', apiUrl('sites') + '/' + site.id)
								.then(function () {
									d.close();
									toast(tr('Site deleted.'), 'success');
									loadSites();
								})
								.catch(function (error) {
									d.setBusy(false);
									d.setError(error.code === 'site_in_use'
										? tr('Equipment is linked to this site. Move it first.')
										: error.message);
								});
						},
					},
				],
			});
		}

		function loadEquipment() {
			clear(sectionActions);
			if (ctx.isOffice) {
				sectionActions.appendChild(el('button', {
					type: 'button', class: 'mn-btn mn-btn--primary mn-btn--compact',
					onClick: function () { equipmentFormDialog(null, customerId, loadEquipment); },
				}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), tr('New equipment')]));
			}
			equipmentList.setAttribute('aria-busy', 'true');
			clear(equipmentList);
			equipmentList.appendChild(skeleton(3));
			api('GET', withQuery(apiUrl('equipment'), { customerId: customerId, limit: 200 }))
				.then(function (envelope) {
					clear(equipmentList);
					if (envelope.total === 0) {
						equipmentList.appendChild(emptyState(
							tr('No equipment yet'),
							ctx.isOffice ? tr('Add the units you maintain at this site.') : tr('Nothing has been registered for this customer yet.')
						));
						return;
					}
					equipmentList.appendChild(equipmentTable(envelope.data, tr('Equipment')));
				})
				.catch(handleGlobalError)
				.finally(function () { equipmentList.removeAttribute('aria-busy'); });
		}

		load();
	}

	function siteFormDialog(existing, customerId, onDone) {
		var f = {
			name: field(tr('Site name'), el('input', { type: 'text', value: existing ? existing.name : '' }), { required: true, wide: true }),
			street: field(tr('Street'), el('input', { type: 'text', value: existing ? (existing.street || '') : '' }), { wide: true }),
			postalCode: field(tr('Postal code'), el('input', { type: 'text', value: existing ? (existing.postalCode || '') : '' })),
			city: field(tr('City'), el('input', { type: 'text', value: existing ? (existing.city || '') : '' })),
			country: field(tr('Country code'), el('input', { type: 'text', maxlength: '2', value: existing ? (existing.country || '') : '' }), { hint: tr('Two letters, e.g. DE or AT.') }),
			lat: field(tr('Latitude'), el('input', {
				type: 'number',
				step: 'any',
				min: '-90',
				max: '90',
				value: existing && existing.lat != null ? String(existing.lat) : '',
			}), { hint: tr('Optional — decimal degrees (−90 to 90).') }),
			lng: field(tr('Longitude'), el('input', {
				type: 'number',
				step: 'any',
				min: '-180',
				max: '180',
				value: existing && existing.lng != null ? String(existing.lng) : '',
			}), { hint: tr('Optional — decimal degrees (−180 to 180).') }),
			notes: field(tr('Notes'), el('textarea', { rows: '2' }), { wide: true }),
			accessNotes: field(tr('Access notes'), el('textarea', { rows: '2' }), { wide: true, hint: tr('Gate codes, parking, contact on site — copied to new work orders.') }),
			preferredWindow: field(tr('Preferred window'), el('input', { type: 'text', value: existing && existing.preferredWindow ? existing.preferredWindow : '' }), { wide: true, hint: tr('e.g. Mon–Fri 08:00–12:00') }),
		};
		if (existing && existing.notes) {
			f.notes.mnInput.value = existing.notes;
		}
		if (existing && existing.accessNotes) {
			f.accessNotes.mnInput.value = existing.accessNotes;
		}
		var activeBox = el('input', { type: 'checkbox' });
		activeBox.checked = existing ? !!existing.active : true;
		openDialog({
			title: existing ? tr('Edit site') : tr('New site'),
			content: el('div', {}, [
				el('div', { class: 'mn-form-grid' }, ['name', 'street', 'postalCode', 'city', 'country', 'lat', 'lng', 'notes'].map(function (k) { return f[k]; })),
				existing ? el('label', { class: 'mn-checkbox' }, [activeBox, el('span', { text: tr('Site is active') })]) : null,
			]),
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: existing ? tr('Save changes') : tr('Create site'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						var body = {
							name: f.name.mnInput.value,
							street: nullable(f.street.mnInput.value),
							postalCode: nullable(f.postalCode.mnInput.value),
							city: nullable(f.city.mnInput.value),
							country: nullable(f.country.mnInput.value),
							notes: nullable(f.notes.mnInput.value),
							accessNotes: nullable(f.accessNotes.mnInput.value),
							preferredWindow: nullable(f.preferredWindow.mnInput.value),
						};
						var latRaw = String(f.lat.mnInput.value || '').trim();
						var lngRaw = String(f.lng.mnInput.value || '').trim();
						if (latRaw !== '') {
							body.lat = Number(latRaw);
						} else if (existing) {
							body.lat = null;
						}
						if (lngRaw !== '') {
							body.lng = Number(lngRaw);
						} else if (existing) {
							body.lng = null;
						}
						if (existing) {
							body.active = activeBox.checked;
						}
						var request = existing
							? api('PUT', apiUrl('sites') + '/' + existing.id, body)
							: api('POST', apiUrl('customers') + '/' + customerId + '/sites', body);
						request.then(function () {
							d.close();
							toast(existing ? tr('Site saved.') : tr('Site created.'), 'success');
							onDone();
						}).catch(function (error) {
							d.setBusy(false);
							if (!applyFieldErrors(f, error)) {
								d.setError(error.message);
							}
						});
					},
				},
			],
		});
	}

	// ── Equipment form + rows (shared) ─────────────────────────────────

	function equipmentTable(items, caption) {
		return tableOrCards([
			{
				id: 'label',
				label: tr('Equipment'),
				render: function (item) {
					var subParts = [item.manufacturer, item.model, item.serialNo ? tr('SN {sn}', { sn: item.serialNo }) : null]
						.filter(function (v) { return v; });
					return tableStack(
						tableLink(pageUrl('equipment') + '/' + item.id, item.label),
						subParts.length ? subParts.join(' · ') : null
					);
				},
			},
			{
				id: 'location',
				label: tr('Location'),
				render: function (item) {
					return tableCellText(item.locationText);
				},
			},
			{
				id: 'status',
				label: tr('Status'),
				render: function (item) {
					return item.active
						? tableCellText(null)
						: statusBadge(tr('Inactive'), 'mn-badge--neutral');
				},
			},
		], items, {
			caption: caption || tr('Equipment'),
			rowClass: function (item) {
				return item.active ? '' : 'mn-table__row--inactive';
			},
		});
	}

	function equipmentFormDialog(existing, fixedCustomerId, onDone) {
		var customerIdForSites = fixedCustomerId || (existing ? existing.customerId : null);
		Promise.all([
			loadAllCatalog(apiUrl('equipTypes')),
			fixedCustomerId || existing
				? Promise.resolve(null)
				: api('GET', withQuery(apiUrl('customers'), { limit: 200 })).then(function (e) { return e.data; }),
			customerIdForSites
				? api('GET', apiUrl('customers') + '/' + customerIdForSites + '/sites').then(function (e) { return e.data || []; })
				: Promise.resolve([]),
		]).then(function (results) {
			var equipTypes = results[0];
			var customers = results[1];
			var sites = results[2] || [];
			var typeSelect = catalogSelect(equipTypes, existing ? existing.equipTypeId : null, true);
			var f = {
				label: field(tr('Label'), el('input', { type: 'text', value: existing ? existing.label : '' }), { required: true, wide: true, hint: tr('How your team refers to this unit, e.g. “Boiler cellar left”.') }),
				equipTypeId: field(tr('Equipment type'), typeSelect, { required: true }),
				manufacturer: field(tr('Manufacturer'), el('input', { type: 'text', value: existing ? (existing.manufacturer || '') : '' })),
				model: field(tr('Model'), el('input', { type: 'text', value: existing ? (existing.model || '') : '' })),
				serialNo: field(tr('Serial number'), el('input', { type: 'text', value: existing ? (existing.serialNo || '') : '' })),
				locationText: field(tr('Location'), el('input', { type: 'text', value: existing ? (existing.locationText || '') : '' }), { hint: tr('Where on site, e.g. “2nd floor, server room”.') }),
				notes: field(tr('Notes'), el('textarea', { rows: '3' }), { wide: true }),
				warrantyEnd: field(tr('Warranty end'), el('input', { type: 'date', value: existing && existing.warrantyEnd ? existing.warrantyEnd : '' }), { hint: tr('Optional — warn techs when creating corrective work after this date.') }),
			equipmentClass: field(tr('Equipment class'), el('input', { type: 'text', value: existing && existing.equipmentClass ? existing.equipmentClass : '', placeholder: 'portable_electrical' }), { hint: tr('Optional class code for Prüfpflichten (e.g. ladder, fire_extinguisher).') }),
			};
			if (existing && existing.notes) {
				f.notes.mnInput.value = existing.notes;
			}
			var customerSelect = null;
			if (customers) {
				customerSelect = el('select', {});
				customers.forEach(function (customer) {
					customerSelect.appendChild(el('option', { value: String(customer.id), text: customer.name }));
				});
				f.customerId = field(tr('Customer'), customerSelect, { required: true, wide: true });
			}
			var siteSelect = el('select', {});
			siteSelect.appendChild(el('option', { value: '', text: tr('No site') }));
			sites.forEach(function (site) {
				siteSelect.appendChild(el('option', {
					value: String(site.id),
					text: site.active ? site.name : tr('{name} (deactivated)', { name: site.name }),
				}));
			});
			if (existing && existing.siteId) {
				siteSelect.value = String(existing.siteId);
			}
			f.siteId = field(tr('Site'), siteSelect, { hint: tr('Optional — link this unit to a customer site.') });
			var activeBox = el('input', { type: 'checkbox' });
			activeBox.checked = existing ? !!existing.active : true;

			var order = customers ? ['customerId', 'label', 'equipTypeId'] : ['label', 'equipTypeId'];
			order = order.concat(['siteId', 'manufacturer', 'model', 'serialNo', 'locationText', 'warrantyEnd', 'equipmentClass', 'notes']);
			var grid = el('div', { class: 'mn-form-grid' }, order.map(function (k) { return f[k]; }));
			var content = el('div', {}, [
				grid,
				existing ? el('label', { class: 'mn-checkbox' }, [activeBox, el('span', { text: tr('Equipment is active') })]) : null,
			]);

			openDialog({
				title: existing ? tr('Edit equipment') : tr('New equipment'),
				content: content,
				actions: [
					{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
					{
						label: existing ? tr('Save changes') : tr('Create equipment'),
						variant: 'mn-btn--primary',
						onClick: function (d) {
							d.setBusy(true);
							d.setError(null);
							var siteRaw = siteSelect.value;
							var body = {
								label: f.label.mnInput.value,
								equipTypeId: Number(typeSelect.value),
								manufacturer: nullable(f.manufacturer.mnInput.value),
								model: nullable(f.model.mnInput.value),
								serialNo: nullable(f.serialNo.mnInput.value),
								locationText: nullable(f.locationText.mnInput.value),
								warrantyEnd: nullable(f.warrantyEnd.mnInput.value),
							equipmentClass: nullable(f.equipmentClass.mnInput.value),
								notes: nullable(f.notes.mnInput.value),
								siteId: siteRaw === '' ? null : Number(siteRaw),
								active: existing ? activeBox.checked : true,
							};
							if (!existing) {
								body.customerId = customers ? Number(customerSelect.value) : fixedCustomerId;
							}
							var request = existing
								? api('PUT', apiUrl('equipment') + '/' + existing.id, body)
								: api('POST', apiUrl('equipment'), body);
							request.then(function () {
								d.close();
								toast(existing ? tr('Equipment saved.') : tr('Equipment created.'), 'success');
								onDone();
							}).catch(function (error) {
								d.setBusy(false);
								if (!applyFieldErrors(f, error)) {
									d.setError(error.message);
								}
							});
						},
					},
				],
			});
		}).catch(handleGlobalError);
	}

	function pageEquipment() {
		var list = document.getElementById('mn-equipment-list');
		var pagination = document.getElementById('mn-equipment-pagination');
		var search = document.getElementById('mn-equipment-search');
		var state = { q: '', offset: 0 };
		var loadSeq = 0;

		if (ctx.isOffice) {
			document.getElementById('mn-page-actions').appendChild(el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--primary',
				onClick: function () { equipmentFormDialog(null, null, load); },
			}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), tr('New equipment')]));
		}

		function load() {
			var seq = ++loadSeq;
			list.setAttribute('aria-busy', 'true');
			clear(list);
			list.appendChild(skeleton(4));
			api('GET', withQuery(apiUrl('equipment'), { q: state.q, offset: state.offset }))
				.then(function (envelope) {
					if (seq !== loadSeq) {
						return;
					}
					render(envelope);
				})
				.catch(function (error) {
					if (seq !== loadSeq) {
						return;
					}
					clear(list);
					handleGlobalError(error);
				})
				.finally(function () {
					if (seq === loadSeq) {
						list.removeAttribute('aria-busy');
					}
				});
		}

		function render(envelope) {
			clear(list);
			if (envelope.total === 0) {
				list.appendChild(emptyState(
					state.q === '' ? tr('No equipment yet') : tr('No equipment matches your search'),
					state.q === '' ? tr('Equipment is created on a customer page, so every unit has a home.') : tr('Try a shorter search term.')
				));
				renderPagination(pagination, envelope, onPage);
				announceResults(0, state.q === '' ? tr('No equipment yet') : tr('No equipment matches your search'));
				return;
			}
			list.appendChild(equipmentTable(envelope.data, tr('Equipment')));
			renderPagination(pagination, envelope, onPage);
			announceResults(envelope.total);
		}

		function onPage(offset) {
			state.offset = offset;
			load();
		}

		search.addEventListener('input', debounce(function () {
			state.q = search.value.trim();
			state.offset = 0;
			load();
		}, 300));

		load();
	}

	// ── Page: equipment detail (plans + history) ──────────────────────

	function planFormDialog(existing, equipmentId, hasOpenVisit, onDone) {
		Promise.all([
			loadAllCatalog(apiUrl('maintTypes')),
			api('GET', apiUrl('equipment') + '/' + equipmentId + '/meters').then(function (e) { return e.data || []; }),
		]).then(function (results) {
			var maintTypes = results[0];
			var meters = results[1];
			var typeSelect = catalogSelect(maintTypes, existing ? existing.maintTypeId : null, true);
			var unitSelect = el('select', {}, [
				el('option', { value: 'day', text: tr('Days') }),
				el('option', { value: 'week', text: tr('Weeks') }),
				el('option', { value: 'month', text: tr('Months') }),
				el('option', { value: 'year', text: tr('Years') }),
			]);
			unitSelect.value = existing ? existing.intervalUnit : 'month';
			var countInput = el('input', { type: 'number', min: '1', step: '1', value: existing ? String(existing.intervalCount) : '6' });
			var firstDueInput = el('input', { type: 'date', value: todayYmd() });
			var contractBox = el('input', { type: 'checkbox' });
			contractBox.checked = existing ? !!existing.hasContract : false;
			var contractNotes = el('input', { type: 'text', value: existing ? (existing.contractNotes || '') : '' });
			var recalcBox = el('input', { type: 'checkbox' });
			var triggerSelect = el('select', {}, [
				el('option', { value: 'interval', text: tr('On a calendar interval') }),
				el('option', { value: 'meter', text: tr('When a meter reaches a threshold') }),
				el('option', { value: 'either', text: tr('Whichever comes first') }),
			]);
			triggerSelect.value = existing && existing.triggerKind ? existing.triggerKind : 'interval';
			var meterSelect = el('select', {});
			meterSelect.appendChild(el('option', { value: '', text: tr('Select a meter…') }));
			meters.filter(function (m) { return m.active; }).forEach(function (meter) {
				meterSelect.appendChild(el('option', {
					value: meter.code,
					text: meter.name + (meter.unit ? ' (' + meter.unit + ')' : '') + ' · ' + meter.code,
				}));
			});
			if (existing && existing.meterCode) {
				meterSelect.value = existing.meterCode;
			}
			var thresholdInput = el('input', {
				type: 'text',
				inputmode: 'decimal',
				value: existing && existing.meterThreshold != null ? String(existing.meterThreshold) : '',
			});

			var f = {
				maintTypeId: field(tr('Maintenance type'), typeSelect, { required: true, wide: true }),
				triggerKind: field(tr('When is it due?'), triggerSelect, { required: true, wide: true }),
				intervalCount: field(tr('Repeat every'), countInput, { required: true }),
				intervalUnit: field(tr('Unit'), unitSelect, { required: true }),
				meterCode: field(tr('Meter'), meterSelect, { required: true, wide: true }),
				meterThreshold: field(tr('Threshold'), thresholdInput, { required: true, hint: tr('Visit opens when the reading reaches this value.') }),
			};
			if (!existing) {
				f.firstDueOn = field(tr('First visit due on'), firstDueInput, { required: true, wide: true });
			}
			f.contractNotes = field(tr('Contract reference'), contractNotes, { hint: tr('Optional — contract number or note.'), wide: true });

			var recalcHint = el('p', { class: 'mn-field__hint' });
			function refreshRecalcHint() {
				var next = addInterval(todayYmd(), unitSelect.value, Number(countInput.value));
				recalcHint.textContent = next
					? tr('New due date would be {date}.', { date: fmt(next) })
					: '';
			}
			unitSelect.addEventListener('change', refreshRecalcHint);
			countInput.addEventListener('input', refreshRecalcHint);
			refreshRecalcHint();

			var intervalFields = el('div', { class: 'mn-form-grid' }, [f.intervalCount, f.intervalUnit]);
			var meterFields = el('div', { class: 'mn-form-grid' }, [f.meterCode, f.meterThreshold]);
			var firstDueWrap = f.firstDueOn || null;

			function syncTriggerUi() {
				var kind = triggerSelect.value;
				var needsInterval = kind === 'interval' || kind === 'either';
				var needsMeter = kind === 'meter' || kind === 'either';
				intervalFields.hidden = !needsInterval;
				meterFields.hidden = !needsMeter;
				if (firstDueWrap) {
					firstDueWrap.hidden = kind === 'meter';
				}
			}
			triggerSelect.addEventListener('change', syncTriggerUi);
			syncTriggerUi();

			var content = el('div', {}, [
				el('div', { class: 'mn-form-grid' }, [f.maintTypeId, f.triggerKind]),
				intervalFields,
				meterFields,
				firstDueWrap,
				f.contractNotes,
				el('label', { class: 'mn-checkbox' }, [contractBox, el('span', { text: tr('There is a maintenance contract') })]),
				existing && hasOpenVisit ? el('label', { class: 'mn-checkbox' }, [recalcBox, el('span', {}, [
					el('span', { text: tr('Recalculate the open visit’s due date') }),
					recalcHint,
				])]) : null,
			]);

			openDialog({
				title: existing ? tr('Edit plan') : tr('New maintenance plan'),
				content: content,
				actions: [
					{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
					{
						label: existing ? tr('Save changes') : tr('Create plan'),
						variant: 'mn-btn--primary',
						onClick: function (d) {
							d.setBusy(true);
							d.setError(null);
							var kind = triggerSelect.value;
							var body = {
								maintTypeId: Number(typeSelect.value),
								triggerKind: kind,
								hasContract: contractBox.checked,
								contractNotes: nullable(contractNotes.value),
							};
							if (kind === 'interval' || kind === 'either') {
								body.intervalUnit = unitSelect.value;
								body.intervalCount = Math.trunc(Number(countInput.value));
							}
							if (kind === 'meter' || kind === 'either') {
								body.meterCode = meterSelect.value;
								body.meterThreshold = thresholdInput.value.trim();
							}
							if (!existing && kind !== 'meter') {
								body.firstDueOn = firstDueInput.value;
							} else if (existing && hasOpenVisit) {
								body.recalculateOpenVisit = recalcBox.checked;
							}
							var request = existing
								? api('PUT', apiUrl('plans') + '/' + existing.id, body)
								: api('POST', apiUrl('equipment') + '/' + equipmentId + '/plans', body);
							request.then(function () {
								d.close();
								if (existing) {
									toast(tr('Plan saved.'), 'success');
								} else if (kind === 'meter') {
									toast(tr('Plan created — waiting for a meter reading.'), 'success');
								} else {
									toast(tr('Plan created — first visit is on the board.'), 'success');
								}
								onDone();
							}).catch(function (error) {
								d.setBusy(false);
								if (error.code === 'invalid_interval') {
									f.intervalCount.mnSetError(tr('Interval out of range (max 3650 days, 520 weeks, 120 months or 10 years).'));
									countInput.focus();
								} else if (error.code === 'invalid_due_date' && f.firstDueOn) {
									f.firstDueOn.mnSetError(tr('The due date must be between 2000-01-01 and ten years from today.'));
									firstDueInput.focus();
								} else if (error.code === 'inactive_maint_type' || error.code === 'unknown_maint_type') {
									f.maintTypeId.mnSetError(FIELD_ERROR_TEXT[error.code]());
									typeSelect.focus();
								} else if (error.code === 'meter_threshold_required') {
									f.meterThreshold.mnSetError(tr('Enter a threshold for this meter plan.'));
									thresholdInput.focus();
								} else if (!applyFieldErrors(f, error)) {
									d.setError(error.message);
								}
							});
						},
					},
				],
			});
		}).catch(handleGlobalError);
	}

	function scheduleVisitDialog(plan, onDone) {
		var dueInput = el('input', { type: 'date', value: todayYmd() });
		var dueField = field(tr('Due on'), dueInput, { required: true });
		openDialog({
			title: tr('Schedule visit'),
			text: tr('Creates a new open visit for this plan — use this after a cancellation.'),
			content: el('div', {}, [dueField]),
			initialFocus: 'input[type="date"]',
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Schedule visit'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						api('POST', apiUrl('plans') + '/' + plan.id + '/schedule', { dueOn: dueInput.value })
							.then(function () {
								d.close();
								toast(tr('Visit scheduled for {date}.', { date: fmt(dueInput.value) }), 'success');
								onDone();
							})
							.catch(function (error) {
								d.setBusy(false);
								if (error.code === 'invalid_due_date') {
									dueField.mnSetError(tr('The due date must be between 2000-01-01 and ten years from today.'));
									dueInput.focus();
								} else if (error.code === 'visit_already_open') {
									d.close();
									toast(tr('This plan already has an open visit.'), 'error');
									onDone();
								} else {
									d.setError(error.message);
								}
							});
					},
				},
			],
		});
	}

	function pageEquipmentDetail() {
		var detail = document.getElementById('mn-equipment-detail');
		var plansBox = document.getElementById('mn-equipment-plans');
		var planActions = document.getElementById('mn-plans-section-actions');

		var obligationsBox = document.getElementById('mn-equipment-obligations');
		var obligationActions = document.getElementById('mn-obligations-section-actions');
		function loadObligations() {
			if (!obligationsBox) return;
			obligationsBox.setAttribute('aria-busy', 'true');
			api('GET', apiUrl('equipment') + '/' + equipmentId + '/obligations')
				.then(function (envelope) {
					clear(obligationsBox);
					var rows = (envelope && envelope.data) ? envelope.data : (Array.isArray(envelope) ? envelope : []);
					if (rows.length === 0) {
						obligationsBox.appendChild(emptyState(
							tr('No inspection obligations yet'),
							ctx.isOffice ? tr('Add an obligation from an equipment class to schedule the next Prüfung.') : tr('No recurring inspections are set for this unit.')
						));
					} else {
						obligationsBox.appendChild(tableOrCards([
							{ key: 'class', label: tr('Class'), render: function (row) { return row.classCode || '—'; } },
							{ key: 'interval', label: tr('Interval'), render: function (row) { return String(row.intervalCount || '') + ' ' + (row.intervalUnit || ''); } },
							{ key: 'due', label: tr('Next due'), render: function (row) {
								return row.openVisit && row.openVisit.dueOn ? fmt(row.openVisit.dueOn) : '—';
							} },
						], rows, {}));
					}
				})
				.catch(function (error) {
					clear(obligationsBox);
					obligationsBox.appendChild(el('p', { class: 'mn-error', text: error.message || tr('Could not load obligations.') }));
				})
				.finally(function () { obligationsBox.removeAttribute('aria-busy'); });
		}
		if (obligationActions && ctx.isOffice) {
			clear(obligationActions);
			var addObl = el('button', { type: 'button', class: 'mn-btn mn-btn--primary button', text: tr('Add obligation') });
			addObl.addEventListener('click', function () {
				var classSelect = el('select', { class: 'mn-input form-select' });
				classSelect.appendChild(el('option', { value: '', text: tr('Select class…') }));
				api('GET', apiUrl('equipmentClasses')).then(function (envelope) {
					((envelope && envelope.data) || []).forEach(function (c) {
						classSelect.appendChild(el('option', {
							value: c.code,
							text: (c.nameDe || c.nameEn || c.code) + ' (' + c.code + ')',
						}));
					});
				}).catch(function () {});
				var dueInput = el('input', { type: 'date', class: 'mn-input form-input', value: todayYmd() });
				openDialog({
					title: tr('Add inspection obligation'),
					content: el('div', { class: 'mn-form-grid' }, [
						field(tr('Equipment class'), classSelect, { required: true, wide: true }),
						field(tr('First due on'), dueInput, { required: true }),
						el('p', { class: 'mn-muted', text: tr('Templates are operational checklists — not legal advice or certification.') }),
					]),
					actions: [
						{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
						{
							label: tr('Create'),
							variant: 'mn-btn--primary',
							onClick: function (d) {
								if (!classSelect.value) {
									d.setError(tr('Please choose an equipment class.'));
									return;
								}
								d.setBusy(true);
								api('POST', apiUrl('equipment') + '/' + equipmentId + '/obligations', {
									classCode: classSelect.value,
									firstDueOn: dueInput.value,
								}).then(function () {
									d.close();
									toast(tr('Inspection obligation created.'), 'success');
									loadObligations();
								}).catch(function (error) {
									d.setBusy(false);
									d.setError(error.message);
								});
							},
						},
					],
				});
			});
			obligationActions.appendChild(addObl);
		}

		var metersBox = document.getElementById('mn-equipment-meters');
		var meterActions = document.getElementById('mn-meters-section-actions');
		var historyBox = document.getElementById('mn-equipment-history');
		var historyPagination = document.getElementById('mn-history-pagination');
		document.getElementById('mn-back-link').setAttribute('href', pageUrl('equipment'));
		var equipmentId = ctx.entityId;
		var current = null;
		var historyState = { offset: 0 };

		function load() {
			detail.setAttribute('aria-busy', 'true');
			api('GET', apiUrl('equipment') + '/' + equipmentId)
				.then(function (item) {
					current = item;
					renderDetail(item);
					loadMeters();
					loadPlans();
					loadHistory();
				})
				.catch(function (error) {
					clear(detail);
					detail.appendChild(emptyState(tr('Equipment not found'), tr('It may have been deleted in the meantime.')));
					clear(plansBox);
					clear(historyBox);
					if (metersBox) clear(metersBox);
					if (error.status !== 404) {
						handleGlobalError(error);
					}
				})
				.finally(function () { detail.removeAttribute('aria-busy'); });
		}

		function renderDetail(item) {
			clear(detail);
			document.getElementById('mn-page-title').textContent = item.label;
			var grid = el('dl', { class: 'mn-detail__grid' }, [
				pair(tr('Manufacturer'), item.manufacturer || '—'),
				pair(tr('Model'), item.model || '—'),
				pair(tr('Serial number'), item.serialNo || '—'),
				pair(tr('Location'), item.locationText || '—'),
				pair(tr('Warranty end'), item.warrantyEnd || '—'),
				pair(tr('Status'), item.active ? tr('Active') : tr('Inactive')),
				pair(tr('QR sticker'), item.hasQrToken ? tr('Issued') : tr('Not issued yet')),
				item.notes ? pair(tr('Notes'), item.notes) : null,
			]);
			var card = el('div', { class: 'mn-card' }, [grid]);
			detail.appendChild(card);
			if (item.warrantyEnd) {
				var todayYmd = (function () {
					var d = new Date();
					return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
				})();
				if (item.warrantyEnd < todayYmd) {
					detail.appendChild(el('div', {
						class: 'mn-callout mn-callout--warning',
						role: 'status',
					}, [
						el('strong', { text: tr('Warranty ended') + ': ' }),
						el('span', { text: tr('This equipment’s warranty ended on {date}.', { date: item.warrantyEnd }) }),
					]));
				}
			}
			loadEquipDocs();
			if (ctx.isOffice) {
				card.appendChild(el('div', { class: 'mn-detail__actions' }, [
					el('button', {
						type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Edit equipment'),
						onClick: function () { equipmentFormDialog(current, null, load); },
					}),
					el('button', {
						type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact',
						text: item.hasQrToken ? tr('Renew QR sticker') : tr('Create QR sticker'),
						title: item.hasQrToken
							? tr('Creates a new sticker. The previous sticker stops working.')
							: tr('Print a sticker technicians can scan to open this unit.'),
						onClick: renewQrSticker,
					}),
					el('button', {
						type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact', text: tr('Delete equipment'),
						onClick: deleteEquipment,
					}),
				]));
			}
		}

		function renewQrSticker() {
			openDialog({
				title: current.hasQrToken ? tr('Renew QR sticker') : tr('Create QR sticker'),
				text: current.hasQrToken
					? tr('A new sticker will replace the old one. Printed stickers with the previous code will no longer open this unit.')
					: tr('Create a printable QR sticker for this equipment. Technicians can scan it to open the unit.'),
				actions: [
					{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
					{
						label: current.hasQrToken ? tr('Renew and show') : tr('Create and show'),
						variant: 'mn-btn--primary',
						onClick: function (d) {
							d.setBusy(true);
							api('POST', apiUrl('equipment') + '/' + equipmentId + '/qr/rotate', {})
								.then(function (result) {
									d.close();
									showQrStickerDialog(result);
									load();
								})
								.catch(function (error) {
									d.setBusy(false);
									d.setError(error.message);
								});
						},
					},
				],
			});
		}

		function showQrStickerDialog(result) {
			var svgWrap = el('div', {
				class: 'mn-qr-sticker',
				html: result.qrSvg || '',
				role: 'img',
				'aria-label': tr('QR code for this equipment'),
			});
			var link = el('p', { class: 'mn-muted' }, [
				el('span', { text: tr('Deep link: ') }),
				el('code', { text: result.qrDeepLink || result.qrPayload || '' }),
			]);
			openDialog({
				title: tr('QR sticker'),
				content: el('div', { class: 'mn-qr-sticker-dialog' }, [
					el('p', { class: 'mn-dialog__text', text: tr('Print this sticker and attach it to the equipment. Scanning opens the unit in MaintenanceCheck.') }),
					svgWrap,
					link,
				]),
				actions: [
					{
						label: tr('Print'),
						variant: 'mn-btn--secondary',
						onClick: function () {
							var win = window.open('', '_blank', 'noopener,noreferrer,width=480,height=640');
							if (!win) {
								toast(tr('Pop-up blocked — allow pop-ups to print the sticker.'), 'error');
								return;
							}
							win.document.write('<!DOCTYPE html><html><head><title>' +
								String(current.label).replace(/[<>&]/g, '') +
								'</title><style>body{font-family:system-ui,sans-serif;text-align:center;padding:2rem}svg{width:240px;height:240px}h1{font-size:1.1rem}</style></head><body><h1>' +
								String(current.label).replace(/[<>&]/g, '') +
								'</h1>' + (result.qrSvg || '') + '<p>' +
								String(result.qrPayload || '').replace(/[<>&]/g, '') +
								'</p></body></html>');
							win.document.close();
							win.focus();
							win.print();
						},
					},
					{ label: tr('Close'), variant: 'mn-btn--primary', onClick: function (d) { d.close(); } },
				],
			});
		}

		function deleteEquipment() {
			openDialog({
				title: tr('Delete equipment'),
				text: tr('Delete “{label}” permanently? Equipment with plans or visits cannot be deleted — deactivate it instead.', { label: current.label }),
				actions: [
					{ label: tr('Keep equipment'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
					{
						label: tr('Delete permanently'),
						variant: 'mn-btn--danger',
						onClick: function (d) {
							d.setBusy(true);
							api('DELETE', apiUrl('equipment') + '/' + equipmentId)
								.then(function () {
									d.close();
									toast(tr('Equipment deleted.'), 'success');
									window.location.href = pageUrl('equipment');
								})
								.catch(function (error) {
									d.setBusy(false);
									d.setError(error.code === 'equipment_in_use'
										? tr('This equipment has plans or visits. Deactivate it instead of deleting.')
										: error.message);
								});
						},
					},
				],
			});
		}

		function pair(label, value) {
			return el('div', {}, [el('dt', { text: label }), el('dd', { text: value })]);
		}

		function meterCsvImportDialog(equipmentId, onDone) {
			var csvInput = el('textarea', { rows: '8', class: 'mn-input form-input', placeholder: 'meter_code,value,read_on,note\nruntime_h,2005,2026-07-26,' });
			var csvField = field(tr('CSV readings'), csvInput, {
				required: true,
				wide: true,
				hint: tr('Columns: meter_code, value, optional read_on (Y-m-d), optional note. Header row allowed. Max 500 rows.'),
			});
			openDialog({
				title: tr('Import meter readings'),
				content: el('div', {}, [csvField]),
				initialFocus: 'textarea',
				actions: [
					{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
					{
						label: tr('Import'),
						variant: 'mn-btn--primary',
						onClick: function (d) {
							d.setBusy(true);
							d.setError(null);
							api('POST', apiUrl('equipment') + '/' + equipmentId + '/meters/import', { csv: csvInput.value })
								.then(function (result) {
									d.close();
									var n = result.imported || 0;
									var trig = (result.triggered || []).length;
									toast(trig
										? tr('Imported {n} readings — {t} plans became due.', { n: String(n), t: String(trig) })
										: tr('Imported {n} readings.', { n: String(n) }), 'success');
									onDone();
								})
								.catch(function (error) {
									d.setBusy(false);
									if (!applyFieldErrors({ csv: csvField }, error)) {
										d.setError(error.message);
									}
								});
						},
					},
				],
			});
		}

		function loadEquipDocs() {
			var docsHostId = 'mn-equipment-docs';
			var existing = document.getElementById(docsHostId);
			if (existing) {
				existing.remove();
			}
			var host = el('section', { id: docsHostId, class: 'mn-card', 'aria-labelledby': 'mn-equip-docs-title' }, [
				el('header', { class: 'mn-card__header' }, [
					el('h3', { id: 'mn-equip-docs-title', class: 'mn-card__title', text: tr('Documents') }),
				]),
			]);
			var body = el('div', { class: 'mn-card__body' });
			host.appendChild(body);
			detail.appendChild(host);
			api('GET', apiUrl('equipment') + '/' + equipmentId + '/docs').then(function (envelope) {
				clear(body);
				var rows = (envelope && envelope.data) || [];
				if (!rows.length) {
					body.appendChild(el('p', { class: 'mn-muted', text: tr('No documents — ask office to attach manuals or contracts.') }));
				} else {
					body.classList.add('mn-card__body--table');
					body.appendChild(tableOrCards([
						{
							id: 'doc',
							label: tr('Document'),
							render: function (doc) {
								var label = doc.title || tr('Document');
								var href = null;
								if (doc.externalUrl) {
									href = doc.externalUrl;
								} else if (doc.id && apiUrl('equipDocs')) {
									href = apiUrl('equipDocs') + '/' + doc.id + '/download';
								}
								if (href) {
									return el('a', {
										class: 'mn-table-link',
										href: href,
										target: '_blank',
										rel: 'noopener noreferrer',
										text: label,
									});
								}
								return tableCellText(label);
							},
						},
						{
							id: 'actions',
							label: tr('Actions'),
							actions: true,
							render: function (doc) {
								var href = null;
								if (doc.externalUrl) {
									href = doc.externalUrl;
								} else if (doc.id && apiUrl('equipDocs')) {
									href = apiUrl('equipDocs') + '/' + doc.id + '/download';
								}
								if (!href) {
									return tableCellText(null);
								}
								return el('a', {
									class: 'mn-btn mn-btn--secondary mn-btn--compact',
									href: href,
									target: '_blank',
									rel: 'noopener noreferrer',
									text: tr('Open'),
								});
							},
						},
					], rows, { caption: tr('Documents') }));
				}
				if (ctx.isOffice) {
					var titleInput = el('input', { type: 'text', class: 'mn-input', 'aria-label': tr('Document title') });
					var urlInput = el('input', { type: 'url', class: 'mn-input', 'aria-label': tr('External URL') });
					body.appendChild(el('div', { class: 'mn-form-grid' }, [
						field(tr('Title'), titleInput),
						field(tr('URL'), urlInput, { hint: tr('Link to a manual or contract (https://…)') }),
						el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--secondary mn-btn--compact',
							text: tr('Add document'),
							onClick: function () {
								api('POST', apiUrl('equipment') + '/' + equipmentId + '/docs', {
									title: titleInput.value,
									externalUrl: nullable(urlInput.value),
								}).then(function () {
									toast(tr('Document added.'), 'success');
									loadEquipDocs();
								}).catch(handleGlobalError);
							},
						}),
					]));
				}
			}).catch(function () {
				clear(body);
				body.appendChild(el('p', { class: 'mn-muted', text: tr('Could not load documents.') }));
			});
		}

		function loadMeters() {
			if (!metersBox) {
				return;
			}
			clear(meterActions);
			if (ctx.isOffice) {
				meterActions.appendChild(el('button', {
					type: 'button', class: 'mn-btn mn-btn--primary mn-btn--compact',
					onClick: function () { meterFormDialog(null, equipmentId, reload); },
				}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), tr('New meter')]));
				meterActions.appendChild(el('button', {
					type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact',
					text: tr('Import CSV'),
					onClick: function () { meterCsvImportDialog(equipmentId, reload); },
				}));
			}
			metersBox.setAttribute('aria-busy', 'true');
			clear(metersBox);
			metersBox.appendChild(skeleton(2));
			api('GET', apiUrl('equipment') + '/' + equipmentId + '/meters')
				.then(function (envelope) {
					clear(metersBox);
					var rows = envelope.data || [];
					if (rows.length === 0) {
						metersBox.appendChild(emptyState(
							tr('No meters yet'),
							ctx.isOffice
								? tr('Add a meter for operating hours or cycles, then plan maintenance on a threshold.')
								: tr('No meters have been set up for this unit.')
						));
						return;
					}
					metersBox.appendChild(tableOrCards([
						{
							id: 'meter',
							label: tr('Meter'),
							render: function (meter) {
								var latest = meter.latestReading;
								var sub = latest
									? tr('Latest {value}{unit} on {date}', {
										value: latest.value,
										unit: meter.unit ? ' ' + meter.unit : '',
										date: fmt(latest.readOn),
									})
									: tr('No readings yet');
								return tableStack(meter.name + ' · ' + meter.code, sub);
							},
						},
						{
							id: 'flags',
							label: tr('Details'),
							render: function (meter) {
								var badges = [];
								if (meter.unit) {
									badges.push(el('span', { class: 'mn-table-meta', text: meter.unit }));
								}
								if (meter.monotonic) {
									badges.push(statusBadge(tr('Counts up only'), 'mn-badge--info'));
								}
								if (!meter.active) {
									badges.push(statusBadge(tr('Inactive'), 'mn-badge--neutral'));
								}
								return badges.length ? badges : tableCellText(null);
							},
						},
						{
							id: 'actions',
							label: tr('Actions'),
							actions: true,
							render: function (meter) {
								var actions = [
									el('button', {
										type: 'button', class: 'mn-btn mn-btn--primary mn-btn--compact', text: tr('Add reading'),
										onClick: function () { readingDialog(meter, reload); },
									}),
								];
								if (ctx.isOffice) {
									actions.push(el('button', {
										type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Edit'),
										onClick: function () { meterFormDialog(meter, equipmentId, reload); },
									}));
								}
								return actions;
							},
						},
					], rows, {
						caption: tr('Meters'),
						rowClass: function (meter) {
							return meter.active ? '' : 'mn-table__row--inactive';
						},
					}));
				})
				.catch(handleGlobalError)
				.finally(function () { metersBox.removeAttribute('aria-busy'); });
		}

		function loadPlans() {
			clear(planActions);
			if (ctx.isOffice) {
				planActions.appendChild(el('button', {
					type: 'button', class: 'mn-btn mn-btn--primary mn-btn--compact',
					onClick: function () { planFormDialog(null, equipmentId, false, reload); },
				}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), tr('New plan')]));
			}
			plansBox.setAttribute('aria-busy', 'true');
			clear(plansBox);
			plansBox.appendChild(skeleton(2));
			Promise.all([
				api('GET', apiUrl('equipment') + '/' + equipmentId + '/plans'),
				loadAllCatalog(apiUrl('maintTypes')),
			]).then(function (results) {
				var plans = results[0];
				var typeNames = {};
				results[1].forEach(function (type) { typeNames[type.id] = type.name; });
				clear(plansBox);
				if (plans.length === 0) {
					plansBox.appendChild(emptyState(
						tr('No maintenance plans yet'),
						ctx.isOffice ? tr('A plan defines the interval — the first visit lands on the due board immediately.') : tr('No plans have been set up for this unit.')
					));
					return;
				}
				plansBox.appendChild(tableOrCards([
					{
						id: 'plan',
						label: tr('Plan'),
						render: function (plan) {
							var sub;
							if (plan.openVisit) {
								sub = tr('Next visit due {date}', { date: fmt(plan.openVisit.dueOn) });
							} else if (plan.triggerKind === 'meter' && plan.active) {
								sub = tr('Waiting for a meter reading that reaches the threshold.');
							} else if (plan.active) {
								sub = tr('No open visit — schedule one to continue the cycle.');
							} else {
								sub = tr('Plan is inactive.');
							}
							return tableStack(planTitle(plan, typeNames), sub);
						},
					},
					{
						id: 'status',
						label: tr('Status'),
						render: function (plan) {
							var badges = [
								statusBadge(
									plan.active ? tr('Active') : tr('Inactive'),
									plan.active ? 'mn-badge--scheduled' : 'mn-badge--neutral'
								),
							];
							if (plan.hasContract) {
								badges.push(statusBadge(tr('Contract'), 'mn-badge--info'));
							}
							if (plan.triggerKind && plan.triggerKind !== 'interval') {
								badges.push(statusBadge(
									plan.triggerKind === 'meter' ? tr('Meter') : tr('Interval or meter'),
									'mn-badge--info'
								));
							}
							return badges;
						},
					},
					{
						id: 'actions',
						label: tr('Actions'),
						actions: true,
						render: function (plan) {
							var actions = [];
							if (ctx.isOffice) {
								actions.push(el('button', {
									type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Edit'),
									onClick: function () { planFormDialog(plan, equipmentId, !!plan.openVisit, reload); },
								}));
								if (plan.active && !plan.openVisit && plan.triggerKind !== 'meter') {
									actions.push(el('button', {
										type: 'button', class: 'mn-btn mn-btn--primary mn-btn--compact', text: tr('Schedule visit'),
										onClick: function () { scheduleVisitDialog(plan, reload); },
									}));
								}
								if (plan.active) {
									actions.push(el('button', {
										type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact', text: tr('Deactivate'),
										onClick: function () { deactivatePlan(plan); },
									}));
								}
							}
							return actions.length ? actions : tableCellText(null);
						},
					},
				], plans, {
					caption: tr('Plans'),
					rowClass: function (plan) {
						return plan.active ? '' : 'mn-table__row--inactive';
					},
				}));
			}).catch(handleGlobalError)
				.finally(function () { plansBox.removeAttribute('aria-busy'); });
			loadObligations();
		}

		function planTitle(plan, typeNames) {
			var typeName = typeNames[plan.maintTypeId] || tr('Maintenance');
			if (plan.triggerKind === 'meter') {
				return typeName + ' · ' + tr('Meter {code} ≥ {threshold}', {
					code: plan.meterCode || '—',
					threshold: plan.meterThreshold || '—',
				});
			}
			if (plan.triggerKind === 'either') {
				return typeName + ' · ' + intervalLabel(plan.intervalUnit, plan.intervalCount)
					+ ' / ' + tr('Meter {code} ≥ {threshold}', {
						code: plan.meterCode || '—',
						threshold: plan.meterThreshold || '—',
					});
			}
			return typeName + ' · ' + intervalLabel(plan.intervalUnit, plan.intervalCount);
		}

		function deactivatePlan(plan) {
			openDialog({
				title: tr('Deactivate plan'),
				text: tr('The open visit stays on the board, but completing it will not create a follow-up. You can reactivate by editing the plan.'),
				actions: [
					{ label: tr('Keep active'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
					{
						label: tr('Deactivate plan'),
						variant: 'mn-btn--danger',
						onClick: function (d) {
							d.setBusy(true);
							api('POST', apiUrl('plans') + '/' + plan.id + '/deactivate', {})
								.then(function () {
									d.close();
									toast(tr('Plan deactivated.'), 'success');
									reload();
								})
								.catch(function (error) {
									d.setBusy(false);
									d.setError(error.message);
								});
						},
					},
				],
			});
		}

		function loadHistory() {
			historyBox.setAttribute('aria-busy', 'true');
			clear(historyBox);
			historyBox.appendChild(skeleton(3));
			api('GET', withQuery(apiUrl('visits'), { equipmentId: equipmentId, offset: historyState.offset }))
				.then(function (envelope) {
					clear(historyBox);
					if (envelope.total === 0) {
						historyBox.appendChild(emptyState(tr('No visits yet'), tr('Visits appear here once a plan creates them.')));
						renderPagination(historyPagination, envelope, onHistoryPage);
						return;
					}
					historyBox.appendChild(tableOrCards([
						{
							id: 'visit',
							label: tr('Visit'),
							render: function (visit) {
								var extra = [];
								if (visit.status === 'done' && visit.doneOn) {
									extra.push(tr('Completed on {date}', { date: fmt(visit.doneOn) }));
								}
								if (visit.assignedUid) {
									extra.push(tr('Assigned to {user}', { user: visit.assignedUid }));
								}
								if (visit.notes) {
									extra.push(visit.notes);
								}
								return tableStack(tr('Due {date}', { date: fmt(visit.dueOn) }), visitSubjectSub(visit, extra));
							},
						},
						{
							id: 'status',
							label: tr('Status'),
							render: function (visit) {
								var meta = statusMeta(visit.status);
								return statusBadge(meta.label, meta.badge, meta.icon);
							},
						},
						{
							id: 'actions',
							label: tr('Actions'),
							actions: true,
							render: function (visit) {
								var actions = visitActions(visit, reload);
								return actions.length ? actions : tableCellText(null);
							},
						},
					], envelope.data, { caption: tr('Visit history') }));
					renderPagination(historyPagination, envelope, onHistoryPage);
				})
				.catch(handleGlobalError)
				.finally(function () { historyBox.removeAttribute('aria-busy'); });
		}

		function onHistoryPage(offset) {
			historyState.offset = offset;
			loadHistory();
		}

		function reload() {
			loadMeters();
			loadPlans();
			loadHistory();
		}

		load();
	}

	function meterFormDialog(existing, equipmentId, onDone) {
		var codeInput = el('input', {
			type: 'text',
			value: existing ? existing.code : '',
			disabled: existing ? 'disabled' : null,
		});
		var f = {
			code: field(tr('Code'), codeInput, {
				required: !existing,
				hint: existing ? tr('The code cannot be changed after creation.') : tr('Lowercase letters, digits and underscores, e.g. heat_pump.'),
			}),
			name: field(tr('Name'), el('input', { type: 'text', value: existing ? existing.name : '' }), { required: true, wide: true }),
			unit: field(tr('Unit'), el('input', { type: 'text', value: existing ? (existing.unit || '') : 'h' }), { hint: tr('Short unit label, e.g. h, km, cycles.') }),
		};
		var monoBox = el('input', { type: 'checkbox' });
		monoBox.checked = existing ? !!existing.monotonic : true;
		var activeBox = el('input', { type: 'checkbox' });
		activeBox.checked = existing ? !!existing.active : true;
		openDialog({
			title: existing ? tr('Edit meter') : tr('New meter'),
			content: el('div', {}, [
				el('div', { class: 'mn-form-grid' }, [f.code, f.name, f.unit]),
				el('label', { class: 'mn-checkbox' }, [monoBox, el('span', { text: tr('Only allow readings that count up') })]),
				existing ? el('label', { class: 'mn-checkbox' }, [activeBox, el('span', { text: tr('Meter is active') })]) : null,
			]),
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: existing ? tr('Save changes') : tr('Create meter'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						var body = {
							name: f.name.mnInput.value,
							unit: nullable(f.unit.mnInput.value),
							monotonic: monoBox.checked,
						};
						if (!existing) {
							body.code = codeInput.value;
						} else {
							body.active = activeBox.checked;
						}
						var request = existing
							? api('PUT', apiUrl('meters') + '/' + existing.id, body)
							: api('POST', apiUrl('equipment') + '/' + equipmentId + '/meters', body);
						request.then(function () {
							d.close();
							toast(existing ? tr('Meter saved.') : tr('Meter created.'), 'success');
							onDone();
						}).catch(function (error) {
							d.setBusy(false);
							if (error.code === 'code_exists') {
								f.code.mnSetError(tr('This code is already in use.'));
								codeInput.focus();
							} else if (!applyFieldErrors(f, error)) {
								d.setError(error.message);
							}
						});
					},
				},
			],
		});
	}

	function readingDialog(meter, onDone) {
		var valueInput = el('input', { type: 'text', inputmode: 'decimal', value: '' });
		var dateInput = el('input', { type: 'date', value: todayYmd() });
		var noteInput = el('input', { type: 'text', value: '' });
		var f = {
			value: field(tr('Reading'), valueInput, { required: true, hint: meter.unit ? tr('Value in {unit}.', { unit: meter.unit }) : null }),
			readOn: field(tr('Read on'), dateInput, { required: true }),
			note: field(tr('Notes'), noteInput, { wide: true }),
		};
		openDialog({
			title: tr('Add reading'),
			text: meter.name + (meter.latestReading ? ' — ' + tr('Latest was {value}.', { value: meter.latestReading.value }) : ''),
			content: el('div', { class: 'mn-form-grid' }, [f.value, f.readOn, f.note]),
			initialFocus: 'input[inputmode="decimal"], input[type="text"]',
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Save reading'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						api('POST', apiUrl('meters') + '/' + meter.id + '/readings', {
							value: valueInput.value.trim(),
							readOn: dateInput.value,
							note: nullable(noteInput.value),
						}).then(function (result) {
							d.close();
							var triggered = (result && result.triggered) || [];
							if (triggered.length > 0) {
								toast(tr('Reading saved — Due board updated.'), 'success');
							} else {
								toast(tr('Reading saved.'), 'success');
							}
							onDone();
						}).catch(function (error) {
							d.setBusy(false);
							if (error.code === 'meter_not_monotonic') {
								f.value.mnSetError(error.message);
								valueInput.focus();
							} else if (!applyFieldErrors(f, error)) {
								d.setError(error.message);
							}
						});
					},
				},
			],
		});
	}

	// ── Page: visits ───────────────────────────────────────────────────

	function pageVisits() {
		var list = document.getElementById('mn-visit-list');
		var pagination = document.getElementById('mn-visit-pagination');
		var form = document.getElementById('mn-visit-filters');
		var statusInput = document.getElementById('mn-filter-status');
		var statusChips = document.getElementById('mn-filter-status-chips');
		var whenInput = document.getElementById('mn-filter-when');
		var whenChips = document.getElementById('mn-filter-when-chips');
		var customDates = document.getElementById('mn-filter-custom-dates');
		var fromInput = document.getElementById('mn-filter-from');
		var toInput = document.getElementById('mn-filter-to');
		var dateHint = document.getElementById('mn-filter-date-hint');
		var mineToggle = document.getElementById('mn-filter-mine');
		var resetButton = document.getElementById('mn-filter-reset');
		var state = { offset: 0 };
		var loadSeq = 0;

		function pad2(n) {
			return n < 10 ? '0' + n : String(n);
		}

		function toYmd(d) {
			return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
		}

		function mondayOf(d) {
			var x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
			var day = x.getDay();
			var diff = day === 0 ? -6 : 1 - day;
			x.setDate(x.getDate() + diff);
			return x;
		}

		function weekRange() {
			var today = todayYmd();
			var parts = today.split('-');
			var base = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
			var start = mondayOf(base);
			var end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6);
			return { from: toYmd(start), to: toYmd(end) };
		}

		function monthRange() {
			var today = todayYmd();
			var parts = today.split('-');
			var y = Number(parts[0]);
			var m = Number(parts[1]) - 1;
			var start = new Date(y, m, 1);
			var end = new Date(y, m + 1, 0);
			return { from: toYmd(start), to: toYmd(end) };
		}

		function filtersDirty() {
			return !!(
				(statusInput && statusInput.value)
				|| (whenInput && whenInput.value)
				|| (fromInput && fromInput.value)
				|| (toInput && toInput.value)
				|| (mineToggle && mineToggle.checked)
			);
		}

		function syncReset() {
			if (!resetButton) {
				return;
			}
			var dirty = filtersDirty();
			resetButton.disabled = !dirty;
			resetButton.setAttribute('aria-disabled', dirty ? 'false' : 'true');
		}

		function setPressedGroup(root, attr, value) {
			if (!root) {
				return;
			}
			var buttons = root.querySelectorAll('[' + attr + ']');
			for (var i = 0; i < buttons.length; i++) {
				var btn = buttons[i];
				var on = (btn.getAttribute(attr) || '') === (value || '');
				btn.classList.toggle('is-active', on);
				btn.setAttribute('aria-pressed', on ? 'true' : 'false');
			}
		}

		function setStatus(value) {
			if (statusInput) {
				statusInput.value = value || '';
			}
			setPressedGroup(statusChips, 'data-mn-status', value || '');
		}

		function showCustomDates(show) {
			if (!customDates) {
				return;
			}
			customDates.hidden = !show;
		}

		function clearDateHint() {
			if (!dateHint) {
				return;
			}
			dateHint.hidden = true;
			dateHint.textContent = '';
			if (fromInput) {
				fromInput.removeAttribute('aria-invalid');
				fromInput.removeAttribute('aria-describedby');
			}
			if (toInput) {
				toInput.removeAttribute('aria-invalid');
				toInput.removeAttribute('aria-describedby');
			}
		}

		function setWhen(value, opts) {
			var options = opts || {};
			var mode = value || '';
			if (whenInput) {
				whenInput.value = mode;
			}
			setPressedGroup(whenChips, 'data-mn-when', mode);
			clearDateHint();

			if (mode === 'week') {
				var week = weekRange();
				if (fromInput) {
					fromInput.value = week.from;
				}
				if (toInput) {
					toInput.value = week.to;
				}
				showCustomDates(false);
			} else if (mode === 'month') {
				var month = monthRange();
				if (fromInput) {
					fromInput.value = month.from;
				}
				if (toInput) {
					toInput.value = month.to;
				}
				showCustomDates(false);
			} else if (mode === 'custom') {
				showCustomDates(true);
				if (options.focusFrom && fromInput) {
					window.setTimeout(function () {
						fromInput.focus();
					}, 0);
				}
			} else {
				if (fromInput) {
					fromInput.value = '';
				}
				if (toInput) {
					toInput.value = '';
				}
				showCustomDates(false);
			}
		}

		function normalizeDates() {
			if (!fromInput || !toInput) {
				return;
			}
			if (!fromInput.value || !toInput.value) {
				clearDateHint();
				return;
			}
			if (fromInput.value <= toInput.value) {
				return;
			}
			var swap = fromInput.value;
			fromInput.value = toInput.value;
			toInput.value = swap;
			if (dateHint) {
				dateHint.hidden = false;
				dateHint.textContent = tr('Date range was swapped so From is before To.');
				fromInput.setAttribute('aria-invalid', 'false');
				toInput.setAttribute('aria-invalid', 'false');
				fromInput.setAttribute('aria-describedby', 'mn-filter-date-hint');
				toInput.setAttribute('aria-describedby', 'mn-filter-date-hint');
			}
			announce(tr('Date range was swapped so From is before To.'), false);
		}

		function applyFilters() {
			normalizeDates();
			state.offset = 0;
			syncReset();
			load();
		}

		function load() {
			var seq = ++loadSeq;
			list.setAttribute('aria-busy', 'true');
			clear(list);
			list.appendChild(skeleton(5));
			api('GET', withQuery(apiUrl('visits'), {
				status: statusInput ? statusInput.value : '',
				from: fromInput ? fromInput.value : '',
				to: toInput ? toInput.value : '',
				mine: mineToggle && mineToggle.checked ? '1' : '',
				offset: state.offset,
			}))
				.then(function (envelope) {
					if (seq !== loadSeq) {
						return;
					}
					render(envelope);
				})
				.catch(function (error) {
					if (seq !== loadSeq) {
						return;
					}
					clear(list);
					if (error.code === 'invalid_query') {
						toast(error.message, 'error');
					} else {
						handleGlobalError(error);
					}
				})
				.finally(function () {
					if (seq === loadSeq) {
						list.removeAttribute('aria-busy');
					}
				});
		}

		function render(envelope) {
			clear(list);
			if (envelope.total === 0) {
				list.appendChild(emptyState(tr('No visits match these filters'), tr('Try widening the date range or clearing filters.')));
				renderPagination(pagination, envelope, onPage);
				announceResults(0, tr('No visits match these filters'));
				return;
			}
			list.appendChild(tableOrCards([
				{
					id: 'equipment',
					label: tr('Visit'),
					render: function (visit) {
						var extra = [];
						if (visit.status === 'done' && visit.doneOn) {
							extra.push(tr('Completed on {date}', { date: fmt(visit.doneOn) }));
						}
						if (visit.assignedUid) {
							extra.push(tr('Assigned to {user}', { user: visit.assignedUid }));
						}
						return tableStack(
							tableLink(
								pageUrl('equipment') + '/' + visit.equipmentId,
								visitSubjectTitle(visit)
							),
							visitSubjectSub(visit, extra)
						);
					},
				},
				{
					id: 'due',
					label: tr('Due'),
					render: function (visit) {
						return tr('Due {date}', { date: fmt(visit.dueOn) });
					},
				},
				{
					id: 'status',
					label: tr('Status'),
					render: function (visit) {
						var meta = statusMeta(visit.status);
						return statusBadge(meta.label, meta.badge, meta.icon);
					},
				},
				{
					id: 'actions',
					label: tr('Actions'),
					actions: true,
					render: function (visit) {
						var actions = visitActions(visit, load);
						return actions.length ? actions : tableCellText(null);
					},
				},
			], envelope.data, { caption: tr('Visits') }));
			renderPagination(pagination, envelope, onPage);
			announceResults(envelope.total);
		}

		function onPage(offset) {
			state.offset = offset;
			load();
		}

		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				applyFilters();
			});
		}
		if (statusChips) {
			statusChips.addEventListener('click', function (event) {
				var btn = event.target.closest('[data-mn-status]');
				if (!btn || !statusChips.contains(btn)) {
					return;
				}
				setStatus(btn.getAttribute('data-mn-status') || '');
				applyFilters();
			});
		}
		if (whenChips) {
			whenChips.addEventListener('click', function (event) {
				var btn = event.target.closest('[data-mn-when]');
				if (!btn || !whenChips.contains(btn)) {
					return;
				}
				var mode = btn.getAttribute('data-mn-when') || '';
				setWhen(mode, { focusFrom: mode === 'custom' });
				if (mode === 'custom' && fromInput && !fromInput.value && toInput && !toInput.value) {
					syncReset();
					return;
				}
				applyFilters();
			});
		}
		if (fromInput) {
			fromInput.addEventListener('change', function () {
				clearDateHint();
				if (whenInput && whenInput.value !== 'custom') {
					setWhen('custom');
				} else {
					showCustomDates(true);
				}
				applyFilters();
			});
		}
		if (toInput) {
			toInput.addEventListener('change', function () {
				clearDateHint();
				if (whenInput && whenInput.value !== 'custom') {
					setWhen('custom');
				} else {
					showCustomDates(true);
				}
				applyFilters();
			});
		}
		if (mineToggle) {
			mineToggle.addEventListener('change', applyFilters);
		}
		if (resetButton) {
			resetButton.addEventListener('click', function () {
				if (resetButton.disabled) {
					return;
				}
				setStatus('');
				if (mineToggle) {
					mineToggle.checked = false;
				}
				setWhen('');
				applyFilters();
			});
		}

		setStatus(statusInput ? statusInput.value : '');
		setWhen(whenInput ? whenInput.value : '');
		syncReset();
		load();
	}

	// ── Page: catalogs ─────────────────────────────────────────────────

	function catalogTypeDialog(kind, existing, onDone) {
		var url = kind === 'equip' ? apiUrl('equipTypes') : apiUrl('maintTypes');
		var codeInput = el('input', { type: 'text', value: existing ? existing.code : '', autocomplete: 'off' });
		if (existing) {
			codeInput.disabled = true;
		}
		var nameInput = el('input', { type: 'text', value: existing ? existing.name : '' });
		var sortInput = el('input', { type: 'number', step: '1', value: existing ? String(existing.sortOrder) : '100' });
		var activeBox = el('input', { type: 'checkbox' });
		activeBox.checked = existing ? !!existing.active : true;
		var f = {
			code: field(tr('Code'), codeInput, {
				required: !existing,
				hint: existing ? tr('The code cannot be changed after creation.') : tr('Lowercase letters, digits and underscores, e.g. heat_pump.'),
			}),
			name: field(tr('Name'), nameInput, { required: true }),
			sortOrder: field(tr('Sort order'), sortInput, { hint: tr('Lower numbers appear first.') }),
		};
		var content = el('div', {}, [
			el('div', { class: 'mn-form-grid' }, [f.code, f.name, f.sortOrder]),
			existing ? el('label', { class: 'mn-checkbox' }, [activeBox, el('span', { text: tr('Type is active (selectable for new entries)') })]) : null,
		]);

		openDialog({
			title: existing
				? (kind === 'equip' ? tr('Edit equipment type') : tr('Edit maintenance type'))
				: (kind === 'equip' ? tr('New equipment type') : tr('New maintenance type')),
			content: content,
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: existing ? tr('Save changes') : tr('Create type'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						var body = {
							name: nameInput.value,
							sortOrder: Math.trunc(Number(sortInput.value)) || 0,
						};
						var request;
						if (existing) {
							body.active = activeBox.checked;
							request = api('PUT', url + '/' + existing.id, body);
						} else {
							body.code = codeInput.value;
							request = api('POST', url, body);
						}
						request.then(function () {
							d.close();
							toast(existing ? tr('Type saved.') : tr('Type created.'), 'success');
							onDone();
						}).catch(function (error) {
							d.setBusy(false);
							if (error.code === 'code_exists') {
								f.code.mnSetError(tr('This code is already in use.'));
								codeInput.focus();
							} else if (!applyFieldErrors(f, error)) {
								d.setError(error.message);
							}
						});
					},
				},
			],
		});
	}

	function pageCatalogs() {
		function showCatalogPanel(key, opts) {
			opts = opts || {};
			var tabs = document.querySelectorAll('[data-mn-catalog]');
			var panels = document.querySelectorAll('[data-mn-catalog-panel]');
			var label = key;
			tabs.forEach(function (tab) {
				var on = (tab.getAttribute('data-mn-catalog') || '') === key;
				tab.classList.toggle('is-active', on);
				tab.setAttribute('aria-selected', on ? 'true' : 'false');
				tab.setAttribute('tabindex', on ? '0' : '-1');
				if (on) {
					label = String(tab.textContent || key).trim() || key;
				}
			});
			panels.forEach(function (panel) {
				var on = (panel.getAttribute('data-mn-catalog-panel') || '') === key;
				if (on) {
					panel.removeAttribute('hidden');
				} else {
					panel.setAttribute('hidden', 'hidden');
				}
			});
			if (opts.announce !== false) {
				announce(tr('Showing {list}', { list: label }), false);
			}
		}

		var catalogNav = document.getElementById('mn-catalogs-toolbar');
		if (catalogNav) {
			var tabNodes = Array.prototype.slice.call(catalogNav.querySelectorAll('[data-mn-catalog]'));
			tabNodes.forEach(function (tab, index) {
				tab.setAttribute('tabindex', index === 0 ? '0' : '-1');
				tab.addEventListener('click', function () {
					showCatalogPanel(tab.getAttribute('data-mn-catalog') || 'equip');
				});
				tab.addEventListener('keydown', function (ev) {
					var i = tabNodes.indexOf(tab);
					if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') {
						ev.preventDefault();
						var next = tabNodes[(i + 1) % tabNodes.length];
						showCatalogPanel(next.getAttribute('data-mn-catalog') || 'equip');
						next.focus();
					} else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') {
						ev.preventDefault();
						var prev = tabNodes[(i - 1 + tabNodes.length) % tabNodes.length];
						showCatalogPanel(prev.getAttribute('data-mn-catalog') || 'equip');
						prev.focus();
					} else if (ev.key === 'Home') {
						ev.preventDefault();
						showCatalogPanel(tabNodes[0].getAttribute('data-mn-catalog') || 'equip');
						tabNodes[0].focus();
					} else if (ev.key === 'End') {
						ev.preventDefault();
						var last = tabNodes[tabNodes.length - 1];
						showCatalogPanel(last.getAttribute('data-mn-catalog') || 'equip');
						last.focus();
					}
				});
			});
			showCatalogPanel('equip', { announce: false });
		}

		renderKind('equip', 'mn-equip-types', 'mn-equip-types-actions');
		renderKind('maint', 'mn-maint-types', 'mn-maint-types-actions');
		renderProcedures();
		renderSimpleCatalog('skills', 'mn-skills', 'mn-skills-actions', tr('Skills'));
		renderSimpleCatalog('kitTemplates', 'mn-kit-templates', 'mn-kit-templates-actions', tr('Kit templates'));

		function renderProcedures() {
			var box = document.getElementById('mn-procedures');
			var actions = document.getElementById('mn-procedures-actions');
			var loadSeq = 0;
			if (!box || !actions) {
				return;
			}
			clear(actions);

			function showIfResultSelect(selected) {
				var select = el('select', { class: 'mn-input form-select' });
				[
					{ value: '', label: tr('Always visible') },
					{ value: 'ok', label: 'ok' },
					{ value: 'fail', label: 'fail' },
					{ value: 'na', label: 'na' },
					{ value: 'any_answered', label: 'any_answered' },
				].forEach(function (opt) {
					select.appendChild(el('option', {
						value: opt.value,
						text: opt.label,
						selected: (selected || '') === opt.value ? 'selected' : null,
					}));
				});
				return select;
			}

			function buildItemEditor(initial) {
				var itemsHost = el('div', { class: 'mn-form-grid', 'aria-label': tr('Checklist items') });
				var itemRows = [];

				function collectItems() {
					return itemRows.map(function (row, index) {
						var showIfCode = String(row.showIfCode.value || '').trim();
						var showIfResult = String(row.showIfResult.value || '').trim();
						return {
							code: String(row.code.value || '').trim(),
							label: String(row.label.value || '').trim(),
							required: !!row.required.checked,
							sortOrder: index + 1,
							showIfItemCode: showIfCode || null,
							showIfResult: showIfResult || null,
						};
					});
				}

				function addRow(seed) {
					seed = seed || {};
					var codeInput = el('input', { type: 'text', class: 'mn-input form-input', value: seed.code || '', autocomplete: 'off' });
					var labelInput = el('input', { type: 'text', class: 'mn-input form-input', value: seed.label || '', autocomplete: 'off' });
					var requiredInput = el('input', { type: 'checkbox', checked: seed.required === false ? null : 'checked' });
					var showIfCode = el('input', { type: 'text', class: 'mn-input form-input', value: seed.showIfItemCode || '', autocomplete: 'off', placeholder: tr('Parent item code') });
					var showIfResult = showIfResultSelect(seed.showIfResult || '');
					var removeBtn = el('button', { type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact', text: tr('Remove item') });
					var wrap = el('div', { class: 'mn-form-grid mn-procedure-item' }, [
						field(tr('Item code'), codeInput, { required: true }),
						field(tr('Label'), labelInput, { required: true }),
						field(tr('Required'), requiredInput),
						field(tr('Show if item'), showIfCode, { hint: tr('Optional: another item code in this procedure.') }),
						field(tr('Show if result'), showIfResult),
						removeBtn,
					]);
					var row = { wrap: wrap, code: codeInput, label: labelInput, required: requiredInput, showIfCode: showIfCode, showIfResult: showIfResult };
					removeBtn.addEventListener('click', function () {
						if (itemRows.length <= 1) {
							return;
						}
						itemsHost.removeChild(wrap);
						itemRows = itemRows.filter(function (r) { return r !== row; });
					});
					itemRows.push(row);
					itemsHost.appendChild(wrap);
				}

				(initial && initial.length ? initial : [{ code: '', label: '', required: true }]).forEach(addRow);
				var addBtn = el('button', { type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Add item') });
				addBtn.addEventListener('click', function () { addRow({}); });
				return {
					root: el('div', {}, [itemsHost, addBtn]),
					collect: collectItems,
				};
			}

			function procedureDialog(existing, onSaved) {
				var isEdit = !!existing;
				var codeInput = el('input', {
					type: 'text',
					class: 'mn-input form-input',
					value: existing && existing.code ? existing.code : '',
					autocomplete: 'off',
					disabled: isEdit ? 'disabled' : null,
				});
				var titleInput = el('input', { type: 'text', class: 'mn-input form-input', value: (existing && existing.title) || '', autocomplete: 'off' });
				var verticalInput = el('input', { type: 'text', class: 'mn-input form-input', value: (existing && existing.vertical) || '', autocomplete: 'off' });
				var localeInput = el('input', { type: 'text', class: 'mn-input form-input', value: (existing && existing.locale) || 'en', autocomplete: 'off' });
				var itemsEditor = buildItemEditor(existing && existing.items ? existing.items : null);
				openDialog({
					title: isEdit ? tr('Edit procedure') : tr('New procedure'),
					content: el('div', { class: 'mn-form-grid' }, [
						field(tr('Code'), codeInput, { required: !isEdit }),
						field(tr('Title'), titleInput, { required: true }),
						field(tr('Vertical'), verticalInput, { hint: tr('Optional short id, e.g. shk.') }),
						field(tr('Locale'), localeInput),
						el('div', { class: 'mn-form-wide' }, [
							el('h3', { class: 'mn-card__title', text: tr('Checklist items') }),
							itemsEditor.root,
						]),
					]),
					actions: [
						{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
						{
							label: isEdit ? tr('Save') : tr('Create'),
							variant: 'mn-btn--primary',
							onClick: function (d) {
								var items = itemsEditor.collect();
								if (!items.length || items.some(function (it) { return !it.code || !it.label; })) {
									d.setError(tr('Every checklist item needs a code and label.'));
									return;
								}
								d.setBusy(true);
								d.setError(null);
								var body = {
									title: titleInput.value.trim(),
									vertical: verticalInput.value.trim() || null,
									locale: localeInput.value.trim() || 'en',
									items: items,
								};
								var req = isEdit
									? api('PUT', apiUrl('procedures') + '/' + existing.id, body)
									: api('POST', apiUrl('procedures'), Object.assign({ code: codeInput.value.trim() }, body));
								req.then(function () {
									d.close();
									toast(isEdit ? tr('Procedure saved.') : tr('Procedure created.'), 'success');
									onSaved();
								}).catch(function (error) {
									if (error.code === 'procedure_in_use') {
										d.setBusy(false);
										d.setError(tr('This procedure is used by work orders. Fork it to change checklist items.'));
										return;
									}
									d.setBusy(false);
									d.setError(error.message);
								});
							},
						},
					],
				});
			}

			if (ctx.isOffice) {
				actions.appendChild(el('button', {
					type: 'button',
					class: 'mn-btn mn-btn--primary button',
					text: tr('New procedure'),
					onClick: function () { procedureDialog(null, load); },
				}));
				var exportBtn = el('button', {
					type: 'button',
					class: 'mn-btn mn-btn--secondary button',
					text: tr('Export pack'),
					hidden: 'hidden',
				});
				exportBtn.addEventListener('click', function () {
					var codeInput = el('input', { type: 'text', class: 'mn-input form-input', value: 'builtin-shk-v1', autocomplete: 'off' });
					openDialog({
						title: tr('Export pack'),
						content: el('div', { class: 'mn-form-grid' }, [
							field(tr('Pack code'), codeInput, { required: true }),
						]),
						actions: [
							{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
							{
								label: tr('Download'),
								variant: 'mn-btn--primary',
								onClick: function (d) {
									var code = codeInput.value.trim();
									if (!code) {
										d.setError(tr('Pack code is required.'));
										return;
									}
									d.close();
									window.open(withQuery(apiUrl('proceduresPack'), { pack: code }), '_blank');
								},
							},
						],
					});
				});
				var importBtn = el('button', {
					type: 'button',
					class: 'mn-btn mn-btn--secondary button',
					text: tr('Import pack'),
					hidden: 'hidden',
				});
				importBtn.addEventListener('click', function () {
					var ta = el('textarea', { class: 'mn-input form-textarea', rows: '8' });
					openDialog({
						title: tr('Import pack'),
						content: el('div', { class: 'mn-form-grid' }, [
							field(tr('Procedures'), ta, { required: true, wide: true, hint: tr('Paste mn_procedure_pack_v1 JSON.') }),
						]),
						actions: [
							{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
							{
								label: tr('Preview'),
								variant: 'mn-btn--secondary',
								onClick: function (d) {
									var raw = ta.value.trim();
									var parsed;
									try {
										parsed = JSON.parse(raw);
									} catch (e) {
										d.setError(tr('This value has the wrong format.'));
										return;
									}
									var procs = (parsed && parsed.procedures) || [];
									if (!Array.isArray(procs) || !procs.length) {
										d.setError(tr('Pack must list at least one procedure.'));
										return;
									}
									var preview = el('ul', { class: 'mn-listing' });
									procs.forEach(function (proc) {
										preview.appendChild(el('li', {
											text: (proc.code || '?') + ' — ' + (proc.title || tr('Procedure'))
												+ ' (' + String((proc.items && proc.items.length) || 0) + ' ' + tr('Checklist items') + ')',
										}));
									});
									openDialog({
										title: tr('Pack preview'),
										content: el('div', { class: 'mn-form-grid' }, [
											el('p', {
												text: tr('Pack code') + ': ' + String(parsed.pack_code || parsed.packCode || '—')
													+ ' · ' + tr('Vertical') + ': ' + String(parsed.vertical || '—'),
											}),
											preview,
										]),
										actions: [
											{ label: tr('Back'), variant: 'mn-btn--tertiary', onClick: function (d2) { d2.close(); } },
											{
												label: tr('Import pack'),
												variant: 'mn-btn--primary',
												onClick: function (d2) {
													d2.close();
													d.setBusy(true);
													api('POST', apiUrl('proceduresPack'), { packJson: raw, overwrite: 0 })
														.then(function () {
															d.close();
															toast(tr('Pack imported.'), 'success');
															load();
														})
														.catch(function (error) {
															d.setBusy(false);
															if (error.code === 'pack_exists') {
																var packCode = '';
																if (error.details && error.details.packCode) {
																	packCode = String(error.details.packCode);
																} else {
																	packCode = String(parsed.pack_code || parsed.packCode || '');
																}
																var confirmInput = el('input', {
																	type: 'text',
																	class: 'mn-input form-input',
																	autocomplete: 'off',
																	'aria-required': 'true',
																});
																openDialog({
																	title: tr('Overwrite pack?'),
																	content: el('div', { class: 'mn-form-grid' }, [
																		el('p', { text: tr('A pack with this code was already imported. Type the pack code to confirm overwrite.') }),
																		packCode ? el('p', { class: 'mn-muted', text: tr('Pack code') + ': ' + packCode }) : null,
																		field(tr('Pack code'), confirmInput, { required: true }),
																	]),
																	actions: [
																		{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d3) { d3.close(); } },
																		{
																			label: tr('Overwrite'),
																			variant: 'mn-btn--primary',
																			onClick: function (d3) {
																				var typed = String(confirmInput.value || '').trim();
																				if (!typed || (packCode && typed !== packCode)) {
																					d3.setError(tr('Type the exact pack code to overwrite.'));
																					return;
																				}
																				d3.setBusy(true);
																				api('POST', apiUrl('proceduresPack'), { packJson: raw, overwrite: 1 })
																					.then(function () {
																						d.close();
																						d3.close();
																						toast(tr('Pack imported.'), 'success');
																						load();
																					})
																					.catch(function (err2) {
																						d3.setBusy(false);
																						d3.setError(err2.message);
																					});
																			},
																		},
																	],
																});
																return;
															}
															d.setError(error.message);
														});
												},
											},
										],
									});
								},
							},
						],
					});
				});
				var packOverflow = visitOverflowMenu([
					{ label: tr('Export pack'), onClick: function () { exportBtn.click(); } },
					{ label: tr('Import pack'), onClick: function () { importBtn.click(); } },
				]);
				if (packOverflow) {
					actions.appendChild(packOverflow);
				}
			}

			function load() {
				var seq = ++loadSeq;
				box.setAttribute('aria-busy', 'true');
				clear(box);
				box.appendChild(skeleton(3));
				api('GET', apiUrl('procedures'))
					.then(function (envelope) {
						if (seq !== loadSeq) {
							return;
						}
						clear(box);
						var rows = (envelope && envelope.data) || [];
						if (!rows.length) {
							var emptyAction = null;
							if (ctx.isOffice) {
								emptyAction = el('button', {
									type: 'button',
									class: 'mn-btn mn-btn--primary button',
									text: tr('New procedure'),
									onClick: function () { procedureDialog(null, load); },
								});
							}
							box.appendChild(emptyState(
								tr('No procedures yet'),
								tr('Create a checklist template or import a pack.'),
								emptyAction
							));
							return;
						}
						var tableRows = rows.map(function (proc) {
							var rowActions = [];
							if (ctx.isOffice) {
								rowActions.push(el('button', {
									type: 'button',
									class: 'mn-btn mn-btn--secondary mn-btn--compact',
									text: tr('Edit'),
									onClick: function () {
										api('GET', apiUrl('procedures') + '/' + proc.id)
											.then(function (detail) { procedureDialog(detail, load); })
											.catch(handleGlobalError);
									},
								}));
								var more = visitOverflowMenu([
									{
										label: tr('Fork'),
										onClick: function () {
											api('POST', apiUrl('procedures') + '/' + proc.id + '/fork', {})
												.then(function () {
													toast(tr('Procedure forked.'), 'success');
													load();
												})
												.catch(handleGlobalError);
										},
									},
									{
										label: proc.active === false ? tr('Activate') : tr('Deactivate'),
										onClick: function () {
											api('PUT', apiUrl('procedures') + '/' + proc.id, { active: proc.active === false })
												.then(function () {
													toast(tr('Status updated.'), 'success');
													load();
												})
												.catch(handleGlobalError);
										},
									},
								]);
								if (more) {
									rowActions.push(more);
								}
							}
							return { proc: proc, rowActions: rowActions };
						});
						box.appendChild(tableOrCards([
							{
								id: 'procedure',
								label: tr('Procedure'),
								render: function (row) {
									var proc = row.proc;
									var sub = (proc.code || '') + (proc.packCode || proc.sourcePack ? ' · ' + (proc.packCode || proc.sourcePack) : '');
									return catalogNameCell(proc.title || proc.code, sub || null, {
										inactive: proc.active === false,
									});
								},
							},
							{
								id: 'actions',
								label: tr('Actions'),
								actions: true,
								render: function (row) {
									return row.rowActions.length ? row.rowActions : tableCellText(null);
								},
							},
						], tableRows, {
							caption: tr('Procedures'),
							rowClass: function (row) {
								return row.proc.active === false ? 'mn-table__row--inactive' : '';
							},
						}));
					})
					.catch(function (error) {
						if (seq !== loadSeq) {
							return;
						}
						handleGlobalError(error);
					})
					.finally(function () {
						if (seq === loadSeq) {
							box.removeAttribute('aria-busy');
						}
					});
			}
			load();
		}

		function renderSimpleCatalog(apiKey, boxId, actionsId, title) {
			var box = document.getElementById(boxId);
			var actions = document.getElementById(actionsId);
			var loadSeq = 0;
			if (!box || !actions) {
				return;
			}
			clear(actions);

			function kitLinesEditor(initial) {
				var host = el('div', { class: 'mn-form-grid' });
				var rows = [];
				function collect() {
					return rows.map(function (row, index) {
						return {
							label: String(row.label.value || '').trim(),
							lineType: row.lineType.value || 'part',
							qtyRequired: Math.max(1, parseInt(row.qty.value, 10) || 1),
							optional: !!row.optional.checked,
							sortOrder: index + 1,
						};
					});
				}
				function addLine(seed) {
					seed = seed || {};
					var labelInput = el('input', { type: 'text', class: 'mn-input form-input', value: seed.label || '', autocomplete: 'off' });
					var typeSelect = el('select', { class: 'mn-input form-select' }, [
						el('option', { value: 'part', text: tr('Part'), selected: (seed.lineType || 'part') === 'part' ? 'selected' : null }),
						el('option', { value: 'tool', text: tr('Tool'), selected: seed.lineType === 'tool' ? 'selected' : null }),
					]);
					var qtyInput = el('input', { type: 'number', class: 'mn-input form-input', min: '1', step: '1', value: String(seed.qtyRequired || 1) });
					var optionalInput = el('input', { type: 'checkbox', checked: seed.optional ? 'checked' : null });
					var removeBtn = el('button', { type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact', text: tr('Remove line') });
					var wrap = el('div', { class: 'mn-form-grid' }, [
						field(tr('Label'), labelInput, { required: true }),
						field(tr('Type'), typeSelect),
						field(tr('Quantity'), qtyInput, { required: true }),
						field(tr('Optional'), optionalInput),
						removeBtn,
					]);
					var row = { wrap: wrap, label: labelInput, lineType: typeSelect, qty: qtyInput, optional: optionalInput };
					removeBtn.addEventListener('click', function () {
						if (rows.length <= 1) {
							return;
						}
						host.removeChild(wrap);
						rows = rows.filter(function (r) { return r !== row; });
					});
					rows.push(row);
					host.appendChild(wrap);
				}
				(initial && initial.length ? initial : [{ label: '', lineType: 'part', qtyRequired: 1 }]).forEach(addLine);
				var addBtn = el('button', { type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Add line') });
				addBtn.addEventListener('click', function () { addLine({}); });
				return { root: el('div', {}, [host, addBtn]), collect: collect };
			}

			function openKitDialog(existing, onSaved) {
				var isEdit = !!existing;
				var kitCode = el('input', { type: 'text', class: 'mn-input form-input', value: (existing && existing.code) || '', autocomplete: 'off', disabled: isEdit ? 'disabled' : null });
				var kitName = el('input', { type: 'text', class: 'mn-input form-input', value: (existing && existing.name) || '', autocomplete: 'off' });
				var linesEditor = kitLinesEditor(existing && existing.lines ? existing.lines : null);
				openDialog({
					title: isEdit ? tr('Edit kit template') : tr('New kit template'),
					content: el('div', { class: 'mn-form-grid' }, [
						field(tr('Code'), kitCode, { required: !isEdit }),
						field(tr('Name'), kitName, { required: true }),
						el('div', { class: 'mn-form-wide' }, [
							el('h3', { class: 'mn-card__title', text: tr('Lines') }),
							linesEditor.root,
						]),
					]),
					actions: [
						{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
						{
							label: isEdit ? tr('Save') : tr('Create'),
							variant: 'mn-btn--primary',
							onClick: function (d) {
								var lines = linesEditor.collect();
								if (!lines.length || lines.some(function (line) { return !line.label; })) {
									d.setError(tr('A line label is required.'));
									return;
								}
								d.setBusy(true);
								d.setError(null);
								var body = { name: kitName.value.trim(), lines: lines };
								var req = isEdit
									? api('PUT', apiUrl('kitTemplates') + '/' + existing.id, body)
									: api('POST', apiUrl('kitTemplates'), Object.assign({ code: kitCode.value.trim() }, body));
								req.then(function () {
									d.close();
									toast(isEdit ? tr('Kit template saved.') : tr('Kit template created.'), 'success');
									onSaved();
								}).catch(function (error) {
									d.setBusy(false);
									d.setError(error.message);
								});
							},
						},
					],
				});
			}

			function openGrantSkillsDialog() {
				var picker = attachUserPicker({ placeholder: tr('Start typing a name…') });
				var skillsHost = el('div', { class: 'mn-form-grid', role: 'group', 'aria-label': tr('Skills') });
				var skillChecks = [];
				api('GET', apiUrl('skills')).then(function (envelope) {
					var skills = (envelope && envelope.data) || [];
					skills.forEach(function (skill) {
						if (skill.active === false) {
							return;
						}
						var cb = el('input', { type: 'checkbox', value: String(skill.id) });
						skillChecks.push({ id: skill.id, input: cb });
						skillsHost.appendChild(field(skill.name || skill.code, cb));
					});
				}).catch(handleGlobalError);

				function loadUserSkills() {
					var uid = picker.getValue();
					if (!uid) {
						return;
					}
					api('GET', apiUrl('users') + '/' + encodeURIComponent(uid) + '/skills')
						.then(function (data) {
							var ids = (data && data.skillIds) || [];
							skillChecks.forEach(function (row) {
								row.input.checked = ids.indexOf(row.id) !== -1 || ids.indexOf(Number(row.id)) !== -1;
							});
						})
						.catch(handleGlobalError);
				}
				picker.root.addEventListener('mn-user-selected', loadUserSkills);
				picker.root.addEventListener('change', loadUserSkills);
				openDialog({
					title: tr('Grant skills'),
					content: el('div', { class: 'mn-form-grid mn-form-grid--single' }, [
						field(tr('Technician'), picker.root, {
							required: true,
							hint: tr('Skills load when you pick a person.'),
						}),
						skillsHost,
					]),
					initialFocus: 'input[type="search"]',
					actions: [
						{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
						{
							label: tr('Save'),
							variant: 'mn-btn--primary',
							onClick: function (d) {
								var uid = picker.getValue();
								if (!uid) {
									d.setError(tr('Pick a technician first.'));
									return;
								}
								var skillIds = skillChecks.filter(function (row) { return row.input.checked; }).map(function (row) { return Number(row.id); });
								d.setBusy(true);
								api('PUT', apiUrl('users') + '/' + encodeURIComponent(uid) + '/skills', { skillIds: skillIds })
									.then(function () {
										d.close();
										toast(tr('Skills updated.'), 'success');
									})
									.catch(function (error) {
										d.setBusy(false);
										d.setError(error.message);
									});
							},
						},
					],
				});
			}

			if (ctx.isOffice) {
				if (apiKey === 'skills') {
					actions.appendChild(el('button', {
						type: 'button',
						class: 'mn-btn mn-btn--primary button',
						text: tr('New skill'),
						onClick: function () {
							var codeInput = el('input', { type: 'text', class: 'mn-input form-input', autocomplete: 'off' });
							var nameInput = el('input', { type: 'text', class: 'mn-input form-input', autocomplete: 'off' });
							openDialog({
								title: tr('New skill'),
								content: el('div', { class: 'mn-form-grid mn-form-grid--single' }, [
									field(tr('Code'), codeInput, { required: true }),
									field(tr('Name'), nameInput, { required: true }),
								]),
								actions: [
									{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
									{
										label: tr('Create'),
										variant: 'mn-btn--primary',
										onClick: function (d) {
											d.setBusy(true);
											d.setError(null);
											api('POST', apiUrl('skills'), {
												code: codeInput.value.trim(),
												name: nameInput.value.trim(),
											}).then(function () {
												d.close();
												toast(tr('Skill created.'), 'success');
												load();
											}).catch(function (error) {
												d.setBusy(false);
												d.setError(error.message);
											});
										},
									},
								],
							});
						},
					}));
					actions.appendChild(el('button', {
						type: 'button',
						class: 'mn-btn mn-btn--secondary button',
						text: tr('Grant skills'),
						onClick: openGrantSkillsDialog,
					}));
				} else {
					actions.appendChild(el('button', {
						type: 'button',
						class: 'mn-btn mn-btn--primary button',
						text: tr('New kit template'),
						onClick: function () { openKitDialog(null, load); },
					}));
				}
			}

			function editSkill(row) {
				var nameInput = el('input', { type: 'text', class: 'mn-input form-input', value: row.name || '', autocomplete: 'off' });
				var activeInput = el('input', { type: 'checkbox', checked: row.active === false ? null : 'checked' });
				openDialog({
					title: tr('Edit skill'),
					content: el('div', { class: 'mn-form-grid' }, [
						field(tr('Name'), nameInput, { required: true }),
						field(tr('Active'), activeInput),
					]),
					actions: [
						{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
						{
							label: tr('Save'),
							variant: 'mn-btn--primary',
							onClick: function (d) {
								d.setBusy(true);
								api('PUT', apiUrl('skills') + '/' + row.id, {
									name: nameInput.value.trim(),
									active: !!activeInput.checked,
								}).then(function () {
									d.close();
									toast(tr('Skill saved.'), 'success');
									load();
								}).catch(function (error) {
									d.setBusy(false);
									d.setError(error.message);
								});
							},
						},
					],
				});
			}

			function load() {
				var seq = ++loadSeq;
				box.setAttribute('aria-busy', 'true');
				clear(box);
				box.appendChild(skeleton(3));
				api('GET', apiUrl(apiKey))
					.then(function (envelope) {
						if (seq !== loadSeq) {
							return;
						}
						clear(box);
						var rows = (envelope && envelope.data) || [];
						if (!rows.length) {
							if (apiKey === 'skills') {
								var skillEmpty = null;
								if (ctx.isOffice) {
									skillEmpty = el('button', {
										type: 'button',
										class: 'mn-btn mn-btn--primary button',
										text: tr('New skill'),
										onClick: function () {
											actions.querySelector('button') && actions.querySelector('button').click();
										},
									});
								}
								box.appendChild(emptyState(
									tr('No skills yet'),
									tr('Add a skill code, then grant it to technicians.'),
									skillEmpty
								));
							} else {
								var kitEmpty = null;
								if (ctx.isOffice) {
									kitEmpty = el('button', {
										type: 'button',
										class: 'mn-btn mn-btn--primary button',
										text: tr('New kit template'),
										onClick: function () { openKitDialog(null, load); },
									});
								}
								box.appendChild(emptyState(
									tr('No kit templates yet'),
									tr('Create a parts list for packing vans.'),
									kitEmpty
								));
							}
							return;
						}
						var tableRows = rows.map(function (row) {
							var sub = row.code || title;
							if (apiKey === 'kitTemplates' && Array.isArray(row.lines)) {
								sub += ' · ' + tr('Lines') + ': ' + String(row.lines.length);
							}
							var onEdit = null;
							if (ctx.isOffice && apiKey === 'skills') {
								onEdit = function () { editSkill(row); };
							}
							if (ctx.isOffice && apiKey === 'kitTemplates') {
								onEdit = function () {
									api('GET', apiUrl('kitTemplates') + '/' + row.id)
										.then(function (detail) { openKitDialog(detail, load); })
										.catch(handleGlobalError);
								};
							}
							return { row: row, sub: sub, onEdit: onEdit };
						});
						box.appendChild(tableOrCards([
							{
								id: 'name',
								label: title,
								render: function (entry) {
									var row = entry.row;
									return catalogNameCell(
										row.name || row.title || row.code || ('#' + row.id),
										entry.sub,
										{
											inactive: row.active === false,
											onEdit: entry.onEdit,
										},
									);
								},
							},
						], tableRows, {
							caption: title,
							rowClass: function (entry) {
								return entry.row.active === false ? 'mn-table__row--inactive' : '';
							},
						}));
					})
					.catch(function (error) {
						if (seq !== loadSeq) {
							return;
						}
						handleGlobalError(error);
					})
					.finally(function () {
						if (seq === loadSeq) {
							box.removeAttribute('aria-busy');
						}
					});
			}
			load();
		}

		function renderKind(kind, boxId, actionsId) {
			var box = document.getElementById(boxId);
			var actions = document.getElementById(actionsId);
			var url = kind === 'equip' ? apiUrl('equipTypes') : apiUrl('maintTypes');
			var loadSeq = 0;

			clear(actions);
			if (ctx.isOffice) {
				actions.appendChild(el('button', {
					type: 'button', class: 'mn-btn mn-btn--primary button',
					onClick: function () { catalogTypeDialog(kind, null, load); },
				}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), kind === 'equip' ? tr('New equipment type') : tr('New maintenance type')]));
			}

			function load() {
				var seq = ++loadSeq;
				box.setAttribute('aria-busy', 'true');
				clear(box);
				box.appendChild(skeleton(3));
				loadAllCatalog(url)
					.then(function (types) {
						if (seq !== loadSeq) {
							return;
						}
						clear(box);
						if (types.length === 0) {
							var typeEmpty = null;
							if (ctx.isOffice) {
								typeEmpty = el('button', {
									type: 'button',
									class: 'mn-btn mn-btn--primary button',
									text: kind === 'equip' ? tr('New equipment type') : tr('New maintenance type'),
									onClick: function () { catalogTypeDialog(kind, null, load); },
								});
							}
							box.appendChild(emptyState(
								tr('No types yet'),
								tr('Create the first one to categorise your data.'),
								typeEmpty
							));
							return;
						}
						box.appendChild(tableOrCards([
							{
								id: 'name',
								label: tr('Name'),
								render: function (type) {
									return catalogNameCell(type.name, type.code, {
										inactive: !type.active,
										onEdit: ctx.isOffice
											? function () { catalogTypeDialog(kind, type, load); }
											: null,
									});
								},
							},
						], types, {
							caption: kind === 'equip' ? tr('Equipment types') : tr('Maintenance types'),
							rowClass: function (type) {
								return type.active ? '' : 'mn-table__row--inactive';
							},
						}));
					})
					.catch(function (error) {
						if (seq !== loadSeq) {
							return;
						}
						handleGlobalError(error);
					})
					.finally(function () {
						if (seq === loadSeq) {
							box.removeAttribute('aria-busy');
						}
					});
			}

			load();
		}
	}

	// ── Page: settings ─────────────────────────────────────────────────

	function idListEditor(options) {
		var ids = options.ids.slice();
		var kind = options.kind === 'group' ? 'group' : 'user';
		var searchKey = kind === 'group' ? 'groupsSearch' : 'usersSearch';
		var wrap = el('div', { class: 'mn-field mn-id-directory' });
		fieldSeq += 1;
		var base = 'mn-idlist-' + fieldSeq;
		var chips = el('ul', { class: 'mn-chips', 'aria-label': options.label });
		var error = el('p', { class: 'mn-field__error', hidden: true });
		var combo = el('input', {
			type: 'search',
			id: base + '-combo',
			class: 'mn-input mn-user-picker__input',
			autocomplete: 'off',
			role: 'combobox',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': base + '-list',
			'aria-haspopup': 'listbox',
			placeholder: options.placeholder || (kind === 'group'
				? tr('Start typing a group name…')
				: tr('Start typing a name…')),
		});
		var list = el('ul', {
			id: base + '-list',
			class: 'mn-user-picker__list',
			role: 'listbox',
			hidden: true,
		});
		var rows = [];
		var activeIdx = -1;
		var labels = Object.create(null);
		(options.ids || []).forEach(function (id) {
			labels[id] = id;
		});

		function setError(message) {
			if (message) {
				error.textContent = message;
				error.hidden = false;
			} else {
				error.textContent = '';
				error.hidden = true;
			}
		}

		function formatRow(row) {
			var dn = row.displayName || row.id;
			return dn + (row.id && dn !== row.id ? ' (' + row.id + ')' : '');
		}

		function chipLabel(id) {
			return labels[id] || id;
		}

		function renderChips() {
			clear(chips);
			if (ids.length === 0) {
				chips.appendChild(el('li', { class: 'mn-chips__empty', text: options.emptyText }));
				return;
			}
			ids.forEach(function (id) {
				chips.appendChild(el('li', { class: 'mn-chip' }, [
					el('span', { text: chipLabel(id) }),
					el('button', {
						type: 'button',
						class: 'mn-chip__remove',
						'aria-label': tr('Remove {id}', { id: chipLabel(id) }),
						html: ICONS.x,
						onClick: function () {
							ids = ids.filter(function (x) { return x !== id; });
							renderChips();
							options.onChange(ids, setError);
						},
					}),
				]));
			});
		}

		function closeList() {
			list.hidden = true;
			combo.setAttribute('aria-expanded', 'false');
			activeIdx = -1;
		}

		function renderOptions() {
			clear(list);
			var pickable = rows.filter(function (row) { return ids.indexOf(row.id) === -1; });
			if (pickable.length === 0) {
				list.appendChild(el('li', {
					class: 'mn-user-picker__empty',
					role: 'presentation',
					text: kind === 'group' ? tr('No groups match your search.') : tr('No users match your search.'),
				}));
				return;
			}
			pickable.forEach(function (row, i) {
				list.appendChild(el('li', {
					class: 'mn-user-picker__option' + (i === activeIdx ? ' is-active' : ''),
					role: 'option',
					id: base + '-opt-' + i,
					tabIndex: -1,
					text: formatRow(row),
					onMouseDown: function (ev) {
						ev.preventDefault();
						applySelection(row);
					},
				}));
			});
		}

		function openList() {
			if (!list.children.length) {
				return;
			}
			list.hidden = false;
			combo.setAttribute('aria-expanded', 'true');
		}

		function applySelection(row) {
			if (!row || !row.id || ids.indexOf(row.id) !== -1) {
				closeList();
				combo.value = '';
				return;
			}
			labels[row.id] = formatRow(row);
			var candidate = ids.concat([row.id]);
			options.onChange(candidate, function (message) {
				setError(message);
				if (!message) {
					ids = candidate;
					combo.value = '';
					renderChips();
				}
			});
			closeList();
		}

		var runSearch = debounce(function (q) {
			if (!ctx.urls || !ctx.urls.api || !ctx.urls.api[searchKey]) {
				setError(tr('Directory search is unavailable. Reload the page and try again.'));
				return;
			}
			api('GET', withQuery(apiUrl(searchKey), { q: q, limit: '25' }))
				.then(function (payload) {
					rows = payload.data || [];
					activeIdx = rows.length ? 0 : -1;
					renderOptions();
					openList();
				})
				.catch(function () {
					rows = [];
					renderOptions();
					openList();
				});
		}, 220);

		combo.addEventListener('input', function () {
			var q = combo.value.trim();
			if (q.length < 2) {
				rows = [];
				clear(list);
				closeList();
				return;
			}
			runSearch(q);
		});
		combo.addEventListener('keydown', function (ev) {
			if (list.hidden) {
				return;
			}
			var pickable = rows.filter(function (row) { return ids.indexOf(row.id) === -1; });
			if (ev.key === 'ArrowDown') {
				ev.preventDefault();
				activeIdx = Math.min(pickable.length - 1, activeIdx + 1);
				renderOptions();
				openList();
			} else if (ev.key === 'ArrowUp') {
				ev.preventDefault();
				activeIdx = Math.max(0, activeIdx - 1);
				renderOptions();
				openList();
			} else if (ev.key === 'Enter' && activeIdx >= 0 && pickable[activeIdx]) {
				ev.preventDefault();
				applySelection(pickable[activeIdx]);
			} else if (ev.key === 'Escape') {
				ev.preventDefault();
				closeList();
			}
		});
		combo.addEventListener('blur', function () {
			window.setTimeout(closeList, 120);
		});

		wrap.appendChild(el('label', { class: 'mn-field__label', for: base + '-combo', text: options.label }));
		wrap.appendChild(el('div', { class: 'mn-user-picker__wrap' }, [combo, list]));
		if (options.hint) {
			wrap.appendChild(el('p', { class: 'mn-field__hint', text: options.hint }));
		}
		wrap.appendChild(error);
		wrap.appendChild(chips);
		renderChips();
		return wrap;
	}

	function pageSettings() {
		if (!ctx.isAppAdmin) {
			return;
		}
		var accessBox = document.getElementById('mn-settings-access');
		var rolesBox = document.getElementById('mn-settings-roles');
		var flangeBox = document.getElementById('mn-settings-inventory-flange');
		var policiesBox = document.getElementById('mn-settings-policies');
		var capacityBox = document.getElementById('mn-settings-capacity');
		var licenseBox = document.getElementById('mn-settings-license');
		// Hub has no hosts — underpages mount one (or a few) boxes only.
		var needsConfig = !!(accessBox || rolesBox || flangeBox || policiesBox);
		if (!needsConfig && !capacityBox && !licenseBox) {
			return;
		}

		if (needsConfig) {
			api('GET', apiUrl('config'))
				.then(function (config) {
					renderAccess(config);
					renderRoles(config);
					renderInventoryFlange(config);
					renderPolicies(config);
				})
				.catch(handleGlobalError)
				.finally(function () {
					if (accessBox) accessBox.removeAttribute('aria-busy');
					if (rolesBox) rolesBox.removeAttribute('aria-busy');
					if (flangeBox) flangeBox.removeAttribute('aria-busy');
					if (policiesBox) policiesBox.removeAttribute('aria-busy');
				});
		}
		if (capacityBox) {
			loadCapacity();
		}
		if (licenseBox) {
			loadLicense();
		}

		function saveAccess(patch, done) {
			api('POST', apiUrl('configAccess'), patch)
				.then(function () {
					toast(tr('Access settings saved.'), 'success');
					done(null);
				})
				.catch(function (error) {
					done(error.message);
				});
		}

		function saveOffice(patch, done) {
			api('POST', apiUrl('configOffice'), patch)
				.then(function () {
					toast(tr('Role settings saved.'), 'success');
					done(null);
				})
				.catch(function (error) {
					done(error.message);
				});
		}

		function savePolicies(patch, done) {
			api('POST', apiUrl('configPolicies'), patch)
				.then(function (config) {
					// Debounce success toast — policy toggles fire often.
					if (savePolicies._toastTimer) {
						window.clearTimeout(savePolicies._toastTimer);
					}
					savePolicies._toastTimer = window.setTimeout(function () {
						toast(tr('Work policies saved.'), 'success');
					}, 700);
					done(null, config);
				})
				.catch(function (error) {
					done(error.message);
				});
		}

		function renderPolicies(config) {
			if (!policiesBox) {
				return;
			}
			clear(policiesBox);
			var p = config.policies || {};
			var checklistSelect = el('select', { class: 'mn-input' });
			[
				{ value: 'all_required', label: tr('All required checklist items must pass') },
				{ value: 'percent', label: tr('A minimum percent of required items') },
				{ value: 'off', label: tr('Checklist is advisory only') },
			].forEach(function (opt) {
				var o = el('option', { value: opt.value, text: opt.label });
				if (opt.value === p.checklistDonePolicy) o.selected = true;
				checklistSelect.appendChild(o);
			});
			var percentInput = el('input', {
				type: 'number', min: '0', max: '100', step: '1',
				value: String(p.checklistMinPercent != null ? p.checklistMinPercent : 100),
			});
			function enforcementSelect(current) {
				var sel = el('select', { class: 'mn-input' });
				[
					{ value: 'off', label: tr('Off') },
					{ value: 'warn', label: tr('Warn') },
					{ value: 'block', label: tr('Block') },
				].forEach(function (opt) {
					var o = el('option', { value: opt.value, text: opt.label });
					if (opt.value === current) o.selected = true;
					sel.appendChild(o);
				});
				return sel;
			}
			var skillsSelect = enforcementSelect(p.skillsEnforcement || 'warn');
			var capacitySelect = enforcementSelect(p.capacityEnforcement || 'warn');
			var warnRatio = el('input', {
				type: 'number', min: '0.01', max: '9.99', step: '0.01',
				value: String(p.capacityWarnRatio != null ? p.capacityWarnRatio : 1),
			});
			var equipBox = el('input', { type: 'checkbox' });
			equipBox.checked = p.requireEquipmentOnWo !== false;
			var failureSelect = el('select', { class: 'mn-input form-select' });
			[
				{ value: 'off', label: tr('Off') },
				{ value: 'warn', label: tr('Warn') },
				{ value: 'required', label: tr('Required') },
			].forEach(function (opt) {
				var o = el('option', { value: opt.value, text: opt.label });
				if (opt.value === (p.failureCodeOnCorrective || 'warn')) {
					o.selected = true;
				}
				failureSelect.appendChild(o);
			});

			function persist(patch) {
				savePolicies(patch, function (message) {
					if (message) toast(message, 'error');
				});
			}
			checklistSelect.addEventListener('change', function () {
				persist({ checklistDonePolicy: checklistSelect.value });
			});
			percentInput.addEventListener('change', function () {
				persist({ checklistMinPercent: Math.trunc(Number(percentInput.value)) });
			});
			skillsSelect.addEventListener('change', function () {
				persist({ skillsEnforcement: skillsSelect.value });
			});
			capacitySelect.addEventListener('change', function () {
				persist({ capacityEnforcement: capacitySelect.value });
			});
			warnRatio.addEventListener('change', function () {
				persist({ capacityWarnRatio: Number(warnRatio.value) });
			});
			equipBox.addEventListener('change', function () {
				persist({ requireEquipmentOnWo: equipBox.checked });
			});
			failureSelect.addEventListener('change', function () {
				persist({ failureCodeOnCorrective: failureSelect.value });
			});

			var defectFollowSelect = el('select', {
				id: 'mn-policy-defect-follow-up',
				class: 'mn-input form-select',
				'aria-label': tr('Defect follow-up after inspection fail'),
			});
			[
				{ value: 'off', label: tr('Off') },
				{ value: 'warn', label: tr('Warn') },
				{ value: 'auto', label: tr('Auto-open corrective') },
			].forEach(function (opt) {
				var o = el('option', { value: opt.value, text: opt.label });
				if (opt.value === (p.defectFollowUp || 'warn')) {
					o.selected = true;
				}
				defectFollowSelect.appendChild(o);
			});
			defectFollowSelect.addEventListener('change', function () {
				persist({ defectFollowUp: defectFollowSelect.value });
			});
			var inspResultBox = el('input', {
				id: 'mn-policy-inspection-result-required',
				type: 'checkbox',
			});
			inspResultBox.checked = p.inspectionResultRequired !== false;
			inspResultBox.addEventListener('change', function () {
				persist({ inspectionResultRequired: inspResultBox.checked });
			});
			var failBlocksBox = el('input', {
				id: 'mn-policy-fail-blocks-roll',
				type: 'checkbox',
			});
			failBlocksBox.checked = !!p.failBlocksRoll;
			failBlocksBox.addEventListener('change', function () {
				persist({ failBlocksRoll: failBlocksBox.checked });
			});

			policiesBox.appendChild(field(tr('Checklist to finish a job'), checklistSelect, { wide: true }));
			policiesBox.appendChild(field(tr('Minimum checklist percent'), percentInput, {
				hint: tr('Used when the checklist policy is “minimum percent”.'),
			}));
			policiesBox.appendChild(field(tr('Missing skills on assign'), skillsSelect));
			policiesBox.appendChild(field(tr('Over capacity on assign'), capacitySelect));
			policiesBox.appendChild(field(tr('Capacity warn ratio'), warnRatio, {
				hint: tr('Warn when projected load exceeds this share of daily minutes (1.0 = 100%).'),
			}));
			policiesBox.appendChild(el('label', { class: 'mn-checkbox' }, [
				equipBox,
				el('span', { text: tr('Require equipment on every work order') }),
			]));
			policiesBox.appendChild(field(tr('Failure code on corrective Done'), failureSelect, {
				hint: tr('Off, warn, or required when closing a corrective job.'),
			}));
			policiesBox.appendChild(field(tr('Defect follow-up after inspection fail'), defectFollowSelect, {
				hint: tr('Off, warn in the UI, or automatically open a corrective work order.'),
			}));
			policiesBox.appendChild(el('label', { class: 'mn-checkbox' }, [
				inspResultBox,
				el('span', { text: tr('Require inspection result on Done') }),
			]));
			policiesBox.appendChild(el('label', { class: 'mn-checkbox' }, [
				failBlocksBox,
				el('span', { text: tr('Failed inspection blocks next-due roll') }),
			]));
			policiesBox.appendChild(el('p', {
				class: 'mn-muted',
				text: tr('When enabled, completing a fail inspection closes the visit without scheduling the next due — overdue risk stays on the corrective work order.'),
			}));
		}

		function loadCapacity() {
			if (!capacityBox) {
				return;
			}
			capacityBox.setAttribute('aria-busy', 'true');
			api('GET', apiUrl('capacity'))
				.then(function (envelope) {
					renderCapacity(envelope.data || []);
				})
				.catch(handleGlobalError)
				.finally(function () { capacityBox.removeAttribute('aria-busy'); });
		}

		function renderCapacity(rows) {
			clear(capacityBox);
			if (rows.length === 0) {
				capacityBox.appendChild(el('p', {
					class: 'mn-section__hint',
					text: tr('No custom capacities yet — everyone defaults to a full workday until you set minutes below.'),
				}));
			} else {
				capacityBox.appendChild(tableOrCards([
					{
						id: 'uid',
						label: tr('User'),
						render: function (row) {
							return tableStack(row.uid, tr('{n} minutes per day', { n: row.dailyMinutes }));
						},
					},
				], rows, { caption: tr('Daily capacity') }));
			}

			var capacityPicker = attachUserPicker({
				label: tr('Nextcloud user'),
				placeholder: tr('Start typing a name…'),
				hint: tr('Search and pick a colleague. Never type a raw user id.'),
			});
			var minutesInput = el('input', { type: 'number', min: '1', max: '1440', step: '1', value: '480' });
			var minutesField = field(tr('Daily minutes'), minutesInput, { required: true, hint: tr('1–1440. Typical full day is 480.') });
			var saveBtn = el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--primary mn-btn--compact',
				text: tr('Save capacity'),
				onClick: function () {
					saveBtn.disabled = true;
					capacityPicker.setError(null);
					minutesField.mnSetError(null);
					var uid = capacityPicker.getValue();
					if (!uid) {
						saveBtn.disabled = false;
						capacityPicker.setError(tr('Pick a colleague first.'));
						capacityPicker.focus();
						return;
					}
					api('PUT', apiUrl('capacity') + '/' + encodeURIComponent(uid), {
						dailyMinutes: Math.trunc(Number(minutesInput.value)),
					}).then(function () {
						toast(tr('Capacity saved.'), 'success');
						loadCapacity();
					}).catch(function (error) {
						saveBtn.disabled = false;
						if (error.code === 'unknown_user') {
							capacityPicker.setError(tr('This Nextcloud user does not exist.'));
							capacityPicker.focus();
						} else if (!applyFieldErrors({ dailyMinutes: minutesField }, error)) {
							toast(error.message, 'error');
						}
					});
				},
			});
			capacityBox.appendChild(el('div', { class: 'mn-form-grid' }, [capacityPicker.root, minutesField]));
			capacityBox.appendChild(saveBtn);
		}

		function renderAccess(config) {
			if (!accessBox) {
				return;
			}
			clear(accessBox);
			var toggle = el('input', { type: 'checkbox' });
			toggle.checked = config.accessRestrictionEnabled;
			toggle.addEventListener('change', function () {
				saveAccess({ accessRestrictionEnabled: toggle.checked }, function (message) {
					if (message) {
						toast(message, 'error');
						toggle.checked = !toggle.checked;
					}
				});
			});
			accessBox.appendChild(el('label', { class: 'mn-switch' }, [
				toggle,
				el('span', { class: 'mn-switch__label', text: tr('Restrict access to the lists below') }),
			]));
			accessBox.appendChild(idListEditor({
				label: tr('Allowed users'),
				kind: 'user',
				ids: config.accessAllowedUserIds,
				emptyText: tr('No users listed.'),
				hint: tr('Search and pick people. Never type a raw user id. These accounts keep access while restriction is on.'),
				onChange: function (ids, done) { saveAccess({ accessAllowedUserIds: ids }, done); },
			}));
			accessBox.appendChild(idListEditor({
				label: tr('Allowed groups'),
				kind: 'group',
				ids: config.accessAllowedGroupIds,
				emptyText: tr('No groups listed.'),
				hint: tr('Search and pick groups. Never type a raw group id. Members keep access while restriction is on.'),
				onChange: function (ids, done) { saveAccess({ accessAllowedGroupIds: ids }, done); },
			}));
			accessBox.appendChild(idListEditor({
				label: tr('App administrators'),
				kind: 'user',
				ids: config.appAdminUserIds,
				emptyText: tr('Only Nextcloud administrators manage this app.'),
				hint: tr('Search and pick delegated admins. Never type a raw user id. System administrators always keep access.'),
				onChange: function (ids, done) { saveAccess({ appAdminUserIds: ids }, done); },
			}));
		}

		function renderRoles(config) {
			if (!rolesBox) {
				return;
			}
			clear(rolesBox);
			rolesBox.appendChild(idListEditor({
				label: tr('Office users'),
				kind: 'user',
				ids: config.officeUserIds,
				emptyText: tr('No office users listed.'),
				hint: tr('Search and pick office members. Never type a raw user id.'),
				onChange: function (ids, done) { saveOffice({ officeUserIds: ids }, done); },
			}));
			rolesBox.appendChild(idListEditor({
				label: tr('Office groups'),
				kind: 'group',
				ids: config.officeGroupIds,
				emptyText: tr('No office groups listed.'),
				hint: tr('Search and pick office groups. Never type a raw group id.'),
				onChange: function (ids, done) { saveOffice({ officeGroupIds: ids }, done); },
			}));
		}

		function saveInventoryFlange(patch, done) {
			api('POST', apiUrl('configInventoryFlange'), patch)
				.then(function () {
					toast(tr('Inventory flange settings saved.'), 'success');
					done(null);
				})
				.catch(function (error) {
					done(error.message);
				});
		}

		function renderInventoryFlange(config) {
			if (!flangeBox) {
				return;
			}
			clear(flangeBox);
			var flange = config.inventoryFlange || { enabled: false, locationPolicy: 'fail_if_ambiguous', explicitLocationId: null };
			var toggle = el('input', { type: 'checkbox' });
			toggle.checked = !!flange.enabled;
			toggle.addEventListener('change', function () {
				saveInventoryFlange({ enabled: toggle.checked }, function (message) {
					if (message) {
						toast(message, 'error');
						toggle.checked = !toggle.checked;
					}
				});
			});
			flangeBox.appendChild(el('label', { class: 'mn-switch' }, [
				toggle,
				el('span', { class: 'mn-switch__label', text: tr('Deduct kit stock when a work order is finished') }),
			]));
			flangeBox.appendChild(el('p', {
				class: 'mn-field__hint',
				text: tr('Optional when a compatible inventory app is installed and enabled. Failures never undo the finished work order.'),
			}));

			var policy = el('select', { class: 'mn-input' });
			[
				{ value: 'fail_if_ambiguous', label: tr('Fail if location is ambiguous (safest)') },
				{ value: 'equipment_default_location', label: tr('Use equipment default location') },
				{ value: 'explicit_location_id', label: tr('Always use the location ID below') },
			].forEach(function (opt) {
				var o = el('option', { value: opt.value, text: opt.label });
				if (opt.value === flange.locationPolicy) {
					o.selected = true;
				}
				policy.appendChild(o);
			});
			policy.addEventListener('change', function () {
				saveInventoryFlange({ locationPolicy: policy.value }, function (message) {
					if (message) toast(message, 'error');
				});
			});
			flangeBox.appendChild(field(tr('Location policy'), policy));

			var locInput = el('input', {
				type: 'number',
				min: '1',
				step: '1',
				value: flange.explicitLocationId ? String(flange.explicitLocationId) : '',
			});
			var locField = field(tr('Explicit location ID'), locInput, {
				hint: tr('Only used when the policy is “Always use the location ID below”. Leave empty to clear.'),
			});
			var saveLoc = el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--secondary mn-btn--compact',
				text: tr('Save location ID'),
				onClick: function () {
					var raw = locInput.value.trim();
					saveInventoryFlange({
						explicitLocationId: raw === '' ? null : parseInt(raw, 10),
					}, function (message) {
						if (message) {
							toast(message, 'error');
						}
					});
				},
			});
			flangeBox.appendChild(locField);
			flangeBox.appendChild(saveLoc);
		}

		function loadLicense() {
			if (!licenseBox) {
				return;
			}
			licenseBox.setAttribute('aria-busy', 'true');
			Promise.all([
				api('GET', apiUrl('license')),
				api('GET', withQuery(apiUrl('licenseSeats'), { limit: 200 })),
			]).then(function (results) {
				renderLicense(results[0], results[1]);
			}).catch(handleGlobalError)
				.finally(function () { licenseBox.removeAttribute('aria-busy'); });
		}

		function renderLicense(status, seats) {
			clear(licenseBox);
			var state = status.state;

			if (state) {
				licenseBox.appendChild(el('div', { class: 'mn-license-status' }, [
					el('span', {}, [
						el('strong', { text: tr('Customer:') + ' ' }),
						el('span', { text: state.customerId }),
					]),
					el('span', {}, [
						el('strong', { text: tr('Valid until:') + ' ' }),
						el('span', { text: fmt(state.validUntil) }),
					]),
					el('span', {
						class: 'mn-badge ' + (state.valid ? 'mn-badge--success' : 'mn-badge--overdue'),
						text: state.valid ? tr('Valid') : tr('Expired'),
					}),
					el('span', {}, [
						el('strong', { text: tr('Seats:') + ' ' }),
						el('span', { text: status.seats.assigned + ' / ' + status.seats.limit }),
					]),
				]));
			} else {
				licenseBox.appendChild(el('p', { class: 'mn-section__hint', text: tr('No license key stored. The web app stays fully functional without one.') }));
			}
			licenseBox.appendChild(el('p', {
				class: 'mn-section__hint',
				text: ctx.mobileAppStatus === 'available'
					? tr('The official mobile app is available. Assigned seats can sign in.')
					: tr('The official mobile app is in preparation. Seats can already be assigned.'),
			}));

			// SPEC §8.3 — pricing page + mailto CTA (no € amounts in-app).
			var pricingLang = (document.documentElement.lang || 'en').toLowerCase().indexOf('de') === 0 ? 'de' : 'en';
			var pricingUrl = 'https://nextcloud.software-by-design.de/' + pricingLang + '/support.html#packages';
			var mobileMailto = 'mailto:info@software-by-design.de?subject='
				+ encodeURIComponent('MaintenanceCheck: Mobile Lizenzen');
			licenseBox.appendChild(el('p', { class: 'mn-license-cta' }, [
				el('a', {
					href: pricingUrl,
					target: '_blank',
					rel: 'noopener noreferrer',
					text: tr('View mobile license options'),
				}),
				el('span', { text: ' · ' }),
				el('a', {
					href: mobileMailto,
					text: tr('Request mobile seats by e-mail'),
				}),
			]));

			var keyInput = el('textarea', { rows: '3', autocomplete: 'off', spellcheck: 'false' });
			var keyField = field(tr('License key'), keyInput, { hint: tr('Paste the MN2 key exactly as you received it.'), wide: true });
			var applyButton = el('button', {
				type: 'button', class: 'mn-btn mn-btn--primary', text: tr('Apply key'),
				onClick: function () {
					applyButton.disabled = true;
					keyField.mnSetError(null);
					api('POST', apiUrl('license'), { key: keyInput.value })
						.then(function () {
							toast(tr('License key applied.'), 'success');
							loadLicense();
						})
						.catch(function (error) {
							applyButton.disabled = false;
							if (error.code === 'license_invalid') {
								keyField.mnSetError(error.message);
								keyInput.focus();
							} else {
								toast(error.message, 'error');
							}
						});
				},
			});
			var keyRow = el('div', {}, [keyField, applyButton]);
			licenseBox.appendChild(keyRow);

			if (state) {
				licenseBox.appendChild(el('button', {
					type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact mn-btn--spaced-top', text: tr('Remove key'),
					onClick: function () {
						openDialog({
							title: tr('Remove license key'),
							text: tr('Seats stay assigned; the mobile gate will report a missing license until a new key is applied.'),
							actions: [
								{ label: tr('Keep key'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
								{
									label: tr('Remove key'),
									variant: 'mn-btn--danger',
									onClick: function (d) {
										api('DELETE', apiUrl('license'))
											.then(function () {
												d.close();
												toast(tr('License key removed.'), 'success');
												loadLicense();
											})
											.catch(function (error) {
												d.setError(error.message);
											});
									},
								},
							],
						});
					},
				}));
			}

			// Seats — SPEC §8.3 NC user picker
			var seatsTitle = el('h3', { class: 'mn-section__title mn-section__title--spaced', text: tr('Mobile seats') });
			licenseBox.appendChild(seatsTitle);
			var seatPicker = attachUserPicker({
				label: tr('Nextcloud user'),
				hint: tr('Search and pick a colleague, then assign a named seat for the official mobile app. Never type a raw user id.'),
				placeholder: tr('Start typing a name…'),
			});
			var seatAdd = el('button', {
				type: 'button', class: 'mn-btn mn-btn--secondary', text: tr('Assign seat'),
				onClick: function () {
					var userId = seatPicker.getValue();
					seatAdd.disabled = true;
					seatPicker.setError(null);
					if (!userId) {
						seatAdd.disabled = false;
						seatPicker.setError(tr('Choose a Nextcloud user from the list.'));
						seatPicker.focus();
						return;
					}
					api('POST', apiUrl('licenseSeats'), { userId: userId })
						.then(function () {
							toast(tr('Seat assigned.'), 'success');
							loadLicense();
						})
						.catch(function (error) {
							seatAdd.disabled = false;
							if (error.code === 'unknown_user') {
								seatPicker.setError(tr('This Nextcloud user does not exist.'));
								seatPicker.focus();
							} else if (error.code === 'seat_limit_reached') {
								seatPicker.setError(tr('All licensed seats are assigned. Remove a seat or request more via the license options above.'));
							} else {
								toast(error.message, 'error');
							}
						});
				},
			});
			licenseBox.appendChild(el('div', { class: 'mn-inline-row' }, [seatPicker.root, seatAdd]));

			var seatList = el('ul', { class: 'mn-chips', 'aria-label': tr('Assigned mobile seats') });
			if (seats.data.length === 0) {
				seatList.appendChild(el('li', { class: 'mn-chips__empty', text: tr('No seats assigned yet.') }));
			}
			seats.data.forEach(function (seat) {
				seatList.appendChild(el('li', { class: 'mn-chip' + (seat.withinLimit ? '' : ' mn-chip--over-limit') }, [
					el('span', { text: seat.displayName + (seat.withinLimit ? '' : ' — ' + tr('over limit')) }),
					el('button', {
						type: 'button',
						class: 'mn-chip__remove',
						'aria-label': tr('Remove seat for {user}', { user: seat.displayName }),
						html: ICONS.x,
						onClick: function () {
							api('DELETE', apiUrl('licenseSeats') + '/' + encodeURIComponent(seat.uid))
								.then(function () {
									toast(tr('Seat removed.'), 'success');
									loadLicense();
								})
								.catch(function (error) { toast(error.message, 'error'); });
						},
					}),
				]));
			});
			licenseBox.appendChild(seatList);
		}
	}

	// ── Boot ───────────────────────────────────────────────────────────

	var PAGES = {
		'due': pageDue,
		'customers': pageCustomers,
		'customer-detail': pageCustomerDetail,
		'equipment': pageEquipment,
		'equipment-detail': pageEquipmentDetail,
		'visits': pageVisits,
		'catalogs': pageCatalogs,
		'settings': pageSettings,
	};

	function registerPage(name, fn) {
		PAGES[name] = fn;
	}

	function wireDismissibleHint(button) {
		if (!button || button.dataset.mnHintWired === '1') {
			return;
		}
		button.dataset.mnHintWired = '1';
		var key = button.getAttribute('data-mn-dismiss-hint');
		if (!key) {
			return;
		}
		var card = button.closest('.mn-quickstart-card, .mn-empty--quickstart');
		if (!card) {
			return;
		}
		var rootEl = document.getElementById('app-content');
		var uid = (ctx && ctx.currentUser) || (rootEl && rootEl.getAttribute('data-mn-current-user')) || '';
		var storageKeys = ['mn:hint:' + key];
		if (uid) {
			storageKeys.unshift('mn:hint:' + uid + ':' + key);
		}
		var dismissed = false;
		try {
			dismissed = storageKeys.some(function (sk) {
				return window.localStorage.getItem(sk) === '1';
			});
		} catch (e) { /* private mode */ }
		if (dismissed) {
			card.hidden = true;
			card.setAttribute('hidden', '');
			return;
		}
		card.hidden = false;
		card.removeAttribute('hidden');
		button.addEventListener('click', function () {
			try {
				storageKeys.forEach(function (sk) {
					window.localStorage.setItem(sk, '1');
				});
			} catch (e2) { /* ignore */ }
			card.hidden = true;
			card.setAttribute('hidden', '');
		});
	}

	function wireAllDismissibleHints(root) {
		var scope = root || document;
		scope.querySelectorAll('[data-mn-dismiss-hint]').forEach(wireDismissibleHint);
	}

	function wireMnLinks(root) {
		var scope = root || document;
		scope.querySelectorAll('[data-mn-link]').forEach(function (trigger) {
			if (trigger.dataset.mnLinkWired === '1') {
				return;
			}
			trigger.dataset.mnLinkWired = '1';
			trigger.addEventListener('click', function (event) {
				var name = trigger.getAttribute('data-mn-link');
				if (!name) {
					return;
				}
				var target = pageUrl(name);
				if (!target || target === '#') {
					return;
				}
				event.preventDefault();
				window.location.assign(target);
			});
		});
	}

	function runCurrentPage() {
		if (!ctx) {
			return;
		}
		var run = PAGES[ctx.page];
		// Settings underpages share pageSettings(); each host is optional.
		if (!run && typeof ctx.page === 'string' && ctx.page.indexOf('settings-') === 0) {
			run = pageSettings;
		}
		if (run) {
			run();
		}
	}

	function boot() {
		ctx = buildContext();
		if (!ctx) {
			return;
		}
		MnApp.__dom = {
			api: api,
			apiUpload: apiUpload,
			apiUrl: apiUrl,
			pageUrl: pageUrl,
			withQuery: withQuery,
			el: el,
			clear: clear,
			emptyState: emptyState,
			skeleton: skeleton,
			renderPagination: renderPagination,
			statusMeta: statusMeta,
			statusBadge: statusBadge,
			tableOrCards: tableOrCards,
			tableStack: tableStack,
			catalogNameCell: catalogNameCell,
			tableLink: tableLink,
			tableCellText: tableCellText,
			toast: toast,
			announce: announce,
			announceResults: announceResults,
			attachUserPicker: attachUserPicker,
			handleGlobalError: handleGlobalError,
			openDialog: openDialog,
			field: field,
			fmt: fmt,
			todayYmd: todayYmd,
			visitOverflowMenu: visitOverflowMenu,
			getCtx: function () { return ctx; },
			registerPage: registerPage,
			runCurrentPage: runCurrentPage,
			wireAllDismissibleHints: wireAllDismissibleHints,
		};
		wireAllDismissibleHints();
		wireMnLinks();
		runCurrentPage();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();

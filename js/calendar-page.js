/**
 * MaintenanceCheck technician calendar (FA-2) — month/week grid.
 *
 * Pure helpers are exported on window.MnCalendar for the Node contract
 * tests (month matrix, span clipping, overflow bucketing). The page module
 * registers into window.MnApp.PAGES like work-order-pages.js.
 */
(function () {
	'use strict';

	function tr(text, vars) {
		if (typeof window !== 'undefined' && typeof window.t === 'function') {
			return window.t('maintenancecheck', text, vars);
		}
		var out = text;
		if (vars) {
			Object.keys(vars).forEach(function (k) {
				out = out.replace('{' + k + '}', String(vars[k]));
			});
		}
		return out;
	}

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
		return d <= new Date(Date.UTC(y, m, 0)).getUTCDate();
	}

	function addDays(ymd, days) {
		var date = new Date(Date.UTC(
			Number(ymd.slice(0, 4)),
			Number(ymd.slice(5, 7)) - 1,
			Number(ymd.slice(8, 10))
		));
		date.setUTCDate(date.getUTCDate() + days);
		var m = date.getUTCMonth() + 1;
		var d = date.getUTCDate();
		return date.getUTCFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
	}

	function monthStart(ymd) {
		return ymd.slice(0, 7) + '-01';
	}

	function addMonths(ymd, count) {
		var y = Number(ymd.slice(0, 4));
		var m = Number(ymd.slice(5, 7));
		var total = y * 12 + (m - 1) + count;
		var ty = Math.floor(total / 12);
		var tm = (total % 12) + 1;
		var d = Math.min(Number(ymd.slice(8, 10)), new Date(Date.UTC(ty, tm, 0)).getUTCDate());
		return ty + '-' + (tm < 10 ? '0' : '') + tm + '-' + (d < 10 ? '0' : '') + d;
	}

	/** Monday-first week start (ISO). */
	function weekStart(ymd) {
		var date = new Date(Date.UTC(
			Number(ymd.slice(0, 4)),
			Number(ymd.slice(5, 7)) - 1,
			Number(ymd.slice(8, 10))
		));
		var dow = (date.getUTCDay() + 6) % 7;
		return addDays(ymd, -dow);
	}

	/**
	 * 6×7 month matrix anchored Monday-first covering the month of `ymd`.
	 * @returns {string[]} 42 day strings Y-m-d
	 */
	function monthMatrix(ymd) {
		var first = weekStart(monthStart(ymd));
		var out = [];
		for (var i = 0; i < 42; i++) {
			out.push(addDays(first, i));
		}
		return out;
	}

	/** Days of a span clipped to [from, to]; null when outside. */
	function clipSpan(startDay, endDay, from, to) {
		var s = startDay > from ? startDay : from;
		var e = endDay < to ? endDay : to;
		if (s > e) {
			return null;
		}
		return { start: s, end: e, continuesBefore: startDay < from, continuesAfter: endDay > to };
	}

	function dayDiff(a, b) {
		// b - a in days
		var da = Date.UTC(Number(a.slice(0, 4)), Number(a.slice(5, 7)) - 1, Number(a.slice(8, 10)));
		var db = Date.UTC(Number(b.slice(0, 4)), Number(b.slice(5, 7)) - 1, Number(b.slice(8, 10)));
		return Math.round((db - da) / 86400000);
	}

	/** Inclusive span length in days (min 1). */
	function spanDays(startDay, endDay) {
		if (!isValidYmd(startDay) || !isValidYmd(endDay) || endDay < startDay) {
			return 0;
		}
		return dayDiff(startDay, endDay) + 1;
	}

	function groupByDay(events) {
		var map = {};
		(events || []).forEach(function (ev) {
			var span = { start: ev.startDay, end: ev.endDay || ev.startDay };
			var d = span.start;
			while (d <= span.end) {
				(map[d] = map[d] || []).push(ev);
				d = addDays(d, 1);
			}
		});
		return map;
	}

	/**
	 * +k more bucketing for a month cell: first `cap` events then overflow.
	 */
	function cellSplit(events, cap) {
		var list = events || [];
		return {
			visible: list.slice(0, cap),
			overflow: list.slice(cap),
		};
	}

	var MnCalendar = {
		isValidYmd: isValidYmd,
		addDays: addDays,
		monthStart: monthStart,
		addMonths: addMonths,
		weekStart: weekStart,
		monthMatrix: monthMatrix,
		clipSpan: clipSpan,
		dayDiff: dayDiff,
		spanDays: spanDays,
		groupByDay: groupByDay,
		cellSplit: cellSplit,
	};
	if (typeof window !== 'undefined') {
		window.MnCalendar = MnCalendar;
	}
	if (typeof globalThis !== 'undefined') {
		globalThis.MnCalendar = MnCalendar;
	}
	var VIEW_KEY = 'mn:calendar:view';
	var MAX_CELL_EVENTS = 3;
	var KIND_META = {
		preventive: { label: tr('Preventive'), badge: 'mn-badge--scheduled' },
		corrective: { label: tr('Corrective'), badge: 'mn-badge--neutral' },
		inspection: { label: tr('Inspection'), badge: 'mn-badge--today' },
		other: { label: tr('Other'), badge: 'mn-badge--neutral' },
		visit: { label: tr('Visit'), badge: 'mn-badge--scheduled' },
	};

	function loadViewPref() {
		try {
			var v = window.localStorage.getItem(VIEW_KEY);
			return v === 'week' ? 'week' : 'month';
		} catch (e) {
			return 'month';
		}
	}

	function saveViewPref(view) {
		try {
			window.localStorage.setItem(VIEW_KEY, view);
		} catch (e) { /* private mode */ }
	}

	function eventBadge(ev) {
		var dom = window.MnApp && window.MnApp.__dom;
		var meta = KIND_META[ev.kind] || KIND_META.other;
		if (ev.entity === 'visit') {
			meta = KIND_META.visit;
		}
		return dom.statusBadge(meta.label, meta.badge);
	}

	function eventLabel(ev) {
		return (ev.number ? ev.number + ' · ' : '') + (ev.title || '');
	}

	function pageCalendar() {
		var dom = window.MnApp && window.MnApp.__dom;
		if (!dom) {
			return;
		}
		var el = dom.el;
		var clear = dom.clear;
		var api = dom.api;
		var apiUrl = dom.apiUrl;
		var pageUrl = dom.pageUrl;
		var withQuery = dom.withQuery;
		var toast = dom.toast;
		var announce = dom.announce;
		var statusBadge = dom.statusBadge;
		var statusMeta = dom.statusMeta;
		var emptyState = dom.emptyState;
		var skeleton = dom.skeleton;
		var openDialog = dom.openDialog;
		var todayYmd = dom.todayYmd;
		var ctx = dom.getCtx();
		var board = document.getElementById('mn-cal-board');
		var agenda = document.getElementById('mn-cal-agenda');
		var periodTitle = document.getElementById('mn-cal-period');
		var filterHost = document.getElementById('mn-cal-filters');
		var isOffice = !!(ctx && ctx.isOffice);

		var view = loadViewPref();
		var anchor = todayYmd();
		var selectedDay = todayYmd();
		var events = [];
		var dayMap = {};
		var focusDay = null;
		var filters = { tech: [], kind: '', status: '' };
		var loadGeneration = 0;

		function windowFor() {
			if (view === 'week') {
				var s = weekStart(anchor);
				return { from: s, to: addDays(s, 6) };
			}
			var days = monthMatrix(anchor);
			return { from: days[0], to: days[41] };
		}

		function queryParams() {
			var w = windowFor();
			var params = { from: w.from, to: w.to };
			if (isOffice) {
				if (filters.tech.length) {
					params.tech = filters.tech.join(',');
				}
				if (filters.kind) {
					params.kind = filters.kind;
				}
				if (filters.status) {
					params.status = filters.status;
				}
			}
			return params;
		}

		function load() {
			var generation = ++loadGeneration;
			board.setAttribute('aria-busy', 'true');
			clear(board);
			board.appendChild(skeleton(4));
			api('GET', withQuery(apiUrl('calendar'), queryParams())).then(function (data) {
				if (generation !== loadGeneration) {
					return;
				}
				events = (data && data.events) || [];
				dayMap = groupByDay(events);
				render();
				if (data && data.truncated) {
					toast(tr('Showing the first {n} jobs in this window — narrow the filters.', { n: 1000 }), 'warning');
				}
				announce(tr('{n} jobs in this period', { n: events.length }), false);
			}).catch(function (error) {
				if (generation !== loadGeneration) {
					return;
				}
				// eslint-disable-next-line no-console
				console.error('mn-cal load failed', error);
				clear(board);
				board.appendChild(emptyState(tr('Calendar could not load'), error.message || tr('Please try again.')));
			}).finally(function () {
				if (generation === loadGeneration) {
					board.removeAttribute('aria-busy');
				}
			});
		}

		function periodLabel() {
			var w = windowFor();
			if (view === 'week') {
				return tr('Week of {date}', { date: dom.fmt(w.from) });
			}
			return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
				year: 'numeric', month: 'long', timeZone: 'UTC',
			}).format(new Date(Date.UTC(Number(anchor.slice(0, 4)), Number(anchor.slice(5, 7)) - 1, 1)));
		}

		function shift(dir) {
			anchor = view === 'week' ? addDays(anchor, dir * 7) : addMonths(anchor, dir);
			load();
		}

		function openDay(day) {
			var list = dayMap[day] || [];
			var dlg = openDialog({
				title: tr('Jobs on {date}', { date: dom.fmt(day) }),
				content: el('ul', { class: 'mn-cal-day-list' }, list.map(function (ev) {
					return el('li', { class: 'mn-cal-day-list__item' }, [
						el('a', { href: ev.url, class: 'mn-cal-day-list__link' }, [
							eventBadge(ev),
							el('span', { class: 'mn-cal-day-list__title', text: eventLabel(ev) }),
						]),
					]);
				})),
				actions: [{ label: tr('Close'), variant: 'mn-btn--secondary', onClick: function (d) { d.close(); } }],
			});
			return dlg;
		}

		function eventRow(ev, opts) {
			opts = opts || {};
			var parts = [
				eventBadge(ev),
				el('span', { class: 'mn-cal-event__title', text: eventLabel(ev) }),
			];
			if (ev.unassigned) {
				parts.push(window.MnApp.__dom.statusBadge(tr('Unassigned'), 'mn-badge--warning'));
			}
			if (ev.status && ev.status !== 'planned' && ev.status !== 'scheduled') {
				var domRef = window.MnApp.__dom;
				var sm = domRef.statusMeta(ev.status);
				parts.push(domRef.statusBadge(sm.label, sm.badge, sm.icon));
			}
			if (opts.continuesBefore) {
				parts.unshift(el('span', { class: 'mn-cal-event__cont', 'aria-hidden': 'true', text: '◂' }));
			}
			if (opts.continuesAfter) {
				parts.push(el('span', { class: 'mn-cal-event__cont', 'aria-hidden': 'true', text: '▸' }));
			}
			var link = el('a', {
				href: ev.url,
				class: 'mn-cal-event' + (ev.entity === 'visit' ? ' mn-cal-event--visit' : '') + (ev.unassigned ? ' mn-cal-event--pool' : ''),
			}, parts);
			return el('li', { class: 'mn-cal-event-wrap' }, [link]);
		}

		function renderMonth() {
			var w = windowFor();
			var days = monthMatrix(anchor);
			var monthKey = anchor.slice(0, 7);
			var grid = el('div', { class: 'mn-cal-grid', role: 'grid', 'aria-label': periodLabel() });
			var headRow = el('div', { class: 'mn-cal-grid__row', role: 'row' });
			['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach(function (dow, i) {
				headRow.appendChild(el('div', {
					class: 'mn-cal-dow', role: 'columnheader',
					text: tr(dow === 'Mon' ? 'Mon' : dow === 'Tue' ? 'Tue' : dow === 'Wed' ? 'Wed' : dow === 'Thu' ? 'Thu' : dow === 'Fri' ? 'Fri' : dow === 'Sat' ? 'Sat' : 'Sun'),
				}));
			});
			grid.appendChild(headRow);

			for (var week = 0; week < 6; week++) {
				var row = el('div', { class: 'mn-cal-grid__row', role: 'row' });
				for (var col = 0; col < 7; col++) {
					var day = days[week * 7 + col];
					var inMonth = day.slice(0, 7) === monthKey;
					var list = dayMap[day] || [];
					var cell = el('section', {
						class: 'mn-cal-cell' + (inMonth ? '' : ' mn-cal-cell--adjacent')
							+ (day === todayYmd() ? ' mn-cal-cell--today' : '')
							+ (day === selectedDay ? ' mn-cal-cell--selected' : ''),
						role: 'gridcell',
						'aria-label': dom.fmt(day) + (list.length ? ', ' + tr('{n} jobs', { n: list.length }) : ''),
						'data-mn-cal-day': day,
						tabindex: '0',
					});
					if (day === todayYmd()) {
						cell.setAttribute('aria-current', 'date');
					}
					cell.appendChild(el('span', {
						class: 'mn-cal-cell__date',
						text: String(Number(day.slice(8, 10))),
					}));
					// Compact marker for narrow screens (CSS toggles visibility).
					if (list.length) {
						cell.appendChild(el('span', {
							class: 'mn-cal-cell__count',
							text: String(list.length),
							'aria-hidden': 'true',
						}));
					}
					var ul = el('ul', { class: 'mn-cal-cell__events' });
					var split = cellSplit(list, MAX_CELL_EVENTS);
					split.visible.forEach(function (ev) {
						var clip = clipSpan(ev.startDay, ev.endDay || ev.startDay, w.from, w.to);
						ul.appendChild(eventRow(ev, clip || {}));
					});
					cell.appendChild(ul);
					if (split.overflow.length) {
						(function (dayRef, extra) {
							var more = el('button', {
								type: 'button',
								class: 'mn-cal-more',
								text: tr('+{n} more', { n: extra }),
								'aria-label': tr('{n} more jobs on {date}', { n: extra, date: dom.fmt(dayRef) }),
							});
							more.addEventListener('click', function () { openDay(dayRef); });
							cell.appendChild(more);
						})(day, split.overflow.length);
					}
					(function (dayRef) {
						cell.addEventListener('click', function () {
							selectedDay = dayRef;
							renderAgenda();
						});
						cell.addEventListener('keydown', function (e) {
							var delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
							if (delta) {
								e.preventDefault();
								var target = addDays(dayRef, delta);
								selectedDay = target;
								focusDay = target;
								render();
							} else if (e.key === 'Enter') {
								e.preventDefault();
								openDay(dayRef);
							}
						});
					})(day);
					row.appendChild(cell);
				}
				grid.appendChild(row);
			}
			return grid;
		}

		function renderWeek() {
			var w = windowFor();
			var wrap = el('div', { class: 'mn-cal-week' });
			for (var i = 0; i < 7; i++) {
				(function (dayRef) {
					var day = addDays(w.from, i);
					var list = dayMap[day] || [];
					var section = el('section', {
						class: 'mn-cal-day' + (day === todayYmd() ? ' mn-cal-day--today' : ''),
						'aria-label': dom.fmt(day),
						'data-mn-cal-day': day,
					});
					section.appendChild(el('h3', {
						class: 'mn-cal-day__title',
						text: dom.fmt(day),
					}));
					if (!list.length) {
						section.appendChild(el('p', { class: 'mn-cal-day__empty', text: tr('No jobs') }));
					} else {
						var ul = el('ul', { class: 'mn-cal-day__events' });
						list.forEach(function (ev) {
							var clip = clipSpan(ev.startDay, ev.endDay || ev.startDay, day, day);
							ul.appendChild(eventRow(ev, clip || {}));
						});
						section.appendChild(ul);
					}
					wrap.appendChild(section);
				})(addDays(w.from, i));
			}
			return wrap;
		}

		function renderAgenda() {
			clear(agenda);
			if (view !== 'month') {
				return;
			}
			var list = dayMap[selectedDay] || [];
			var head = el('h3', {
				class: 'mn-cal-agenda__title',
				text: tr('Jobs on {date}', { date: dom.fmt(selectedDay) }),
			});
			agenda.appendChild(head);
			if (!list.length) {
				agenda.appendChild(el('p', { class: 'mn-cal-agenda__empty', text: tr('No jobs on this day.') }));
				return;
			}
			var ul = el('ul', { class: 'mn-cal-agenda__list' });
			list.forEach(function (ev) {
				ul.appendChild(eventRow(ev, clipSpan(ev.startDay, ev.endDay || ev.startDay, selectedDay, selectedDay) || {}));
			});
			agenda.appendChild(ul);
		}

		function render() {
			clear(board);
			if (!events.length) {
				// §7: empty state — "No jobs in this period" (+ dispatch link for
				// office), never a bare grid.
				board.appendChild(emptyState(
					tr('No jobs in this period'),
					isOffice ? null : tr('Ask your coordinator if you expected work here.'),
					isOffice && pageUrl('dispatch')
						? el('a', { class: 'mn-btn mn-btn--secondary', href: pageUrl('dispatch'), text: tr('Open dispatch board') })
						: null
				));
			} else {
				board.appendChild(view === 'week' ? renderWeek() : renderMonth());
			}
			renderAgenda();
			periodTitle.textContent = periodLabel();
			if (focusDay) {
				var cell = board.querySelector('[data-mn-cal-day="' + focusDay + '"]');
				focusDay = null;
				if (cell) {
					cell.focus();
				}
			}
		}

		function renderFilters() {
			if (!isOffice) {
				return;
			}
			filterHost.hidden = false;
			var kindSel = el('select', { class: 'mn-input form-select', 'aria-label': tr('Kind') }, [
				el('option', { value: '', text: tr('All kinds') }),
				el('option', { value: 'preventive', text: tr('Preventive') }),
				el('option', { value: 'corrective', text: tr('Corrective') }),
				el('option', { value: 'inspection', text: tr('Inspection') }),
				el('option', { value: 'other', text: tr('Other') }),
			]);
			kindSel.addEventListener('change', function () {
				filters.kind = kindSel.value;
				load();
			});
			var statusSel = el('select', { class: 'mn-input form-select', 'aria-label': tr('Status') }, [
				el('option', { value: '', text: tr('All open statuses') }),
				el('option', { value: 'draft', text: tr('Draft') }),
				el('option', { value: 'planned', text: tr('Planned') }),
				el('option', { value: 'ready', text: tr('Ready') }),
				el('option', { value: 'in_progress', text: tr('In progress') }),
				el('option', { value: 'blocked', text: tr('Blocked') }),
			]);
			statusSel.addEventListener('change', function () {
				filters.status = statusSel.value;
				load();
			});
			var chips = el('div', { class: 'mn-cal-chips', role: 'group', 'aria-label': tr('Selected technicians') });
			var techLabels = {};
			function renderChips() {
				clear(chips);
				filters.tech.forEach(function (uid) {
					var label = techLabels[uid] || uid;
					chips.appendChild(el('button', {
						type: 'button',
						class: 'mn-chip',
						text: label + ' ×',
						'aria-label': tr('Remove technician {name}', { name: label }),
						onClick: function () {
							filters.tech = filters.tech.filter(function (u) { return u !== uid; });
							renderChips();
							load();
						},
					}));
				});
			}
			var picker = dom.attachUserPicker({
				label: tr('Technician'),
				placeholder: tr('Search a person…'),
				hint: tr('Pick one or more technicians to narrow the grid.'),
			});
			picker.root.addEventListener('mn-user-selected', function (ev) {
				var uid = ev && ev.detail && ev.detail.uid ? String(ev.detail.uid) : '';
				var label = ev && ev.detail && (ev.detail.displayName || ev.detail.label)
					? String(ev.detail.displayName || ev.detail.label)
					: uid;
				if (uid && filters.tech.indexOf(uid) === -1) {
					techLabels[uid] = label;
					filters.tech.push(uid);
					renderChips();
					load();
				}
			});
			filterHost.appendChild(el('div', { class: 'mn-cal-filters__row' }, [picker.root, kindSel, statusSel]));
			filterHost.appendChild(chips);
		}

		document.getElementById('mn-cal-prev').addEventListener('click', function () { shift(-1); });
		document.getElementById('mn-cal-next').addEventListener('click', function () { shift(1); });
		document.getElementById('mn-cal-today').addEventListener('click', function () {
			anchor = todayYmd();
			selectedDay = anchor;
			load();
		});
		document.querySelectorAll('[data-mn-cal-view]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				view = btn.getAttribute('data-mn-cal-view') === 'week' ? 'week' : 'month';
				saveViewPref(view);
				syncViewButtons();
				load();
			});
		});
		function syncViewButtons() {
			document.querySelectorAll('[data-mn-cal-view]').forEach(function (btn) {
				var active = btn.getAttribute('data-mn-cal-view') === view;
				btn.classList.toggle('is-active', active);
				btn.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
		}

		syncViewButtons();
		renderFilters();
		load();
	}

	/**
	 * Registration is deferred like work-order-pages.js: MnApp.__dom only
	 * exists after app.js's own DOMContentLoaded listener has run.
	 */
	function register() {
		var dom = window.MnApp && window.MnApp.__dom;
		if (!dom) {
			return;
		}
		dom.registerPage('calendar', pageCalendar);
		dom.runCurrentPage();
	}

	if (typeof document === 'undefined' || typeof window === 'undefined') {
		return;
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', register);
	} else {
		register();
	}
})();

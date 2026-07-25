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
			default: return { label: String(status), badge: '', icon: null };
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
			case 'later': return { title: tr('Later (up to 30 days)'), badge: '', icon: 'calendar' };
			default: return { title: key, badge: '', icon: null };
		}
	}

	/** A4 status / bucket badge: text label + decorative icon (never colour alone). */
	function statusBadge(label, badgeClass, iconName) {
		// Node contract tests have no DOM — return a plain descriptor instead.
		if (typeof document === 'undefined') {
			return { label: label, badge: badgeClass || '', icon: iconName || null };
		}
		var kids = [];
		if (iconName && ICONS[iconName]) {
			kids.push(svgIcon(iconName));
		}
		kids.push(document.createTextNode(label));
		return el('span', { class: 'mn-badge' + (badgeClass ? ' ' + badgeClass : '') }, kids);
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
		todayYmd: todayYmd,
		setServerToday: setServerToday,
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

	// ── Toasts (§11.2, A7) ─────────────────────────────────────────────

	function announce(message, assertive) {
		var region = document.getElementById(assertive ? 'mn-alert-region' : 'mn-live-region');
		if (region) {
			region.textContent = '';
			window.setTimeout(function () { region.textContent = message; }, 30);
		}
	}

	function toast(message, type) {
		var region = document.getElementById('mn-toast-region');
		if (!region) {
			return;
		}
		var kind = type || 'info';
		var azcKind = kind === 'error' ? 'error' : (kind === 'success' || kind === 'ok' ? 'success' : (kind === 'warning' ? 'warning' : 'info'));
		var node = el('div', {
			class: 'toast mn-toast toast--' + azcKind + (type ? ' mn-toast--' + type : ''),
			role: kind === 'error' ? 'alert' : 'status',
		}, [
			el('div', { class: 'toast-content' }, [
				el('p', { class: 'mn-toast__message', text: message }),
			]),
			el('button', {
				type: 'button',
				class: 'mn-toast__close',
				'aria-label': tr('Dismiss'),
				html: ICONS.x,
				onClick: function () { remove(); },
			}),
		]);
		var timer = window.setTimeout(remove, kind === 'error' ? 5000 : 3000);
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
		var id = 'mn-f-' + fieldSeq;
		var errorId = id + '-error';
		var hintId = id + '-hint';
		input.setAttribute('id', id);
		input.classList.add('mn-input');
		var describedBy = [];
		var wrap = el('div', { class: 'mn-field' + (options.wide ? ' mn-field--wide' : '') });
		wrap.appendChild(el('label', {
			class: 'mn-field__label',
			for: id,
			text: options.required ? tr('{label} (required)', { label: labelText }) : labelText,
		}));
		wrap.appendChild(input);
		if (options.hint) {
			wrap.appendChild(el('p', { class: 'mn-field__hint', id: hintId, text: options.hint }));
			describedBy.push(hintId);
		}
		var error = el('p', { class: 'mn-field__error', id: errorId, hidden: true });
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
			placeholder: options.placeholder || tr('Start typing a name or user ID…'),
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

	function completeDialog(visit, onDone) {
		var doneOnInput = el('input', { type: 'date', value: todayYmd(), max: todayYmd() });
		var notesInput = el('textarea', { rows: '3' });
		var doneOnField = field(tr('Completed on'), doneOnInput, { required: true });
		var notesField = field(tr('Notes'), notesInput, { hint: tr('Optional — what was done, parts used, anything the office should know.') });
		var nextInfo = el('p', { class: 'mn-dialog__hint' });

		function refreshNext() {
			if (!visit.planActive) {
				nextInfo.className = 'mn-dialog__hint mn-dialog__hint--warning';
				nextInfo.textContent = tr('Plan inactive — no follow-up visit will be created.');
				return;
			}
			var next = addInterval(doneOnInput.value, visit.intervalUnit, visit.intervalCount);
			nextInfo.textContent = next
				? tr('The next visit will be due on {date}.', { date: fmt(next) })
				: tr('Enter a valid date to see the next due date.');
		}
		doneOnInput.addEventListener('input', refreshNext);
		refreshNext();

		var content = el('div', {}, [doneOnField, notesField, nextInfo]);
		openDialog({
			title: tr('Complete visit'),
			text: tr('{customer} — {equipment}, {type}', {
				customer: visit.customerName, equipment: visit.equipmentLabel, type: visit.maintTypeName,
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
						api('POST', apiUrl('visits') + '/' + visit.id + '/complete', {
							doneOn: doneOnInput.value,
							notes: notesInput.value.trim() === '' ? null : notesInput.value,
						}).then(function (result) {
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
				customer: visit.customerName, equipment: visit.equipmentLabel, type: visit.maintTypeName,
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

	function rescheduleDialog(visit, onDone) {
		var dueOnInput = el('input', { type: 'date', value: visit.dueOn });
		var dueOnField = field(tr('New due date'), dueOnInput, { required: true });
		openDialog({
			title: tr('Reschedule visit'),
			text: tr('{customer} — {equipment}, {type}', {
				customer: visit.customerName, equipment: visit.equipmentLabel, type: visit.maintTypeName,
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
		var input = el('input', { type: 'text', value: visit.assignedUid || '', autocomplete: 'off' });
		var warn = el('p', {
			class: 'mn-dialog__hint mn-dialog__hint--warning',
			hidden: 'true',
			role: 'status',
			text: tr('This user currently cannot open MaintenanceCheck (access restriction). You can still assign the visit — the warning is informational only.'),
		});
		var assignField = field(tr('Nextcloud user ID'), input, {
			hint: tr('Leave empty to remove the assignment.'),
		});

		var accessTimer = null;
		function refreshAccessWarning() {
			var value = input.value.trim();
			warn.hidden = true;
			if (value === '' || !ctx.urls || !ctx.urls.api || !ctx.urls.api.userAccess) {
				return;
			}
			api('GET', withQuery(ctx.urls.api.userAccess, { userId: value }))
				.then(function (result) {
					if (input.value.trim() !== value) {
						return;
					}
					if (result.exists && result.canUseApp === false) {
						warn.hidden = false;
					}
				})
				.catch(function () { /* preview is best-effort */ });
		}
		input.addEventListener('input', function () {
			window.clearTimeout(accessTimer);
			accessTimer = window.setTimeout(refreshAccessWarning, 300);
		});
		if (input.value.trim() !== '') {
			refreshAccessWarning();
		}

		openDialog({
			title: tr('Assign visit'),
			text: tr('{customer} — {equipment}, {type}', {
				customer: visit.customerName, equipment: visit.equipmentLabel, type: visit.maintTypeName,
			}),
			content: el('div', {}, [assignField, warn]),
			initialFocus: 'input[type="text"]',
			actions: [
				{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
				{
					label: tr('Save assignment'),
					variant: 'mn-btn--primary',
					onClick: function (d) {
						d.setBusy(true);
						d.setError(null);
						var value = input.value.trim();
						api('PUT', apiUrl('visits') + '/' + visit.id + '/assign', { userId: value === '' ? null : value })
							.then(function () {
								d.close();
								toast(value === '' ? tr('Assignment removed.') : tr('Visit assigned to {user}.', { user: value }), 'success');
								onDone();
							})
							.catch(function (error) {
								d.setBusy(false);
								if (error.code === 'unknown_user') {
									assignField.mnSetError(tr('This Nextcloud user does not exist.'));
									input.focus();
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

	function visitActions(visit, onDone, options) {
		options = options || {};
		var actions = [];
		if (visit.status !== 'scheduled') {
			return actions;
		}
		actions.push(el('button', {
			type: 'button',
			class: 'mn-btn mn-btn--primary mn-btn--compact',
			text: tr('Complete'),
			onClick: function () { completeDialog(visit, onDone); },
		}));

		var overflowItems = [];
		overflowItems.push({
			label: tr('Skip'),
			onClick: function () { skipDialog(visit, onDone); },
		});
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

		if (options.overflow) {
			actions.push(visitOverflowMenu(overflowItems));
		} else {
			overflowItems.forEach(function (item) {
				actions.push(el('button', {
					type: 'button',
					class: 'mn-btn ' + (item.danger ? 'mn-btn--tertiary' : 'mn-btn--secondary') + ' mn-btn--compact',
					text: item.label,
					onClick: item.onClick,
				}));
			});
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
		});
		var wrap = el('div', { class: 'mn-overflow' }, [toggle, menu]);

		function setOpen(next) {
			open = next;
			menu.hidden = !open;
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		}

		function onDoc(ev) {
			if (!wrap.contains(ev.target)) {
				setOpen(false);
			}
		}
		function onKey(ev) {
			if (ev.key === 'Escape') {
				setOpen(false);
				toggle.focus();
			}
		}
		document.addEventListener('click', onDoc);
		document.addEventListener('keydown', onKey);
		// Lightweight cleanup when the card is cleared from the DOM.
		wrap.addEventListener('DOMNodeRemoved', function () {
			document.removeEventListener('click', onDoc);
			document.removeEventListener('keydown', onKey);
		});
		return wrap;
	}

	// ── Page: due board ────────────────────────────────────────────────

	function pageDue() {
		var board = document.getElementById('mn-due-board');
		var emptyBox = document.getElementById('mn-due-empty');
		var mineToggle = document.getElementById('mn-due-mine');
		var todayLabel = document.getElementById('mn-due-today');

		function load() {
			board.setAttribute('aria-busy', 'true');
			api('GET', withQuery(apiUrl('visitsDue'), { mine: mineToggle.checked ? '1' : '' }))
				.then(render)
				.catch(handleGlobalError)
				.finally(function () { board.removeAttribute('aria-busy'); });
		}

		function render(data) {
			todayLabel.textContent = tr('Today is {date}.', { date: fmt(data.today_date) });
			setServerToday(data.today_date);
			var totalVisits = 0;
			['overdue', 'today', 'next7', 'later'].forEach(function (key) {
				var meta = bucketMeta(key);
				var title = document.getElementById('mn-bucket-title-' + key);
				title.textContent = meta.title + ' (' + data.counts[key] + ')';
				var list = board.querySelector('[data-bucket-list="' + key + '"]');
				clear(list);
				totalVisits += data[key].length;
				if (data[key].length === 0) {
					list.appendChild(el('p', { class: 'mn-bucket__empty', text: tr('Nothing here.') }));
					return;
				}
				data[key].forEach(function (visit) {
					list.appendChild(visitCard(visit, key));
				});
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
			}
		}

		function visitCard(visit, bucketKey) {
			var meta = bucketMeta(bucketKey);
			var badgeLabel = bucketKey === 'overdue'
				? tr('Overdue')
				: (bucketKey === 'today' ? tr('Today') : fmt(visit.dueOn));
			var head = el('div', { class: 'mn-visit-card__head' }, [
				el('p', { class: 'mn-visit-card__customer', text: visit.customerName }),
				statusBadge(badgeLabel, meta.badge, meta.icon),
			]);
			var equipmentLink = el('a', {
				href: pageUrl('equipment') + '/' + visit.equipmentId,
				text: visit.equipmentLabel,
			});
			var line = el('p', { class: 'mn-visit-card__line' }, [
				equipmentLink,
				' — ' + visit.maintTypeName + ' · ' + tr('Due {date}', { date: fmt(visit.dueOn) }),
			]);
			var card = el('div', { class: 'mn-visit-card' }, [head, line]);
			if (visit.assignedUid) {
				card.appendChild(el('p', { class: 'mn-visit-card__line', text: tr('Assigned to {user}', { user: visit.assignedUid }) }));
			}
			var actions = visitActions(visit, load);
			if (actions.length) {
				card.appendChild(el('div', { class: 'mn-visit-card__actions' }, actions));
			}
			return card;
		}

		mineToggle.addEventListener('change', load);
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

		if (ctx.isOffice) {
			document.getElementById('mn-page-actions').appendChild(el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--primary',
				onClick: function () { customerFormDialog(null, load); },
			}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), tr('New customer')]));
		}

		function load() {
			list.setAttribute('aria-busy', 'true');
			clear(list);
			list.appendChild(skeleton(4));
			api('GET', withQuery(apiUrl('customers'), { q: state.q, offset: state.offset }))
				.then(render)
				.catch(function (error) {
					clear(list);
					handleGlobalError(error);
				})
				.finally(function () { list.removeAttribute('aria-busy'); });
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
				return;
			}
			envelope.data.forEach(function (customer) {
				var subParts = [];
				if (customer.customerNo) {
					subParts.push(tr('No. {no}', { no: customer.customerNo }));
				}
				if (customer.city) {
					subParts.push(customer.city);
				}
				var row = el('div', { class: 'mn-row' + (customer.active ? '' : ' mn-row--inactive') }, [
					el('div', { class: 'mn-row__main' }, [
						el('h3', { class: 'mn-row__title' }, [
							el('a', { href: pageUrl('customers') + '/' + customer.id, text: customer.name }),
						]),
						subParts.length ? el('p', { class: 'mn-row__sub', text: subParts.join(' · ') }) : null,
					]),
					el('div', { class: 'mn-row__meta' }, [
						customer.active ? null : el('span', { class: 'mn-badge', text: tr('Inactive') }),
					]),
				]);
				list.appendChild(row);
			});
			renderPagination(pagination, envelope, onPage);
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
		document.getElementById('mn-back-link').setAttribute('href', pageUrl('customers'));
		var customerId = ctx.entityId;
		var current = null;

		function load() {
			detail.setAttribute('aria-busy', 'true');
			api('GET', apiUrl('customers') + '/' + customerId)
				.then(function (customer) {
					current = customer;
					renderDetail(customer);
					loadEquipment();
				})
				.catch(function (error) {
					clear(detail);
					detail.appendChild(emptyState(tr('Customer not found'), tr('It may have been deleted in the meantime.')));
					clear(equipmentList);
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
			if (ctx.isOffice) {
				var actions = el('div', { class: 'mn-row__actions', style: 'margin-top:12px' }, [
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
					envelope.data.forEach(function (item) {
						equipmentList.appendChild(equipmentRow(item));
					});
				})
				.catch(handleGlobalError)
				.finally(function () { equipmentList.removeAttribute('aria-busy'); });
		}

		load();
	}

	// ── Equipment form + rows (shared) ─────────────────────────────────

	function equipmentRow(item) {
		var subParts = [item.manufacturer, item.model, item.serialNo ? tr('SN {sn}', { sn: item.serialNo }) : null]
			.filter(function (v) { return v; });
		return el('div', { class: 'mn-row' + (item.active ? '' : ' mn-row--inactive') }, [
			el('div', { class: 'mn-row__main' }, [
				el('h3', { class: 'mn-row__title' }, [
					el('a', { href: pageUrl('equipment') + '/' + item.id, text: item.label }),
				]),
				subParts.length ? el('p', { class: 'mn-row__sub', text: subParts.join(' · ') }) : null,
			]),
			el('div', { class: 'mn-row__meta' }, [
				item.locationText ? el('span', { text: item.locationText }) : null,
				item.active ? null : el('span', { class: 'mn-badge', text: tr('Inactive') }),
			]),
		]);
	}

	function equipmentFormDialog(existing, fixedCustomerId, onDone) {
		Promise.all([
			loadAllCatalog(apiUrl('equipTypes')),
			fixedCustomerId || existing
				? Promise.resolve(null)
				: api('GET', withQuery(apiUrl('customers'), { limit: 200 })).then(function (e) { return e.data; }),
		]).then(function (results) {
			var equipTypes = results[0];
			var customers = results[1];
			var typeSelect = catalogSelect(equipTypes, existing ? existing.equipTypeId : null, true);
			var f = {
				label: field(tr('Label'), el('input', { type: 'text', value: existing ? existing.label : '' }), { required: true, wide: true, hint: tr('How your team refers to this unit, e.g. “Boiler cellar left”.') }),
				equipTypeId: field(tr('Equipment type'), typeSelect, { required: true }),
				manufacturer: field(tr('Manufacturer'), el('input', { type: 'text', value: existing ? (existing.manufacturer || '') : '' })),
				model: field(tr('Model'), el('input', { type: 'text', value: existing ? (existing.model || '') : '' })),
				serialNo: field(tr('Serial number'), el('input', { type: 'text', value: existing ? (existing.serialNo || '') : '' })),
				locationText: field(tr('Location'), el('input', { type: 'text', value: existing ? (existing.locationText || '') : '' }), { hint: tr('Where on site, e.g. “2nd floor, server room”.') }),
				notes: field(tr('Notes'), el('textarea', { rows: '3' }), { wide: true }),
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
			var activeBox = el('input', { type: 'checkbox' });
			activeBox.checked = existing ? !!existing.active : true;

			var order = customers ? ['customerId', 'label', 'equipTypeId'] : ['label', 'equipTypeId'];
			order = order.concat(['manufacturer', 'model', 'serialNo', 'locationText', 'notes']);
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
							var body = {
								label: f.label.mnInput.value,
								equipTypeId: Number(typeSelect.value),
								manufacturer: nullable(f.manufacturer.mnInput.value),
								model: nullable(f.model.mnInput.value),
								serialNo: nullable(f.serialNo.mnInput.value),
								locationText: nullable(f.locationText.mnInput.value),
								notes: nullable(f.notes.mnInput.value),
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

		if (ctx.isOffice) {
			document.getElementById('mn-page-actions').appendChild(el('button', {
				type: 'button',
				class: 'mn-btn mn-btn--primary',
				onClick: function () { equipmentFormDialog(null, null, load); },
			}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), tr('New equipment')]));
		}

		function load() {
			list.setAttribute('aria-busy', 'true');
			clear(list);
			list.appendChild(skeleton(4));
			api('GET', withQuery(apiUrl('equipment'), { q: state.q, offset: state.offset }))
				.then(render)
				.catch(function (error) {
					clear(list);
					handleGlobalError(error);
				})
				.finally(function () { list.removeAttribute('aria-busy'); });
		}

		function render(envelope) {
			clear(list);
			if (envelope.total === 0) {
				list.appendChild(emptyState(
					state.q === '' ? tr('No equipment yet') : tr('No equipment matches your search'),
					state.q === '' ? tr('Equipment is created on a customer page, so every unit has a home.') : tr('Try a shorter search term.')
				));
				renderPagination(pagination, envelope, onPage);
				return;
			}
			envelope.data.forEach(function (item) {
				list.appendChild(equipmentRow(item));
			});
			renderPagination(pagination, envelope, onPage);
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
		loadAllCatalog(apiUrl('maintTypes')).then(function (maintTypes) {
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

			var f = {
				maintTypeId: field(tr('Maintenance type'), typeSelect, { required: true, wide: true }),
				intervalCount: field(tr('Repeat every'), countInput, { required: true }),
				intervalUnit: field(tr('Unit'), unitSelect, { required: true }),
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

			var content = el('div', {}, [
				el('div', { class: 'mn-form-grid' }, Object.keys(f).map(function (k) { return f[k]; })),
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
							var body = {
								maintTypeId: Number(typeSelect.value),
								intervalUnit: unitSelect.value,
								intervalCount: Math.trunc(Number(countInput.value)),
								hasContract: contractBox.checked,
								contractNotes: nullable(contractNotes.value),
							};
							if (!existing) {
								body.firstDueOn = firstDueInput.value;
							} else if (hasOpenVisit) {
								body.recalculateOpenVisit = recalcBox.checked;
							}
							var request = existing
								? api('PUT', apiUrl('plans') + '/' + existing.id, body)
								: api('POST', apiUrl('equipment') + '/' + equipmentId + '/plans', body);
							request.then(function () {
								d.close();
								toast(existing ? tr('Plan saved.') : tr('Plan created — first visit is on the board.'), 'success');
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
					loadPlans();
					loadHistory();
				})
				.catch(function (error) {
					clear(detail);
					detail.appendChild(emptyState(tr('Equipment not found'), tr('It may have been deleted in the meantime.')));
					clear(plansBox);
					clear(historyBox);
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
				pair(tr('Status'), item.active ? tr('Active') : tr('Inactive')),
				item.notes ? pair(tr('Notes'), item.notes) : null,
			]);
			var card = el('div', { class: 'mn-card' }, [grid]);
			detail.appendChild(card);
			if (ctx.isOffice) {
				card.appendChild(el('div', { class: 'mn-row__actions', style: 'margin-top:12px' }, [
					el('button', {
						type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Edit equipment'),
						onClick: function () { equipmentFormDialog(current, null, load); },
					}),
					el('button', {
						type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact', text: tr('Delete equipment'),
						onClick: deleteEquipment,
					}),
				]));
			}
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
				plans.forEach(function (plan) {
					plansBox.appendChild(planRow(plan, typeNames));
				});
			}).catch(handleGlobalError)
				.finally(function () { plansBox.removeAttribute('aria-busy'); });
		}

		function planRow(plan, typeNames) {
			var meta = el('div', { class: 'mn-row__meta' }, [
				el('span', { class: 'mn-badge ' + (plan.active ? 'mn-badge--scheduled' : ''), text: plan.active ? tr('Active') : tr('Inactive') }),
				plan.hasContract ? el('span', { class: 'mn-badge', text: tr('Contract') }) : null,
			]);
			var sub;
			if (plan.openVisit) {
				sub = tr('Next visit due {date}', { date: fmt(plan.openVisit.dueOn) });
			} else if (plan.active) {
				sub = tr('No open visit — schedule one to continue the cycle.');
			} else {
				sub = tr('Plan is inactive.');
			}
			var actions = [];
			if (ctx.isOffice) {
				actions.push(el('button', {
					type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Edit'),
					onClick: function () { planFormDialog(plan, equipmentId, !!plan.openVisit, reload); },
				}));
				if (plan.active && !plan.openVisit) {
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
			return el('div', { class: 'mn-row' + (plan.active ? '' : ' mn-row--inactive') }, [
				el('div', { class: 'mn-row__main' }, [
					el('h3', { class: 'mn-row__title', text: (typeNames[plan.maintTypeId] || tr('Maintenance')) + ' · ' + intervalLabel(plan.intervalUnit, plan.intervalCount) }),
					el('p', { class: 'mn-row__sub', text: sub }),
				]),
				meta,
				actions.length ? el('div', { class: 'mn-row__actions' }, actions) : null,
			]);
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
					envelope.data.forEach(function (visit) {
						historyBox.appendChild(historyRow(visit));
					});
					renderPagination(historyPagination, envelope, onHistoryPage);
				})
				.catch(handleGlobalError)
				.finally(function () { historyBox.removeAttribute('aria-busy'); });
		}

		function historyRow(visit) {
			var meta = statusMeta(visit.status);
			var subParts = [visit.maintTypeName];
			if (visit.status === 'done' && visit.doneOn) {
				subParts.push(tr('Completed on {date}', { date: fmt(visit.doneOn) }));
			}
			if (visit.assignedUid) {
				subParts.push(tr('Assigned to {user}', { user: visit.assignedUid }));
			}
			var actions = visitActions(visit, reload);
			return el('div', { class: 'mn-row' }, [
				el('div', { class: 'mn-row__main' }, [
					el('h3', { class: 'mn-row__title', text: tr('Due {date}', { date: fmt(visit.dueOn) }) }),
					el('p', { class: 'mn-row__sub', text: subParts.join(' · ') }),
					visit.notes ? el('p', { class: 'mn-row__sub', text: visit.notes }) : null,
				]),
				el('div', { class: 'mn-row__meta' }, [
					statusBadge(meta.label, meta.badge, meta.icon),
				]),
				actions.length ? el('div', { class: 'mn-row__actions' }, actions) : null,
			]);
		}

		function onHistoryPage(offset) {
			historyState.offset = offset;
			loadHistory();
		}

		function reload() {
			loadPlans();
			loadHistory();
		}

		load();
	}

	// ── Page: visits ───────────────────────────────────────────────────

	function pageVisits() {
		var list = document.getElementById('mn-visit-list');
		var pagination = document.getElementById('mn-visit-pagination');
		var form = document.getElementById('mn-visit-filters');
		var statusSelect = document.getElementById('mn-filter-status');
		var fromInput = document.getElementById('mn-filter-from');
		var toInput = document.getElementById('mn-filter-to');
		var mineToggle = document.getElementById('mn-filter-mine');
		var resetButton = document.getElementById('mn-filter-reset');
		var state = { offset: 0 };

		function load() {
			list.setAttribute('aria-busy', 'true');
			clear(list);
			list.appendChild(skeleton(5));
			api('GET', withQuery(apiUrl('visits'), {
				status: statusSelect.value,
				from: fromInput.value,
				to: toInput.value,
				mine: mineToggle.checked ? '1' : '',
				offset: state.offset,
			}))
				.then(render)
				.catch(function (error) {
					clear(list);
					if (error.code === 'invalid_query') {
						toast(error.message, 'error');
					} else {
						handleGlobalError(error);
					}
				})
				.finally(function () { list.removeAttribute('aria-busy'); });
		}

		function render(envelope) {
			clear(list);
			if (envelope.total === 0) {
				list.appendChild(emptyState(tr('No visits match these filters'), tr('Try widening the date range or clearing filters.')));
				renderPagination(pagination, envelope, onPage);
				return;
			}
			envelope.data.forEach(function (visit) {
				var meta = statusMeta(visit.status);
				var subParts = [visit.maintTypeName];
				if (visit.status === 'done' && visit.doneOn) {
					subParts.push(tr('Completed on {date}', { date: fmt(visit.doneOn) }));
				}
				if (visit.assignedUid) {
					subParts.push(tr('Assigned to {user}', { user: visit.assignedUid }));
				}
				var actions = visitActions(visit, load);
				list.appendChild(el('div', { class: 'mn-row' }, [
					el('div', { class: 'mn-row__main' }, [
						el('h3', { class: 'mn-row__title' }, [
							el('a', { href: pageUrl('equipment') + '/' + visit.equipmentId, text: visit.customerName + ' — ' + visit.equipmentLabel }),
						]),
						el('p', { class: 'mn-row__sub', text: subParts.join(' · ') }),
					]),
					el('div', { class: 'mn-row__meta' }, [
						el('span', { text: tr('Due {date}', { date: fmt(visit.dueOn) }) }),
						statusBadge(meta.label, meta.badge, meta.icon),
					]),
					actions.length ? el('div', { class: 'mn-row__actions' }, actions) : null,
				]));
			});
			renderPagination(pagination, envelope, onPage);
		}

		function onPage(offset) {
			state.offset = offset;
			load();
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			state.offset = 0;
			load();
		});
		resetButton.addEventListener('click', function () {
			statusSelect.value = '';
			fromInput.value = '';
			toInput.value = '';
			mineToggle.checked = false;
			state.offset = 0;
			load();
		});

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
		renderKind('equip', 'mn-equip-types', 'mn-equip-types-actions');
		renderKind('maint', 'mn-maint-types', 'mn-maint-types-actions');

		function renderKind(kind, boxId, actionsId) {
			var box = document.getElementById(boxId);
			var actions = document.getElementById(actionsId);
			var url = kind === 'equip' ? apiUrl('equipTypes') : apiUrl('maintTypes');

			clear(actions);
			if (ctx.isOffice) {
				actions.appendChild(el('button', {
					type: 'button', class: 'mn-btn mn-btn--primary mn-btn--compact',
					onClick: function () { catalogTypeDialog(kind, null, load); },
				}, [el('span', { html: ICONS.plus, 'aria-hidden': 'true' }), kind === 'equip' ? tr('New equipment type') : tr('New maintenance type')]));
			}

			function load() {
				box.setAttribute('aria-busy', 'true');
				clear(box);
				box.appendChild(skeleton(3));
				loadAllCatalog(url)
					.then(function (types) {
						clear(box);
						if (types.length === 0) {
							box.appendChild(emptyState(tr('No types yet'), tr('Create the first one to categorise your data.')));
							return;
						}
						types.forEach(function (type) {
							var rowActions = [];
							if (ctx.isOffice) {
								rowActions.push(el('button', {
									type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Edit'),
									onClick: function () { catalogTypeDialog(kind, type, load); },
								}));
							}
							box.appendChild(el('div', { class: 'mn-row' + (type.active ? '' : ' mn-row--inactive') }, [
								el('div', { class: 'mn-row__main' }, [
									el('h3', { class: 'mn-row__title', text: type.name }),
									el('p', { class: 'mn-row__sub', text: type.code }),
								]),
								el('div', { class: 'mn-row__meta' }, [
									type.active ? null : el('span', { class: 'mn-badge', text: tr('Deactivated') }),
								]),
								rowActions.length ? el('div', { class: 'mn-row__actions' }, rowActions) : null,
							]));
						});
					})
					.catch(handleGlobalError)
					.finally(function () { box.removeAttribute('aria-busy'); });
			}

			load();
		}
	}

	// ── Page: settings ─────────────────────────────────────────────────

	function idListEditor(options) {
		var ids = options.ids.slice();
		var wrap = el('div', { class: 'mn-field' });
		var input = el('input', { type: 'text', autocomplete: 'off' });
		fieldSeq += 1;
		var inputId = 'mn-idlist-' + fieldSeq;
		input.setAttribute('id', inputId);
		input.classList.add('mn-input');
		var chips = el('ul', { class: 'mn-chips', 'aria-label': options.label });
		var error = el('p', { class: 'mn-field__error', hidden: true });

		function renderChips() {
			clear(chips);
			if (ids.length === 0) {
				chips.appendChild(el('li', { class: 'mn-field__hint', text: options.emptyText }));
				return;
			}
			ids.forEach(function (id) {
				chips.appendChild(el('li', { class: 'mn-chip' }, [
					el('span', { text: id }),
					el('button', {
						type: 'button',
						class: 'mn-chip__remove',
						'aria-label': tr('Remove {id}', { id: id }),
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

		function setError(message) {
			if (message) {
				error.textContent = message;
				error.hidden = false;
			} else {
				error.textContent = '';
				error.hidden = true;
			}
		}

		var addButton = el('button', {
			type: 'button', class: 'mn-btn mn-btn--secondary mn-btn--compact', text: tr('Add'),
			onClick: add,
		});
		function add() {
			var value = input.value.trim();
			if (value === '') {
				return;
			}
			if (ids.indexOf(value) !== -1) {
				input.value = '';
				return;
			}
			var candidate = ids.concat([value]);
			options.onChange(candidate, function (message) {
				setError(message);
				if (!message) {
					ids = candidate;
					input.value = '';
					renderChips();
				}
			});
		}
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				add();
			}
		});

		wrap.appendChild(el('label', { class: 'mn-field__label', for: inputId, text: options.label }));
		wrap.appendChild(el('div', { style: 'display:flex;gap:8px;align-items:center' }, [input, addButton]));
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
		var licenseBox = document.getElementById('mn-settings-license');

		api('GET', apiUrl('config'))
			.then(function (config) {
				renderAccess(config);
				renderRoles(config);
			})
			.catch(handleGlobalError)
			.finally(function () {
				accessBox.removeAttribute('aria-busy');
				rolesBox.removeAttribute('aria-busy');
			});
		loadLicense();

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

		function renderAccess(config) {
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
				ids: config.accessAllowedUserIds,
				emptyText: tr('No users listed.'),
				hint: tr('Nextcloud user IDs with access while the restriction is on.'),
				onChange: function (ids, done) { saveAccess({ accessAllowedUserIds: ids }, done); },
			}));
			accessBox.appendChild(idListEditor({
				label: tr('Allowed groups'),
				ids: config.accessAllowedGroupIds,
				emptyText: tr('No groups listed.'),
				hint: tr('Members of these Nextcloud groups get access while the restriction is on.'),
				onChange: function (ids, done) { saveAccess({ accessAllowedGroupIds: ids }, done); },
			}));
			accessBox.appendChild(idListEditor({
				label: tr('App administrators'),
				ids: config.appAdminUserIds,
				emptyText: tr('Only Nextcloud administrators manage this app.'),
				hint: tr('These users manage settings, catalogs and the license in addition to Nextcloud admins.'),
				onChange: function (ids, done) { saveAccess({ appAdminUserIds: ids }, done); },
			}));
		}

		function renderRoles(config) {
			clear(rolesBox);
			rolesBox.appendChild(idListEditor({
				label: tr('Office users'),
				ids: config.officeUserIds,
				emptyText: tr('No office users listed.'),
				hint: tr('Office members manage customers, equipment and plans.'),
				onChange: function (ids, done) { saveOffice({ officeUserIds: ids }, done); },
			}));
			rolesBox.appendChild(idListEditor({
				label: tr('Office groups'),
				ids: config.officeGroupIds,
				emptyText: tr('No office groups listed.'),
				hint: tr('All members of these groups count as office.'),
				onChange: function (ids, done) { saveOffice({ officeGroupIds: ids }, done); },
			}));
		}

		function loadLicense() {
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
					type: 'button', class: 'mn-btn mn-btn--tertiary mn-btn--compact', text: tr('Remove key'),
					style: 'margin-top:8px',
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
			var seatsTitle = el('h3', { class: 'mn-section__title', style: 'font-size:15px;margin-top:20px', text: tr('Mobile seats') });
			licenseBox.appendChild(seatsTitle);
			var seatPicker = attachUserPicker({
				label: tr('Nextcloud user'),
				hint: tr('Search by name or user ID, then assign a named seat for the official mobile app.'),
				placeholder: tr('Start typing a name or user ID…'),
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
			licenseBox.appendChild(el('div', { style: 'display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap' }, [seatPicker.root, seatAdd]));

			var seatList = el('ul', { class: 'mn-chips', 'aria-label': tr('Assigned mobile seats') });
			if (seats.data.length === 0) {
				seatList.appendChild(el('li', { class: 'mn-field__hint', text: tr('No seats assigned yet.') }));
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

	function boot() {
		ctx = buildContext();
		if (!ctx) {
			return;
		}
		var run = PAGES[ctx.page];
		if (run) {
			run();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();

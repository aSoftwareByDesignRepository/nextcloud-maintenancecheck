/**
 * MaintenanceCheck work-order / dispatch / tours page modules (W1–W3).
 * Loaded after app.js; registers into window.MnApp.PAGES.
 */
(function () {
	'use strict';
	var APP = 'maintenancecheck';

	function tr(text, vars) {
		if (typeof window.t === 'function') {
			return window.t(APP, text, vars);
		}
		var out = text;
		if (vars) {
			Object.keys(vars).forEach(function (k) {
				out = out.replace('{' + k + '}', String(vars[k]));
			});
		}
		return out;
	}

	var WO_ALLOWED = {
		draft: ['planned', 'cancelled'],
		planned: ['ready', 'in_progress', 'blocked', 'cancelled'],
		ready: ['planned', 'in_progress', 'blocked', 'cancelled'],
		in_progress: ['done', 'blocked', 'cancelled'],
		blocked: ['planned', 'ready', 'in_progress', 'cancelled'],
		done: [],
		cancelled: [],
	};

	function woNextStatuses(from) {
		return (WO_ALLOWED[from] || []).slice();
	}

	function transitionLabel(to) {
		switch (to) {
			case 'planned': return tr('Mark planned');
			case 'ready': return tr('Mark ready');
			case 'in_progress': return tr('Start work');
			case 'blocked': return tr('Block');
			case 'done': return tr('Complete');
			case 'cancelled': return tr('Cancel');
			default: return String(to);
		}
	}

	/** Human-readable F6 sync status (text + code — never colour alone). */
	function inventorySyncLabel(sync, code) {
		var base;
		switch (sync) {
			case 'ok': base = tr('Stock deducted'); break;
			case 'failed': base = tr('Stock not deducted'); break;
			case 'disabled': base = tr('Stock sync is off'); break;
			default: base = tr('Stock sync: {status}', { status: String(sync || '') }); break;
		}
		if (code) {
			return base + ' (' + String(code) + ')';
		}
		return base;
	}

	function showBootFailure(message) {
		var hosts = [
			document.getElementById('mn-wo-list'),
			document.getElementById('mn-wo-detail'),
			document.getElementById('mn-dispatch-board'),
			document.getElementById('mn-tours-board'),
			document.getElementById('mn-tours-list'),
		];
		var shown = false;
		hosts.forEach(function (host) {
			if (!host) {
				return;
			}
			host.setAttribute('role', 'alert');
			host.setAttribute('aria-live', 'assertive');
			host.textContent = message;
			shown = true;
		});
		if (!shown && typeof window.console !== 'undefined' && window.console.error) {
			window.console.error(message);
		}
	}

	function waitForMnApp(cb) {
		if (window.MnApp && window.MnApp.__dom) {
			cb(window.MnApp.__dom);
			return;
		}
		var n = 0;
		var timer = window.setInterval(function () {
			n += 1;
			if (window.MnApp && window.MnApp.__dom) {
				window.clearInterval(timer);
				cb(window.MnApp.__dom);
			} else if (n > 100) {
				window.clearInterval(timer);
				showBootFailure(tr('Work orders could not start. Reload the page. If this keeps happening, contact your admin.'));
			}
		}, 20);
	}

	function register() {
		waitForMnApp(function (dom) {
			var api = dom.api;
			var apiUpload = dom.apiUpload;
			var apiUrl = dom.apiUrl;
			var pageUrl = dom.pageUrl;
			var withQuery = dom.withQuery;
			var el = dom.el;
			var clear = dom.clear;
			var emptyState = dom.emptyState;
			var skeleton = dom.skeleton;
			var renderPagination = dom.renderPagination;
			var statusMeta = dom.statusMeta;
			var statusBadge = dom.statusBadge;
			var toast = dom.toast;
			var announce = dom.announce || function () {};
			var attachUserPicker = dom.attachUserPicker;
			var handleGlobalError = dom.handleGlobalError;
			var openDialog = dom.openDialog;
			var field = dom.field;
			var fmt = dom.fmt;
			var todayYmd = dom.todayYmd;
			var tableOrCards = dom.tableOrCards;
			var tableStack = dom.tableStack;
			var tableLink = dom.tableLink;
			var tableCellText = dom.tableCellText;
			var getCtx = dom.getCtx;

			function debounce(fn, wait) {
				var timer = null;
				return function () {
					var args = arguments;
					var self = this;
					window.clearTimeout(timer);
					timer = window.setTimeout(function () {
						fn.apply(self, args);
					}, wait);
				};
			}

			function reasonLongEnough(value, min) {
				return String(value || '').trim().length >= min;
			}

			/** Bachus: scroll/focus the first open required checklist item so techs are not stuck. */
			function focusFirstIncompleteChecklist(error) {
				var items = (error && error.details && error.details.incompleteItems) || [];
				var code = items[0] && items[0].code ? String(items[0].code) : '';
				var li = null;
				if (code) {
					try {
						li = document.querySelector('.mn-checklist__item[data-item-code="' + CSS.escape(code) + '"]');
					} catch (e) {
						li = document.querySelector('.mn-checklist__item[data-item-code="' + code.replace(/"/g, '') + '"]');
					}
				}
				if (!li) {
					var nodes = document.querySelectorAll('.mn-checklist__item');
					for (var i = 0; i < nodes.length; i++) {
						if (!nodes[i].querySelector('.mn-btn.is-selected, [aria-pressed="true"]')) {
							li = nodes[i];
							break;
						}
					}
					li = li || nodes[0] || null;
				}
				if (!li) {
					return;
				}
				document.querySelectorAll('.mn-checklist__item--attention').forEach(function (node) {
					node.classList.remove('mn-checklist__item--attention');
				});
				li.classList.add('mn-checklist__item--attention');
				if (!li.hasAttribute('tabindex')) {
					li.setAttribute('tabindex', '-1');
				}
				li.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				window.setTimeout(function () {
					try { li.focus(); } catch (e2) { /* ignore */ }
				}, 40);
				announce(tr('Required checklist items are still open. Finish them or ask the office.'), true);
			}

			function loadProcedures() {
				return api('GET', apiUrl('procedures')).then(function (envelope) {
					return (envelope && envelope.data) ? envelope.data : [];
				});
			}

			function procedureSelect(procedures, selectedId) {
				var select = el('select', { class: 'mn-input form-select', 'aria-required': 'false' });
				select.appendChild(el('option', { value: '', text: tr('Select a procedure…') }));
				(procedures || []).forEach(function (proc) {
					if (proc.active === false) {
						return;
					}
					select.appendChild(el('option', {
						value: String(proc.id),
						text: (proc.title || proc.code || ('#' + proc.id)),
						selected: selectedId && Number(selectedId) === Number(proc.id) ? 'selected' : null,
					}));
				});
				return select;
			}

			function procedureLabel(procedures, procedureId, skipped, skipReason) {
				if (skipped) {
					return tr('Skipped') + (skipReason ? (': ' + skipReason) : '');
				}
				if (!procedureId) {
					return tr('No procedure attached');
				}
				var found = null;
				(procedures || []).forEach(function (proc) {
					if (Number(proc.id) === Number(procedureId)) {
						found = proc;
					}
				});
				if (found) {
					return found.title || found.code || ('#' + found.id);
				}
				return '#' + procedureId;
			}

			function openProcedureChoiceDialog(options) {
				loadProcedures().then(function (procedures) {
					var select = procedureSelect(procedures, options.selectedId || null);
					var skipToggle = el('input', { type: 'checkbox', id: 'mn-wo-proc-skip' });
					var skipReason = el('textarea', {
						class: 'mn-input form-textarea',
						rows: '3',
						id: 'mn-wo-proc-skip-reason',
					});
					var skipLabel = el('label', { class: 'mn-checkbox', for: 'mn-wo-proc-skip' }, [
						skipToggle,
						el('span', { text: tr('Skip without procedure') }),
					]);
					function syncSkipUi() {
						select.disabled = !!skipToggle.checked;
						skipReason.disabled = !skipToggle.checked;
					}
					skipToggle.addEventListener('change', syncSkipUi);
					syncSkipUi();
					openDialog({
						title: options.title || tr('Attach procedure'),
						content: el('div', { class: 'mn-form-grid mn-form-grid--single' }, [
							field(tr('Procedure'), select, {
								hint: tr('Pick a checklist template, or skip with a reason.'),
							}),
							skipLabel,
							field(tr('Skip reason'), skipReason, {
								hint: tr('At least 10 characters when skipping.'),
							}),
						]),
						actions: [
							{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
							{
								label: options.confirmLabel || tr('Save'),
								variant: 'mn-btn--primary',
								onClick: function (d) {
									var body = {};
									if (skipToggle.checked) {
										if (!reasonLongEnough(skipReason.value, 10)) {
											d.setError(tr('The skip reason must be at least 10 characters.'));
											return;
										}
										body.procedureSkipped = true;
										body.procedureSkipReason = skipReason.value.trim();
										body.procedureId = null;
									} else {
										if (!select.value) {
											d.setError(tr('Select a procedure or skip with a reason.'));
											return;
										}
										body.procedureId = Number(select.value);
										body.procedureSkipped = false;
										body.procedureSkipReason = null;
									}
									d.setBusy(true);
									d.setError(null);
									Promise.resolve(options.onConfirm(body, d)).catch(function (error) {
										d.setBusy(false);
										d.setError(error.message || tr('Something went wrong. Please try again.'));
									});
								},
							},
						],
					});
				}).catch(function (error) {
					toast(error.message || tr('Could not load procedures.'), 'error');
				});
			}

			function makeSignaturePad() {
				var canvas = el('canvas', {
					width: '400',
					height: '150',
					class: 'mn-signature-canvas',
					'aria-label': tr('Draw signature'),
				});
				var ctx2d = canvas.getContext('2d');
				// Ink token targets the always-light signature pad (see --mn-signature-ink)
				var ink = getComputedStyle(document.body).getPropertyValue('--mn-signature-ink').trim()
					|| getComputedStyle(document.documentElement).getPropertyValue('--mn-signature-ink').trim()
					|| '#111';
				ctx2d.strokeStyle = ink;
				ctx2d.lineWidth = 2;
				ctx2d.lineCap = 'round';
				ctx2d.lineJoin = 'round';
				var drawing = false;
				var dirty = false;
				function pos(event) {
					var rect = canvas.getBoundingClientRect();
					var src = event.touches && event.touches[0] ? event.touches[0] : event;
					return {
						x: (src.clientX - rect.left) * (canvas.width / rect.width),
						y: (src.clientY - rect.top) * (canvas.height / rect.height),
					};
				}
				function start(event) {
					event.preventDefault();
					drawing = true;
					var p = pos(event);
					ctx2d.beginPath();
					ctx2d.moveTo(p.x, p.y);
				}
				function move(event) {
					if (!drawing) {
						return;
					}
					event.preventDefault();
					var p = pos(event);
					ctx2d.lineTo(p.x, p.y);
					ctx2d.stroke();
					dirty = true;
				}
				function end(event) {
					if (!drawing) {
						return;
					}
					event.preventDefault();
					drawing = false;
				}
				canvas.addEventListener('mousedown', start);
				canvas.addEventListener('mousemove', move);
				canvas.addEventListener('mouseup', end);
				canvas.addEventListener('mouseleave', end);
				canvas.addEventListener('touchstart', start, { passive: false });
				canvas.addEventListener('touchmove', move, { passive: false });
				canvas.addEventListener('touchend', end);
				return {
					canvas: canvas,
					isEmpty: function () { return !dirty; },
					clear: function () {
						ctx2d.clearRect(0, 0, canvas.width, canvas.height);
						dirty = false;
					},
					toDataURL: function () { return canvas.toDataURL('image/png'); },
				};
			}

			function pageWorkOrders() {
				var ctx = getCtx();
				var list = document.getElementById('mn-wo-list');
				var pagination = document.getElementById('mn-wo-pagination');
				var form = document.getElementById('mn-wo-filters');
				var statusSelect = document.getElementById('mn-wo-status');
				var qInput = document.getElementById('mn-wo-q');
				var mineToggle = document.getElementById('mn-wo-mine');
				var resetButton = document.getElementById('mn-wo-reset');
				var newButton = document.getElementById('mn-wo-new');
				var state = { offset: 0 };
				var loadSeq = 0;

				function applyFilters() {
					state.offset = 0;
					load();
				}

				var runSearch = debounce(function () {
					applyFilters();
				}, 250);

				function load() {
					var seq = ++loadSeq;
					list.setAttribute('aria-busy', 'true');
					clear(list);
					list.appendChild(skeleton(5));
					api('GET', withQuery(apiUrl('workOrders'), {
						q: qInput.value,
						mine: mineToggle.checked ? '1' : '',
						offset: state.offset,
						open: statusSelect.value === '' ? '1' : '',
						status: statusSelect.value,
					})).then(function (envelope) {
						if (seq !== loadSeq) {
							return;
						}
						render(envelope);
					}).catch(function (error) {
						if (seq !== loadSeq) {
							return;
						}
						clear(list);
						handleGlobalError(error);
					}).finally(function () {
						if (seq === loadSeq) {
							list.removeAttribute('aria-busy');
						}
					});
				}

				function render(envelope) {
					clear(list);
					if (!envelope || !envelope.data || envelope.total === 0) {
						list.appendChild(emptyState(
							tr('No work orders yet'),
							tr('Office creates jobs here. Technicians open a job to run the checklist and add photos.')
						));
						renderPagination(pagination, envelope || { total: 0, limit: 50, offset: 0 }, onPage);
						announce(tr('No work orders yet'));
						return;
					}
					list.appendChild(tableOrCards([
						{
							id: 'job',
							label: tr('Work order'),
							render: function (wo) {
								var href = pageUrl('workOrders') + '/' + wo.id;
								var subParts = [wo.customerName || '', wo.equipmentLabel || '', wo.kind || ''].filter(Boolean);
								if (wo.dueOn) {
									subParts.push(tr('Due {date}', { date: fmt(wo.dueOn) }));
								}
								return tableStack(
									tableLink(href, (wo.number || '') + ' — ' + (wo.title || tr('Work order'))),
									subParts.join(' · ')
								);
							},
						},
						{
							id: 'status',
							label: tr('Status'),
							render: function (wo) {
								var meta = statusMeta(wo.status);
								return statusBadge(meta.label, meta.badge, meta.icon);
							},
						},
						{
							id: 'actions',
							label: tr('Actions'),
							actions: true,
							render: function (wo) {
								return [
									el('a', {
										class: 'mn-btn mn-btn--primary mn-btn--compact button',
										href: pageUrl('workOrders') + '/' + wo.id,
										text: tr('Open'),
									}),
								];
							},
						},
					], envelope.data, { caption: tr('Work orders') }));
					renderPagination(pagination, envelope, onPage);
					announce(tr('{n} results', { n: envelope.total }));
				}

				function onPage(offset) {
					state.offset = offset;
					load();
				}

				form.addEventListener('submit', function (event) {
					event.preventDefault();
					applyFilters();
				});
				statusSelect.addEventListener('change', applyFilters);
				mineToggle.addEventListener('change', applyFilters);
				qInput.addEventListener('input', runSearch);
				resetButton.addEventListener('click', function () {
					statusSelect.value = '';
					qInput.value = '';
					mineToggle.checked = false;
					applyFilters();
				});
				if (newButton) {
					newButton.addEventListener('click', function () {
						Promise.all([
							api('GET', withQuery(apiUrl('customers'), { limit: 200 })),
							loadProcedures(),
						]).then(function (results) {
							var customers = (results[0] && results[0].data) ? results[0].data : [];
							var procedures = results[1] || [];
							var titleInput = el('input', { type: 'text', class: 'mn-input form-input', autocomplete: 'off' });
							var customerSelect = el('select', { class: 'mn-input form-select', 'aria-required': 'true' });
							customerSelect.appendChild(el('option', { value: '', text: tr('Select a customer…') }));
							customers.forEach(function (customer) {
								customerSelect.appendChild(el('option', {
									value: String(customer.id),
									text: customer.name || ('#' + customer.id),
								}));
							});
							var equipmentSelect = el('select', { class: 'mn-input form-select' });
							equipmentSelect.appendChild(el('option', { value: '', text: tr('No equipment') }));
							function reloadEquipment() {
								clear(equipmentSelect);
								equipmentSelect.appendChild(el('option', { value: '', text: tr('No equipment') }));
								if (!customerSelect.value) {
									return;
								}
								api('GET', withQuery(apiUrl('equipment'), {
									customerId: customerSelect.value,
									limit: 200,
								})).then(function (envelope) {
									((envelope && envelope.data) || []).forEach(function (eq) {
										equipmentSelect.appendChild(el('option', {
											value: String(eq.id),
											text: eq.label || ('#' + eq.id),
										}));
									});
								}).catch(function () { /* keep empty list */ });
							}
							customerSelect.addEventListener('change', reloadEquipment);
							var kindSelect = el('select', { class: 'mn-input form-select' }, [
								el('option', { value: 'corrective', text: tr('Corrective') }),
								el('option', { value: 'preventive', text: tr('Preventive') }),
								el('option', { value: 'inspection', text: tr('Inspection') }),
								el('option', { value: 'other', text: tr('Other') }),
							]);
							var prioritySelect = el('select', { class: 'mn-input form-select' }, [
								el('option', { value: 'low', text: tr('Low') }),
								el('option', { value: 'normal', text: tr('Normal'), selected: 'selected' }),
								el('option', { value: 'high', text: tr('High') }),
								el('option', { value: 'emergency', text: tr('Emergency') }),
							]);
							var procSelect = procedureSelect(procedures, null);
							var requesterInput = el('input', { type: 'text', class: 'mn-input form-input', autocomplete: 'name' });
							var phoneInput = el('input', { type: 'tel', class: 'mn-input form-input', autocomplete: 'tel' });
							var symptomInput = el('textarea', { class: 'mn-input form-textarea', rows: '2' });
							var accessInput = el('textarea', { class: 'mn-input form-textarea', rows: '2' });
							var formFields = [
								field(tr('Title'), titleInput, { required: true }),
								field(tr('Customer'), customerSelect, {
									required: true,
									hint: customers.length
										? tr('Pick the customer this work belongs to.')
										: tr('No customers yet — add one in the register first.'),
								}),
								field(tr('Kind'), kindSelect, { required: true }),
								field(tr('Priority'), prioritySelect, { required: true }),
								field(tr('Equipment'), equipmentSelect, {
									hint: tr('Optional — link a unit for this job.'),
								}),
								field(tr('Procedure'), procSelect, {
									hint: tr('Optional — attach a checklist template now.'),
								}),
								field(tr('Requester name'), requesterInput, {
									hint: tr('Who called — for break/fix intake.'),
								}),
								field(tr('Requester phone'), phoneInput),
								field(tr('What is wrong'), symptomInput, {
									hint: tr('Short symptom — e.g. no heat, alarm fault.'),
								}),
								field(tr('Access notes'), accessInput, {
									hint: tr('Gate codes, dogs, preferred hours — copied from the site when empty.'),
								}),
							];
							openDialog({
								title: tr('New work order'),
								content: el('div', { class: 'mn-form-grid' }, formFields),
								actions: [
									{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
									{
										label: tr('Create'),
										variant: 'mn-btn--primary',
										onClick: function (d) {
											if (!customerSelect.value) {
												d.setError(tr('Choose a customer.'));
												return;
											}
											if (!String(titleInput.value || '').trim()) {
												d.setError(tr('Title is required.'));
												return;
											}
											d.setBusy(true);
											d.setError(null);
											var body = {
												title: titleInput.value.trim(),
												customerId: Number(customerSelect.value),
												kind: kindSelect.value,
												priority: prioritySelect.value,
											};
											if (equipmentSelect.value) {
												body.equipmentId = Number(equipmentSelect.value);
											}
											if (procSelect.value) {
												body.procedureId = Number(procSelect.value);
											}
											if (requesterInput.value.trim()) {
												body.requesterName = requesterInput.value.trim();
											}
											if (phoneInput.value.trim()) {
												body.requesterPhone = phoneInput.value.trim();
											}
											if (symptomInput.value.trim()) {
												body.symptom = symptomInput.value.trim();
											}
											if (accessInput.value.trim()) {
												body.accessNotes = accessInput.value.trim();
											}
											api('POST', apiUrl('workOrders'), body).then(function (wo) {
												d.close();
												var msg = tr('Work order created.');
												if (wo && wo.warnings && wo.warnings.length) {
													msg += ' ' + tr('Warranty warning: check the equipment.');
												}
												toast(msg, 'success');
												if (wo && wo.id) {
													window.location.href = pageUrl('workOrders') + '/' + wo.id;
												} else {
													load();
												}
											}).catch(function (error) {
												d.setBusy(false);
												d.setError(error.message);
											});
										},
									},
								],
							});
						}).catch(function (error) {
							toast(error.message || tr('Could not load customers.'), 'error');
						});
					});
				}
				load();
			}

			function pageWorkOrderDetail() {
				var ctx = getCtx();
				var root = document.getElementById('mn-wo-detail');
				var id = ctx.entityId;
				if (!id) {
					clear(root);
					root.appendChild(emptyState(tr('Work order not found'), tr('Go back to the list and open a job.')));
					return;
				}

				function load() {
					root.setAttribute('aria-busy', 'true');
					api('GET', apiUrl('workOrders') + '/' + id).then(render).catch(function (error) {
						clear(root);
						handleGlobalError(error);
					}).finally(function () { root.removeAttribute('aria-busy'); });
				}

				function render(wo) {
					clear(root);
					var meta = statusMeta(wo.status);
					var next = woNextStatuses(wo.status);
					var required = wo.requiredSkills || [];
					var kit = wo.kit;
					var kitLines = (kit && kit.lines) || [];
					var kitIncomplete = !!(kit && kitLines.some(function (line) {
						return Number(line.qtyPacked || 0) < Number(line.qtyRequired || 0);
					}));
					var working = wo.status === 'in_progress';
					var terminal = wo.status === 'done' || wo.status === 'cancelled';
					var photos = wo.photos || [];
					var hasSignature = !!(wo.signature && wo.signature.id);
					var needsSetup = (wo.status === 'draft' || wo.status === 'planned') && !wo.procedureId && !wo.procedureSkipped;
					var openMore = (ctx.isOffice && required.length > 0) || kitIncomplete || needsSetup;

					function afterTransitionOk(nextStatus) {
						var label = nextStatus ? transitionLabel(nextStatus) : tr('Status updated.');
						var msg = nextStatus ? tr('Status: {status}', { status: label }) : tr('Status updated.');
						toast(msg, 'success');
						announce(msg, false);
						load();
					}

					function postTransition(body) {
						return api('POST', apiUrl('workOrders') + '/' + wo.id + '/transition', body);
					}

					function openKitOverrideDialog() {
						var reasonInput = el('textarea', {
							class: 'mn-input form-textarea',
							rows: '3',
							'aria-required': 'true',
						});
						openDialog({
							title: tr('Kit not fully packed'),
							content: el('div', { class: 'mn-form-grid' }, [
								el('p', { text: tr('The kit is not fully packed yet. Office can override with a reason.') }),
								field(tr('Override reason'), reasonInput, {
									required: true,
									hint: tr('At least 10 characters.'),
								}),
							]),
							actions: [
								{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
								{
									label: tr('Override and mark ready'),
									variant: 'mn-btn--primary',
									onClick: function (d) {
										if (!reasonLongEnough(reasonInput.value, 10)) {
											d.setError(tr('The override reason must be at least 10 characters.'));
											return;
										}
										d.setBusy(true);
										d.setError(null);
										postTransition({
											to: 'ready',
											kitOverride: true,
											kitOverrideReason: reasonInput.value.trim(),
										}).then(function () {
											d.close();
											afterTransitionOk();
										}).catch(function (error) {
											d.setBusy(false);
											d.setError(error.message);
										});
									},
								},
							],
						});
					}

					function openForceCloseDialog() {
						var reasonInput = el('textarea', {
							class: 'mn-input form-textarea',
							rows: '3',
							'aria-required': 'true',
						});
						openDialog({
							title: tr('Force-close work order'),
							content: el('div', { class: 'mn-form-grid' }, [
								el('p', { text: tr('Required checklist items are still open. Finish them or confirm the exception.') }),
								field(tr('Force-close reason'), reasonInput, {
									required: true,
									hint: tr('At least 20 characters.'),
								}),
							]),
							actions: [
								{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
								{
									label: tr('Force close'),
									variant: 'mn-btn--primary',
									onClick: function (d) {
										if (!reasonLongEnough(reasonInput.value, 20)) {
											d.setError(tr('The force-close reason must be at least 20 characters.'));
											return;
										}
										d.setBusy(true);
										d.setError(null);
										postTransition({
											to: 'done',
											forceClose: reasonInput.value.trim(),
										}).then(function () {
											d.close();
											afterTransitionOk();
										}).catch(function (error) {
											d.setBusy(false);
											d.setError(error.message);
										});
									},
								},
							],
						});
					}

					function runTransition(to) {
						if (to === 'blocked') {
							postTransition({
								to: to,
								blockReasonCode: 'other',
								blockNote: tr('Blocked from phone UI'),
							}).then(afterTransitionOk).catch(function (error) {
								toast(error.message, 'error');
							});
							return;
						}
						if (to === 'planned') {
							openProcedureChoiceDialog({
								title: tr('Mark planned'),
								confirmLabel: tr('Mark planned'),
								selectedId: wo.procedureId,
								onConfirm: function (procBody, d) {
									var body = { to: 'planned' };
									if (procBody.procedureSkipped) {
										body.procedureSkipped = true;
										body.procedureSkipReason = procBody.procedureSkipReason;
										body.procedureId = null;
									} else {
										body.procedureId = procBody.procedureId;
										body.procedureSkipped = false;
										body.procedureSkipReason = null;
									}
									return postTransition(body).then(function () {
										d.close();
										afterTransitionOk();
									});
								},
							});
							return;
						}
						if (to === 'ready') {
							postTransition({ to: 'ready' }).then(function () { afterTransitionOk('ready'); }).catch(function (error) {
								if (error.code === 'kit_incomplete') {
									openKitOverrideDialog();
									return;
								}
								toast(error.message, 'error');
							});
							return;
						}
						if (to === 'done') {
							if (wo.kind === 'inspection') {
								openDoneDialog();
								return;
							}
							postTransition({ to: 'done' }).then(function () {
								afterTransitionOk('done');
							}).catch(function (error) {
								if (error.code === 'failure_code_required' || error.code === 'validation_failed') {
									openDoneDialog();
									return;
								}
								if (error.code === 'checklist_incomplete') {
									focusFirstIncompleteChecklist(error);
									if (ctx.isOffice) {
										openForceCloseDialog();
									} else {
										toast(error.message || tr('Required checklist items are still open. Finish them or ask the office.'), 'error');
									}
									return;
								}
								toast(error.message, 'error');
							});
							return;
						}
						postTransition({ to: to }).then(afterTransitionOk).catch(function (error) {
							toast(error.message, 'error');
						});
					}

					function openDoneDialog() {
						if (wo.kind === 'inspection') {
							var resultSelect = el('select', { class: 'mn-input form-select', 'aria-label': tr('Inspection result') });
							[
								{ value: '', label: tr('Select result…') },
								{ value: 'pass', label: tr('Pass') },
								{ value: 'fail', label: tr('Fail') },
								{ value: 'conditional', label: tr('Conditional') },
							].forEach(function (opt) {
								resultSelect.appendChild(el('option', { value: opt.value, text: opt.label }));
							});
							var inspectorInput = el('input', {
								type: 'text',
								class: 'mn-input form-input',
								maxlength: '128',
								'aria-label': tr('Inspector name'),
							});
							inspectorInput.value = String((getCtx() && getCtx().currentUser) || '').trim();
							var inspectorNote = el('input', {
								type: 'text',
								class: 'mn-input form-input',
								maxlength: '512',
								'aria-label': tr('Inspector note'),
							});
							var defectsList = el('div', { class: 'mn-form-grid', 'aria-label': tr('Defects') });
							var photoOptions = (wo.photos || []).slice();
							function addDefectRow(prefill) {
								var codeSelect = el('select', {
									class: 'mn-input form-select',
									'aria-label': tr('Defect code'),
								});
								codeSelect.appendChild(el('option', { value: '', text: tr('Select defect code…') }));
								codeSelect.appendChild(el('option', { value: '__other__', text: tr('Other (free text)') }));
								var codeOther = el('input', {
									type: 'text',
									class: 'mn-input form-input',
									maxlength: '64',
									placeholder: tr('Defect code'),
									'aria-label': tr('Other defect code'),
									hidden: true,
								});
								var prefillCode = (prefill && prefill.code) ? String(prefill.code) : '';
								function syncOther() {
									var isOther = codeSelect.value === '__other__';
									codeOther.hidden = !isOther;
									if (!isOther) {
										codeOther.value = '';
									}
								}
								codeSelect.addEventListener('change', syncOther);
								var body = el('textarea', {
									class: 'mn-input form-input',
									rows: '2',
									maxlength: '2000',
									'aria-label': tr('Defect description'),
								});
								if (prefill && prefill.body) {
									body.value = prefill.body;
								}
								var photoSelect = el('select', {
									class: 'mn-input form-select',
									'aria-label': tr('Defect photo'),
								});
								photoSelect.appendChild(el('option', { value: '', text: tr('No photo linked') }));
								photoOptions.forEach(function (ph) {
									var label = (ph.originalName || ph.fileName || ('#' + ph.id));
									photoSelect.appendChild(el('option', { value: String(ph.id), text: label }));
								});
								if (prefill && prefill.photoFileId) {
									photoSelect.value = String(prefill.photoFileId);
								}
								var row = el('div', { class: 'mn-form-grid mn-defect-row' }, [
									field(tr('Defect code'), codeSelect),
									field(tr('Other defect code'), codeOther, { wide: true }),
									field(tr('Defect description'), body, { wide: true }),
									field(tr('Defect photo'), photoSelect, {
										wide: true,
										hint: photoOptions.length ? tr('Optional — pick a photo already attached to this work order.') : tr('Attach photos on the work order first, then link them here.'),
									}),
								]);
								row._mnCodeSelect = codeSelect;
								row._mnCodeOther = codeOther;
								row._mnResolveCode = function () {
									if (codeSelect.value === '__other__') {
										return String(codeOther.value || '').trim();
									}
									return String(codeSelect.value || '').trim();
								};
								row._mnBody = body;
								row._mnPhoto = photoSelect;
								api('GET', withQuery(apiUrl('failureCodes'), { active: '1' })).then(function (envelope) {
									var known = {};
									((envelope && envelope.data) || []).forEach(function (fc) {
										var v = String(fc.code || '');
										if (!v || known[v]) {
											return;
										}
										known[v] = true;
										codeSelect.appendChild(el('option', {
											value: v,
											text: (fc.name || fc.code),
										}));
									});
									if (prefillCode) {
										if (known[prefillCode]) {
											codeSelect.value = prefillCode;
										} else {
											codeSelect.value = '__other__';
											codeOther.value = prefillCode;
										}
										syncOther();
									}
								}).catch(function () {
									if (prefillCode) {
										codeSelect.value = '__other__';
										codeOther.value = prefillCode;
										syncOther();
									}
								});
								defectsList.appendChild(row);
								return row;
							}
							addDefectRow();
							var addDefectBtn = el('button', {
								type: 'button',
								class: 'mn-btn mn-btn--tertiary mn-btn--compact',
								text: tr('Add another defect'),
							});
							addDefectBtn.addEventListener('click', function () { addDefectRow(); });
							var defectWrap = el('div', {}, [
								defectsList,
								el('p', { class: 'mn-muted', text: tr('Required when the result is fail or conditional.') }),
								addDefectBtn,
							]);
							defectWrap.hidden = true;
							resultSelect.addEventListener('change', function () {
								defectWrap.hidden = resultSelect.value === 'pass' || resultSelect.value === '';
							});
							openDialog({
								title: tr('Complete inspection'),
								content: el('div', { class: 'mn-form-grid' }, [
									field(tr('Result'), resultSelect, { required: true, wide: true }),
									field(tr('Inspector name'), inspectorInput, { required: true, wide: true }),
									field(tr('Inspector note'), inspectorNote, { hint: tr('Optional qualification or company.'), wide: true }),
									defectWrap,
									el('p', { class: 'mn-muted', text: tr('This creates an operational evidence pack — not a certificate.') }),
								]),
								actions: [
									{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
									{
										label: tr('Complete'),
										variant: 'mn-btn--primary',
										onClick: function (d) {
											if (!resultSelect.value) {
												d.setError(tr('Please choose an inspection result.'));
												return;
											}
											if (!inspectorInput.value.trim()) {
												d.setError(tr('Inspector name is required.'));
												return;
											}
											var defects = [];
											Array.prototype.forEach.call(defectsList.children, function (row) {
												var code = row._mnResolveCode
													? String(row._mnResolveCode() || '').trim()
													: (row._mnCode && row._mnCode.value || '').trim();
												var bodyText = (row._mnBody && row._mnBody.value || '').trim();
												if (code || bodyText) {
													var defect = {
														code: code || 'defect',
														body: bodyText,
													};
													var photoVal = row._mnPhoto && row._mnPhoto.value ? parseInt(row._mnPhoto.value, 10) : 0;
													if (photoVal > 0) {
														defect.photoFileId = photoVal;
													}
													defects.push(defect);
												}
											});
											if (resultSelect.value !== 'pass') {
												var complete = defects.filter(function (x) { return x.body; });
												if (!complete.length) {
													d.setError(tr('Add at least one defect when the result is not pass.'));
													return;
												}
												defects = complete;
											}
											d.setBusy(true);
											d.setError(null);
											var body = {
												to: 'done',
												result: resultSelect.value,
												inspectorName: inspectorInput.value.trim(),
											};
											if (inspectorNote.value.trim()) {
												body.inspectorNote = inspectorNote.value.trim();
											}
											if (resultSelect.value !== 'pass') {
												body.defects = defects;
											}
											postTransition(body).then(function (detail) {
												d.close();
												afterTransitionOk('done');
												if (detail && detail.defectFollowUp === 'warn') {
													toast(tr('Inspection failed — open a corrective work order for the defects.'), 'warning');
													announce(tr('Inspection failed — open a corrective work order for the defects.'), true);
												} else if (detail && detail.defectFollowUp === 'auto' && detail.correctiveWorkOrderId) {
													var correctiveId = detail.correctiveWorkOrderId;
													toast(tr('Corrective follow-up work order created.'), 'success', {
														label: tr('Open'),
														onClick: function () {
															window.location.href = pageUrl('workOrders') + '/' + correctiveId;
														},
													});
												} else if (detail && detail.defectFollowUpFailed) {
													toast(tr('Inspection saved. Corrective follow-up could not be created automatically — open one manually.'), 'warning');
												} else if (detail && detail.defectFollowUp === 'auto' && !detail.correctiveWorkOrderId) {
													toast(tr('Inspection saved. Corrective follow-up could not be created automatically — open one manually.'), 'warning');
												}
											}).catch(function (error) {
												d.setBusy(false);
												d.setError(error.message);
											});
										},
									},
								],
							});
							return;
						}
						var codeSelect = el('select', { class: 'mn-input form-select' });
						codeSelect.appendChild(el('option', { value: '', text: tr('No failure code') }));
						var laborInput = el('input', {
							type: 'number',
							class: 'mn-input form-input',
							min: '0',
							max: '1440',
							step: '1',
							inputmode: 'numeric',
							'aria-label': tr('Job duration (evidence) in minutes'),
						});
						api('GET', withQuery(apiUrl('failureCodes'), { active: '1' })).then(function (envelope) {
							((envelope && envelope.data) || []).forEach(function (row) {
								codeSelect.appendChild(el('option', {
									value: String(row.code),
									text: (row.name || row.code),
								}));
							});
						}).catch(function () { /* optional catalog */ });
						openDialog({
							title: tr('Complete work order'),
							content: el('div', { class: 'mn-form-grid' }, [
								field(tr('Failure code'), codeSelect, {
									hint: tr('Required for corrective jobs when policy says so.'),
								}),
								field(tr('Job duration (evidence)'), laborInput, {
									hint: tr('Minutes on site — not working-time clock. 0–1440.'),
								}),
							]),
							actions: [
								{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
								{
									label: tr('Complete'),
									variant: 'mn-btn--primary',
									onClick: function (d) {
										d.setBusy(true);
										d.setError(null);
										var body = { to: 'done' };
										if (codeSelect.value) {
											body.failureCode = codeSelect.value;
										}
										if (laborInput.value !== '') {
											body.laborMinutes = Number(laborInput.value);
										}
										postTransition(body).then(function () {
											d.close();
											afterTransitionOk('done');
										}).catch(function (error) {
											d.setBusy(false);
											if (error.code === 'checklist_incomplete') {
												d.close();
												focusFirstIncompleteChecklist(error);
												if (ctx.isOffice) {
													openForceCloseDialog();
												} else {
													toast(error.message || tr('Required checklist items are still open. Finish them or ask the office.'), 'error');
												}
												return;
											}
											d.setError(error.message);
										});
									},
								},
							],
						});
					}

					function openAssignDialog() {
						if (!attachUserPicker) {
							toast(tr('Directory search is unavailable. Reload the page and try again.'), 'error');
							return;
						}
						var picker = attachUserPicker({
							label: tr('Technician'),
							placeholder: tr('Start typing a name…'),
							hint: tr('Search and pick the primary technician.'),
						});
						if (wo.primaryUserId) {
							picker.setValue && picker.setValue(wo.primaryUserId);
						}
						var helperUids = Array.isArray(wo.helperUids) ? wo.helperUids.slice() : [];
						var helperLabels = Object.create(null);
						helperUids.forEach(function (uid) {
							helperLabels[uid] = uid;
						});
						var helpersHost = el('div', { class: 'mn-assign-helpers' });
						helpersHost.appendChild(el('p', {
							class: 'mn-assign-helpers__label',
							id: 'mn-assign-helpers-label',
							text: tr('Helpers'),
						}));
						helpersHost.appendChild(el('p', {
							class: 'mn-assign-helpers__hint',
							text: tr('Optional — people who help on this job.'),
						}));
						var helpersChips = el('ul', {
							class: 'mn-chips',
							'aria-labelledby': 'mn-assign-helpers-label',
						});
						var helperPicker = attachUserPicker({
							label: tr('Add helper'),
							placeholder: tr('Start typing a name…'),
							hint: null,
						});
						var chipRemoveIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="mn-icon mn-icon--sm" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>';

						function resolveHelperLabel(uid) {
							if (!uid || !getCtx || !getCtx()) {
								return;
							}
							var c = getCtx();
							if (!c.urls || !c.urls.api || !c.urls.api.usersSearch) {
								return;
							}
							api('GET', withQuery(apiUrl('usersSearch'), { q: uid, limit: '10' }))
								.then(function (payload) {
									var rowsFound = payload.data || [];
									var match = null;
									rowsFound.forEach(function (row) {
										if (row && row.id === uid) {
											match = row;
										}
									});
									if (!match) {
										return;
									}
									var dn = match.displayName || match.id;
									helperLabels[uid] = dn + (match.id && dn !== match.id ? ' (' + match.id + ')' : '');
									renderHelperChips();
								})
								.catch(function () { /* best-effort */ });
						}

						function renderHelperChips() {
							clear(helpersChips);
							if (helperUids.length === 0) {
								helpersChips.classList.add('mn-chips--empty');
								helpersChips.appendChild(el('li', {
									class: 'mn-chips__empty',
									text: tr('No helpers yet.'),
								}));
								return;
							}
							helpersChips.classList.remove('mn-chips--empty');
							helperUids.forEach(function (uid) {
								var label = helperLabels[uid] || uid;
								helpersChips.appendChild(el('li', { class: 'mn-chip' }, [
									el('span', { text: label }),
									el('button', {
										type: 'button',
										class: 'mn-chip__remove',
										'aria-label': tr('Remove {id}', { id: label }),
										html: chipRemoveIcon,
										onClick: function () {
											helperUids = helperUids.filter(function (x) { return x !== uid; });
											delete helperLabels[uid];
											renderHelperChips();
										},
									}),
								]));
							});
						}
						helperPicker.root.addEventListener('mn-user-selected', function (ev) {
							var uid = (ev.detail && ev.detail.uid) || (helperPicker.getValue && helperPicker.getValue());
							if (!uid) {
								return;
							}
							var primary = picker.getValue ? picker.getValue() : '';
							if (primary && uid === primary) {
								helperPicker.setValue && helperPicker.setValue('');
								toast(tr('That person is already the technician.'), 'error');
								return;
							}
							if (helperUids.indexOf(uid) !== -1) {
								helperPicker.setValue && helperPicker.setValue('');
								return;
							}
							helperLabels[uid] = (ev.detail && (ev.detail.label || ev.detail.displayName)) || uid;
							helperUids.push(uid);
							helperPicker.setValue && helperPicker.setValue('');
							renderHelperChips();
						});
						helperUids.forEach(resolveHelperLabel);
						renderHelperChips();
						helpersHost.appendChild(helperPicker.root);
						helpersHost.appendChild(helpersChips);
						openDialog({
							title: tr('Assign technician'),
							content: el('div', { class: 'mn-form-grid mn-form-grid--single' }, [
								picker.root,
								helpersHost,
							]),
							initialFocus: 'input[type="search"]',
							actions: [
								{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
								{
									label: tr('Save assignment'),
									variant: 'mn-btn--primary',
									onClick: function (d) {
										d.setBusy(true);
										d.setError(null);
										var uid = picker.getValue ? picker.getValue() : '';
										if (!uid) {
											d.setBusy(false);
											d.setError(tr('Pick a technician first.'));
											return;
										}
										var assignBody = {
											primaryUserId: uid || null,
											helperUids: helperUids.filter(function (h) { return h !== uid; }),
										};
										function doAssign(force) {
											var body = Object.assign({}, assignBody);
											if (force) {
												body.force = true;
											}
											return api('PUT', apiUrl('workOrders') + '/' + wo.id + '/assign', body);
										}
										doAssign(false).then(function () {
											d.close();
											toast(tr('Status updated.'), 'success');
											load();
										}).catch(function (error) {
											if (error.code === 'skills_warning' || error.code === 'capacity_warning') {
												d.setBusy(false);
												openDialog({
													title: tr('Confirm assignment'),
													content: el('p', { text: error.message || tr('Confirm to assign anyway.') }),
													actions: [
														{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d2) { d2.close(); } },
														{
															label: tr('Assign anyway'),
															variant: 'mn-btn--primary',
															onClick: function (d2) {
																d2.setBusy(true);
																doAssign(true).then(function () {
																	d.close();
																	d2.close();
																	toast(tr('Status updated.'), 'success');
																	load();
																}).catch(function (err2) {
																	d2.setBusy(false);
																	d2.setError(err2.message);
																});
															},
														},
													],
												});
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

					var actions = el('div', { class: 'mn-wo-actions mn-wo-hero__actions', role: 'group', 'aria-label': tr('Change status') });
					var primaryTo = null;
					if (next.indexOf('done') !== -1) {
						primaryTo = 'done';
					} else if (next.indexOf('in_progress') !== -1) {
						primaryTo = 'in_progress';
					} else if (next.indexOf('ready') !== -1) {
						primaryTo = 'ready';
					} else if (next.length) {
						primaryTo = next[0];
					}
					if (primaryTo) {
						var primaryBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--primary button mn-wo-hero__primary',
							text: transitionLabel(primaryTo),
						});
						primaryBtn.addEventListener('click', function () { runTransition(primaryTo); });
						actions.appendChild(primaryBtn);
					}
					var overflowItems = [];
					next.forEach(function (to) {
						if (to === primaryTo) {
							return;
						}
						overflowItems.push({
							label: transitionLabel(to),
							onClick: function () { runTransition(to); },
							danger: to === 'cancelled',
						});
					});
					if (wo.logHoursUrl) {
						overflowItems.push({ label: tr('Log hours'), href: wo.logHoursUrl });
					}
					if (wo.recordTimeUrl) {
						overflowItems.push({ label: tr('Record time'), href: wo.recordTimeUrl });
					}
					if (wo.status !== 'done' && wo.status !== 'cancelled') {
						overflowItems.push({
							label: tr('Download job pack'),
							href: apiUrl('workOrders') + '/' + wo.id + '/pdf/job-pack',
						});
					}
					if (wo.status === 'done') {
						overflowItems.push({
							label: tr('Download service report'),
							href: apiUrl('workOrders') + '/' + wo.id + '/pdf/servicebericht',
						});
						if (wo.kind === 'inspection') {
							overflowItems.push({
								label: tr('Download inspection evidence'),
								href: apiUrl('workOrders') + '/' + wo.id + '/pdf/inspection-evidence',
							});
						}
					}
					if (ctx.isOffice && wo.status !== 'done' && wo.status !== 'cancelled') {
						overflowItems.push({
							label: tr('Assign technician'),
							onClick: openAssignDialog,
						});
					}
					var overflowMenu = dom.visitOverflowMenu ? dom.visitOverflowMenu(overflowItems) : null;
					if (overflowMenu) {
						actions.appendChild(overflowMenu);
					}

					var sheet = el('article', {
						class: 'mn-card mn-wo-sheet',
						'aria-labelledby': 'mn-wo-hero-title',
					});

					var heroTop = el('div', { class: 'mn-wo-hero__top' }, [
						el('p', { class: 'mn-wo-hero__number', text: wo.number || ('#' + wo.id) }),
						statusBadge(meta.label, meta.badge, meta.icon),
					]);
					var whereLine = [wo.customerName, wo.equipmentLabel].filter(Boolean).join(' · ');
					var heroHead = el('header', { class: 'mn-wo-hero__head' }, [
						heroTop,
						el('h2', {
							id: 'mn-wo-hero-title',
							class: 'mn-wo-hero__title',
							text: wo.title || wo.number || tr('Work order'),
						}),
						whereLine ? el('p', { class: 'mn-wo-hero__where', text: whereLine }) : null,
						el('div', { class: 'mn-wo-detail__meta' }, [
							wo.dueOn ? el('span', { class: 'mn-wo-hero__chip', text: tr('Due {date}', { date: fmt(wo.dueOn) }) }) : null,
							wo.primaryUserId ? el('span', { class: 'mn-wo-hero__chip', text: tr('Tech') + ': ' + wo.primaryUserId }) : null,
						]),
					]);
					var heroKids = [heroHead];
					if (wo.inventorySync === 'failed' || wo.inventorySync === 'ok' || wo.inventorySync === 'disabled') {
						var syncLabel = inventorySyncLabel(wo.inventorySync, wo.inventorySyncCode);
						heroKids.push(el('div', {
							class: 'mn-callout mn-callout--' + (wo.inventorySync === 'failed' ? 'warning' : 'info'),
							role: wo.inventorySync === 'failed' ? 'alert' : 'status',
						}, [
							el('strong', { text: tr('Stock sync') + ': ' }),
							el('span', { text: syncLabel }),
						]));
					}
					if (wo.warrantyExpired) {
						heroKids.push(el('div', {
							class: 'mn-callout mn-callout--warning',
							role: 'status',
						}, [
							el('strong', { text: tr('Warranty ended') + ': ' }),
							el('span', { text: tr('This equipment’s warranty ended on {date}.', { date: fmt(wo.warrantyEnd) }) }),
						]));
					}
					if (wo.requesterName || wo.symptom || wo.accessNotes || wo.preferredWindow) {
						var intakeRows = [];
						if (wo.requesterName) {
							intakeRows.push(el('div', { class: 'mn-wo-intake__row' }, [
								el('dt', { text: tr('Requester') }),
								el('dd', { text: wo.requesterName + (wo.requesterPhone ? ' · ' + wo.requesterPhone : '') }),
							]));
						}
						if (wo.symptom) {
							intakeRows.push(el('div', { class: 'mn-wo-intake__row' }, [
								el('dt', { text: tr('Symptom') }),
								el('dd', { text: wo.symptom }),
							]));
						}
						if (wo.accessNotes) {
							intakeRows.push(el('div', { class: 'mn-wo-intake__row' }, [
								el('dt', { text: tr('Access') }),
								el('dd', { text: wo.accessNotes }),
							]));
						}
						if (wo.preferredWindow) {
							intakeRows.push(el('div', { class: 'mn-wo-intake__row' }, [
								el('dt', { text: tr('Preferred window') }),
								el('dd', { text: wo.preferredWindow }),
							]));
						}
						heroKids.push(el('dl', {
							class: 'mn-wo-intake',
							'aria-label': tr('Request intake'),
						}, intakeRows));
					}
					if (next.length || overflowItems.length) {
						heroKids.push(actions);
					}
					sheet.appendChild(el('section', {
						class: 'mn-wo-hero',
						'aria-labelledby': 'mn-wo-hero-title',
					}, heroKids));

					// Checklist — the one job that matters on site
					var items = wo.checklist || [];
					var checklistBody;
					if (!working) {
						checklistBody = el('p', {
							class: 'mn-wo-checklist__idle',
							role: 'status',
							text: terminal
								? tr('Checklist is locked for this status.')
								: tr('Start work to fill in the checklist.'),
						});
					} else if (!items.length) {
						checklistBody = el('p', { class: 'mn-wo-checklist__idle', role: 'status', text: tr('No checklist items on this job.') });
					} else {
						checklistBody = el('ul', { class: 'mn-checklist', role: 'list' });
						var focusCode = window.__mnPendingRevealCode || null;
						var focusNode = null;
						if (window.__mnPendingRevealAnnounce) {
							announce(window.__mnPendingRevealAnnounce, false);
							window.__mnPendingRevealAnnounce = null;
						}
						window.__mnPendingRevealCode = null;
						items.forEach(function (item) {
							if (item.visible === false) {
								return;
							}
							var itemCode = item.code || item.itemCode;
							var rowActions = el('div', { class: 'mn-checklist__actions', role: 'group', 'aria-label': item.label || itemCode });
							['ok', 'fail', 'na'].forEach(function (result) {
								var label = result === 'ok' ? tr('OK') : (result === 'fail' ? tr('Fail') : tr('N/A'));
								var active = item.result === result;
								var btn = el('button', {
									type: 'button',
									class: 'mn-btn mn-btn--compact button' + (active ? ' mn-btn--primary' : ' mn-btn--secondary'),
									text: label,
									'aria-pressed': active ? 'true' : 'false',
								});
								btn.addEventListener('click', function () {
									function putResult(payload) {
										var beforeVisible = {};
										(wo.checklist || []).forEach(function (it) {
											var code = it.code || it.itemCode;
											if (it.visible !== false && code) {
												beforeVisible[code] = true;
											}
										});
										return api('PUT', apiUrl('workOrders') + '/' + wo.id + '/checklist/' + encodeURIComponent(itemCode), payload)
											.then(function (detail) {
												var revealed = null;
												var list = (detail && detail.items) || (detail && detail.checklist) || [];
												list.forEach(function (it) {
													var code = it.code || it.itemCode;
													if (it.visible !== false && code && !beforeVisible[code] && !revealed) {
														revealed = code;
													}
												});
												if (revealed) {
													window.__mnPendingRevealCode = revealed;
													window.__mnPendingRevealAnnounce = tr('Additional step required');
												}
												return load();
											})
											.catch(function (error) { toast(error.message, 'error'); });
									}
									if (result === 'na' && item.required) {
										var noteInput = el('textarea', {
											class: 'mn-input form-textarea',
											rows: '3',
											'aria-required': 'true',
										});
										if (item.note) {
											noteInput.value = item.note;
										}
										openDialog({
											title: tr('N/A note required'),
											content: el('div', { class: 'mn-form-grid' }, [
												el('p', { text: tr('Required items marked N/A need a short note.') }),
												field(tr('Note'), noteInput, { required: true }),
											]),
											actions: [
												{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
												{
													label: tr('Save'),
													variant: 'mn-btn--primary',
													onClick: function (d) {
														var note = String(noteInput.value || '').trim();
														if (!note) {
															d.setError(tr('A note is required for N/A.'));
															return;
														}
														d.setBusy(true);
														putResult({ result: 'na', note: note }).then(function () { d.close(); });
													},
												},
											],
										});
										return;
									}
									putResult({ result: result });
								});
								rowActions.appendChild(btn);
							});
							var li = el('li', {
								class: 'mn-checklist__item',
								'data-item-code': itemCode || '',
								tabIndex: focusCode && itemCode === focusCode ? -1 : null,
							}, [
								el('p', { class: 'mn-checklist__label', text: item.label || itemCode }),
								item.note ? el('p', { class: 'mn-muted', text: item.note }) : null,
								rowActions,
							]);
							if (focusCode && itemCode === focusCode) {
								focusNode = li;
							}
							checklistBody.appendChild(li);
						});
						if (focusNode) {
							window.setTimeout(function () { focusNode.focus(); }, 30);
						}
					}
					var workBodyKids = [checklistBody];
					var showEvidence = working || wo.status === 'done' || photos.length > 0 || hasSignature;
					if (showEvidence && wo.status !== 'cancelled') {
						var photoList = el('div', { class: 'mn-photo-list' });
						photos.forEach(function (photo) {
							photoList.appendChild(el('a', {
								class: 'mn-photo-chip',
								href: apiUrl('workOrders') + '/' + wo.id + '/photos/' + photo.id,
								target: '_blank',
								rel: 'noopener noreferrer',
								text: photo.originalName || photo.name || (tr('Photo') + ' ' + photo.id),
							}));
						});
						var fileInput = el('input', { type: 'file', accept: 'image/*', capture: 'environment', class: 'mn-input mn-sr-only', id: 'mn-wo-photo-input' });
						fileInput.setAttribute('aria-label', tr('Add photo'));
						var addBtn = el('button', { type: 'button', class: 'mn-btn mn-btn--secondary button', text: tr('Add photo') });
						function uploadSelected() {
							if (!fileInput.files || !fileInput.files[0]) {
								fileInput.click();
								return;
							}
							var fd = new FormData();
							fd.append('photo', fileInput.files[0]);
							addBtn.disabled = true;
							apiUpload(apiUrl('workOrders') + '/' + wo.id + '/photos', fd)
								.then(function () { toast(tr('Photo added.'), 'success'); load(); })
								.catch(function (error) { toast(error.message, 'error'); })
								.finally(function () { addBtn.disabled = false; fileInput.value = ''; });
						}
						addBtn.addEventListener('click', uploadSelected);
						fileInput.addEventListener('change', function () {
							if (fileInput.files && fileInput.files[0]) {
								uploadSelected();
							}
						});
						var evidenceWrap = el('div', {
							class: 'mn-wo-evidence',
							'aria-labelledby': 'mn-wo-evidence-title',
						}, [
							el('h2', { id: 'mn-wo-evidence-title', class: 'mn-wo-evidence__title', text: tr('Evidence') }),
							el('h3', { class: 'mn-wo-evidence__sub', text: tr('Photos') }),
							photos.length ? photoList : el('p', { class: 'mn-muted', role: 'status', text: tr('No photos yet.') }),
							el('div', { class: 'mn-wo-photo-actions' }, [fileInput, addBtn]),
						]);
						var sigPad = makeSignaturePad();
						var signerInput = el('input', {
							type: 'text',
							class: 'mn-input form-input',
							autocomplete: 'name',
							value: (wo.signature && wo.signature.signerName) || '',
						});
						var saveSigBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--primary button',
							text: tr('Save signature'),
						});
						var clearSigBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--secondary button',
							text: tr('Clear signature'),
						});
						clearSigBtn.addEventListener('click', function () { sigPad.clear(); });
						saveSigBtn.addEventListener('click', function () {
							if (sigPad.isEmpty()) {
								toast(tr('Draw a signature first.'), 'error');
								return;
							}
							saveSigBtn.disabled = true;
							api('POST', apiUrl('workOrders') + '/' + wo.id + '/signature', {
								imageBase64: sigPad.toDataURL(),
								signerName: String(signerInput.value || '').trim() || null,
							}).then(function () {
								toast(tr('Signature saved.'), 'success');
								load();
							}).catch(function (error) {
								toast(error.message, 'error');
							}).finally(function () {
								saveSigBtn.disabled = false;
							});
						});
						var sigKids = [
							el('h3', { class: 'mn-wo-evidence__sub', text: tr('Signature') }),
						];
						if (hasSignature) {
							sigKids.push(el('p', {
								class: 'mn-muted',
								role: 'status',
								text: tr('A signature is already on file. Saving replaces it.'),
							}));
						}
						sigKids.push(field(tr('Signer name'), signerInput));
						sigKids.push(sigPad.canvas);
						sigKids.push(el('div', { class: 'mn-wo-actions' }, [clearSigBtn, saveSigBtn]));
						evidenceWrap.appendChild(el('div', { class: 'mn-wo-evidence__signature' }, sigKids));
						workBodyKids.push(evidenceWrap);
					}
					sheet.appendChild(el('section', {
						class: 'mn-wo-checklist' + (working ? ' mn-wo-checklist--live' : ' mn-wo-checklist--idle'),
						'aria-labelledby': 'mn-wo-checklist-title',
					}, [
						el('h2', { id: 'mn-wo-checklist-title', class: 'mn-wo-band__title', text: tr('Checklist') }),
						el('div', { class: 'mn-wo-checklist__body' }, workBodyKids),
					]));

					// More — office / secondary surfaces (closed unless attention needed)
					var moreBody = el('div', { class: 'mn-wo-more__body' });

					if (wo.status === 'draft' || wo.status === 'planned') {
						var procBody = el('div', { class: 'mn-wo-procedure' });
						var procStatus = el('p', {
							class: 'mn-muted',
							role: 'status',
							text: tr('Loading…'),
						});
						procBody.appendChild(procStatus);
						loadProcedures().then(function (procedures) {
							procStatus.textContent = procedureLabel(
								procedures,
								wo.procedureId,
								wo.procedureSkipped,
								wo.procedureSkipReason
							);
						}).catch(function () {
							procStatus.textContent = procedureLabel(
								[],
								wo.procedureId,
								wo.procedureSkipped,
								wo.procedureSkipReason
							);
						});
						var attachProcBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--secondary button',
							text: tr('Attach procedure'),
						});
						attachProcBtn.addEventListener('click', function () {
							openProcedureChoiceDialog({
								title: tr('Attach procedure'),
								confirmLabel: tr('Attach procedure'),
								selectedId: wo.procedureId,
								onConfirm: function (procBodyPayload, d) {
									return api('PUT', apiUrl('workOrders') + '/' + wo.id, procBodyPayload).then(function () {
										d.close();
										toast(tr('Status updated.'), 'success');
										load();
									});
								},
							});
						});
						procBody.appendChild(attachProcBtn);
						moreBody.appendChild(el('section', {
							class: 'mn-wo-more__block',
							'aria-labelledby': 'mn-wo-procedure-title',
						}, [
							el('h3', { id: 'mn-wo-procedure-title', class: 'mn-wo-more__heading', text: tr('Procedure') }),
							procBody,
						]));
					}

					if (ctx.isOffice && !terminal) {
						var skillsBody = required.length
							? tableOrCards([
								{
									id: 'skill',
									label: tr('Skill'),
									render: function (sk) {
										return tableStack(sk.name || sk.code || ('#' + sk.id), sk.code && sk.name ? sk.code : null);
									},
								},
							], required, { caption: tr('Required skills') })
							: el('p', { class: 'mn-muted', text: tr('No required skills yet.') });
						var editSkillsBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--secondary button',
							text: tr('Edit required skills'),
						});
						editSkillsBtn.addEventListener('click', function () {
							api('GET', apiUrl('skills')).then(function (envelope) {
								var catalog = (envelope && envelope.data) || [];
								var selected = {};
								(required || []).forEach(function (sk) { selected[String(sk.id)] = true; });
								var host = el('div', { class: 'mn-form-grid', role: 'group', 'aria-label': tr('Required skills') });
								var checks = [];
								catalog.forEach(function (skill) {
									if (skill.active === false) {
										return;
									}
									var cb = el('input', {
										type: 'checkbox',
										checked: selected[String(skill.id)] ? 'checked' : null,
									});
									checks.push({ id: skill.id, input: cb });
									host.appendChild(field(skill.name || skill.code, cb));
								});
								openDialog({
									title: tr('Required skills'),
									content: host,
									actions: [
										{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
										{
											label: tr('Save'),
											variant: 'mn-btn--primary',
											onClick: function (d) {
												var skillIds = checks.filter(function (row) { return row.input.checked; }).map(function (row) { return Number(row.id); });
												d.setBusy(true);
												api('PUT', apiUrl('workOrders') + '/' + wo.id + '/skills', { skillIds: skillIds })
													.then(function () {
														d.close();
														toast(tr('Status updated.'), 'success');
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
							}).catch(handleGlobalError);
						});
						moreBody.appendChild(el('section', {
							class: 'mn-wo-more__block' + (required.length ? ' mn-wo-more__block--table' : ''),
							'aria-labelledby': 'mn-wo-skills-title',
						}, [
							el('h2', { id: 'mn-wo-skills-title', class: 'mn-wo-more__heading', text: tr('Required skills') }),
							skillsBody,
							editSkillsBtn,
						]));
					}

					var kitBody;
					if (!kit) {
						kitBody = el('div', {}, [
							el('p', { class: 'mn-muted', role: 'status', text: tr('No kit attached.') }),
						]);
						if (ctx.isOffice && !terminal) {
							var attachBtn = el('button', {
								type: 'button',
								class: 'mn-btn mn-btn--secondary button',
								text: tr('Attach kit'),
							});
							attachBtn.addEventListener('click', function () {
								api('GET', apiUrl('kitTemplates')).then(function (envelope) {
									var templates = (envelope && envelope.data) ? envelope.data : [];
									if (!templates.length) {
										toast(tr('No kit attached.'), 'error');
										return;
									}
									var select = el('select', { class: 'mn-input form-select' });
									templates.forEach(function (tpl) {
										select.appendChild(el('option', {
											value: String(tpl.id),
											text: tpl.name || ('#' + tpl.id),
										}));
									});
									openDialog({
										title: tr('Attach kit'),
										content: el('div', { class: 'mn-form-grid' }, [
											field(tr('Kit templates'), select, { required: true }),
										]),
										actions: [
											{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
											{
												label: tr('Attach kit'),
												variant: 'mn-btn--primary',
												onClick: function (d) {
													d.setBusy(true);
													api('POST', apiUrl('workOrders') + '/' + wo.id + '/kit', {
														templateId: Number(select.value),
													}).then(function () {
														d.close();
														toast(tr('Status updated.'), 'success');
														load();
													}).catch(function (error) {
														d.setBusy(false);
														d.setError(error.message);
													});
												},
											},
										],
									});
								}).catch(function (error) {
									toast(error.message, 'error');
								});
							});
							kitBody.appendChild(attachBtn);
						}
					} else {
						kitBody = kitLines.length
							? tableOrCards([
								{
									id: 'part',
									label: tr('Part'),
									render: function (line) {
										return tableStack(
											line.label || line.sku || tr('Part'),
											line.sku && line.label ? line.sku : null
										);
									},
								},
								{
									id: 'packed',
									label: tr('Packed'),
									render: function (line) {
										return tr('Packed {packed} of {required}', {
											packed: Number(line.qtyPacked || 0),
											required: Number(line.qtyRequired || 0),
										});
									},
								},
								{
									id: 'actions',
									label: tr('Actions'),
									actions: true,
									render: function (line) {
										var packed = Number(line.qtyPacked || 0);
										var requiredQty = Number(line.qtyRequired || 0);
										if (!(ctx.isOffice && packed < requiredQty && !terminal)) {
											return tableCellText(null);
										}
										return el('button', {
											type: 'button',
											class: 'mn-btn mn-btn--compact mn-btn--primary button',
											text: tr('Pack'),
											onClick: function () {
												api('POST', apiUrl('workOrders') + '/' + wo.id + '/kit/lines/' + line.id + '/pack', {
													qtyPacked: requiredQty,
												}).then(function () {
													toast(tr('Status updated.'), 'success');
													load();
												}).catch(function (error) {
													toast(error.message, 'error');
												});
											},
										});
									},
								},
							], kitLines, { caption: tr('Kit / parts') })
							: el('p', { class: 'mn-muted', role: 'status', text: tr('No kit lines yet.') });
					}
					moreBody.appendChild(el('section', {
						class: 'mn-wo-more__block' + (kit && kitLines.length ? ' mn-wo-more__block--table' : ''),
						'aria-labelledby': 'mn-wo-kit-title',
					}, [
						el('h3', { id: 'mn-wo-kit-title', class: 'mn-wo-more__heading', text: tr('Kit / parts') }),
						kitBody,
					]));

					var commentsBox = el('section', {
						class: 'mn-wo-more__block',
						'aria-labelledby': 'mn-wo-comments-title',
					}, [
						el('h3', { id: 'mn-wo-comments-title', class: 'mn-wo-more__heading', text: tr('Comments') }),
					]);
					var commentsList = el('div', { class: 'mn-wo-comments', 'aria-live': 'polite' });
					var commentInput = el('textarea', { class: 'mn-input', rows: '2', 'aria-label': tr('New comment') });
					var postBtn = el('button', {
						type: 'button',
						class: 'mn-btn mn-btn--secondary mn-btn--compact',
						text: tr('Post comment'),
						onClick: function () {
							var text = (commentInput.value || '').trim();
							if (text.length < 1) {
								return;
							}
							postBtn.disabled = true;
							api('POST', apiUrl('workOrders') + '/' + wo.id + '/comments', { body: text }).then(function () {
								commentInput.value = '';
								toast(tr('Comment saved.'), 'success');
								refreshComments();
							}).catch(function (error) {
								handleGlobalError(error);
							}).finally(function () { postBtn.disabled = false; });
						},
					});
					commentsBox.appendChild(commentsList);
					commentsBox.appendChild(el('div', { class: 'mn-form-grid' }, [
						field(tr('Add a comment'), commentInput, { wide: true, hint: tr('Append-only — comments cannot be edited or deleted.') }),
						postBtn,
					]));
					moreBody.appendChild(commentsBox);
					function refreshComments() {
						api('GET', apiUrl('workOrders') + '/' + wo.id + '/comments').then(function (envelope) {
							clear(commentsList);
							var rows = (envelope && envelope.data) || [];
							if (!rows.length) {
								commentsList.appendChild(el('p', { class: 'mn-muted', text: tr('No comments yet.') }));
								return;
							}
							rows.slice().sort(function (a, b) {
								return (a.createdAt || 0) - (b.createdAt || 0);
							}).forEach(function (row) {
								commentsList.appendChild(el('article', { class: 'mn-comment' }, [
									el('p', { class: 'mn-comment__meta mn-muted', text: (row.createdBy || '') + (row.createdAt ? ' · ' + new Date(row.createdAt * 1000).toLocaleString() : '') }),
									el('p', { class: 'mn-comment__body', text: row.body || '' }),
								]));
							});
						}).catch(function () {
							clear(commentsList);
							commentsList.appendChild(el('p', { class: 'mn-muted', text: tr('Could not load comments.') }));
						});
					}
					refreshComments();

					var more = el('details', {
						class: 'mn-wo-more',
						open: openMore ? true : null,
					});
					more.appendChild(el('summary', {
						class: 'mn-wo-more__summary',
						text: openMore ? tr('Needs setup') : tr('Details'),
					}));
					more.appendChild(moreBody);
					sheet.appendChild(more);
					root.appendChild(sheet);
				}


				load();
			}

			function enableDispatchKeyboard(board) {
				var hint = el('p', {
					class: 'mn-sr-only mn-dispatch-hint',
					text: tr('Use arrow keys to move between jobs, Enter to open.'),
				});
				if (board.firstChild) {
					board.insertBefore(hint, board.firstChild);
				} else {
					board.appendChild(hint);
				}
				var links = Array.prototype.slice.call(board.querySelectorAll('a.mn-dispatch-job'));
				if (!links.length) {
					return;
				}
				var index = 0;
				function setActive(i) {
					index = (i + links.length) % links.length;
					links.forEach(function (link, li) {
						link.tabIndex = li === index ? 0 : -1;
					});
					links[index].focus();
				}
				links.forEach(function (link, li) {
					link.tabIndex = li === 0 ? 0 : -1;
					link.addEventListener('keydown', function (ev) {
						if (ev.key === 'ArrowDown' || ev.key === 'ArrowRight') {
							ev.preventDefault();
							setActive(index + 1);
						} else if (ev.key === 'ArrowUp' || ev.key === 'ArrowLeft') {
							ev.preventDefault();
							setActive(index - 1);
						} else if (ev.key === 'Home') {
							ev.preventDefault();
							setActive(0);
						} else if (ev.key === 'End') {
							ev.preventDefault();
							setActive(links.length - 1);
						}
					});
					link.addEventListener('focus', function () {
						index = links.indexOf(link);
						links.forEach(function (l, li2) { l.tabIndex = li2 === index ? 0 : -1; });
					});
				});
			}

			function pageDispatch() {
				var board = document.getElementById('mn-dispatch-board');
				if (!board) {
					return;
				}
				var ctx = getCtx();
				var filter = 'unassigned';
				var loadSeq = 0;
				var cached = null;

				function setChipState(active) {
					document.querySelectorAll('[data-mn-dispatch-filter]').forEach(function (b) {
						var on = (b.getAttribute('data-mn-dispatch-filter') || 'unassigned') === active;
						b.classList.toggle('is-active', on);
						b.setAttribute('aria-pressed', on ? 'true' : 'false');
					});
				}

				function ownerLabel(wo) {
					if (!wo.primaryUserId) {
						return null;
					}
					return wo.primaryUserDisplayName || wo.primaryUserId;
				}

				function flattenJobs(data, onlyUnassigned) {
					var out = [];
					((data && data.days) || []).forEach(function (day) {
						var dayJobs = [];
						(day.lanes || []).forEach(function (lane) {
							var cap = lane.capacity || null;
							(lane.workOrders || []).forEach(function (wo) {
								if (onlyUnassigned && wo.primaryUserId) {
									return;
								}
								dayJobs.push(Object.assign({}, wo, {
									_capacity: lane.uid === 'unassigned' ? null : cap,
									_laneDisplayName: lane.displayName || null,
								}));
							});
						});
						if (dayJobs.length) {
							out.push({ kind: 'day', date: day.date, jobs: dayJobs });
						}
					});
					var noDue = ((data && data.noDueDate) || []).filter(function (wo) {
						return !onlyUnassigned || !wo.primaryUserId;
					});
					if (noDue.length) {
						out.push({ kind: 'noDue', jobs: noDue });
					}
					return out;
				}

				function openDispatchAssign(wo, onDone) {
					if (!attachUserPicker) {
						toast(tr('Directory search is unavailable. Reload the page and try again.'), 'error');
						return;
					}
					var picker = attachUserPicker({
						label: tr('Technician'),
						placeholder: tr('Start typing a name…'),
						hint: tr('Search and pick a technician. Never type a raw user id.'),
					});
					if (wo.primaryUserId && picker.setValue) {
						picker.setValue(wo.primaryUserId);
					}
					openDialog({
						title: tr('Assign technician'),
						content: el('div', { class: 'mn-form-grid mn-form-grid--single' }, [picker.root]),
						initialFocus: 'input[type="search"]',
						actions: [
							{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
							{
								label: tr('Save assignment'),
								variant: 'mn-btn--primary',
								onClick: function (d) {
									var uid = picker.getValue ? picker.getValue() : '';
									if (!uid) {
										d.setError(tr('Pick a technician first.'));
										return;
									}
									d.setBusy(true);
									d.setError(null);
									function doAssign(force) {
										var body = { primaryUserId: uid };
										if (force) {
											body.force = true;
										}
										return api('PUT', apiUrl('workOrders') + '/' + wo.id + '/assign', body);
									}
									doAssign(false).then(function () {
										d.close();
										toast(tr('Job assigned.'), 'success');
										announce(tr('Job assigned.'), false);
										if (onDone) {
											onDone();
										}
									}).catch(function (error) {
										if (error.code === 'skills_warning' || error.code === 'capacity_warning') {
											d.setBusy(false);
											openDialog({
												title: tr('Confirm assignment'),
												content: el('p', { text: error.message || tr('Confirm to assign anyway.') }),
												actions: [
													{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d2) { d2.close(); } },
													{
														label: tr('Assign anyway'),
														variant: 'mn-btn--primary',
														onClick: function (d2) {
															d2.setBusy(true);
															doAssign(true).then(function () {
																d.close();
																d2.close();
																toast(tr('Job assigned.'), 'success');
																announce(tr('Job assigned.'), false);
																if (onDone) {
																	onDone();
																}
															}).catch(function (err2) {
																d2.setBusy(false);
																d2.setError(err2.message);
															});
														},
													},
												],
											});
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

				function ownerCell(wo) {
					var name = ownerLabel(wo);
					if (!name) {
						return statusBadge(tr('Unassigned'), 'mn-badge--warning');
					}
					var kids = [el('span', { class: 'mn-dispatch-owner', text: name })];
					var cap = wo._capacity;
					if (cap) {
						var flags = [];
						if (cap.onDuty === true) {
							flags.push(statusBadge(tr('On duty'), 'mn-badge--success'));
						} else if (cap.onDuty === false) {
							flags.push(statusBadge(tr('Off duty'), 'mn-badge--neutral'));
						}
						if (cap.exceeds) {
							flags.push(statusBadge(tr('Over capacity'), 'mn-badge--danger'));
						}
						if (flags.length) {
							kids.push(el('div', { class: 'mn-dispatch-owner__flags' }, flags));
						}
					}
					return el('div', { class: 'mn-dispatch-owner-cell' }, kids);
				}

				function jobTable(jobs, caption) {
					var cols = [
						{
							id: 'job',
							label: tr('Work order'),
							render: function (wo) {
								return tableStack(
									tableLink(
										pageUrl('workOrders') + '/' + wo.id,
										(wo.number || '') + ' — ' + (wo.title || ''),
										'mn-dispatch-job'
									),
									[wo.customerName, wo.equipmentLabel].filter(Boolean).join(' · ') || null
								);
							},
						},
						{
							id: 'owner',
							label: tr('Owner'),
							render: function (wo) {
								return ownerCell(wo);
							},
						},
						{
							id: 'actions',
							label: tr('Actions'),
							actions: true,
							render: function (wo) {
								if (!(ctx && ctx.isOffice)) {
									return [
										el('a', {
											class: 'mn-btn mn-btn--secondary button',
											href: pageUrl('workOrders') + '/' + wo.id,
											text: tr('Open'),
										}),
									];
								}
								var assignBtn = el('button', {
									type: 'button',
									class: 'mn-btn mn-btn--primary button',
									text: wo.primaryUserId ? tr('Reassign') : tr('Assign'),
								});
								assignBtn.addEventListener('click', function () {
									openDispatchAssign(wo, load);
								});
								return [assignBtn];
							},
						},
					];
					return tableOrCards(cols, jobs, { caption: caption, tableClass: 'mn-dispatch-table' });
				}

				function render(data) {
					clear(board);
					var sections = flattenJobs(data, filter === 'unassigned');
					if (!sections.length) {
						if (filter === 'unassigned') {
							var allSections = flattenJobs(data, false);
							board.appendChild(emptyState(
								tr('Nothing needs an owner'),
								allSections.length
									? tr('Every open job already has a technician — switch to All open to review.')
									: tr('Open work orders will appear here for assignment.')
							));
						} else {
							board.appendChild(emptyState(
								tr('Nothing to dispatch'),
								tr('Open work orders will appear here for assignment.')
							));
						}
						return;
					}
					sections.forEach(function (section) {
						var title = section.kind === 'noDue'
							? tr('No due date') + ' (' + section.jobs.length + ')'
							: fmt(section.date) + ' (' + section.jobs.length + ')';
						var titleId = 'mn-dispatch-bucket-' + (section.kind === 'noDue' ? 'nodue' : String(section.date));
						var bucketClass = 'mn-bucket' + (section.kind === 'noDue' ? ' mn-bucket--later' : ' mn-bucket--today');
						if (section.kind === 'day' && section.date < todayYmd()) {
							bucketClass = 'mn-bucket mn-bucket--overdue';
						}
						board.appendChild(el('section', {
							class: bucketClass,
							'aria-labelledby': titleId,
						}, [
							el('h2', { class: 'mn-bucket__title', id: titleId, text: title }),
							el('div', { class: 'mn-bucket__list mn-card__body--table' }, [
								jobTable(section.jobs, title),
							]),
						]));
					});
					enableDispatchKeyboard(board);
				}

				function load() {
					var seq = ++loadSeq;
					board.setAttribute('aria-busy', 'true');
					api('GET', withQuery(apiUrl('dispatch'), { from: todayYmd() })).then(function (data) {
						if (seq !== loadSeq) {
							return;
						}
						cached = data;
						render(data);
					}).catch(function (error) {
						if (seq !== loadSeq) {
							return;
						}
						clear(board);
						handleGlobalError(error);
					}).finally(function () {
						if (seq === loadSeq) {
							board.removeAttribute('aria-busy');
						}
					});
				}

				setChipState(filter);
				document.querySelectorAll('[data-mn-dispatch-filter]').forEach(function (btn) {
					btn.addEventListener('click', function () {
						filter = btn.getAttribute('data-mn-dispatch-filter') || 'unassigned';
						setChipState(filter);
						if (cached) {
							render(cached);
						} else {
							load();
						}
					});
				});
				load();
			}

			function pageTours() {
				var ctx = getCtx();
				var board = document.getElementById('mn-tours-board');
				var toolbarHost = document.getElementById('mn-tours-toolbar');
				if (!board) {
					return;
				}
				var addInterval = (window.MnApp && window.MnApp.addInterval) || function (ymd, unit, count) {
					return ymd;
				};
				var visitOverflowMenu = dom.visitOverflowMenu;
				var date = todayYmd();
				var loadSeq = 0;
				board.setAttribute('aria-busy', 'true');

				function shiftDate(ymd, deltaDays) {
					return addInterval(ymd, 'day', deltaDays);
				}

				function emptyTitle() {
					return date === todayYmd() ? tr('No tours today') : tr('No tours on this day');
				}

				function openCreateTourDialog() {
					if (!attachUserPicker) {
						toast(tr('Directory search is unavailable. Reload the page and try again.'), 'error');
						return;
					}
					var techPicker = attachUserPicker({
						label: tr('Technician'),
						placeholder: tr('Start typing a name…'),
						hint: tr('Search and pick a technician. Never type a raw user id.'),
					});
					openDialog({
						title: tr('Create tour'),
						content: el('div', { class: 'mn-form-grid mn-form-grid--single' }, [
							techPicker.root,
						]),
						initialFocus: 'input[type="search"]',
						actions: [
							{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
							{
								label: tr('Create'),
								variant: 'mn-btn--primary',
								onClick: function (d) {
									var techUid = techPicker.getValue();
									if (!techUid) {
										d.setError(tr('Pick a technician first.'));
										return;
									}
									d.setBusy(true);
									api('POST', apiUrl('tours'), {
										tourDate: date,
										techUid: techUid,
									}).then(function () {
										d.close();
										toast(tr('Tour created.'), 'success');
										announce(tr('Tour created.'), false);
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

				function suggestOrderFlow(tour, stops) {
					api('POST', apiUrl('tours') + '/' + tour.id + '/suggest-order', {})
						.then(function (suggestion) {
							var ids = (suggestion && suggestion.suggestedWorkOrderIds) || [];
							if (!ids.length) {
								toast(tr('No suggested order available.'), 'warning');
								return;
							}
							var labels = ids.map(function (woId, i) {
								var match = stops.find(function (s) {
									return Number(s.workOrderId || (s.workOrder && s.workOrder.id)) === Number(woId);
								});
								var rowWo = (match && match.workOrder) || {};
								return String(i + 1) + '. ' + (rowWo.number || ('#' + woId)) + ' — ' + (rowWo.title || tr('Work order'));
							});
							openDialog({
								title: tr('Suggested order — verify drive times'),
								content: el('div', { class: 'mn-form-grid' }, [
									el('p', { text: tr('Review the suggested stop order, then apply it.') }),
									el('ol', {}, labels.map(function (line) {
										return el('li', { text: line });
									})),
								]),
								actions: [
									{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
									{
										label: tr('Apply suggested order'),
										variant: 'mn-btn--primary',
										onClick: function (d) {
											d.setBusy(true);
											api('PUT', apiUrl('tours') + '/' + tour.id + '/reorder', {
												workOrderIds: ids.map(Number),
											}).then(function () {
												d.close();
												toast(tr('Order updated.'), 'success');
												load();
											}).catch(function (error) {
												d.setBusy(false);
												d.setError(error.message);
											});
										},
									},
								],
							});
						})
						.catch(function (error) { toast(error.message, 'error'); });
				}

				function openAddStopDialog(tour) {
					var select = el('select', { class: 'mn-input form-select', 'aria-required': 'true' });
					select.appendChild(el('option', { value: '', text: tr('Select a work order…') }));
					api('GET', withQuery(apiUrl('workOrders'), { limit: 50, offset: 0 }))
						.then(function (envelope) {
							var woRows = (envelope && envelope.data) || [];
							var open = woRows.filter(function (wo) {
								return wo.status !== 'done' && wo.status !== 'cancelled';
							});
							var preferred = open.filter(function (wo) {
								return wo.primaryUserId === tour.techUid;
							});
							var choices = preferred.length ? preferred : open;
							if (!choices.length) {
								openDialog({
									title: tr('Add stop'),
									content: el('p', { text: tr('No open work orders available to add.') }),
									actions: [{ label: tr('Close'), variant: 'mn-btn--primary', onClick: function (d) { d.close(); } }],
								});
								return;
							}
							choices.forEach(function (wo) {
								select.appendChild(el('option', {
									value: String(wo.id),
									text: (wo.number || ('#' + wo.id)) + ' — ' + (wo.title || tr('Work order')),
								}));
							});
							openDialog({
								title: tr('Add stop'),
								content: el('div', { class: 'mn-form-grid mn-form-grid--single' }, [
									field(tr('Work order'), select, { required: true }),
								]),
								initialFocus: 'select',
								actions: [
									{ label: tr('Cancel'), variant: 'mn-btn--tertiary', onClick: function (d) { d.close(); } },
									{
										label: tr('Add stop'),
										variant: 'mn-btn--primary',
										onClick: function (d) {
											var workOrderId = Number(select.value);
											if (!workOrderId || workOrderId < 1) {
												d.setError(tr('Select a work order.'));
												return;
											}
											d.setBusy(true);
											d.setError(null);
											api('POST', apiUrl('tours') + '/' + tour.id + '/stops', {
												workOrderId: workOrderId,
											}).then(function () {
												d.close();
												toast(tr('Stop added.'), 'success');
												announce(tr('Stop added.'), false);
												load();
											}).catch(function (error) {
												d.setBusy(false);
												d.setError(error.message);
											});
										},
									},
								],
							});
						})
						.catch(handleGlobalError);
				}

				function renderToolbar() {
					if (!toolbarHost) {
						return;
					}
					clear(toolbarHost);
					var prevBtn = el('button', {
						type: 'button',
						class: 'mn-btn mn-btn--secondary button mn-tours-toolbar__nav',
						text: '‹',
						'aria-label': tr('Previous day'),
					});
					prevBtn.addEventListener('click', function () {
						date = shiftDate(date, -1);
						load();
					});
					var dateInput = el('input', {
						type: 'date',
						class: 'mn-input form-input mn-tours-toolbar__date',
						value: date,
						'aria-label': tr('Tour date'),
					});
					dateInput.addEventListener('change', function () {
						date = dateInput.value || todayYmd();
						load();
					});
					var nextBtn = el('button', {
						type: 'button',
						class: 'mn-btn mn-btn--secondary button mn-tours-toolbar__nav',
						text: '›',
						'aria-label': tr('Next day'),
					});
					nextBtn.addEventListener('click', function () {
						date = shiftDate(date, 1);
						load();
					});
					var todayBtn = el('button', {
						type: 'button',
						class: 'mn-chip' + (date === todayYmd() ? ' is-active' : ''),
						text: tr('Today'),
						'aria-pressed': date === todayYmd() ? 'true' : 'false',
					});
					todayBtn.addEventListener('click', function () {
						date = todayYmd();
						load();
					});
					toolbarHost.appendChild(prevBtn);
					toolbarHost.appendChild(dateInput);
					toolbarHost.appendChild(nextBtn);
					toolbarHost.appendChild(todayBtn);
					if (ctx && ctx.isOffice) {
						var createBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--primary button',
							text: tr('Create tour'),
						});
						createBtn.addEventListener('click', openCreateTourDialog);
						toolbarHost.appendChild(createBtn);
					}
				}

				function stopTable(tour, stops) {
					return tableOrCards([
						{
							id: 'order',
							label: tr('#'),
							render: function (entry) {
								return tableCellText(entry.index + 1);
							},
						},
						{
							id: 'stop',
							label: tr('Stop'),
							render: function (entry) {
								var wo = entry.stop.workOrder || {};
								var woId = entry.stop.workOrderId || wo.id || '';
								return tableStack(
									tableLink(
										pageUrl('workOrders') + '/' + woId,
										(wo.number || ('#' + woId)) + ' — ' + (wo.title || tr('Work order')),
										'mn-tour-stop-link'
									),
									[wo.customerName, wo.equipmentLabel].filter(Boolean).join(' · ') || null
								);
							},
						},
						{
							id: 'actions',
							label: tr('Actions'),
							actions: true,
							render: function (entry) {
								var wo = entry.stop.workOrder || {};
								var woId = entry.stop.workOrderId || wo.id || '';
								var kids = [
									el('a', {
										class: 'mn-btn mn-btn--primary button',
										href: pageUrl('workOrders') + '/' + woId,
										text: tr('Open'),
									}),
								];
								if (!ctx.isOffice || !entry.stop.id) {
									return kids;
								}
								var stopIdx = entry.index;
								var overflowItems = [];
								if (!tour.orderLocked && stops.length > 1) {
									if (stopIdx > 0) {
										overflowItems.push({
											label: tr('Move up'),
											onClick: function () {
												var ids = stops.map(function (s) {
													return Number(s.workOrderId || (s.workOrder && s.workOrder.id));
												});
												var tmp = ids[stopIdx - 1];
												ids[stopIdx - 1] = ids[stopIdx];
												ids[stopIdx] = tmp;
												reorderTour(tour, ids);
											},
										});
									}
									if (stopIdx < stops.length - 1) {
										overflowItems.push({
											label: tr('Move down'),
											onClick: function () {
												var ids = stops.map(function (s) {
													return Number(s.workOrderId || (s.workOrder && s.workOrder.id));
												});
												var tmp = ids[stopIdx + 1];
												ids[stopIdx + 1] = ids[stopIdx];
												ids[stopIdx] = tmp;
												reorderTour(tour, ids);
											},
										});
									}
								}
								overflowItems.push({
									label: tr('Remove stop'),
									danger: true,
									onClick: function () {
										api('DELETE', apiUrl('tours') + '/' + tour.id + '/stops/' + entry.stop.id)
											.then(function () {
												toast(tr('Stop removed.'), 'success');
												load();
											})
											.catch(function (error) { toast(error.message, 'error'); });
									},
								});
								var overflow = visitOverflowMenu ? visitOverflowMenu(overflowItems) : null;
								if (overflow) {
									kids.push(overflow);
								}
								return kids;
							},
						},
					], stops.map(function (stop, index) {
						return { stop: stop, index: index };
					}), { caption: tr('Stops'), tableClass: 'mn-tours-table' });
				}

				function reorderTour(tour, ids) {
					return api('PUT', apiUrl('tours') + '/' + tour.id + '/reorder', { workOrderIds: ids })
						.then(function () {
							toast(tr('Order updated.'), 'success');
							load();
						})
						.catch(function (error) { toast(error.message, 'error'); });
				}

				function renderTourBucket(tour) {
					var stops = tour.stops || [];
					var techLabel = tour.techDisplayName || tour.techUid || tr('Tour');
					var titleId = 'mn-tour-title-' + String(tour.id);
					var titleKids = [
						el('span', { class: 'mn-tour__name', text: techLabel }),
						el('span', {
							class: 'mn-tour__count',
							text: tr('{count} stops', { count: String(stops.length) }),
						}),
					];
					if (tour.orderLocked) {
						titleKids.push(statusBadge(tr('Order locked'), 'mn-badge--neutral'));
					}

					var actions = el('div', { class: 'mn-tour__actions', role: 'group', 'aria-label': tr('Tour actions') });
					if (ctx && ctx.isOffice) {
						var addStopBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--primary button',
							text: tr('Add stop'),
						});
						addStopBtn.addEventListener('click', function () { openAddStopDialog(tour); });
						actions.appendChild(addStopBtn);

						var moreItems = [];
						if (!tour.orderLocked && stops.length > 1) {
							moreItems.push({
								label: tr('Suggest order'),
								onClick: function () { suggestOrderFlow(tour, stops); },
							});
						}
						moreItems.push({
							label: tour.orderLocked ? tr('Unlock order') : tr('Lock order'),
							onClick: function () {
								api('PUT', apiUrl('tours') + '/' + tour.id, { orderLocked: !tour.orderLocked })
									.then(function () {
										toast(tour.orderLocked ? tr('Order unlocked.') : tr('Order locked.'), 'success');
										load();
									})
									.catch(function (error) { toast(error.message, 'error'); });
							},
						});
						var more = visitOverflowMenu ? visitOverflowMenu(moreItems) : null;
						if (more) {
							actions.appendChild(more);
						}
					}

					var listBody = stops.length
						? stopTable(tour, stops)
						: el('p', { class: 'mn-muted mn-tour__empty', role: 'status', text: tr('No stops yet — add a work order.') });

					return el('section', {
						class: 'mn-bucket mn-tour',
						'aria-labelledby': titleId,
						'data-tour-id': String(tour.id),
					}, [
						el('div', { class: 'mn-bucket__title mn-tour__header' }, [
							el('h2', { class: 'mn-tour__title', id: titleId }, titleKids),
							actions,
						]),
						el('div', { class: 'mn-bucket__list mn-card__body--table' }, [listBody]),
					]);
				}

				function render(envelope) {
					clear(board);
					var rows = (envelope && envelope.data) || [];
					if (envelope && envelope.date) {
						date = envelope.date;
					}
					renderToolbar();

					if (!Array.isArray(rows) || !rows.length) {
						if (ctx && ctx.isOffice) {
							board.appendChild(emptyState(
								emptyTitle(),
								tr('Plan who drives today — then add stops.'),
								el('button', {
									type: 'button',
									class: 'mn-btn mn-btn--primary button',
									text: tr('Create tour'),
									onClick: openCreateTourDialog,
								})
							));
						} else {
							board.appendChild(emptyState(
								date === todayYmd() ? tr('No tour for you') : emptyTitle(),
								tr('Ask the office to plan your stops.')
							));
						}
						return;
					}

					rows.forEach(function (tour) {
						board.appendChild(renderTourBucket(tour));
					});
				}

				function load() {
					var seq = ++loadSeq;
					board.setAttribute('aria-busy', 'true');
					api('GET', withQuery(apiUrl('tours'), { date: date })).then(function (envelope) {
						if (seq !== loadSeq) {
							return;
						}
						render(envelope);
					}).catch(function (error) {
						if (seq !== loadSeq) {
							return;
						}
						clear(board);
						renderToolbar();
						handleGlobalError(error);
					}).finally(function () {
						if (seq === loadSeq) {
							board.removeAttribute('aria-busy');
						}
					});
				}

				renderToolbar();
				load();
			}

			window.MnApp.woAllowed = WO_ALLOWED;
			window.MnApp.woNextStatuses = woNextStatuses;
			window.MnApp.transitionLabel = transitionLabel;
			dom.registerPage('work-orders', pageWorkOrders);
			dom.registerPage('work-order-detail', pageWorkOrderDetail);
			dom.registerPage('dispatch', pageDispatch);
			dom.registerPage('tours', pageTours);
			dom.registerPage('kpi', pageKpi);
			dom.registerPage('exceptions', pageExceptions);
			dom.runCurrentPage();
		});
	}

	function pageKpi() {
		var dom = window.MnApp && window.MnApp.__dom;
		var root = document.getElementById('mn-kpi-snapshot');
		var csvLink = document.getElementById('mn-kpi-csv');
		if (!root || !dom) {
			return;
		}
		var api = dom.api;
		var apiUrl = dom.apiUrl;
		var withQuery = dom.withQuery;
		var el = dom.el;
		var clear = dom.clear;
		var tableOrCards = dom.tableOrCards;
		var tableCellText = dom.tableCellText;
		var statusMeta = dom.statusMeta;
		var statusBadge = dom.statusBadge;
		var days = 30;

		function load() {
			if (csvLink && apiUrl('kpiCsv')) {
				csvLink.setAttribute('href', withQuery(apiUrl('kpiCsv'), { days: String(days) }));
			}
			root.setAttribute('aria-busy', 'true');
			api('GET', withQuery(apiUrl('kpi'), { days: String(days) })).then(function (snap) {
				root.removeAttribute('aria-busy');
				clear(root);
				var cards = [
					{ label: tr('PM compliance'), value: snap.pmCompliancePercent == null ? '—' : (String(snap.pmCompliancePercent) + '%'), hint: tr('On-time visits in the window') },
					{ label: tr('Overdue visits'), value: String(snap.overdueVisitCount || 0), hint: tr('Still open and past due') },
					{ label: tr('Inspection overdue'), value: String(snap.inspectionOverdueCount || 0), hint: tr('Open inspection work past due') },
					{ label: tr('Inspection on-time'), value: snap.inspectionCompliancePercent == null ? '—' : (String(snap.inspectionCompliancePercent) + '%'), hint: tr('Inspection jobs closed on or before due') },
					{ label: tr('MTTR (minutes)'), value: snap.mttrMinutes == null ? '—' : String(snap.mttrMinutes), hint: tr('Corrective start→done average') },
					{ label: tr('Corrective closed'), value: String(snap.correctiveClosedInWindow || 0), hint: tr('In this window') },
				];
				var tiles = el('div', { class: 'mn-kpi-tiles' });
				cards.forEach(function (c) {
					tiles.appendChild(el('article', { class: 'mn-kpi-card' }, [
						el('h3', { class: 'mn-kpi-card__label', text: c.label }),
						el('p', { class: 'mn-kpi-card__value', text: c.value }),
						el('p', { class: 'mn-muted', text: c.hint }),
					]));
				});
				root.appendChild(tiles);

				var statusRows = Object.keys(snap.openWorkOrdersByStatus || {}).map(function (st) {
					return { status: st, count: snap.openWorkOrdersByStatus[st] };
				});
				var statusBlock = el('div', { class: 'mn-kpi-status mn-card__body--table' });
				statusBlock.appendChild(el('h3', { class: 'mn-kpi-status__title', text: tr('Open work orders by status') }));
				if (!statusRows.length) {
					statusBlock.appendChild(el('p', { class: 'mn-muted', role: 'status', text: tr('No open work orders.') }));
				} else {
					statusBlock.appendChild(tableOrCards([
						{
							id: 'status',
							label: tr('Status'),
							render: function (row) {
								var meta = statusMeta(row.status);
								return statusBadge(meta.label, meta.badge, meta.icon);
							},
						},
						{
							id: 'count',
							label: tr('Count'),
							render: function (row) {
								return tableCellText(row.count);
							},
						},
					], statusRows, { caption: tr('Open work orders by status') }));
				}
				root.appendChild(statusBlock);
			}).catch(function (error) {
				root.removeAttribute('aria-busy');
				clear(root);
				root.appendChild(el('p', { class: 'mn-error', role: 'alert', text: error.message || tr('Could not load KPI.') }));
			});
		}
		document.querySelectorAll('[data-mn-kpi-days]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				document.querySelectorAll('[data-mn-kpi-days]').forEach(function (b) {
					var on = b === btn;
					b.classList.toggle('is-active', on);
					b.setAttribute('aria-pressed', on ? 'true' : 'false');
				});
				days = Number(btn.getAttribute('data-mn-kpi-days') || '30') || 30;
				load();
			});
		});
		load();
	}

	function pageExceptions() {
		var dom = window.MnApp && window.MnApp.__dom;
		var root = document.getElementById('mn-exceptions-board');
		if (!root || !dom) {
			return;
		}
		var api = dom.api;
		var apiUrl = dom.apiUrl;
		var pageUrl = dom.pageUrl;
		var withQuery = dom.withQuery;
		var el = dom.el;
		var clear = dom.clear;
		var emptyState = dom.emptyState;
		var tableOrCards = dom.tableOrCards;
		var tableStack = dom.tableStack;
		var tableLink = dom.tableLink;
		var tableCellText = dom.tableCellText;
		var statusMeta = dom.statusMeta;
		var statusBadge = dom.statusBadge;
		var handleGlobalError = dom.handleGlobalError;
		var announce = dom.announce || function () {};
		var filter = 'all';

		function emptyCopy() {
			switch (filter) {
				case 'blocked':
					return {
						title: tr('No blocked jobs'),
						hint: tr('Blocked work orders will show up here.'),
					};
				case 'overdue':
					return {
						title: tr('Nothing overdue'),
						hint: tr('Overdue work orders will show up here.'),
					};
				case 'kit':
					return {
						title: tr('No incomplete kits'),
						hint: tr('Jobs missing packed kits will show up here.'),
					};
				case 'skills':
					return {
						title: tr('No missing skills'),
						hint: tr('Jobs missing required skills will show up here.'),
					};
				default:
					return {
						title: tr('No exceptions right now'),
						hint: tr('When a job needs attention, it appears here.'),
					};
			}
		}

		function setChipState(activeFilter) {
			document.querySelectorAll('[data-mn-exception-filter]').forEach(function (b) {
				var on = (b.getAttribute('data-mn-exception-filter') || 'all') === activeFilter;
				b.classList.toggle('is-active', on);
				b.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
		}

		function load() {
			root.setAttribute('aria-busy', 'true');
			api('GET', withQuery(apiUrl('exceptions'), { filter: filter, limit: 100 })).then(function (envelope) {
				root.removeAttribute('aria-busy');
				clear(root);
				var rows = (envelope && envelope.data) || [];
				if (!rows.length) {
					var copy = emptyCopy();
					var cta = el('a', {
						class: 'mn-btn mn-btn--primary button',
						href: pageUrl('workOrders'),
						text: tr('Open Work orders'),
					});
					root.appendChild(emptyState(copy.title, copy.hint, cta));
					announce(copy.title);
					return;
				}
				root.appendChild(tableOrCards([
					{
						id: 'job',
						label: tr('Work order'),
						render: function (row) {
							return tableStack(
								tableLink(
									pageUrl('workOrders') + '/' + row.id,
									(row.number || '') + ' — ' + (row.title || tr('Work order'))
								),
								[row.customerName, row.equipmentLabel].filter(Boolean).join(' · ') || null
							);
						},
					},
					{
						id: 'status',
						label: tr('Status'),
						render: function (row) {
							var meta = statusMeta(row.status);
							return statusBadge(meta.label, meta.badge, meta.icon);
						},
					},
					{
						id: 'reasons',
						label: tr('Why'),
						render: function (row) {
							var reasons = (row.exceptionReasons || []).join(', ');
							return tableCellText(reasons || null);
						},
					},
					{
						id: 'actions',
						label: tr('Actions'),
						actions: true,
						render: function (row) {
							return el('a', {
								class: 'mn-btn mn-btn--primary button',
								href: pageUrl('workOrders') + '/' + row.id,
								text: tr('Open'),
							});
						},
					},
				], rows, { caption: tr('Exceptions') }));
				announce(tr('{count} exceptions', { count: String(rows.length) }));
			}).catch(function (error) {
				root.removeAttribute('aria-busy');
				clear(root);
				handleGlobalError(error);
				root.appendChild(el('p', { class: 'mn-error', role: 'alert', text: error.message || tr('Could not load exceptions.') }));
			});
		}
		document.querySelectorAll('[data-mn-exception-filter]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				filter = btn.getAttribute('data-mn-exception-filter') || 'all';
				setChipState(filter);
				load();
			});
		});
		setChipState(filter);
		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', register);
	} else {
		register();
	}
})();

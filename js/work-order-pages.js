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

			function reasonLongEnough(value, min) {
				return String(value || '').trim().length >= min;
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
						content: el('div', { class: 'mn-form-grid' }, [
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
					state.offset = 0;
					load();
				});
				resetButton.addEventListener('click', function () {
					statusSelect.value = '';
					qInput.value = '';
					mineToggle.checked = false;
					state.offset = 0;
					load();
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
					root.appendChild(el('section', { class: 'mn-card' }, [
						el('header', { class: 'mn-card__header' }, [
							el('h2', { class: 'mn-card__title', text: (wo.number || '') + ' — ' + (wo.title || '') }),
							el('p', { class: 'mn-card__lead', text: [wo.customerName, wo.equipmentLabel].filter(Boolean).join(' · ') }),
						]),
						el('div', { class: 'mn-card__body' }, [
							statusBadge(meta.label, meta.badge, meta.icon),
							wo.dueOn ? el('span', { class: 'mn-muted', text: ' · ' + tr('Due {date}', { date: fmt(wo.dueOn) }) }) : null,
						]),
					]));

					if (wo.inventorySync === 'failed' || wo.inventorySync === 'ok' || wo.inventorySync === 'disabled') {
						var syncLabel = inventorySyncLabel(wo.inventorySync, wo.inventorySyncCode);
						var syncRole = wo.inventorySync === 'failed' ? 'alert' : 'status';
						root.appendChild(el('div', {
							class: 'mn-callout mn-callout--' + (wo.inventorySync === 'failed' ? 'warning' : 'info'),
							role: syncRole,
						}, [
							el('strong', { text: tr('Stock sync') + ': ' }),
							el('span', { text: syncLabel }),
						]));
					}

					if (wo.warrantyExpired) {
						root.appendChild(el('div', {
							class: 'mn-callout mn-callout--warning',
							role: 'status',
						}, [
							el('strong', { text: tr('Warranty ended') + ': ' }),
							el('span', { text: tr('This equipment’s warranty ended on {date}.', { date: fmt(wo.warrantyEnd) }) }),
						]));
					}

					if (wo.requesterName || wo.symptom || wo.accessNotes) {
						root.appendChild(el('section', { class: 'mn-card', 'aria-labelledby': 'mn-intake-title' }, [
							el('header', { class: 'mn-card__header' }, [
								el('h3', { id: 'mn-intake-title', class: 'mn-card__title', text: tr('Request intake') }),
							]),
							el('div', { class: 'mn-card__body mn-dl' }, [
								wo.requesterName ? el('p', { text: tr('Requester') + ': ' + wo.requesterName + (wo.requesterPhone ? ' · ' + wo.requesterPhone : '') }) : null,
								wo.symptom ? el('p', { text: tr('Symptom') + ': ' + wo.symptom }) : null,
								wo.accessNotes ? el('p', { text: tr('Access') + ': ' + wo.accessNotes }) : null,
							]),
						]));
					}

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
							postTransition({ to: 'done' }).then(function () { afterTransitionOk('done'); }).catch(function (error) {
								if (error.code === 'checklist_incomplete') {
									if (ctx.isOffice) {
										openForceCloseDialog();
									} else {
										toast(error.message || tr('Required checklist items are still open. Finish them or confirm the exception.'), 'error');
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

					var actions = el('div', { class: 'mn-wo-actions', role: 'group', 'aria-label': tr('Change status') });
					next.forEach(function (to) {
						var primary = (to === 'in_progress' || to === 'done');
						var btn = el('button', {
							type: 'button',
							class: 'mn-btn ' + (primary ? 'mn-btn--primary' : 'mn-btn--secondary') + ' button',
							text: transitionLabel(to),
						});
						btn.addEventListener('click', function () { runTransition(to); });
						actions.appendChild(btn);
					});
					if (wo.logHoursUrl) {
						actions.appendChild(el('a', {
							class: 'mn-btn mn-btn--secondary button',
							href: wo.logHoursUrl,
							target: '_blank',
							rel: 'noopener noreferrer',
							text: tr('Log hours'),
						}));
					}
					if (wo.recordTimeUrl) {
						actions.appendChild(el('a', {
							class: 'mn-btn mn-btn--secondary button',
							href: wo.recordTimeUrl,
							target: '_blank',
							rel: 'noopener noreferrer',
							text: tr('Record time'),
						}));
					}
					if (wo.status !== 'done' && wo.status !== 'cancelled') {
						actions.appendChild(el('a', {
							class: 'mn-btn mn-btn--secondary button',
							href: apiUrl('workOrders') + '/' + wo.id + '/pdf/job-pack',
							target: '_blank',
							rel: 'noopener noreferrer',
							text: tr('Download job pack'),
						}));
					}
					if (wo.status === 'done') {
						actions.appendChild(el('a', {
							class: 'mn-btn mn-btn--secondary button',
							href: apiUrl('workOrders') + '/' + wo.id + '/pdf/servicebericht',
							target: '_blank',
							rel: 'noopener noreferrer',
							text: tr('Download service report'),
						}));
					} else if (wo.status !== 'cancelled') {
						var sbDisabled = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--secondary button',
							disabled: 'disabled',
							'aria-describedby': 'mn-wo-servicebericht-hint',
							text: tr('Download service report'),
						});
						actions.appendChild(sbDisabled);
						actions.appendChild(el('span', {
							id: 'mn-wo-servicebericht-hint',
							class: 'mn-hint',
							text: tr('Available after the work order is marked done.'),
						}));
					}
					if (ctx.isOffice && wo.status !== 'done' && wo.status !== 'cancelled') {
						var assignBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--secondary button',
							text: tr('Assign technician'),
						});
						assignBtn.addEventListener('click', function () {
							if (!attachUserPicker) {
								toast(tr('Directory search is unavailable. Reload the page and try again.'), 'error');
								return;
							}
							var picker = attachUserPicker({
								label: tr('Technician'),
								placeholder: tr('Start typing a name…'),
								hint: tr('Search and pick a technician. Never type a raw user id.'),
							});
							if (wo.primaryUserId) {
								picker.setValue && picker.setValue(wo.primaryUserId);
							}
							var helperUids = Array.isArray(wo.helperUids) ? wo.helperUids.slice() : [];
							var helpersHost = el('div', { class: 'mn-field' });
							var helpersChips = el('ul', { class: 'mn-chips', 'aria-label': tr('Helpers') });
							var helperPicker = attachUserPicker({
								label: tr('Add helper'),
								placeholder: tr('Start typing a name…'),
								hint: tr('Optional helpers — search and pick. Never type a raw user id.'),
							});
							function renderHelperChips() {
								clear(helpersChips);
								if (helperUids.length === 0) {
									helpersChips.appendChild(el('li', { class: 'mn-field__hint', text: tr('No helpers yet.') }));
									return;
								}
								helperUids.forEach(function (uid) {
									helpersChips.appendChild(el('li', { class: 'mn-chip' }, [
										el('span', { text: uid }),
										el('button', {
											type: 'button',
											class: 'mn-chip__remove',
											'aria-label': tr('Remove {id}', { id: uid }),
											text: '×',
											onClick: function () {
												helperUids = helperUids.filter(function (x) { return x !== uid; });
												renderHelperChips();
											},
										}),
									]));
								});
							}
							helperPicker.root.addEventListener('mn-user-selected', function () {
								var uid = helperPicker.getValue();
								if (!uid || helperUids.indexOf(uid) !== -1) {
									return;
								}
								helperUids.push(uid);
								helperPicker.setValue && helperPicker.setValue('');
								renderHelperChips();
							});
							renderHelperChips();
							helpersHost.appendChild(helpersChips);
							helpersHost.appendChild(helperPicker.root);
							openDialog({
								title: tr('Assign technician'),
								content: el('div', { class: 'mn-form-grid' }, [
									picker.root,
									helpersHost,
								]),
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
												helperUids: helperUids.slice(),
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
						});
						actions.appendChild(assignBtn);
					}
					if (next.length || wo.logHoursUrl || wo.recordTimeUrl) {
						root.appendChild(el('section', { class: 'mn-card' }, [
							el('header', { class: 'mn-card__header' }, [
								el('h2', { class: 'mn-card__title', text: tr('Next step') }),
								el('p', { class: 'mn-card__lead', text: tr('Big buttons — tap one to move the job forward.') }),
							]),
							el('div', { class: 'mn-card__body' }, [actions]),
						]));
					}

					// Procedure card (draft / planned)
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
						root.appendChild(el('section', { class: 'mn-card' }, [
							el('header', { class: 'mn-card__header' }, [
								el('h2', { class: 'mn-card__title', text: tr('Procedure') }),
								el('p', { class: 'mn-card__lead', text: tr('Checklist template for this job.') }),
							]),
							el('div', { class: 'mn-card__body' }, [procBody]),
						]));
					}

					// Required skills (W2 / UC-SKILL)
					if (ctx.isOffice && wo.status !== 'done' && wo.status !== 'cancelled') {
						var skillsList = el('ul', { class: 'mn-listing' });
						var required = wo.requiredSkills || [];
						if (!required.length) {
							skillsList.appendChild(el('li', { class: 'mn-muted', text: tr('No required skills yet.') }));
						} else {
							required.forEach(function (sk) {
								skillsList.appendChild(el('li', { text: (sk.name || sk.code || ('#' + sk.id)) }));
							});
						}
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
						root.appendChild(el('section', { class: 'mn-card' }, [
							el('header', { class: 'mn-card__header' }, [
								el('h2', { class: 'mn-card__title', text: tr('Required skills') }),
								el('p', { class: 'mn-card__lead', text: tr('Technicians need these skills when enforcement is on.') }),
							]),
							el('div', { class: 'mn-card__body' }, [skillsList, editSkillsBtn]),
						]));
					}

					// Checklist
					var items = wo.checklist || [];
					var checklistBody;
					if (wo.status !== 'in_progress') {
						checklistBody = el('p', { class: 'mn-muted', role: 'status', text: tr('Start work to fill in the checklist.') });
					} else if (!items.length) {
						checklistBody = el('p', { class: 'mn-muted', role: 'status', text: tr('No checklist items on this job.') });
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
					root.appendChild(el('section', { class: 'mn-card' }, [
						el('header', { class: 'mn-card__header' }, [
							el('h2', { class: 'mn-card__title', text: tr('Checklist') }),
							el('p', { class: 'mn-card__lead', text: tr('Mark each step OK, Fail, or N/A.') }),
						]),
						el('div', { class: 'mn-card__body' }, [checklistBody]),
					]));

					// Photos
					var photos = wo.photos || [];
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
					var fileInput = el('input', { type: 'file', accept: 'image/*', capture: 'environment', class: 'mn-input' });
					fileInput.setAttribute('aria-label', tr('Add photo'));
					var addBtn = el('button', { type: 'button', class: 'mn-btn mn-btn--primary button', text: tr('Add photo') });
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
					root.appendChild(el('section', { class: 'mn-card' }, [
						el('header', { class: 'mn-card__header' }, [
							el('h2', { class: 'mn-card__title', text: tr('Photos') }),
							el('p', { class: 'mn-card__lead', text: tr('Evidence for this job — clear and close-up.') }),
						]),
						el('div', { class: 'mn-card__body' }, [
							photos.length ? photoList : el('p', { class: 'mn-muted', role: 'status', text: tr('No photos yet.') }),
							el('div', { class: 'mn-wo-photo-actions' }, [fileInput, addBtn]),
						]),
					]));

					// Signature (open or done — not cancelled)
					if (wo.status !== 'cancelled') {
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
							if (sigPad.isEmpty() && !(wo.signature && wo.signature.id)) {
								toast(tr('Draw a signature first.'), 'error');
								return;
							}
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
							field(tr('Signer name'), signerInput),
							sigPad.canvas,
							el('div', { class: 'mn-wo-actions' }, [clearSigBtn, saveSigBtn]),
						];
						if (wo.signature && wo.signature.id) {
							sigKids.unshift(el('p', {
								class: 'mn-muted',
								role: 'status',
								text: tr('A signature is already on file. Saving replaces it.'),
							}));
						}
						root.appendChild(el('section', { class: 'mn-card' }, [
							el('header', { class: 'mn-card__header' }, [
								el('h2', { class: 'mn-card__title', text: tr('Signature') }),
								el('p', { class: 'mn-card__lead', text: tr('Customer or technician sign-off for this job.') }),
							]),
							el('div', { class: 'mn-card__body' }, sigKids),
						]));
					}

					// Kit
					var kit = wo.kit;
					var kitBody;
					if (!kit) {
						kitBody = el('div', {}, [
							el('p', { class: 'mn-muted', role: 'status', text: tr('No kit attached.') }),
						]);
						if (ctx.isOffice && wo.status !== 'done' && wo.status !== 'cancelled') {
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
						var lines = (kit.lines || []).map(function (line) {
							var packed = Number(line.qtyPacked || 0);
							var required = Number(line.qtyRequired || 0);
							var row = el('li', { class: 'mn-kit-line' }, [
								el('span', {
									text: (line.label || line.sku || '') + ' — ' + tr('Packed {packed} of {required}', {
										packed: packed,
										required: required,
									}),
								}),
							]);
							if (ctx.isOffice && packed < required && wo.status !== 'done' && wo.status !== 'cancelled') {
								var packBtn = el('button', {
									type: 'button',
									class: 'mn-btn mn-btn--compact mn-btn--primary button',
									text: tr('Pack'),
								});
								packBtn.addEventListener('click', function () {
									api('POST', apiUrl('workOrders') + '/' + wo.id + '/kit/lines/' + line.id + '/pack', {
										qtyPacked: required,
									}).then(function () {
										toast(tr('Status updated.'), 'success');
										load();
									}).catch(function (error) {
										toast(error.message, 'error');
									});
								});
								row.appendChild(packBtn);
							}
							return row;
						});
						kitBody = el('ul', { class: 'mn-list' }, lines);
					}
					root.appendChild(el('section', { class: 'mn-card' }, [
						el('header', { class: 'mn-card__header' }, [
							el('h2', { class: 'mn-card__title', text: tr('Kit / parts') }),
							el('p', { class: 'mn-card__lead', text: tr('Parts planned for this job.') }),
						]),
						el('div', { class: 'mn-card__body' }, [kitBody]),
					]));
				}

				load();
			}

			function enableDispatchKeyboard(board) {
				var hint = el('p', {
					class: 'mn-muted mn-dispatch-hint',
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
				board.setAttribute('aria-busy', 'true');
				api('GET', withQuery(apiUrl('dispatch'), { from: todayYmd() })).then(function (data) {
					clear(board);
					var days = (data && data.days) || [];
					var noDue = (data && data.noDueDate) || [];
					var hasAny = days.some(function (day) { return (day.lanes || []).length > 0; }) || noDue.length > 0;
					if (!hasAny) {
						board.appendChild(emptyState(tr('Nothing to dispatch'), tr('Open work orders will appear here for assignment.')));
						return;
					}
					days.forEach(function (day) {
						var lanes = day.lanes || [];
						if (!lanes.length) {
							return;
						}
						var daySection = el('section', { class: 'mn-card' }, [
							el('header', { class: 'mn-card__header' }, [
								el('h2', { class: 'mn-card__title', text: fmt(day.date) }),
							]),
						]);
						var body = el('div', { class: 'mn-card__body mn-dispatch-day' });
						lanes.forEach(function (lane) {
							var cap = lane.capacity;
							var title = lane.uid === 'unassigned' ? tr('Unassigned') : (lane.uid || tr('Unassigned'));
							if (cap) {
								if (cap.onDuty === true) {
									title += ' · ' + tr('On duty');
								} else if (cap.onDuty === false) {
									title += ' · ' + tr('Off duty');
								}
								if (cap.exceeds) {
									title += ' · ' + tr('Capacity');
								}
							}
							var jobs = lane.workOrders || [];
							var list = jobs.length
								? tableOrCards([
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
								], jobs, { caption: title })
								: el('p', { class: 'mn-muted', text: tr('No jobs in this lane.') });
							body.appendChild(el('section', { class: 'mn-dispatch-lane', 'aria-label': title }, [
								el('h3', { class: 'mn-dispatch-lane__title', text: title }),
								list,
							]));
						});
						daySection.appendChild(body);
						board.appendChild(daySection);
					});
					if (noDue.length) {
						board.appendChild(el('section', { class: 'mn-card' }, [
							el('header', { class: 'mn-card__header' }, [
								el('h2', { class: 'mn-card__title', text: tr('No due date') }),
							]),
							el('div', { class: 'mn-card__body mn-card__body--table' }, [
								tableOrCards([
									{
										id: 'job',
										label: tr('Work order'),
										render: function (wo) {
											return el('a', {
												class: 'mn-table-link mn-dispatch-job',
												href: pageUrl('workOrders') + '/' + wo.id,
												text: (wo.number || '') + ' — ' + (wo.title || ''),
											});
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
								], noDue, { caption: tr('No due date') }),
							]),
						]));
					}
					enableDispatchKeyboard(board);
				}).catch(function (error) {
					clear(board);
					handleGlobalError(error);
				}).finally(function () { board.removeAttribute('aria-busy'); });
			}

			function pageTours() {
				var ctx = getCtx();
				var board = document.getElementById('mn-tours-board');
				if (!board) {
					return;
				}
				var date = todayYmd();
				board.setAttribute('aria-busy', 'true');

				function load() {
					board.setAttribute('aria-busy', 'true');
					api('GET', withQuery(apiUrl('tours'), { date: date })).then(render).catch(function (error) {
						clear(board);
						handleGlobalError(error);
					}).finally(function () { board.removeAttribute('aria-busy'); });
				}

				function render(envelope) {
					clear(board);
					var rows = (envelope && envelope.data) || [];
					var toolbar = el('div', { class: 'mn-toolbar mn-toolbar--inline' });
					var dateInput = el('input', {
						type: 'date',
						class: 'mn-input form-input',
						value: date,
						'aria-label': tr('Tour date'),
					});
					dateInput.addEventListener('change', function () {
						date = dateInput.value || todayYmd();
						load();
					});
					toolbar.appendChild(dateInput);
					if (ctx.isOffice) {
						var createBtn = el('button', {
							type: 'button',
							class: 'mn-btn mn-btn--primary button',
							text: tr('Create tour'),
						});
						createBtn.addEventListener('click', function () {
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
								content: el('div', { class: 'mn-form-grid' }, [
									techPicker.root,
								]),
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
						});
						toolbar.appendChild(createBtn);
					}
					board.appendChild(toolbar);

					if (!Array.isArray(rows) || !rows.length) {
						board.appendChild(emptyState(tr('No tours today'), tr('Office can plan day tours under Planning.')));
						return;
					}
					rows.forEach(function (tour) {
						var stops = tour.stops || [];
						var stopList = el('ol', { class: 'mn-tour-stops' });
						stops.forEach(function (stop) {
							var wo = stop.workOrder || {};
							var stopRow = el('li', {}, [
								el('a', {
									href: pageUrl('workOrders') + '/' + (stop.workOrderId || wo.id || ''),
									text: (wo.number || ('#' + (stop.workOrderId || ''))) + ' — ' + (wo.title || tr('Work order')),
								}),
							]);
							if (ctx.isOffice && stop.id) {
								var stopIdx = stops.indexOf(stop);
								function reorderTour(ids) {
									return api('PUT', apiUrl('tours') + '/' + tour.id + '/reorder', { workOrderIds: ids })
										.then(function () {
											toast(tr('Status updated.'), 'success');
											load();
										})
										.catch(function (error) { toast(error.message, 'error'); });
								}
								if (!tour.orderLocked && stops.length > 1) {
									var upBtn = el('button', {
										type: 'button',
										class: 'mn-btn mn-btn--compact mn-btn--secondary button',
										text: tr('Move up'),
										disabled: stopIdx <= 0 ? 'disabled' : null,
									});
									upBtn.addEventListener('click', function () {
										if (stopIdx <= 0) {
											return;
										}
										var ids = stops.map(function (s) {
											return Number(s.workOrderId || (s.workOrder && s.workOrder.id));
										});
										var tmp = ids[stopIdx - 1];
										ids[stopIdx - 1] = ids[stopIdx];
										ids[stopIdx] = tmp;
										reorderTour(ids);
									});
									var downBtn = el('button', {
										type: 'button',
										class: 'mn-btn mn-btn--compact mn-btn--secondary button',
										text: tr('Move down'),
										disabled: stopIdx >= stops.length - 1 ? 'disabled' : null,
									});
									downBtn.addEventListener('click', function () {
										if (stopIdx >= stops.length - 1) {
											return;
										}
										var ids = stops.map(function (s) {
											return Number(s.workOrderId || (s.workOrder && s.workOrder.id));
										});
										var tmp = ids[stopIdx + 1];
										ids[stopIdx + 1] = ids[stopIdx];
										ids[stopIdx] = tmp;
										reorderTour(ids);
									});
									stopRow.appendChild(upBtn);
									stopRow.appendChild(downBtn);
								}
								var removeBtn = el('button', {
									type: 'button',
									class: 'mn-btn mn-btn--compact mn-btn--secondary button',
									text: tr('Remove stop'),
								});
								removeBtn.addEventListener('click', function () {
									api('DELETE', apiUrl('tours') + '/' + tour.id + '/stops/' + stop.id)
										.then(function () {
											toast(tr('Status updated.'), 'success');
											load();
										})
										.catch(function (error) { toast(error.message, 'error'); });
								});
								stopRow.appendChild(removeBtn);
							}
							stopList.appendChild(stopRow);
						});
						var actions = el('div', { class: 'mn-wo-actions', role: 'group' });
						if (ctx.isOffice) {
							var addStopBtn = el('button', {
								type: 'button',
								class: 'mn-btn mn-btn--primary button',
								text: tr('Add stop'),
							});
							addStopBtn.addEventListener('click', function () {
								var select = el('select', { class: 'mn-input form-select', 'aria-required': 'true' });
								select.appendChild(el('option', { value: '', text: tr('Select a work order…') }));
								api('GET', withQuery(apiUrl('workOrders'), { limit: 50, offset: 0 }))
									.then(function (envelope) {
										var rows = (envelope && envelope.data) || [];
										var open = rows.filter(function (wo) {
											return wo.status !== 'done' && wo.status !== 'cancelled';
										});
										if (!open.length) {
											openDialog({
												title: tr('Add stop'),
												content: el('p', { text: tr('No open work orders available to add.') }),
												actions: [{ label: tr('Close'), variant: 'mn-btn--primary', onClick: function (d) { d.close(); } }],
											});
											return;
										}
										open.forEach(function (wo) {
											select.appendChild(el('option', {
												value: String(wo.id),
												text: (wo.number || ('#' + wo.id)) + ' — ' + (wo.title || tr('Work order')),
											}));
										});
										openDialog({
											title: tr('Add stop'),
											content: el('div', { class: 'mn-form-grid' }, [
												field(tr('Work order'), select, { required: true }),
											]),
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
									})
									.catch(handleGlobalError);
							});
							actions.appendChild(addStopBtn);
							var suggestBtn = el('button', {
								type: 'button',
								class: 'mn-btn mn-btn--secondary button',
								text: tr('Suggest order'),
								title: tr('Suggested order — verify drive times'),
								disabled: !!tour.orderLocked,
							});
							suggestBtn.addEventListener('click', function () {
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
									})
									.catch(function (error) { toast(error.message, 'error'); });
							});
							actions.appendChild(suggestBtn);
							var lockBtn = el('button', {
								type: 'button',
								class: 'mn-btn mn-btn--secondary button',
								text: tour.orderLocked ? tr('Unlock order') : tr('Lock order'),
							});
							lockBtn.addEventListener('click', function () {
								api('PUT', apiUrl('tours') + '/' + tour.id, { orderLocked: !tour.orderLocked })
									.then(function () {
										toast(tr('Status updated.'), 'success');
										load();
									})
									.catch(function (error) { toast(error.message, 'error'); });
							});
							actions.appendChild(lockBtn);
						}
						board.appendChild(el('section', { class: 'mn-card' }, [
							el('header', { class: 'mn-card__header' }, [
								el('h2', { class: 'mn-card__title', text: (tour.techUid || tr('Tour')) + (tour.orderLocked ? ' · ' + tr('Order locked') : '') }),
								el('p', { class: 'mn-card__lead', text: (tour.tourDate || '') + ' · ' + tr('Stops') + ': ' + String(stops.length) }),
							]),
							el('div', { class: 'mn-card__body' }, [
								stops.length
									? stopList
									: el('p', { class: 'mn-muted', role: 'status', text: tr('No stops yet — add a work order.') }),
								actions,
							]),
						]));
					});
				}

				load();
			}

			window.MnApp.woAllowed = WO_ALLOWED;
			window.MnApp.woNextStatuses = woNextStatuses;
			window.MnApp.transitionLabel = transitionLabel;
			dom.registerPage('work-orders', pageWorkOrders);
			dom.registerPage('work-order-detail', pageWorkOrderDetail);
			dom.registerPage('dispatch', pageDispatch);
			dom.registerPage('tours', pageTours);
			dom.runCurrentPage();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', register);
	} else {
		register();
	}
})();
